<?php

/**
 * Unit tests for DemoDataService's choice list and for how it reads the
 * schemas that take no demo data: fragments under register.d, unreadable
 * descriptors, and entries that are not schemas.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the demo-data choices and the exclusion read.
 *
 * @covers \OCA\Buildiq\Service\DemoDataService
 */
final class DemoDataServiceChoicesTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private DemoDataService $service;

	private string $appDir;

	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/buildiq-demo-choices-' . uniqid();
		mkdir($this->appDir . '/lib/Settings/register.d', 0777, true);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);
		$appManager->method('getAppVersion')->willReturn('1.0.0');
		$appManager->method('getInstalledApps')->willReturn(['buildiq', 'openregister']);
		$this->container = $this->createMock(ContainerInterface::class);

		$this->service = new DemoDataService($appManager, $this->container, $this->createMock(LoggerInterface::class));
	}//end setUp()

	protected function tearDown(): void {
		foreach (['/lib/Settings/register.d/*.json', '/lib/Settings/*.json'] as $pattern) {
			foreach ((glob($this->appDir . $pattern) ?: []) as $file) {
				unlink($file);
			}
		}

		@rmdir($this->appDir . '/lib/Settings/register.d');
		@rmdir($this->appDir . '/lib/Settings');
		@rmdir($this->appDir . '/lib');
		@rmdir($this->appDir);
	}//end tearDown()

	/**
	 * Write a file under lib/Settings.
	 *
	 * @param string $name    The relative name.
	 * @param string $content The content.
	 *
	 * @return void
	 */
	private function ship(string $name, string $content): void {
		file_put_contents($this->appDir . '/lib/Settings/' . $name, $content);
	}//end ship()

	/**
	 * The mock descriptor with objects for the given schemas.
	 *
	 * @param array<int, string>|null $schemas One object per schema; null for no objects block.
	 *
	 * @return void
	 */
	private function shipDescriptor(?array $schemas): void {
		$components = ['schemas' => ['Thing' => ['type' => 'object']]];
		if ($schemas !== null) {
			$components['objects'] = array_map(static fn (string $schema): array => ['@self' => ['register' => 'buildiq', 'schema' => $schema]], $schemas);
		}

		$this->ship('buildiq_mock_register.json', (string)json_encode(['x-openregister' => ['type' => 'mock'], 'components' => $components]));
	}//end shipDescriptor()

	/**
	 * A stand-in importer that records whether it was called.
	 *
	 * @return object
	 */
	private function importer(): object {
		return new class {
			public bool $called = false;

			/**
			 * @param string               $appId   Config identity.
			 * @param array<string, mixed> $data    Descriptor.
			 * @param string               $version App version.
			 * @param boolean              $force   Forced.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->called = true;
				return ['objects' => $data['components']['objects'], 'registers' => [], 'schemas' => []];
			}
		};
	}//end importer()

	/**
	 * Without a descriptor only "None" is offered.
	 *
	 * @return void
	 */
	public function testWithoutADescriptorOnlyNoneIsOffered(): void {
		$choices = $this->service->listChoices();

		self::assertSame(['none'], array_column($choices, 'id'));
		self::assertSame(0, $choices[0]['objectCount']);
		self::assertSame('CloseCircleOutline', $choices[0]['icon']);
	}//end testWithoutADescriptorOnlyNoneIsOffered()

	/**
	 * The example card carries the number of objects in the shipped file.
	 *
	 * @return void
	 */
	public function testTheExampleCardCarriesTheShippedObjectCount(): void {
		$this->shipDescriptor(['Thing', 'Thing', 'Thing']);

		$choices = $this->service->listChoices();

		self::assertSame(['none', 'demo'], array_column($choices, 'id'));
		self::assertSame('Example data', $choices[1]['label']);
		self::assertSame(3, $choices[1]['objectCount']);
		self::assertSame('DatabaseOutline', $choices[1]['icon']);
		self::assertStringNotContainsString('3', $choices[1]['description']);
	}//end testTheExampleCardCarriesTheShippedObjectCount()

	/**
	 * A descriptor without an objects block offers the card with zero objects.
	 *
	 * @return void
	 */
	public function testADescriptorWithoutObjectsCountsZero(): void {
		$this->shipDescriptor(null);

		self::assertSame(0, $this->service->listChoices()[1]['objectCount']);
	}//end testADescriptorWithoutObjectsCountsZero()

	/**
	 * A descriptor that is not JSON offers only "None".
	 *
	 * @return void
	 */
	public function testAMalformedDescriptorOffersOnlyNone(): void {
		$this->ship('buildiq_mock_register.json', '{not json');

		self::assertSame(['none'], array_column($this->service->listChoices(), 'id'));
	}//end testAMalformedDescriptorOffersOnlyNone()

	/**
	 * An exclusion declared in a register.d fragment, by reason string and
	 * under its slug, refuses the import; unreadable files, a schemas block
	 * that is not a list and entries that are not schemas are passed over.
	 *
	 * @return void
	 */
	public function testAnExclusionInAFragmentIsHonouredPastUnreadableEntries(): void {
		$this->ship('broken.json', '{not json');
		$this->ship('odd_register.json', (string)json_encode(['components' => ['schemas' => 'not a list']]));
		$this->ship(
			'register.d/built.json',
			(string)json_encode(
				[
					'components' => [
						'schemas' => [
							'Note'  => 'not a schema',
							'Built' => ['slug' => 'built-app', 'x-openregister-demo-data' => 'Written by the app itself.'],
							'Open'  => ['slug' => 'open', 'x-openregister-demo-data' => ''],
						],
					],
				]
			)
		);
		$this->shipDescriptor(['open', 'built-app']);
		$this->container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('schema(s) this app writes itself: built-app.');

		$this->service->install();
	}//end testAnExclusionInAFragmentIsHonouredPastUnreadableEntries()

	/**
	 * Without any exclusion declared, the dataset imports.
	 *
	 * @return void
	 */
	public function testWithoutExclusionsTheDatasetImports(): void {
		$this->shipDescriptor(['Thing', 'Thing']);
		$importer = $this->importer();
		$this->container->expects($this->once())->method('get')->with('OCA\OpenRegister\Service\ConfigurationService')->willReturn($importer);

		$result = $this->service->install();

		self::assertTrue($importer->called);
		self::assertSame(2, $result['objects']);
		self::assertSame(2, $result['declared']);
	}//end testWithoutExclusionsTheDatasetImports()
}//end class
