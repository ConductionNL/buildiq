<?php

/**
 * Golden tests for the three MCP handlers that edit a version's manifest.
 *
 * upsertPage, upsertMenuItem and addWidget each load the version, change its
 * manifest, apply the size caps, save it and summarise the saved version. These
 * goldens pin what each one answers on every leg of that walk (not found, cap
 * refused, created, updated, store failure) so the steps can be shared without
 * changing a single key of the result.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the answers of the manifest-editing MCP handlers.
 */
class ManifestEditHandlersGoldenTest extends TestCase {

	/**
	 * Provider under test.
	 *
	 * @var BuildiqToolProvider
	 */
	private BuildiqToolProvider $provider;

	/**
	 * OR object read/write double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The manifest handed to saveObject(), captured by the double.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $savedManifest = null;

	/**
	 * Set up the provider with an owner user.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($owner);
		$groupManager->method('getUserGroups')->willReturn([]);
		$container->method('get')->willReturn($this->objectService);
		$this->provider = new BuildiqToolProvider($userSession, $groupManager, $container, $this->logger, $this->objectService);
	}//end setUp()

	/**
	 * One valid call per handler.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function editCalls(): array {
		return [
			'upsertPage'     => ['buildiq.upsertPage', ['appSlug' => 'my-app', 'pageId' => 'about', 'title' => 'About', 'type' => 'dashboard', 'route' => '/about']],
			'upsertMenuItem' => ['buildiq.upsertMenuItem', ['appSlug' => 'my-app', 'id' => 'about', 'label' => 'About', 'route' => 'about']],
			'addWidget'      => ['buildiq.addWidget', ['appSlug' => 'my-app', 'pageId' => 'home', 'widgetType' => 'text', 'widgetId' => 'w1', 'title' => 'Intro']],
		];
	}//end editCalls()

	/**
	 * A missing version is answered with the not_found envelope and nothing is saved.
	 *
	 * @param string               $tool The tool name.
	 * @param array<string, mixed> $args The tool arguments.
	 *
	 * @return void
	 */
	#[DataProvider('editCalls')]
	public function testMissingVersionIsNotFound(string $tool, array $args): void {
		$this->wireVersion(manifest: null);
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->provider->invokeTool($tool, $args);

		$message = "No version 'development' found for app 'my-app'.";
		self::assertSame(['isError' => true, 'error' => 'not_found', 'message' => $message], $result);
	}//end testMissingVersionIsNotFound()

	/**
	 * A store failure is logged with the handler's context and answered generically.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, string>, 4: array<string, mixed>}>
	 */
	public static function failingCalls(): array {
		$calls = self::editCalls();
		return [
			'upsertPage'     => array_merge($calls['upsertPage'], ['Buildiq MCP: upsertPage failed', ['appSlug' => 'my-app', 'pageId' => 'about'], ['isError' => true, 'error' => 'upsert_failed', 'message' => 'Failed to upsert page. See server logs for details.']]),
			'upsertMenuItem' => array_merge($calls['upsertMenuItem'], ['Buildiq MCP: upsertMenuItem failed', ['appSlug' => 'my-app', 'id' => 'about'], ['isError' => true, 'error' => 'upsert_failed', 'message' => 'Failed to upsert menu item. See server logs for details.']]),
			'addWidget'      => array_merge($calls['addWidget'], ['Buildiq MCP: addWidget failed', ['appSlug' => 'my-app', 'pageId' => 'home'], ['isError' => true, 'error' => 'add_failed', 'message' => 'Failed to add widget. See server logs for details.']]),
		];
	}//end failingCalls()

	/**
	 * The store failure path.
	 *
	 * @param string                $tool    The tool name.
	 * @param array<string, mixed>  $args    The tool arguments.
	 * @param string                $message The log message.
	 * @param array<string, string> $context The leading log context keys.
	 * @param array<string, mixed>  $answer  The envelope returned.
	 *
	 * @return void
	 */
	#[DataProvider('failingCalls')]
	public function testStoreFailureIsLogged(string $tool, array $args, string $message, array $context, array $answer): void {
		$this->wireVersion(manifest: $this->manifest(menuItems: 1));
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('disk full'));
		$expected = $context + ['exception' => 'disk full'];
		$this->logger->expects(self::once())->method('error')->with($message, self::callback(
			static fn (array $logged): bool => array_keys($logged) === [...array_keys($expected), 'trace']
				&& array_slice($logged, 0, count($expected)) === $expected
		));

