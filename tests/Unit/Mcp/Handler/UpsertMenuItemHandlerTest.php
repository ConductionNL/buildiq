<?php

/**
 * Unit tests for the UpsertMenuItemHandler MCP tool past its argument guards.
 *
 * Covers the write-role gate, a missing app or version, creating and
 * replacing a menu item (matched case-insensitively on its id), the manifest
 * cap that applies to new entries only, the version lock, and a failing save.
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

use OCA\Buildiq\Mcp\Handler\UpsertMenuItemHandler;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for UpsertMenuItemHandler::handle.
 */
class UpsertMenuItemHandlerTest extends TestCase {

	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Handler under test.
	 *
	 * @var UpsertMenuItemHandler
	 */
	private UpsertMenuItemHandler $handler;

	/**
	 * Wire a signed-in, non-admin caller `alice` and the handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroups')->willReturn([]);

		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->handler = new UpsertMenuItemHandler(
			userSession: $session,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->logger,
			groupManager: $groupManager,
			objectService: $this->objectService
		);
	}//end setUp()

	/**
	 * Valid tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function args(): array {
		return [
			'appSlug' => 'demo',
			'id' => 'cases',
			'label' => 'Cases',
			'icon' => 'icon-folder',
			'route' => 'cases',
			'order' => '5',
		];
	}//end args()

	/**
	 * Build an entity mock that serialises to the given data.
	 *
	 * @param array<string, mixed> $data The serialised payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Route the app and version lookups.
	 *
	 * @param array<string, mixed> $permissions The app's permissions block.
	 * @param array<int, array<string, mixed>>|null $entries The version's existing menu entries; null means no version.
	 *
	 * @return void
	 */
	private function arrange(array $permissions, ?array $entries): void {
		$app = ['id' => 'app-uuid', 'slug' => 'demo', 'name' => 'Demo', 'permissions' => $permissions];
		$version = null;
		if ($entries !== null) {
			$version = [
				'id' => 'ver-uuid',
				'slug' => 'development',
				'manifest' => ['menu' => $entries],
				'@self' => ['id' => 'ver-uuid'],
			];
		}

		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(
			function (string $register, string $schema, array $filters) use ($app, $version): array {
				if ($schema === 'built-app') {
					return [$this->entity(data: $app)];
				}

				if ($version === null) {
					return [];
				}

				return [$this->entity(data: $version)];
			}
		);
	}//end arrange()

