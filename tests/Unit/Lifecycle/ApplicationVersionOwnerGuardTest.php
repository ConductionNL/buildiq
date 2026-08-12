<?php

/**
 * Unit tests for ApplicationVersionOwnerGuard (openbuild-rbac).
 *
 * Exercises the per-Application owner role enforcement on the destructive
 * ApplicationVersion lifecycle transitions (publish/archive/reopen): owner
 * allow, editor/viewer/non-member deny, NC-admin escape-hatch allow, the
 * IDOR case (caller owns a *different* Application), and the fail-closed
 * branches (unresolvable caller, unresolved parent Application, missing
 * permissions block). A real PermissionResolver (with a mocked IGroupManager)
 * drives the grammar end-to-end through the guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Lifecycle
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

namespace OCA\OpenBuild\Tests\Unit\Lifecycle;

use OCA\OpenBuild\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationVersionOwnerGuard::check.
 */
class ApplicationVersionOwnerGuardTest extends TestCase {

	/**
	 * Mocked ObjectService for stub-loading the parent Application.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mocked IGroupManager passed to the real PermissionResolver.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mocked IUserManager for resolving the acting caller.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Mocked PSR logger for fail-closed diagnostic assertions.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Guard under test, wired with a real PermissionResolver.
	 *
	 * @var ApplicationVersionOwnerGuard
	 */
	private ApplicationVersionOwnerGuard $guard;

