<?php

/**
 * Unit tests for ManifestBaseResolver (app-delta-override): a template base
 * is the template's manifest, a fleet-app base is the installed app's
 * bundled manifest, and anything that cannot be read is null.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ManifestBaseResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Service\ManifestBaseResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class ManifestBaseResolverTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;

	private IAppManager&MockObject $appManager;

	private string $appDir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appDir = sys_get_temp_dir() . '/buildiq-base-' . bin2hex(random_bytes(4));
		mkdir($this->appDir . '/src', 0700, true);
	}//end setUp()

	protected function tearDown(): void {
		foreach (glob($this->appDir . '/src/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->appDir . '/src');
		rmdir($this->appDir);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The resolver under test.
	 *
	 * @return ManifestBaseResolver
	 */
	private function resolver(): ManifestBaseResolver {
		$register = $this->getMockBuilder(Register::class)->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(1);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(3);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->with('application-template')->willReturn($schema);

		return new ManifestBaseResolver($this->objectService, $registers, $schemas, $this->appManager, $this->createMock(LoggerInterface::class));
	}//end resolver()

	public function testATemplateBaseIsTheTemplatesManifest(): void {
		$manifest = ['version' => '1.0.0', 'pages' => []];
		$this->objectService->expects($this->once())->method('searchObjects')
			->with(['@self' => ['register' => 1, 'schema' => 3], 'slug' => 'crm'])
			->willReturn([['slug' => 'crm', 'manifest' => $manifest]]);

		$this->assertSame($manifest, $this->resolver()->template(slug: 'crm'));
	}

	public function testATemplateWithAnotherSlugOrNoManifestIsNoBase(): void {
		$this->objectService->method('searchObjects')->willReturn([['slug' => 'crm-2', 'manifest' => ['pages' => []]], ['slug' => 'crm', 'manifest' => 'x']]);

		$this->assertNull($this->resolver()->template(slug: 'crm'));
		$this->assertNull($this->resolver()->template(slug: ''));
	}

	public function testATemplateThatCannotBeReadIsNoBase(): void {
		$this->objectService->method('searchObjects')->willThrowException(new RuntimeException('store down'));

		$this->assertNull($this->resolver()->template(slug: 'crm'));
	}

	public function testAFleetAppBaseIsItsBundledManifest(): void {
		file_put_contents($this->appDir . '/src/manifest.json', '{"version":"2.0.0","pages":[{"id":"home"}]}');
		$this->appManager->method('isEnabledForAnyone')->with('pipelinq')->willReturn(true);
		$this->appManager->method('getAppPath')->with('pipelinq')->willReturn($this->appDir);

		$this->assertSame(['version' => '2.0.0', 'pages' => [['id' => 'home']]], $this->resolver()->fleetApp(appId: 'pipelinq'));
	}

	public function testAFleetAppThatIsNotEnabledOrHasNoManifestIsNoBase(): void {
		$this->appManager->method('getAppPath')->willReturn($this->appDir);
		$this->appManager->method('isEnabledForAnyone')->willReturnMap([['off', false], ['nomanifest', true], ['broken', true]]);

		$this->assertNull($this->resolver()->fleetApp(appId: 'off'));
		$this->assertNull($this->resolver()->fleetApp(appId: 'nomanifest'));

		file_put_contents($this->appDir . '/src/manifest.json', 'not json');
		$this->assertNull($this->resolver()->fleetApp(appId: 'broken'));
	}

	public function testAnIdThatIsNotAnAppIdIsNeverLookedUp(): void {
		$this->appManager->expects($this->never())->method('getAppPath');
		$this->appManager->expects($this->never())->method('isEnabledForAnyone');

		foreach (['../etc', 'Pipelinq', 'a', 'app/../../x', ''] as $id) {
			$this->assertNull($this->resolver()->fleetApp(appId: $id), $id);
		}
	}
}//end class
