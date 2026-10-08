<?php

/**
 * Unit tests for AddWidgetHandler's stored output.
 *
 * WriteHandlerValidationTest next door covers the input guards and stops at
 * the gate. These tests go past it and read the manifest that reached
 * `saveObject()`, because the defect this file was written for was invisible
 * from the input side: the handler accepted every argument, returned
 * `success`, and stored a widget with neither an id nor a title, which makes
 * the whole manifest fail the canonical validator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp\Handler
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

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the manifest AddWidgetHandler actually stores.
 */
class AddWidgetHandlerTest extends TestCase {

	/**
	 * Provider under test.
	 *
	 * @var BuildiqToolProvider
	 */
	private BuildiqToolProvider $provider;

	/**
	 * OR object read/write double, shared with the container (see
	 * WriteHandlerValidationTest for why both must be wired).
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The manifest handed to saveObject(), captured by the double.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $savedManifest = null;

	/**
	 * Set up the provider with an owner user and a one-dashboard-page version.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($owner);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroups')->willReturn([]);

		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->provider = new BuildiqToolProvider(
			$userSession,
			$groupManager,
			$container,
			$logger,
			$this->objectService,
		);

	}//end setUp()

	/**
	 * Wire the OR double to answer the RBAC probe, the app lookup and the
	 * version lookup, and to record whatever manifest is written back.
	 *
	 * @param array<string, mixed> $manifest The version's starting manifest.
	 *
	 * @return void
	 */
	private function wireVersion(array $manifest): void {
		$callCount = 0;
		$this->objectService->method('searchObjectsBySlug')
			->willReturnCallback(function () use (&$callCount, $manifest): array {
				$callCount++;
				if ($callCount === 1) {
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice']]]];
				}

				if ($callCount === 2) {
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']];
				}

				if ($callCount === 3) {
					return [['uuid' => 'ver-uuid-1', 'slug' => 'development', 'manifest' => $manifest]];
				}

				return [];
			});

		$this->objectService->method('saveObject')
			->willReturnCallback(function (array $object): ObjectEntity {
				$this->savedManifest = $object['manifest'];

				$saved = $this->createMock(ObjectEntity::class);
				$saved->method('jsonSerialize')->willReturn(['uuid' => 'ver-uuid-1', 'slug' => 'development'] + $object);

				return $saved;
			});

	}//end wireVersion()

	/**
	 * A dashboard page carrying nothing but an id.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyDashboardManifest(): array {
		return [
			'version' => '1.0.0',
			'menu' => [],
			'pages' => [['id' => 'home', 'route' => '/', 'type' => 'dashboard', 'title' => 'Home', 'config' => []]],
		];
	}//end emptyDashboardManifest()

	/**
	 * The stored widget carries the three non-empty strings the canonical
	 * validator demands.
	 *
	 * @return void
	 */
	public function testStoredWidgetCarriesIdTitleAndType(): void {
		$this->wireVersion(manifest: $this->emptyDashboardManifest());

		$result = $this->provider->invokeTool('buildiq.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'stat',
			'widgetConfig' => ['register' => 'tool-library', 'schema' => 'loan', 'title' => 'Tools out on loan'],
		]);

		$this->assertArrayNotHasKey('isError', $result);
		$this->assertNotNull($this->savedManifest, 'the handler must have written a manifest');

		$widget = $this->savedManifest['pages'][0]['config']['widgets'][0];

		$this->assertSame('tools-out-on-loan', $widget['id']);
		$this->assertSame('Tools out on loan', $widget['title']);
		$this->assertSame('stat', $widget['type']);

	}//end testStoredWidgetCarriesIdTitleAndType()

	/**
	 * The stored widget is placed on the grid, so the dashboard renders it
	 * instead of reporting that no widgets are configured.
	 *
	 * @return void
	 */
	public function testStoredWidgetGetsALayoutRow(): void {
		$this->wireVersion(manifest: $this->emptyDashboardManifest());

		$this->provider->invokeTool('buildiq.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'stat',
			'widgetId' => 'overdue-tools',
			'title' => 'Overdue tools',
		]);

		$layout = $this->savedManifest['pages'][0]['config']['layout'];

		$this->assertCount(1, $layout);
		$this->assertSame('overdue-tools', $layout[0]['widgetId']);
		$this->assertSame(6, $layout[0]['gridWidth']);

	}//end testStoredWidgetGetsALayoutRow()

	/**
	 * A second widget whose title matches the first gets its own id, so a
	 * page never stores two widgets under one key.
	 *
	 * @return void
	 */
	public function testASecondWidgetDoesNotReuseTheFirstId(): void {
		$manifest = $this->emptyDashboardManifest();
		$manifest['pages'][0]['config'] = [
			'widgets' => [['id' => 'loans', 'title' => 'Loans', 'type' => 'stat']],
			'layout' => [['id' => 'loans', 'widgetId' => 'loans', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2]],
		];
		$this->wireVersion(manifest: $manifest);

		$this->provider->invokeTool('buildiq.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'stat',
			'title' => 'Loans',
		]);

		$ids = array_column($this->savedManifest['pages'][0]['config']['widgets'], 'id');

		$this->assertSame(['loans', 'loans-2'], $ids);

	}//end testASecondWidgetDoesNotReuseTheFirstId()

	/**
	 * A malformed widgetId is refused rather than quietly replaced.
	 *
	 * @return void
	 */
	public function testAMalformedWidgetIdIsRefused(): void {
		$this->wireVersion(manifest: $this->emptyDashboardManifest());

		$result = $this->provider->invokeTool('buildiq.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'stat',
			'widgetId' => 'Tools Out On Loan',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);
		$this->assertNull($this->savedManifest);

	}//end testAMalformedWidgetIdIsRefused()
}//end class