	/**
	 * Wire mocks and a real PermissionResolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(originalClassName: ObjectService::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->userManager = $this->createMock(originalClassName: IUserManager::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$resolver = new PermissionResolver(groupManager: $this->groupManager, logger: $this->logger);

		$this->guard = new ApplicationVersionOwnerGuard(
			objectService: $this->objectService,
			permissionResolver: $resolver,
			userManager: $this->userManager,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * An owner of the parent Application may publish.
	 *
	 * @return void
	 */
	public function testOwnerMayPublish(): void {
		$this->arrangeCaller(uid: 'alice', groups: ['team-alpha'], isAdmin: false);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['group:team-alpha'], 'editors' => [], 'viewers' => []]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'alice');

		self::assertTrue(condition: $result->isAllowed());
	}//end testOwnerMayPublish()

	/**
	 * An owner referenced by `user:` prefix may archive.
	 *
	 * @return void
	 */
	public function testUserPrefixOwnerMayArchive(): void {
		$this->arrangeCaller(uid: 'alice', groups: [], isAdmin: false);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'archive', 'alice');

		self::assertTrue(condition: $result->isAllowed());
	}//end testUserPrefixOwnerMayArchive()

	/**
	 * An editor (not owner) is denied a destructive transition.
	 *
	 * @return void
	 */
	public function testEditorIsDenied(): void {
		$this->arrangeCaller(uid: 'bob', groups: ['team-editors'], isAdmin: false);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['group:team-alpha'], 'editors' => ['group:team-editors'], 'viewers' => []]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'bob');

		self::assertFalse(condition: $result->isAllowed());
		self::assertNotNull(actual: $result->getMessage());
	}//end testEditorIsDenied()

	/**
	 * A viewer is denied a destructive transition.
	 *
	 * @return void
	 */
	public function testViewerIsDenied(): void {
		$this->arrangeCaller(uid: 'carol', groups: ['team-viewers'], isAdmin: false);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['group:team-alpha'], 'editors' => [], 'viewers' => ['group:team-viewers']]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'reopen', 'carol');

		self::assertFalse(condition: $result->isAllowed());
	}//end testViewerIsDenied()

	/**
	 * A user with no role on the parent Application is denied.
	 *
	 * @return void
	 */
	public function testNonMemberIsDenied(): void {
		$this->arrangeCaller(uid: 'eve', groups: ['outsiders'], isAdmin: false);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['group:team-alpha'], 'editors' => [], 'viewers' => []]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'eve');

		self::assertFalse(condition: $result->isAllowed());
	}//end testNonMemberIsDenied()

	/**
	 * A Nextcloud admin is granted as the audited incident-response escape hatch.
	 *
	 * @return void
	 */
	public function testAdminBypassIsAllowed(): void {
		$this->arrangeCaller(uid: 'root', groups: ['admin'], isAdmin: true);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['group:team-alpha'], 'editors' => [], 'viewers' => []]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'archive', 'root');

		self::assertTrue(condition: $result->isAllowed());
	}//end testAdminBypassIsAllowed()

	/**
	 * IDOR: a caller who owns a DIFFERENT Application cannot drive a transition
	 * on a version whose parent is an Application they do not own. The guard
	 * resolves the parent from the version's own `application` relation, so the
	 * check is always against the correct Application.
	 *
	 * @return void
	 */
	public function testIdorOwnerOfDifferentApplicationIsDenied(): void {
		// Caller owns app-B (team-beta) but the version belongs to app-A.
		$this->arrangeCaller(uid: 'mallory', groups: ['team-beta'], isAdmin: false);
		$this->arrangeParentApplication(
			applicationUuid: 'app-A',
			permissions: ['owners' => ['group:team-alpha'], 'editors' => [], 'viewers' => []]
		);

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'mallory');

		self::assertFalse(condition: $result->isAllowed());
	}//end testIdorOwnerOfDifferentApplicationIsDenied()

	/**
	 * Fail-closed: an unresolvable caller UID denies the transition.
	 *
	 * @return void
	 */
	public function testUnresolvableCallerIsDenied(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		// ObjectService must never be consulted once the caller is unresolved.
		$this->objectService->expects(self::never())->method('find');

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'ghost');

		self::assertFalse(condition: $result->isAllowed());
	}//end testUnresolvableCallerIsDenied()

	/**
	 * Fail-closed: a missing `application` relation denies the transition.
	 *
	 * @return void
	 */
	public function testMissingApplicationRelationIsDenied(): void {
		$this->arrangeCaller(uid: 'alice', groups: ['team-alpha'], isAdmin: false);
		$this->objectService->expects(self::never())->method('find');

		$result = $this->guard->check([], 'publish', 'alice');

		self::assertFalse(condition: $result->isAllowed());
	}//end testMissingApplicationRelationIsDenied()

	/**
	 * Fail-closed: an unresolved parent Application denies the transition.
	 *
	 * @return void
	 */
	public function testUnresolvedParentApplicationIsDenied(): void {
		$this->arrangeCaller(uid: 'alice', groups: ['team-alpha'], isAdmin: false);
		$this->objectService->method('find')->willReturn(null);

		$result = $this->guard->check(['application' => 'app-missing'], 'publish', 'alice');

		self::assertFalse(condition: $result->isAllowed());
	}//end testUnresolvedParentApplicationIsDenied()

	/**
	 * No rechtenblok + NC admin caller: the audited admin escape hatch is
	 * granted, so a programmatically / synthetically created Application (which
	 * never materialised a permissions block) can still be published by an
	 * admin instead of bricking with a 422. Regression for the publish-422
	 * reported during fleet Newman verification.
	 *
	 * @return void
	 */
	public function testParentWithoutPermissionsAllowsAdminEscapeHatch(): void {
		$this->arrangeCaller(uid: 'root', groups: ['admin'], isAdmin: true);
		$this->arrangeParentApplication(applicationUuid: 'app-A', permissions: null);

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'root');

		self::assertTrue(condition: $result->isAllowed());
	}//end testParentWithoutPermissionsAllowsAdminEscapeHatch()

	/**
	 * No rechtenblok + ordinary (non-admin) caller: still denied. The absence
	 * of a permissions block must NOT widen access to ordinary users — no
	 * owner can be proven, so the guard stays fail-closed for everyone but the
	 * audited admin escape hatch. Guards the security boundary of the fix.
	 *
	 * @return void
	 */
	public function testParentWithoutPermissionsDeniesNonAdmin(): void {
		$this->arrangeCaller(uid: 'alice', groups: ['team-alpha'], isAdmin: false);
		$this->arrangeParentApplication(applicationUuid: 'app-A', permissions: null);

		$result = $this->guard->check(['application' => 'app-A'], 'publish', 'alice');

		self::assertFalse(condition: $result->isAllowed());
		self::assertNotNull(actual: $result->getMessage());
	}//end testParentWithoutPermissionsDeniesNonAdmin()

	/**
	 * No rechtenblok + an EMPTY permissions block (vs the omitted-key case) is
	 * treated identically: admin allowed, semantics unchanged. Confirms the
	 * `permissions === []` arm of the guard behaves like the missing-key arm.
	 *
	 * @return void
	 */
	public function testParentWithEmptyPermissionsBlockAllowsAdmin(): void {
		$this->arrangeCaller(uid: 'root', groups: ['admin'], isAdmin: true);
		$this->arrangeParentApplication(applicationUuid: 'app-A', permissions: []);

		$result = $this->guard->check(['application' => 'app-A'], 'archive', 'root');

		self::assertTrue(condition: $result->isAllowed());
	}//end testParentWithEmptyPermissionsBlockAllowsAdmin()

	/**
	 * Arrange the caller: an IUser resolved by IUserManager, with the given
	 * group memberships and admin status on IGroupManager.
	 *
	 * @param string $uid The caller UID.
	 * @param array<string> $groups The caller's group GIDs.
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 *
	 * @return void
	 */
	private function arrangeCaller(string $uid, array $groups, bool $isAdmin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);

		$this->userManager->method('get')->with($uid)->willReturn($user);

		$groupObjects = [];
		foreach ($groups as $gid) {
			$group = $this->createMock(originalClassName: IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$groupObjects[] = $group;
		}

		$this->groupManager->method('getUserGroups')->willReturn($groupObjects);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}//end arrangeCaller()

	/**
	 * Arrange the parent Application returned by ObjectService::find.
	 *
	 * @param string $applicationUuid The Application UUID the version points at.
	 * @param array<string, mixed>|null $permissions The Application's permissions block (or null to omit it).
	 *
	 * @return void
	 */
	private function arrangeParentApplication(string $applicationUuid, ?array $permissions): void {
		$data = ['uuid' => $applicationUuid, 'slug' => 'demo-app'];
		if ($permissions !== null) {
			$data['permissions'] = $permissions;
		}

		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		$this->objectService->method('find')->willReturn($entity);
	}//end arrangeParentApplication()
}//end class
