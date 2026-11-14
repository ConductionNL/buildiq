<?php

/**
 * Unit tests for AppVisibilityResolver: UID/group/wildcard/admin visibility of app surfaces and placements.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppVisibilityResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AppVisibilityResolver.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\AppVisibilityResolver
 */
class AppVisibilityResolverTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var AppVisibilityResolver
	 */
	private AppVisibilityResolver $resolver;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = new AppVisibilityResolver();
	}//end setUp()

	/**
	 * Build a mock user with the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Build a mock group manager answering the given groups and admin flag for one user.
	 *
	 * @param array<int, string> $groups  The user's group ids.
	 * @param bool               $isAdmin Whether the user is a Nextcloud admin.
	 *
	 * @return IGroupManager&MockObject
	 */
	private function groupManager(array $groups, bool $isAdmin = false): IGroupManager&MockObject {
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('getUserGroupIds')->willReturn($groups);
		$gm->method('isAdmin')->willReturn($isAdmin);

		return $gm;
	}//end groupManager()

	/**
	 * A null user (nobody signed in) can never see a surface.
	 *
	 * @return void
	 */
	public function testNullUserNeverVisible(): void {
		$this->assertFalse(
			$this->resolver->isVisibleToUser(['owners' => ['user:alice']], [], null, $this->groupManager([]))
		);
	}//end testNullUserNeverVisible()

	/**
	 * The wildcard sentinel makes a surface visible to any signed-in user.
	 *
	 * @return void
	 */
	public function testWildcardVisibleToEveryone(): void {
		$visible = $this->resolver->isVisibleToUser(
			['viewers' => [AppVisibilityResolver::WILDCARD]],
			[],
			$this->user('bob'),
			$this->groupManager([])
		);

		$this->assertTrue($visible);
	}//end testWildcardVisibleToEveryone()

	/**
	 * A direct user:<uid> principal grants visibility.
	 *
	 * @return void
	 */
	public function testDirectUidMatch(): void {
		$visible = $this->resolver->isVisibleToUser(
			['editors' => ['user:carol']],
			[],
			$this->user('carol'),
			$this->groupManager(['staff'])
		);

		$this->assertTrue($visible);
	}//end testDirectUidMatch()

	/**
	 * A group:<gid> principal matching one of the user's groups grants visibility.
	 *
	 * @return void
	 */
	public function testGroupMatch(): void {
		$visible = $this->resolver->isVisibleToUser(
			['owners' => ['group:reviewers']],
			[],
			$this->user('dan'),
			$this->groupManager(['reviewers', 'staff'])
		);

		$this->assertTrue($visible);
	}//end testGroupMatch()

	/**
	 * A Nextcloud admin sees every surface even without a matching principal.
	 *
	 * @return void
	 */
	public function testAdminAlwaysVisible(): void {
		$visible = $this->resolver->isVisibleToUser(
			['owners' => ['user:someoneelse']],
			[],
			$this->user('root'),
			$this->groupManager([], isAdmin: true)
		);

		$this->assertTrue($visible);
	}//end testAdminAlwaysVisible()

	/**
	 * A non-admin with no matching principal is refused.
	 *
	 * @return void
	 */
	public function testNoMatchIsRefused(): void {
		$visible = $this->resolver->isVisibleToUser(
			['owners' => ['user:other', 'group:managers']],
			[],
			$this->user('eve'),
			$this->groupManager(['staff'])
		);

		$this->assertFalse($visible);
	}//end testNoMatchIsRefused()

	/**
	 * Extra principals are considered alongside permission entries.
	 *
	 * @return void
	 */
	public function testExtraPrincipalsAreConsidered(): void {
		$visible = $this->resolver->isVisibleToUser(
			[],
			['user:frank'],
			$this->user('frank'),
			$this->groupManager([])
		);

		$this->assertTrue($visible);
	}//end testExtraPrincipalsAreConsidered()

	/**
	 * isVisible() reads the current user off the session and delegates to isVisibleToUser().
	 *
	 * @return void
	 */
	public function testIsVisibleReadsSessionUser(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('gina'));

		$visible = $this->resolver->isVisible(
			['owners' => ['user:gina']],
			[],
			$session,
			$this->groupManager([])
		);

		$this->assertTrue($visible);
	}//end testIsVisibleReadsSessionUser()

	/**
	 * A placement with no extra roles is visible exactly when the app is usable.
	 *
	 * @return void
	 */
	public function testPlacementWithoutRolesTracksAppVisibility(): void {
		$visible = $this->resolver->isPlacementVisibleToUser(
			['user:hank'],
			[],
			$this->user('hank'),
			$this->groupManager([])
		);

		$this->assertTrue($visible);
	}//end testPlacementWithoutRolesTracksAppVisibility()

	/**
	 * A placement requiring a role the user lacks is hidden even when the app itself is usable.
	 *
	 * @return void
	 */
	public function testPlacementWithUnmetRoleIsHidden(): void {
		$visible = $this->resolver->isPlacementVisibleToUser(
			['group:everyone'],
			['group:approvers'],
			$this->user('iris'),
			$this->groupManager(['everyone'])
		);

		$this->assertFalse($visible);
	}//end testPlacementWithUnmetRoleIsHidden()

	/**
	 * A placement whose required role the user holds is visible.
	 *
	 * @return void
	 */
	public function testPlacementWithMetRoleIsVisible(): void {
		$visible = $this->resolver->isPlacementVisibleToUser(
			['group:everyone'],
			['group:approvers'],
			$this->user('jade'),
			$this->groupManager(['everyone', 'approvers'])
		);

		$this->assertTrue($visible);
	}//end testPlacementWithMetRoleIsVisible()

	/**
	 * normaliseRoles() coerces null/empty to an empty list, a string to a one-element list,
	 * an array to its values, and a non-array/non-string scalar to null (invalid).
	 *
	 * @return void
	 */
	public function testNormaliseRoles(): void {
		$this->assertSame([], $this->resolver->normaliseRoles(null));
		$this->assertSame([], $this->resolver->normaliseRoles(''));
		$this->assertSame(['group:x'], $this->resolver->normaliseRoles('group:x'));
		$this->assertSame(['a', 'b'], $this->resolver->normaliseRoles(['k1' => 'a', 'k2' => 'b']));
		$this->assertNull($this->resolver->normaliseRoles(42));
	}//end testNormaliseRoles()

	/**
	 * flattenPermissions() concatenates owners/editors/viewers and treats non-array buckets as empty.
	 *
	 * @return void
	 */
	public function testFlattenPermissions(): void {
		$flat = $this->resolver->flattenPermissions(
			['owners' => ['user:a'], 'editors' => 'not-an-array', 'viewers' => ['group:g']]
		);

		$this->assertSame(['user:a', 'group:g'], $flat);
	}//end testFlattenPermissions()
}//end class
