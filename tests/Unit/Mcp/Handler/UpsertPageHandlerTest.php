<?php

/**
 * Unit tests for the UpsertPageHandler MCP tool past its argument guards.
 *
 * Covers the write-role gate, a missing app or version, creating and
 * replacing a page (matched case-insensitively on its id), the manifest
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
use RuntimeException;

/**
 * Tests for UpsertPageHandler::handle.
 */
class UpsertPageHandlerTest extends TestCase {

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
	 * @var UpsertPageHandler
	 */
	private UpsertPageHandler $handler;

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

		$this->handler = new UpsertPageHandler(
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
			'pageId' => 'cases',
			'title' => 'Cases',
			'type' => 'index',
			'route' => '/cases',
			'config' => ['schema' => 'case'],
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
	 * @param array<int, array<string, mixed>>|null $entries The version's existing pages entries; null means no version.
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
				'manifest' => ['pages' => $entries],
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
	 * A new page is appended and saved under the version lock.
	 *
	 * @return void
	 */
	public function testAppendsANewEntryUnderTheLock(): void {
		$this->arrange(permissions: ['editors' => ['user:alice']], entries: [['id' => 'home', 'title' => 'Home', 'type' => 'dashboard', 'route' => '/']]);
		$this->objectService->expects(self::once())->method('lockObject')
			->with('ver-uuid', 'buildiq.mcp-manifest-edit', 30);
		$this->objectService->expects(self::once())->method('unlockObject')->with('ver-uuid');
		$captured = null;
		$this->captureSave(captured: $captured);

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'created', actual: $result['action']);
		self::assertSame(expected: 2, actual: $result['pageCount']);
		self::assertSame(expected: 'ver-uuid', actual: $result['version']['uuid']);
		self::assertSame(expected: 'cases', actual: $result['page']['id']);
		self::assertCount(expectedCount: 2, haystack: $captured['manifest']['pages']);
		self::assertSame(expected: 'home', actual: $captured['manifest']['pages'][0]['id']);
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
				['id' => 'home', 'title' => 'Home', 'type' => 'dashboard', 'route' => '/'],
				['id' => 'CASES', 'title' => 'Old', 'type' => 'index', 'route' => '/cases'],
			]
		);
		$captured = null;
		$this->captureSave(captured: $captured);

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'updated', actual: $result['action']);
		self::assertSame(expected: 2, actual: $result['pageCount']);
		self::assertSame(expected: 'cases', actual: $captured['manifest']['pages'][1]['id']);
	}//end testReplacesAnEntryMatchedCaseInsensitively()

	/**
	 * A new entry past the manifest cap is refused; nothing is saved.
	 *
	 * @return void
	 */
	public function testRefusesANewEntryPastTheCap(): void {
		$full = [];
		for ($i = 0; $i < 100; $i++) {
			$full[] = ['id' => 'p' . $i, 'title' => 'P', 'type' => 'index', 'route' => '/p' . $i];
		}

		$this->arrange(permissions: ['owners' => ['user:alice']], entries: $full);
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'invalid_arguments', actual: $result['error']);
		self::assertSame(expected: 'Manifest exceeds maximum of 100 pages.', actual: $result['message']);
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
		$this->logger->expects(self::once())->method('error')->with('Buildiq MCP: upsertPage failed', self::anything());

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

		$r0 = $this->handler->handle(args: ['type' => 'wizard'] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r0['error']);
		self::assertSame(expected: "Invalid page type 'wizard'.", actual: $r0['message']);
		$r1 = $this->handler->handle(args: ['route' => 'https://evil.example/x'] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r1['error']);
		self::assertStringStartsWith(prefix: "Invalid route 'https://evil.example/x'.", string: $r1['message']);
		$r2 = $this->handler->handle(args: ['title' => ''] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r2['error']);
		self::assertSame(expected: 'title is required.', actual: $r2['message']);
		$r3 = $this->handler->handle(args: ['pageId' => ''] + $this->args());
		self::assertSame(expected: 'invalid_arguments', actual: $r3['error']);
		self::assertSame(expected: 'pageId is required.', actual: $r3['message']);
	}//end testRejectsMalformedArguments()
}//end class

