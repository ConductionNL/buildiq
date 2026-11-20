<?php

/**
 * Unit tests for the MCP write handlers' malformed-argument guards.
 *
 * `config`, `widgetConfig` and `required` are read straight off the tool
 * arguments, which are arbitrary JSON from an MCP client, and each handler
 * normalises a non-array to `[]` before it goes anywhere near the manifest.
 * That guard is the only thing standing between a client that sends
 * `"config": "oops"` and a manifest with a bare string where a page's config
 * object belongs — which no schema check downstream would repair, because the
 * manifest would already be written.
 *
 * Nothing exercised those lines: every existing test passes a well-formed
 * object. These pass the malformed shapes and assert what lands in the saved
 * manifest.
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
 *
 * @covers \OCA\Buildiq\Mcp\Handler\UpsertPageHandler
 * @covers \OCA\Buildiq\Mcp\Handler\AddWidgetHandler
 *
 * @uses \OCA\Buildiq\Mcp\BuildiqToolProvider
 * @uses \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 * @uses \OCA\Buildiq\Support\ObjectRecord
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Mcp\Handler\UpsertPageHandler;
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
 * Tests the non-array normalisation of tool arguments.
 */
class MalformedToolArgumentGuardsTest extends TestCase {
	/**
	 * Mock OR object service.
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
	 * The MCP tool provider, for the handlers reached through it.
	 *
	 * @var BuildiqToolProvider
	 */
	private BuildiqToolProvider $provider;

	/**
	 * A signed-in non-admin caller `alice`.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * A non-admin group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Wire the caller, the OR double and the provider.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($user);

		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$this->objectService = $this->createMock(
			originalClassName: ObjectServiceInterface::class
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->provider = new BuildiqToolProvider(
			$this->session,
			$this->groupManager,
			$container,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->objectService,
		);
	}//end setUp()

	/**
	 * Answer the RBAC probe, the app lookup and the version lookup, and
	 * record whatever manifest is written back.
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
					return [
						[
							'uuid' => 'app-uuid-1',
							'slug' => 'my-app',
							'permissions' => ['owners' => ['user:alice']],
						],
					];
				}

				if ($callCount === 2) {
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']];
				}

				if ($callCount === 3) {
					return [
						['uuid' => 'ver-uuid-1', 'slug' => 'development', 'manifest' => $manifest],
					];
				}

				return [];
			});

		$this->objectService->method('saveObject')
			->willReturnCallback(function (array $object): ObjectEntity {
				$this->savedManifest = $object['manifest'];

				$saved = $this->createMock(originalClassName: ObjectEntity::class);
				$saved->method('jsonSerialize')
					->willReturn(['uuid' => 'ver-uuid-1', 'slug' => 'development'] + $object);

				return $saved;
			});
	}//end wireVersion()

	/**
	 * A one-dashboard-page manifest.
	 *
	 * @return array<string, mixed>
	 */
	private function dashboardManifest(): array {
		return [
			'version' => '1.0.0',
			'menu' => [],
			'pages' => [
				[
					'id' => 'home',
					'route' => '/',
					'type' => 'dashboard',
					'title' => 'Home',
					'config' => [],
				],
			],
		];
	}//end dashboardManifest()

