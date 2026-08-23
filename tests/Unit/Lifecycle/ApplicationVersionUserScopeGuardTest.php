<?php

/**
 * Unit tests for the user-scope branch of ApplicationVersionOwnerGuard
 * (layered-versioned-app-deltas).
 *
 * A `scope: user` ApplicationVersion is a single user's personal delta. The
 * guard authorises a transition on it iff the caller owns it (audited NC-admin
 * bypass aside) AND the parent Application still permits per-user overrides.
 * Fail-closed: a foreign owner, a disabled flag (for a non-admin), or a missing
 * owner denies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Lifecycle
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

namespace OCA\Buildiq\Tests\Unit\Lifecycle;

use OCA\Buildiq\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationVersionOwnerGuard::check on `scope: user` rows.
 */
class ApplicationVersionUserScopeGuardTest extends TestCase {

	/**
	 * Mocked ObjectService for stub-loading the parent Application.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mocked group manager passed to the real PermissionResolver.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mocked user manager for resolving the caller.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Guard under test (real PermissionResolver wired in).
	 *
	 * @var ApplicationVersionOwnerGuard
	 */
	private ApplicationVersionOwnerGuard $guard;

	/**
	 * Wire mocks + the guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->userManager = $this->createMock(originalClassName: IUserManager::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$resolver = new PermissionResolver(groupManager: $this->groupManager, logger: $logger);

		$this->guard = new ApplicationVersionOwnerGuard(
			objectService: $this->objectService,
			permissionResolver: $resolver,
			userManager: $this->userManager,
			logger: $logger
		);
	}//end setUp()

	/**
	 * Arrange the calling user and their admin status.
	 *
	 * @param string $uid The caller UID.
	 * @param bool $isAdmin Whether the caller is an NC admin.
	 *
	 * @return void
	 */
	private function arrangeCaller(string $uid, bool $isAdmin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userManager->method('get')->with($uid)->willReturn($user);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}//end arrangeCaller()

	/**
	 * Arrange the parent Application returned by ObjectService::find.
	 *
	 * @param bool $allowUserOverrides Whether the parent app permits user overrides.
	 *
	 * @return void
	 */
	private function arrangeParentApp(bool $allowUserOverrides): void {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			['uuid' => 'app-A', 'slug' => 'demo', 'allowUserOverrides' => $allowUserOverrides]
		);
		$this->objectService->method('find')->willReturn($entity);
	}//end arrangeParentApp()

	/**
	 * The owner may transition their own user delta when the flag is on.
	 *
	 * @return void
	 */
	public function testOwnerAllowedWhenFlagOn(): void {
		$this->arrangeCaller(uid: 'alice', isAdmin: false);
		$this->arrangeParentApp(allowUserOverrides: true);

		$version = ['scope' => 'user', 'owner' => 'alice', 'application' => 'app-A'];
		$result = $this->guard->check($version, 'reopen', 'alice');

		self::assertTrue(condition: $result->isAllowed());
	}//end testOwnerAllowedWhenFlagOn()

	/**
	 * A foreign (non-owner, non-admin) caller is denied (no-admin-idor).
	 *
	 * @return void
	 */
	public function testForeignUserDenied(): void {
		$this->arrangeCaller(uid: 'bob', isAdmin: false);
		$this->arrangeParentApp(allowUserOverrides: true);

		$version = ['scope' => 'user', 'owner' => 'alice', 'application' => 'app-A'];
		$result = $this->guard->check($version, 'reopen', 'bob');

		self::assertFalse(condition: $result->isAllowed());
		self::assertNotNull(actual: $result->getMessage());
	}//end testForeignUserDenied()

	/**
	 * The owner is denied when the parent app no longer allows user overrides.
	 *
	 * @return void
	 */
	public function testOwnerDeniedWhenFlagOff(): void {
		$this->arrangeCaller(uid: 'alice', isAdmin: false);
		$this->arrangeParentApp(allowUserOverrides: false);

		$version = ['scope' => 'user', 'owner' => 'alice', 'application' => 'app-A'];
		$result = $this->guard->check($version, 'reopen', 'alice');

		self::assertFalse(condition: $result->isAllowed());
	}//end testOwnerDeniedWhenFlagOff()

	/**
	 * An NC admin keeps the audited escape hatch even when the flag is off.
	 *
	 * @return void
	 */
	public function testAdminAllowedEvenWhenFlagOff(): void {
		$this->arrangeCaller(uid: 'root', isAdmin: true);
		$this->arrangeParentApp(allowUserOverrides: false);

		// Admin is not the owner, but the bypass grants ownership + the flag gate.
		$version = ['scope' => 'user', 'owner' => 'alice', 'application' => 'app-A'];
		$result = $this->guard->check($version, 'reopen', 'root');

		self::assertTrue(condition: $result->isAllowed());
	}//end testAdminAllowedEvenWhenFlagOff()
}//end class