	/**
	 * Capture the object passed to saveObject and echo it back as the saved version.
	 *
	 * @param array<string, mixed>|null $captured Receives the saved payload.
	 *
	 * @return void
	 */
	private function captureSave(?array &$captured): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured) {
				$captured = $object;
				return $this->entity(data: ['id' => 'ver-uuid', 'slug' => 'development'] + $object);
			}
		);
	}//end captureSave()

	/**
	 * A caller without owner or editor access is refused before anything loads.
	 *
	 * @return void
	 */
	public function testRefusesACallerWithoutWriteAccess(): void {
		$this->arrange(permissions: ['viewers' => ['user:alice'], 'owners' => ['user:bob']], entries: []);
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'forbidden', actual: $result['error']);
		self::assertStringContainsString(needle: "application 'demo'", haystack: $result['message']);
	}//end testRefusesACallerWithoutWriteAccess()

	/**
	 * A missing version is reported as not found.
	 *
	 * @return void
	 */
	public function testReportsAMissingVersion(): void {
		$this->arrange(permissions: ['owners' => ['user:alice']], entries: null);
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'not_found', actual: $result['error']);
		self::assertSame(expected: "No version 'development' found for app 'demo'.", actual: $result['message']);
	}//end testReportsAMissingVersion()

	/**
	 * A new menu item is appended and saved under the version lock.
	 *
	 * @return void
	 */
	public function testAppendsANewEntryUnderTheLock(): void {
		$this->arrange(permissions: ['editors' => ['user:alice']], entries: [['id' => 'home', 'label' => 'Home', 'route' => 'home', 'order' => 0]]);
		$this->objectService->expects(self::once())->method('lockObject')
			->with('ver-uuid', 'buildiq.mcp-manifest-edit', 30);
		$this->objectService->expects(self::once())->method('unlockObject')->with('ver-uuid');
		$captured = null;
		$this->captureSave(captured: $captured);

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'created', actual: $result['action']);
		self::assertSame(expected: 2, actual: $result['menuCount']);
		self::assertSame(expected: 'ver-uuid', actual: $result['version']['uuid']);
		self::assertSame(expected: 'cases', actual: $result['menuItem']['id']);
		self::assertCount(expectedCount: 2, haystack: $captured['manifest']['menu']);
		self::assertSame(expected: 'home', actual: $captured['manifest']['menu'][0]['id']);
		self::assertArrayNotHasKey(key: '@self', array: $captured);
		self::assertArrayNotHasKey(key: 'id', array: $captured);
	}//end testAppendsANewEntryUnderTheLock()

	/**
	 * An entry with the same id in another case is replaced, not duplicated.
	 *
	 * @return void
	 */
	public function testReplacesAnEntryMatchedCaseInsensitively(): void {
		$this->arrange(
			permissions: ['owners' => ['user:alice']],
			entries: [
				['id' => 'home', 'label' => 'Home', 'route' => 'home', 'order' => 0],
				['id' => 'CASES', 'label' => 'Old', 'route' => 'cases', 'order' => 1],
			]
		);
		$captured = null;
		$this->captureSave(captured: $captured);

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'updated', actual: $result['action']);
		self::assertSame(expected: 2, actual: $result['menuCount']);
		self::assertSame(expected: 'cases', actual: $captured['manifest']['menu'][1]['id']);
	}//end testReplacesAnEntryMatchedCaseInsensitively()

	/**
	 * A new entry past the manifest cap is refused; nothing is saved.
	 *
	 * @return void
	 */
	public function testRefusesANewEntryPastTheCap(): void {
		$full = [];
		for ($i = 0; $i < 30; $i++) {
			$full[] = ['id' => 'm' . $i, 'label' => 'M', 'route' => 'm' . $i, 'order' => $i];
		}

		$this->arrange(permissions: ['owners' => ['user:alice']], entries: $full);
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'invalid_arguments', actual: $result['error']);
		self::assertSame(expected: 'Manifest exceeds maximum of 30 menu items.', actual: $result['message']);
	}//end testRefusesANewEntryPastTheCap()

	/**
	 * A locked version and a failing save both come back as a generic error.
	 *
	 * @return void
	 */
	public function testALockedVersionReturnsAGenericError(): void {
		$this->arrange(permissions: ['owners' => ['user:alice']], entries: []);
		$this->objectService->method('lockObject')->willThrowException(new RuntimeException('held'));
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('error')->with('Buildiq MCP: upsertMenuItem failed', self::anything());

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'upsert_failed', actual: $result['error']);
		self::assertStringNotContainsString(needle: 'held', haystack: $result['message']);
	}//end testALockedVersionReturnsAGenericError()

	/**
	 * Malformed arguments are refused before the role check runs.
	 *
	 * @return void
	 */
	public function testRejectsMalformedArguments(): void {
		$this->objectService->expects(self::never())->method('searchObjectsBySlug');

		$r0 = $this->handler->handle(args: ['route' => 'https://evil.example/x'] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r0['error']);
		self::assertStringStartsWith(prefix: "Invalid route 'https://evil.example/x'.", string: $r0['message']);
		$r1 = $this->handler->handle(args: ['label' => ''] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r1['error']);
		self::assertSame(expected: 'label is required.', actual: $r1['message']);
		$r2 = $this->handler->handle(args: ['id' => ''] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r2['error']);
		self::assertSame(expected: 'id is required.', actual: $r2['message']);
		$r3 = $this->handler->handle(args: ['route' => '  '] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r3['error']);
		self::assertSame(expected: 'route is required.', actual: $r3['message']);
	}//end testRejectsMalformedArguments()
}//end class

