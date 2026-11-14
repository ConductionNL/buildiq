<?php

/**
 * Golden tests for the case-insensitive upsert of the page and menu handlers.
 *
 * upsertPage and upsertMenuItem replace an existing entry whose id matches the
 * requested one ignoring case, in place, and append an unknown one. These pin
 * the stored list and the reported action for both, so the shared upsert
 * cannot reorder, duplicate or drop an entry.
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
 * Pins the stored list after an upsert.
 */
class ManifestEntryUpsertTest extends TestCase {

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
	 * The manifest handed to saveObject().
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * Set up the provider with an owner user.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($owner);
		$groupManager->method('getUserGroups')->willReturn([]);
		$container->method('get')->willReturn($this->objectService);
		$this->provider = new BuildiqToolProvider($userSession, $groupManager, $container, $this->createMock(LoggerInterface::class), $this->objectService);
	}//end setUp()

	/**
	 * A page whose id matches ignoring case is replaced where it stands.
	 *
	 * @return void
	 */
	public function testPageIsReplacedInPlace(): void {
		$this->wire(pages: [$this->page(id: 'home'), $this->page(id: 'about'), 'stray']);

		$result = $this->provider->invokeTool('buildiq.upsertPage', ['appSlug' => 'my-app', 'pageId' => 'ABOUT', 'title' => 'Over ons', 'type' => 'index', 'route' => '/about']);

		self::assertSame(['updated', 3], [$result['action'], $result['pageCount']]);
		self::assertSame(['home', 'ABOUT', null], array_map(static fn ($p) => is_array($p) ? $p['id'] : null, $this->saved['pages']));
	}//end testPageIsReplacedInPlace()

	/**
	 * An unknown page is appended.
	 *
	 * @return void
	 */
	public function testUnknownPageIsAppended(): void {
		$this->wire(pages: [$this->page(id: 'home')]);

		$result = $this->provider->invokeTool('buildiq.upsertPage', ['appSlug' => 'my-app', 'pageId' => 'contact', 'title' => 'Contact', 'type' => 'index', 'route' => '/contact']);

		self::assertSame(['created', 2], [$result['action'], $result['pageCount']]);
		self::assertSame(['home', 'contact'], array_column($this->saved['pages'], 'id'));
	}//end testUnknownPageIsAppended()

	/**
	 * Only the first matching menu item is replaced; an unknown one is appended.
	 *
	 * @return void
	 */
	public function testMenuItemsUpsertCaseInsensitively(): void {
		$menu = [['id' => 'm1', 'label' => 'One'], ['id' => 'M1', 'label' => 'Dup'], ['id' => 'm2', 'label' => 'Two']];
		$this->wire(pages: [$this->page(id: 'home')], menu: $menu);

		$result = $this->provider->invokeTool('buildiq.upsertMenuItem', ['appSlug' => 'my-app', 'id' => 'M1', 'label' => 'Een', 'route' => 'home']);

		self::assertSame(['updated', 3], [$result['action'], $result['menuCount']]);
		self::assertSame([['M1', 'Een'], ['M1', 'Dup'], ['m2', 'Two']], array_map(static fn (array $m): array => [$m['id'], $m['label']], $this->saved['menu']));
	}//end testMenuItemsUpsertCaseInsensitively()

	/**
	 * A minimal valid page.
	 *
	 * @param string $id The page id.
	 *
	 * @return array<string, mixed>
	 */
	private function page(string $id): array {
		return ['id' => $id, 'route' => '/' . $id, 'type' => 'index', 'title' => ucfirst($id), 'config' => []];
	}//end page()

	/**
	 * Wire the RBAC probe, the app and version lookups and the save.
	 *
	 * @param array<int, mixed> $pages The version's pages.
	 * @param array<int, mixed> $menu  The version's menu.
	 *
	 * @return void
	 */
	private function wire(array $pages, array $menu = []): void {
		$manifest = ['version' => '1.0.0', 'menu' => $menu, 'pages' => $pages];
		$answers = [
			[['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice']]]],
			[['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']],
			[['uuid' => 'ver-uuid-1', 'slug' => 'development', 'manifest' => $manifest]],
		];
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(static function () use (&$answers): array {
			return (array)array_shift($answers);
		});
		$this->objectService->method('saveObject')->willReturnCallback(function (array $object): ObjectEntity {
			$this->saved = $object['manifest'];
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn(['uuid' => 'ver-uuid-1', 'slug' => 'development']);
			return $entity;
		});
	}//end wire()
}//end class
