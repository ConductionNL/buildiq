<?php

/**
 * Unit tests for the AbstractToolHandler paths the guard tests leave out:
 * an empty uid, the write-role gate end to end (missing app, resolver
 * decision, genuine admin bypass with an unresolvable entity, a session that
 * ends mid-check, non-array permissions), slug shape, version lookup misses
 * and a failed lock release after a manifest save.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\Handler\AbstractToolHandler;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the AbstractToolHandler write gate and version helpers.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(AbstractToolHandler::class)]
#[UsesClass(ObjectRecord::class)]
final class AbstractToolHandlerWriteGuardTest extends TestCase {

	/**
	 * Log calls, as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Fresh object service per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logged = [];
	}//end setUp()

	/**
	 * A user with an empty uid counts as not signed in.
	 *
	 * @return void
	 */
	public function testAnEmptyUidIsNotSignedIn(): void {
		$this->assertSame(
			['isError' => true, 'error' => 'forbidden', 'message' => 'You must be signed in.'],
			$this->handler(users: [$this->user('')])->call('requireAdminUser', [])
		);
	}//end testAnEmptyUidIsNotSignedIn()

	/**
	 * An unknown app is not_found, looked up by slug across organisations.
	 *
	 * @return void
	 */
	public function testAnUnknownAppIsNotFound(): void {
		$this->objects->expects($this->once())
			->method('searchObjectsBySlug')
			->with('buildiq', 'built-app', ['slug' => 'permits'], true, false)
			->willReturn([]);

		$this->assertSame(
			['isError' => true, 'error' => 'not_found', 'message' => "No virtual app found for slug 'permits'."],
			$this->handler(users: [$this->user('alice')])->call('requireWriteRole', ['permits'])
		);
	}//end testAnUnknownAppIsNotFound()

	/**
	 * With a resolver, a caller holding a write role passes and no bypass is recorded.
	 *
	 * @return void
	 */
	public function testTheResolverGrantsAWriteRole(): void {
		$this->objects->method('searchObjectsBySlug')->willReturn([['uuid' => 'u1', 'permissions' => ['editors' => ['user:alice']]]]);
		$this->objects->expects($this->never())->method('find');

		$resolver = $this->createMock(PermissionResolver::class);
		$resolver->method('resolveUserGroups')->willReturn(['team']);
		$resolver->expects($this->once())
			->method('matchesCaller')
			->with(['editors' => ['user:alice']], $this->isInstanceOf(IUser::class), ['team'], true, ['owners', 'editors'])
			->willReturn(true);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		$this->assertNull($this->handler(users: [$this->user('alice')], groups: $groups, resolver: $resolver)->call('requireWriteRole', ['permits']));
		$this->assertSame([], $this->logged);
	}//end testTheResolverGrantsAWriteRole()

	/**
	 * A genuine admin bypass whose entity cannot be re-read still passes and is logged.
	 *
	 * @return void
	 */
	public function testAGenuineBypassWithAnUnreadableEntityIsLogged(): void {
		$this->objects->method('searchObjectsBySlug')->willReturn([['id' => 'u2', 'permissions' => []]]);
		$this->objects->expects($this->once())->method('find')->with('u2')->willThrowException(new RuntimeException('gone'));

		$resolver = $this->createMock(PermissionResolver::class);
		$resolver->method('resolveUserGroups')->willReturn([]);
		$resolver->method('matchesCaller')->willReturnCallback(
			fn (array $permissions, IUser $caller, array $userGroups, bool $allowAdminBypass, array $roles): bool => $allowAdminBypass
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('root')->willReturn(true);

		$this->assertNull($this->handler(users: [$this->user('root')], groups: $groups, resolver: $resolver)->call('requireWriteRole', ['permits']));
		$this->assertSame(
			[['info', 'Buildiq MCP: rbac.admin_bypass', ['event' => 'rbac.admin_bypass', 'actor' => 'root', 'appSlug' => 'permits', 'channel' => 'mcp']]],
			$this->logged
		);
	}//end testAGenuineBypassWithAnUnreadableEntityIsLogged()

	/**
	 * A session that ends between sign-in and the role check, or non-array permissions, is refused.
	 *
	 * @return void
	 */
	public function testALostSessionOrUnreadablePermissionsIsForbidden(): void {
		$forbidden = ['isError' => true, 'error' => 'forbidden', 'message' => "You do not have owner or editor access to application 'permits'."];
		$this->objects->method('searchObjectsBySlug')->willReturnOnConsecutiveCalls(
			[['uuid' => 'u1', 'permissions' => ['owners' => ['user:alice']]]],
			[['uuid' => 'u1', 'permissions' => 'owners']]
		);

		$resolver = $this->createMock(PermissionResolver::class);
		$resolver->expects($this->never())->method('matchesCaller');

		$lostSession = $this->handler(users: [$this->user('alice'), null], resolver: $resolver);
		$this->assertSame($forbidden, $lostSession->call('requireWriteRole', ['permits', false]));
		$this->assertSame($forbidden, $this->handler(users: [$this->user('alice')], resolver: $resolver)->call('requireWriteRole', ['permits', false]));
	}//end testALostSessionOrUnreadablePermissionsIsForbidden()

	/**
	 * A slug is two to 48 lowercase letters, digits or inner hyphens.
	 *
	 * @return void
	 */
	public function testSlugShape(): void {
		$handler = $this->handler(users: [null]);

		$this->assertTrue($handler->call('isValidSlug', ['ab']));
		$this->assertTrue($handler->call('isValidSlug', [str_repeat('a', 48)]));
		$this->assertFalse($handler->call('isValidSlug', ['a']));
		$this->assertFalse($handler->call('isValidSlug', [str_repeat('a', 49)]));
		$this->assertFalse($handler->call('isValidSlug', ['-ab']));
		$this->assertFalse($handler->call('isValidSlug', ['Ab']));
	}//end testSlugShape()

	/**
	 * A missing app or version is reported; a found one carries the app uuid and name.
	 *
	 * @return void
	 */
	public function testLoadVersion(): void {
		$this->objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $register, string $schema, array $filters): array {
				$known = [['uuid' => 'ver-1']];
				$wanted = ['application' => 'app-1', 'slug' => 'v1'];
				if ($schema === 'built-app') {
					$known = [['uuid' => 'app-1', 'name' => 'Permits']];
					$wanted = ['slug' => 'permits'];
				}

				if ($filters !== $wanted) {
					return [];
				}

				return $known;
			}
		);
		$handler = $this->handler(users: [null]);

		$this->assertSame(
			['error' => 'not_found', 'message' => "No virtual app found for slug 'nope'."],
			$handler->call('loadVersion', [$this->objects, 'nope', 'v1'])
		);
		$this->assertSame(
			['error' => 'not_found', 'message' => "No version 'v2' found for app 'permits'."],
			$handler->call('loadVersion', [$this->objects, 'permits', 'v2'])
		);
		$this->assertSame(
			['version' => ['uuid' => 'ver-1'], 'appUuid' => 'app-1', 'appName' => 'Permits'],
			$handler->call('loadVersion', [$this->objects, 'permits', 'v1'])
		);
	}//end testLoadVersion()

	/**
	 * A failed lock release after a save is logged as a warning and the saved version is still returned.
	 *
	 * @return void
	 */
	public function testAFailedUnlockAfterASaveIsAWarning(): void {
		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('jsonSerialize')->willReturn(['slug' => 'v1', 'manifest' => ['pages' => []]]);

		$this->objects->expects($this->once())->method('lockObject')->with('ver-1', 'buildiq.mcp-manifest-edit', 30)->willReturn([]);
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(['slug' => 'v1', 'manifest' => ['pages' => []]], [], 'buildiq', 'applicationVersion', 'ver-1')
			->willReturn($saved);
		$this->objects->expects($this->once())->method('unlockObject')->with('ver-1')->willThrowException(new RuntimeException('lock lost'));

		$result = $this->handler(users: [null])->call(
			'saveVersionManifest',
			[$this->objects, ['uuid' => 'ver-1', 'id' => 'ver-1', '@self' => ['x' => 1], 'slug' => 'v1', 'manifest' => ['old' => true]], ['pages' => []]]
		);

		$this->assertSame(['slug' => 'v1', 'manifest' => ['pages' => []]], $result);
		$this->assertSame([['warning', 'Buildiq MCP: failed to release manifest lock on ver-1', ['exception' => 'lock lost']]], $this->logged);
	}//end testAFailedUnlockAfterASaveIsAWarning()

	/**
	 * A signed-in user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A handler exposing the protected helpers.
	 *
	 * @param array<int, IUser|null>  $users    What the session answers, call by call; the last answer repeats.
	 * @param IGroupManager|null      $groups   The group manager.
	 * @param PermissionResolver|null $resolver The permission resolver.
	 *
	 * @return AbstractToolHandler The handler.
	 */
	private function handler(array $users, ?IGroupManager $groups = null, ?PermissionResolver $resolver = null): AbstractToolHandler {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use (&$users): ?IUser {
				if (count($users) > 1) {
					return array_shift($users);
				}

				return $users[0];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'critical'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new class(
			userSession: $session,
			container: $this->createMock(ContainerInterface::class),
			logger: $logger,
			groupManager: ($groups ?? $this->createMock(IGroupManager::class)),
			objectService: $this->objects,
			permissionResolver: $resolver
		) extends AbstractToolHandler {

			/**
			 * Not exercised.
			 *
			 * @param array<string, mixed> $args The arguments.
			 *
			 * @return array<string, mixed> Nothing.
			 */
			public function handle(array $args): array {
				return [];
			}//end handle()

			/**
			 * Expose a protected method.
			 *
			 * @param string       $name The method.
			 * @param array<mixed> $args The arguments.
			 *
			 * @return mixed What it returns.
			 */
			public function call(string $name, array $args): mixed {
				return $this->{$name}(...$args);
			}//end call()
		};
	}//end handler()
}//end class