		self::assertSame($answer, $this->provider->invokeTool($tool, $args));
	}//end testStoreFailureIsLogged()

	/**
	 * A new page is created, stored and summarised.
	 *
	 * @return void
	 */
	public function testUpsertPageCreates(): void {
		$this->wireVersion(manifest: $this->manifest(menuItems: 1));

		$result = $this->provider->invokeTool(...self::editCalls()['upsertPage']);

		self::assertSame(['success', 'action', 'page', 'pageCount', 'version'], array_keys($result));
		self::assertSame(['created', 2, self::version()], [$result['action'], $result['pageCount'], $result['version']]);
		self::assertSame($this->savedManifest['pages'][1], $result['page']);
	}//end testUpsertPageCreates()

	/**
	 * An existing page is replaced, even when the manifest is over the page cap.
	 *
	 * @return void
	 */
	public function testUpsertPageUpdatesPastTheCap(): void {
		$this->wireVersion(manifest: $this->manifest(menuItems: 31));
		$args = ['pageId' => 'HOME', 'route' => '/'] + self::editCalls()['upsertPage'][1];

		$result = $this->provider->invokeTool('buildiq.upsertPage', $args);

		self::assertSame(['updated', 1, self::version()], [$result['action'], $result['pageCount'], $result['version']]);
	}//end testUpsertPageUpdatesPastTheCap()

	/**
	 * Appending to a manifest over a cap is refused before anything is saved.
	 *
	 * @param string               $tool The tool name.
	 * @param array<string, mixed> $args The tool arguments.
	 *
	 * @return void
	 */
	#[DataProvider('editCalls')]
	public function testAppendOverTheCapIsRefused(string $tool, array $args): void {
		$this->wireVersion(manifest: $this->manifest(menuItems: 31));
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->provider->invokeTool($tool, $args);

		$expected = ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Manifest exceeds maximum of 30 menu items.'];
		self::assertSame($expected, $result);
	}//end testAppendOverTheCapIsRefused()

	/**
	 * A new menu item is created; an existing one is updated past the cap.
	 *
	 * @return void
	 */
	public function testUpsertMenuItemCreatesAndUpdates(): void {
		$this->wireVersion(manifest: $this->manifest(menuItems: 1));
		$created = $this->provider->invokeTool(...self::editCalls()['upsertMenuItem']);
		$item = ['id' => 'about', 'label' => 'About', 'icon' => '', 'route' => 'about', 'order' => 100];
		self::assertSame(['success' => true, 'action' => 'created', 'menuItem' => $item, 'menuCount' => 2, 'version' => self::version()], $created);

		$this->setUp();
		$this->wireVersion(manifest: $this->manifest(menuItems: 31));
		$updated = $this->provider->invokeTool('buildiq.upsertMenuItem', ['id' => 'M0'] + self::editCalls()['upsertMenuItem'][1]);
		self::assertSame(['updated', 31], [$updated['action'], $updated['menuCount']]);
	}//end testUpsertMenuItemCreatesAndUpdates()

	/**
	 * A widget is added and summarised.
	 *
	 * @return void
	 */
	public function testAddWidgetAdds(): void {
		$this->wireVersion(manifest: $this->manifest(menuItems: 1));

		$result = $this->provider->invokeTool(...self::editCalls()['addWidget']);

		self::assertSame(['success', 'added', 'widget', 'pageId', 'widgetCount', 'version'], array_keys($result));
		self::assertSame([true, 'home', 1, self::version()], [$result['added'], $result['pageId'], $result['widgetCount'], $result['version']]);
	}//end testAddWidgetAdds()

	/**
	 * The version summary every successful edit ends with.
	 *
	 * @return array{uuid: string, slug: string}
	 */
	private static function version(): array {
		return ['uuid' => 'ver-uuid-1', 'slug' => 'saved-slug'];
	}//end version()

	/**
	 * A one-page manifest with the given number of menu items.
	 *
	 * @param int $menuItems How many menu items to carry.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(int $menuItems): array {
		$menu = [];
		for ($i = 0; $i < $menuItems; $i++) {
			$menu[] = ['id' => 'm' . $i, 'label' => 'M' . $i, 'route' => 'home'];
		}

		$page = ['id' => 'home', 'route' => '/', 'type' => 'dashboard', 'title' => 'Home', 'config' => []];
		return ['version' => '1.0.0', 'menu' => $menu, 'pages' => [$page]];
	}//end manifest()

	/**
	 * Wire the RBAC probe, the app lookup and the version lookup (none when null).
	 *
	 * @param array<string, mixed>|null $manifest The version's manifest, or null for no version.
	 *
	 * @return void
	 */
	private function wireVersion(?array $manifest): void {
		$answers = [
			[['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice']]]],
			[['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']],
			($manifest === null) ? [] : [['uuid' => 'ver-uuid-1', 'slug' => 'development', 'manifest' => $manifest]],
		];
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(static function () use (&$answers): array {
			return (array)array_shift($answers);
		});
		$this->objectService->method('saveObject')->willReturnCallback(function (array $object): ObjectEntity {
			$this->savedManifest = $object['manifest'];
			$saved = $this->createMock(ObjectEntity::class);
			$saved->method('jsonSerialize')->willReturn(['uuid' => 'ver-uuid-1', 'slug' => 'saved-slug']);
			return $saved;
		});
	}//end wireVersion()
}//end class