	/**
	 * Non-array `config` values an MCP client could plausibly send.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function nonArrayValues(): array {
		return [
			'a string' => ['oops'],
			'a number' => [42],
			'a boolean' => [true],
			'JSON null as a string' => ['null'],
		];
	}

	/**
	 * upsertPage: a non-array `config` is stored as an empty object, never as
	 * the scalar itself.
	 *
	 * @param mixed $value The malformed config value.
	 *
	 * @dataProvider nonArrayValues
	 *
	 * @return void
	 */
	public function testUpsertPageStoresAnEmptyConfigForANonArrayValue(mixed $value): void {
		$this->wireVersion(manifest: $this->dashboardManifest());

		$handler = new UpsertPageHandler(
			userSession: $this->session,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			groupManager: $this->groupManager,
			objectService: $this->objectService
		);

		$result = $handler->handle(args: [
			'appSlug' => 'my-app',
			'pageId' => 'cases',
			'title' => 'Cases',
			'type' => 'index',
			'route' => '/cases',
			'config' => $value,
		]);

		self::assertArrayNotHasKey('isError', $result);
		self::assertNotNull($this->savedManifest, 'the handler must have written a manifest');

		$page = null;
		foreach ($this->savedManifest['pages'] as $entry) {
			if (($entry['id'] ?? '') === 'cases') {
				$page = $entry;
				break;
			}
		}

		self::assertNotNull($page, 'the new page must be in the saved manifest');
		self::assertSame(
			[],
			$page['config'],
			'a scalar config must be normalised away, not written into the manifest'
		);
	}//end testUpsertPageStoresAnEmptyConfigForANonArrayValue()

	/**
	 * upsertPage: a well-formed config still goes through untouched, so the
	 * guard above is not just discarding everything.
	 *
	 * @return void
	 */
	public function testUpsertPageKeepsAWellFormedConfig(): void {
		$this->wireVersion(manifest: $this->dashboardManifest());

		$handler = new UpsertPageHandler(
			userSession: $this->session,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			groupManager: $this->groupManager,
			objectService: $this->objectService
		);

		$handler->handle(args: [
			'appSlug' => 'my-app',
			'pageId' => 'cases',
			'title' => 'Cases',
			'type' => 'index',
			'route' => '/cases',
			'config' => ['schema' => 'case'],
		]);

		$page = null;
		foreach ($this->savedManifest['pages'] as $entry) {
			if (($entry['id'] ?? '') === 'cases') {
				$page = $entry;
				break;
			}
		}

		self::assertSame(['schema' => 'case'], $page['config']);
	}//end testUpsertPageKeepsAWellFormedConfig()

	/**
	 * addWidget: a non-array `widgetConfig` is normalised away, and the
	 * widget is still stored with its identity intact.
	 *
	 * @param mixed $value The malformed widgetConfig value.
	 *
	 * @dataProvider nonArrayValues
	 *
	 * @return void
	 */
	public function testAddWidgetDropsANonArrayWidgetConfig(mixed $value): void {
		$this->wireVersion(manifest: $this->dashboardManifest());

		$result = $this->provider->invokeApprovedTool('buildiq.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'stat',
			'widgetId' => 'overdue-tools',
			'title' => 'Overdue tools',
			'widgetConfig' => $value,
		]);

		self::assertArrayNotHasKey('isError', $result);
		self::assertNotNull($this->savedManifest, 'the handler must have written a manifest');

		$widget = $this->savedManifest['pages'][0]['config']['widgets'][0];

		self::assertSame('overdue-tools', $widget['id']);
		self::assertSame('stat', $widget['type']);
		// Whatever the widget's own shape, none of the scalar leaked into it.
		self::assertNotContains(
			$value,
			array_values($widget),
			'the scalar widgetConfig must not be written onto the widget'
		);
	}//end testAddWidgetDropsANonArrayWidgetConfig()

	/**
	 * addWidget: a malformed `widgetConfig` is not accepted as a way past the
	 * widget-type registry — an unknown type is still refused.
	 *
	 * @return void
	 */
	public function testAddWidgetStillRefusesAnUnknownTypeWithAMalformedConfig(): void {
		$this->wireVersion(manifest: $this->dashboardManifest());
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->provider->invokeApprovedTool('buildiq.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'not-a-widget',
			'widgetId' => 'x',
			'title' => 'X',
			'widgetConfig' => 'oops',
		]);

		self::assertTrue(($result['isError'] ?? false));
	}//end testAddWidgetStillRefusesAnUnknownTypeWithAMalformedConfig()
}//end class
