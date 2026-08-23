<?php

/**
 * Unit tests for PermissionResolver — PrincipalMatcherTest (L3).
 *
 * Covers the canonical permission-grammar (user:, group:, bare back-compat),
 * admin-bypass toggle, and role-bucket filtering used by all four call sites.
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

use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PermissionResolver::matchesCaller and resolveUserGroups.
 */
class PrincipalMatcherTest extends TestCase {

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 */
	private PermissionResolver $resolver;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->resolver = new PermissionResolver(
			$this->groupManager,
			$this->logger,
		);
	}//end setUp()

	/**
	 * user:<uid> prefix grants access to the matching user.
	 *
	 * @return void
	 */
	public function testUserPrefixGrantsAccess(): void {
		$caller = $this->mockUser('alice');
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$permissions = ['owners' => ['user:alice', 'user:bob']];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: [],
			allowAdminBypass: false,
			roles: ['owners']
		);

		self::assertTrue($result);

	}//end testUserPrefixGrantsAccess()

	/**
	 * user:<uid> does NOT grant access when uid is different.
	 *
	 * @return void
	 */
	public function testUserPrefixDeniesOtherUser(): void {
		$caller = $this->mockUser('charlie');
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$permissions = ['owners' => ['user:alice', 'user:bob']];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: [],
			allowAdminBypass: false,
			roles: ['owners']
		);

		self::assertFalse($result);

	}//end testUserPrefixDeniesOtherUser()

	/**
	 * group:<gid> grants access when caller belongs to that group.
	 *
	 * @return void
	 */
	public function testGroupPrefixGrantsAccess(): void {
		$caller = $this->mockUser('dave');

		$permissions = ['editors' => ['group:developers']];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: ['developers', 'testers'],
			allowAdminBypass: false,
			roles: ['editors']
		);

		self::assertTrue($result);

	}//end testGroupPrefixGrantsAccess()

	/**
	 * group:<gid> denies when caller is not in that group.
	 *
	 * @return void
	 */
	public function testGroupPrefixDeniesNonMember(): void {
		$caller = $this->mockUser('eve');

		$permissions = ['editors' => ['group:developers']];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: ['testers'],
			allowAdminBypass: false,
			roles: ['editors']
		);

		self::assertFalse($result);

	}//end testGroupPrefixDeniesNonMember()

	/**
	 * Bare value (no prefix) is treated as group GID — back-compat.
	 *
	 * A warning is logged for bare values.
	 *
	 * @return void
	 */
	public function testBareValueTreatedAsGroupGid(): void {
		$caller = $this->mockUser('frank');

		// Bare value 'legacy-group' should be treated as a GID.
		$permissions = ['owners' => ['legacy-group']];

		$this->logger->expects($this->once())->method('warning');

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: ['legacy-group'],
			allowAdminBypass: false,
			roles: ['owners']
		);

		self::assertTrue($result);

	}//end testBareValueTreatedAsGroupGid()

	/**
	 * allowAdminBypass=true grants access to NC admins even without a role entry.
	 *
	 * @return void
	 */
	public function testAdminBypassGrantsAccessWhenEnabled(): void {
		$caller = $this->mockUser('admin-user');
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);

		$permissions = ['owners' => ['user:alice']];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: [],
			allowAdminBypass: true,
			roles: ['owners']
		);

		self::assertTrue($result);

	}//end testAdminBypassGrantsAccessWhenEnabled()

	/**
	 * allowAdminBypass=false denies admins that lack an explicit role entry.
	 *
	 * This is the REQ-OBVP-007 promotion constraint.
	 *
	 * @return void
	 */
	public function testAdminBypassDeniesWhenDisabled(): void {
		$caller = $this->mockUser('admin-user');
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);

		$permissions = ['owners' => ['user:alice']];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: [],
			allowAdminBypass: false,
			roles: ['owners']
		);

		self::assertFalse($result);

	}//end testAdminBypassDeniesWhenDisabled()

	/**
	 * Empty permissions block returns false.
	 *
	 * @return void
	 */
	public function testEmptyPermissionsReturnsFalse(): void {
		$caller = $this->mockUser('grace');

		$result = $this->resolver->matchesCaller(
			permissions: [],
			caller: $caller,
			userGroups: [],
			allowAdminBypass: false,
			roles: ['owners', 'editors']
		);

		self::assertFalse($result);

	}//end testEmptyPermissionsReturnsFalse()

	/**
	 * Role not in the checked $roles list is ignored.
	 *
	 * caller is a viewer but only owners+editors are checked.
	 *
	 * @return void
	 */
	public function testRoleNotCheckedIsIgnored(): void {
		$caller = $this->mockUser('heidi');

		$permissions = [
			'owners' => ['user:alice'],
			'editors' => [],
			'viewers' => ['user:heidi'],
		];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: [],
			allowAdminBypass: false,
			roles: ['owners', 'editors']
		);

		self::assertFalse($result);

	}//end testRoleNotCheckedIsIgnored()

	/**
	 * resolveUserGroups returns the GID list from the caller's groups.
	 *
	 * @return void
	 */
	public function testResolveUserGroupsReturnsList(): void {
		$user = $this->mockUser('ivan');

		$groupA = $this->createMock(IGroup::class);
		$groupA->method('getGID')->willReturn('groupA');

		$groupB = $this->createMock(IGroup::class);
		$groupB->method('getGID')->willReturn('groupB');

		$this->groupManager->method('getUserGroups')->with($user)->willReturn([$groupA, $groupB]);

		$result = $this->resolver->resolveUserGroups($user);

		self::assertSame(['groupA', 'groupB'], $result);

	}//end testResolveUserGroupsReturnsList()

	/**
	 * Viewer role grants access when viewers is included in $roles.
	 *
	 * @return void
	 */
	public function testViewerRoleGrantsAccessWhenIncluded(): void {
		$caller = $this->mockUser('judy');

		$permissions = [
			'owners' => [],
			'editors' => [],
			'viewers' => ['user:judy'],
		];

		$result = $this->resolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: [],
			allowAdminBypass: false,
			roles: ['owners', 'editors', 'viewers']
		);

		self::assertTrue($result);

	}//end testViewerRoleGrantsAccessWhenIncluded()

	// -------------------------------------------------------------------------
	// Per-handler RBAC smoke tests (L3 addition for ListAppsHandler,
	// GetAppManifestHandler — confirm the plumbing wires PermissionResolver).
	// These instantiate the provider with a real PermissionResolver so the
	// integration path is exercised without a container.
	// -------------------------------------------------------------------------

	/**
	 * getAppManifest: caller with no role in permissions is denied (C2 RBAC gate).
	 *
	 * The RBAC gate on getAppManifest must return a forbidden result when the
	 * authenticated caller holds no role entry in the application's permissions
	 * block (owners/editors/viewers all empty for that user).
	 *
	 * @return void
	 */
	public function testGetAppManifestRbacDeniesNoRole(): void {
		$caller = $this->mockUser('mallory');
		$this->userSession->method('getUser')->willReturn($caller);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		// Application whose permissions block does not include 'mallory'.
		$applicationData = [
			'uuid' => 'app-uuid-1234',
			'name' => 'Test App',
			'slug' => 'test-app',
			'permissions' => [
				'owners' => ['user:alice'],
				'editors' => [],
				'viewers' => [],
			],
			'manifest' => ['pages' => []],
		];

		$applicationEntity = $this->createMock(ObjectEntity::class);
		$applicationEntity->method('jsonSerialize')->willReturn($applicationData);

		// Route result pointing at the application.
		$routeObject = [
			'slug' => 'test-app',
			'applicationUuid' => 'app-uuid-1234',
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$routeObject]);
		$objectService->method('find')->willReturn($applicationEntity);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$provider = new \OCA\Buildiq\Mcp\BuildiqToolProvider(
			$this->userSession,
			$this->groupManager,
			$container,
			$this->logger,
			// The RBAC gate reads the INJECTED object service (ADR-084), while the
			// handler bodies still resolve one from the container. Both must be the
			// same double or the gate answers not_found and the assertion below
			// stops testing the role check it names.
			$objectService,
			permissionResolver: $this->resolver,
		);

		$result = $provider->invokeTool('buildiq.getAppManifest', ['slug' => 'test-app']);

		// Caller has no role — RBAC must deny with forbidden.
		self::assertTrue($result['isError'] ?? false);
		self::assertSame('forbidden', $result['error'] ?? '');

	}//end testGetAppManifestRbacDeniesNoRole()

	/**
	 * getAppManifest: caller with viewer role is granted access and
	 * permissions block is stripped from the returned manifest (C2 leak fix).
	 *
	 * @return void
	 */
	public function testGetAppManifestRbacGrantsViewerAndStripsPermissions(): void {
		$caller = $this->mockUser('viewer-user');
		$this->userSession->method('getUser')->willReturn($caller);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$applicationData = [
			'uuid' => 'app-uuid-5678',
			'name' => 'My App',
			'slug' => 'my-app',
			'permissions' => [
				'owners' => ['user:alice'],
				'editors' => [],
				'viewers' => ['user:viewer-user'],
			],
			'manifest' => [
				'pages' => [],
				'permissions' => ['owners' => ['user:alice']],
			],
		];

		$applicationEntity = $this->createMock(ObjectEntity::class);
		$applicationEntity->method('jsonSerialize')->willReturn($applicationData);

		$routeObject = [
			'slug' => 'my-app',
			'applicationUuid' => 'app-uuid-5678',
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsBySlug')->willReturn([$routeObject]);
		$objectService->method('find')->willReturn($applicationEntity);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$provider = new \OCA\Buildiq\Mcp\BuildiqToolProvider(
			$this->userSession,
			$this->groupManager,
			$container,
			$this->logger,
			// The RBAC gate reads the INJECTED object service (ADR-084), while the
			// handler bodies still resolve one from the container. Both must be the
			// same double or the gate answers not_found and the assertion below
			// stops testing the role check it names.
			$objectService,
			permissionResolver: $this->resolver,
		);

		$result = $provider->invokeTool('buildiq.getAppManifest', ['slug' => 'my-app']);

		// Viewer is allowed.
		self::assertFalse($result['isError'] ?? false);
		self::assertTrue($result['success'] ?? false);
		// permissions block must be stripped from manifest.
		self::assertArrayNotHasKey('permissions', $result['manifest'] ?? []);

	}//end testGetAppManifestRbacGrantsViewerAndStripsPermissions()

	/**
	 * listApps: a caller with only viewers access passes the auth gate
	 * (authentication gate is "any authenticated user") — RBAC is per-row
	 * in filterApplicationsByRole, not at the listApps tool level.
	 *
	 * After C1 fix: listApps only checks for authentication, not per-app RBAC
	 * at the tool level. Per-app filtering happens server-side in HTTP listMine.
	 *
	 * @return void
	 */
	public function testListAppsToolPassesForAuthenticatedUser(): void {
		$caller = $this->mockUser('karen');
		$this->userSession->method('getUser')->willReturn($caller);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsBySlug')->willReturn([]);
		$container->method('get')->willReturn($objectService);

		$provider = new \OCA\Buildiq\Mcp\BuildiqToolProvider(
			$this->userSession,
			$this->groupManager,
			$container,
			$this->logger,
			// The RBAC gate reads the INJECTED object service (ADR-084), while the
			// handler bodies still resolve one from the container. Both must be the
			// same double or the gate answers not_found and the assertion below
			// stops testing the role check it names.
			$objectService,
			permissionResolver: $this->resolver,
		);

		$result = $provider->invokeTool('buildiq.listApps', []);

		// Gate passed — returns success (empty list, not forbidden).
		self::assertFalse($result['isError'] ?? false);
		self::assertArrayHasKey('apps', $result);

	}//end testListAppsToolPassesForAuthenticatedUser()

	/**
	 * Helper: create a minimal IUser mock with the given UID.
	 *
	 * @param string $uid User UID.
	 *
	 * @return IUser&MockObject
	 */
	private function mockUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end mockUser()

}//end class
