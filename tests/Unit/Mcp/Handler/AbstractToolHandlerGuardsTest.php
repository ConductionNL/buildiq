<?php

/**
 * Unit tests for the shared guards of AbstractToolHandler.
 *
 * Pins the admin-bypass audit (trail, log fallback, failed write at
 * critical), the any-role gate on an application with and without a
 * permission resolver, the manifest caps and the builder deep link.
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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the AbstractToolHandler guards.
 */
#[CoversClass(AbstractToolHandler::class)]
final class AbstractToolHandlerGuardsTest extends TestCase {

	/**
	 * Log calls, as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A handler exposing the protected guards, for a signed-in uid (or none).
	 *
	 * @param string|null             $uid          The signed-in user, or null.
	 * @param IGroupManager|null      $groupManager The group manager.
	 * @param PermissionResolver|null $resolver     The permission resolver.
	 * @param AuditTrailMapper|null   $auditTrail   The audit trail mapper.
	 *
	 * @return AbstractToolHandler The handler.
	 */
	private function handler(
		?string $uid,
		?IGroupManager $groupManager = null,
		?PermissionResolver $resolver = null,
		?AuditTrailMapper $auditTrail = null
	): AbstractToolHandler {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['info', 'critical'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new class(
			userSession: $session,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $logger,
			groupManager: ($groupManager ?? $this->createMock(originalClassName: IGroupManager::class)),
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			permissionResolver: $resolver,
			auditTrailMapper: $auditTrail
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

	/**
	 * Without an audit trail the bypass is logged at info with its context.
	 *
	 * @return void
	 */
	public function testABypassWithoutAnAuditTrailIsLogged(): void {
		$this->handler(uid: 'admin')->call('recordAdminBypass', [null, 'permits', 'admin']);

		$this->assertSame(
			expected: [
				[
					'info',
					'Buildiq MCP: rbac.admin_bypass',
					['event' => 'rbac.admin_bypass', 'actor' => 'admin', 'appSlug' => 'permits', 'channel' => 'mcp'],
				],
			],
			actual: $this->logged
		);
	}//end testABypassWithoutAnAuditTrailIsLogged()

	/**
	 * With an audit trail and the app entity the bypass is recorded, then mirrored at info.
	 *
	 * @return void
	 */
	public function testABypassIsWrittenToTheAuditTrail(): void {
		$audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$audit->expects($this->once())->method('recordObjectEvent');

		$this->handler(uid: 'admin', auditTrail: $audit)
			->call('recordAdminBypass', [$this->createMock(originalClassName: ObjectEntity::class), 'permits', 'admin']);

		$this->assertSame(expected: ['info'], actual: array_column($this->logged, 0));
	}//end testABypassIsWrittenToTheAuditTrail()

	/**
	 * A failed audit write is reported at critical with the reason, and nothing else is logged.
	 *
	 * @return void
	 */
	public function testAFailedAuditWriteIsCritical(): void {
		$audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willThrowException(new RuntimeException(message: 'database gone'));

		$this->handler(uid: 'admin', auditTrail: $audit)
			->call('recordAdminBypass', [$this->createMock(originalClassName: ObjectEntity::class), 'permits', 'admin']);

		$this->assertCount(expectedCount: 1, haystack: $this->logged);
		$this->assertSame(expected: 'critical', actual: $this->logged[0][0]);
		$this->assertSame(expected: 'Buildiq MCP: rbac.admin_bypass audit-trail write failed', actual: $this->logged[0][1]);
		$this->assertSame(expected: 'database gone', actual: $this->logged[0][2]['exception']);
	}//end testAFailedAuditWriteIsCritical()

	/**
	 * Nobody signed in is refused before any role is read.
	 *
	 * @return void
	 */
	public function testTheAnyRoleGateRefusesAnAnonymousCaller(): void {
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'forbidden', 'message' => 'You must be signed in.'],
			actual: $this->handler(uid: null)->call('requireAnyRoleOnApp', [['permissions' => ['owners' => ['user:alice']]]])
		);
	}//end testTheAnyRoleGateRefusesAnAnonymousCaller()

	/**
	 * With a resolver, every role (viewers too) is asked about, admin bypass allowed.
	 *
	 * @return void
	 */
	public function testTheResolverDecidesOnEveryRole(): void {
		$resolver = $this->createMock(originalClassName: PermissionResolver::class);
		$resolver->method('resolveUserGroups')->willReturn(['team']);
		$resolver->expects($this->once())->method('matchesCaller')
			->with(['viewers' => ['user:bob']], $this->anything(), ['team'], true, ['owners', 'editors', 'viewers'])
			->willReturn(true);

		$handler = $this->handler(uid: 'bob', resolver: $resolver);

		$this->assertNull(actual: $handler->call('requireAnyRoleOnApp', [['permissions' => ['viewers' => ['user:bob']]]]));
	}//end testTheResolverDecidesOnEveryRole()

	/**
	 * A resolver that says no is a refusal; permissions that are not a list are read as none.
	 *
	 * @return void
	 */
	public function testARefusedRoleIsForbidden(): void {
		$resolver = $this->createMock(originalClassName: PermissionResolver::class);
		$resolver->method('resolveUserGroups')->willReturn([]);
		$resolver->expects($this->once())->method('matchesCaller')->with([])->willReturn(false);

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'forbidden', 'message' => 'You do not have access to this application.'],
			actual: $this->handler(uid: 'bob', resolver: $resolver)->call('requireAnyRoleOnApp', [['permissions' => 'everyone']])
		);
	}//end testARefusedRoleIsForbidden()

	/**
	 * Without a resolver an owner or editor named as a user is let in.
	 *
	 * @return void
	 */
	public function testWithoutAResolverANamedEditorIsLetIn(): void {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->expects($this->never())->method('isAdmin');

		$this->assertNull(
			actual: $this->handler(uid: 'carol', groupManager: $groups)
				->call('requireAnyRoleOnApp', [['permissions' => ['editors' => ['', 42, 'user:carol']]]])
		);
	}//end testWithoutAResolverANamedEditorIsLetIn()

	/**
	 * Without a resolver a member of a named group is let in, with or without the group: prefix.
	 *
	 * @return void
	 */
	public function testWithoutAResolverAGroupMemberIsLetIn(): void {
		foreach (['group:planners', 'planners'] as $principal) {
			$group = $this->createMock(originalClassName: IGroup::class);
			$group->method('getGID')->willReturn('planners');
			$groups = $this->createMock(originalClassName: IGroupManager::class);
			$groups->method('isAdmin')->willReturn(false);
			$groups->method('getUserGroups')->willReturn([$group]);

			$this->assertNull(
				actual: $this->handler(uid: 'dave', groupManager: $groups)->call('requireAnyRoleOnApp', [['permissions' => ['owners' => [$principal]]]]),
				message: $principal
			);
		}
	}//end testWithoutAResolverAGroupMemberIsLetIn()

	/**
	 * Without a resolver an administrator is let in, and a viewer is not a writer.
	 *
	 * @return void
	 */
	public function testWithoutAResolverAnAdminPassesAndAViewerDoesNot(): void {
		$admin = $this->createMock(originalClassName: IGroupManager::class);
		$admin->method('isAdmin')->willReturn(true);
		$this->assertNull(actual: $this->handler(uid: 'root', groupManager: $admin)->call('requireAnyRoleOnApp', [['permissions' => []]]));

		$plain = $this->createMock(originalClassName: IGroupManager::class);
		$plain->method('isAdmin')->willReturn(false);
		$plain->method('getUserGroups')->willReturn([]);
		$this->assertSame(
			expected: 'forbidden',
			actual: $this->handler(uid: 'erin', groupManager: $plain)
				->call('requireAnyRoleOnApp', [['permissions' => ['viewers' => ['user:erin'], 'owners' => 'not-a-list']]])['error']
		);
	}//end testWithoutAResolverAnAdminPassesAndAViewerDoesNot()

	/**
	 * A manifest is refused above 100 pages, above 50 widgets on the written page and above 256 KB.
	 *
	 * @return void
	 */
	public function testTheManifestCaps(): void {
		$handler = $this->handler(uid: 'admin');
		$widgets = ['pages' => [['config' => ['widgets' => array_fill(0, 51, ['type' => 'x'])]]]];

		$this->assertSame(
			expected: 'Manifest exceeds maximum of 100 pages.',
			actual: $handler->call('checkManifestCaps', [['pages' => array_fill(0, 101, [])]])['message']
		);
		$this->assertSame(expected: 'Page exceeds maximum of 50 widgets.', actual: $handler->call('checkManifestCaps', [$widgets, 0])['message']);
		$this->assertNull(actual: $handler->call('checkManifestCaps', [$widgets, null]));
		$this->assertNull(actual: $handler->call('checkManifestCaps', [$widgets, 3]));
		$this->assertSame(
			expected: 'Manifest exceeds maximum size of 256 KB.',
			actual: $handler->call('checkManifestCaps', [['blob' => str_repeat('a', 262145)]])['message']
		);
	}//end testTheManifestCaps()

	/**
	 * The deep link points into the builder for a slug and at the app root without one.
	 *
	 * @return void
	 */
	public function testTheDeepLink(): void {
		$handler = $this->handler(uid: 'admin');

		$this->assertSame(expected: '/apps/buildiq/builder/permits', actual: $handler->call('buildDeepLink', ['permits']));
		$this->assertSame(expected: '/apps/buildiq', actual: $handler->call('buildDeepLink', ['']));
	}//end testTheDeepLink()
}//end class
