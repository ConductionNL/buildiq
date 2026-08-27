<?php

/**
 * Unit tests for ManifestResolverService::filterManifestForCaller() and
 * resolveCallerPermissionsForDisplay() (spec `runtime-group-scoped-access`).
 *
 * This is the SERVER-SIDE enforcement point for group-scoped menu/page
 * visibility: an out-of-group caller must never receive the gated `menu[]` /
 * `pages[]` entry in the manifest payload at all — the load-bearing proof
 * that the group check actually DENIES (not just hides client-side) lives in
 * {@see testOutOfGroupCallerNeverReceivesGatedMenuItemOrPage()} below.
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
 *
 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ManifestResolverService's group-scoped permission filter.
 */
class ManifestResolverServicePermissionFilterTest extends TestCase {
	/**
	 * Build a ManifestResolverService wired to a real PermissionResolver, so
	 * the `group:<gid>` / `user:<uid>` grammar under test is the actual
	 * production grammar, not a stub.
	 *
	 * @param array<string> $userGroups Group GIDs {@see IGroupManager::getUserGroups} returns.
	 * @param bool $isAdmin Value {@see IGroupManager::isAdmin} returns.
	 *
	 * @return ManifestResolverService
	 */
	private function buildService(array $userGroups = [], bool $isAdmin = false): ManifestResolverService {
		$groupObjects = array_map(
			function (string $gid) {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn($gid);
				return $group;
			},
			$userGroups
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn($groupObjects);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$permissionResolver = new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class));

		return new ManifestResolverService(
			objectService: $this->createMock(ObjectServiceInterface::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			logger: $this->createMock(LoggerInterface::class),
			permissionResolver: $permissionResolver,
		);
	}//end buildService()

	/**
	 * Build a caller mock with the given uid.
	 *
	 * @param string $uid The caller's Nextcloud UID.
	 *
	 * @return IUser&MockObject
	 */
	private function buildCaller(string $uid): IUser&MockObject {
		$caller = $this->createMock(IUser::class);
		$caller->method('getUID')->willReturn($uid);
		return $caller;
	}//end buildCaller()

	/**
	 * A manifest with one ungated menu item + one `group:vets`-gated menu
	 * item, and the mirrored pair of pages.
	 *
	 * @return array<string, mixed>
	 */
	private function gatedManifest(): array {
		return [
			'version' => '1.0.0',
			'menu' => [
				['id' => 'dashboard', 'label' => 'Dashboard', 'route' => 'Dashboard'],
				['id' => 'medical', 'label' => 'Medical', 'route' => 'MedicalIndex', 'permission' => 'group:vets'],
			],
			'pages' => [
				['id' => 'Dashboard', 'route' => '/', 'type' => 'dashboard', 'title' => 'Dashboard'],
				['id' => 'MedicalIndex', 'route' => '/medical', 'type' => 'index', 'title' => 'Medical', 'permission' => 'group:vets'],
			],
		];
	}//end gatedManifest()

	/**
	 * THE load-bearing test: a caller who is NOT in the gated group, NOT an
	 * owner/editor, and NOT an admin never receives the gated menu item or
	 * page in the filtered manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
	 */
	public function testOutOfGroupCallerNeverReceivesGatedMenuItemOrPage(): void {
		$service = $this->buildService(userGroups: ['finance'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('carol');

		$result = $service->filterManifestForCaller(
			manifest: $this->gatedManifest(),
			application: $application,
			caller: $caller
		);

		$menuIds = array_column($result['menu'], 'id');
		$pageIds = array_column($result['pages'], 'id');

		self::assertContains('dashboard', $menuIds);
		self::assertNotContains('medical', $menuIds, 'Out-of-group caller must not receive the gated menu item.');
		self::assertContains('Dashboard', $pageIds);
		self::assertNotContains('MedicalIndex', $pageIds, 'Out-of-group caller must not receive the gated page — this is what makes it un-routable.');
	}//end testOutOfGroupCallerNeverReceivesGatedMenuItemOrPage()

	/**
	 * A caller who IS a member of the gated group receives both entries.
	 *
	 * @return void
	 */
	public function testGroupMemberReceivesGatedMenuItemAndPage(): void {
		$service = $this->buildService(userGroups: ['vets'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('vera');

		$result = $service->filterManifestForCaller(
			manifest: $this->gatedManifest(),
			application: $application,
			caller: $caller
		);

		self::assertContains('medical', array_column($result['menu'], 'id'));
		self::assertContains('MedicalIndex', array_column($result['pages'], 'id'));
	}//end testGroupMemberReceivesGatedMenuItemAndPage()

	/**
	 * A Nextcloud admin sees the manifest completely unfiltered, even when
	 * not a member of the gated group (design.md: "admins/owners see
	 * everything").
	 *
	 * @return void
	 */
	public function testAdminBypassesFiltering(): void {
		$service = $this->buildService(userGroups: [], isAdmin: true);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('root-admin');

		$result = $service->filterManifestForCaller(
			manifest: $this->gatedManifest(),
			application: $application,
			caller: $caller
		);

		self::assertSame($this->gatedManifest(), $result);
	}//end testAdminBypassesFiltering()

	/**
	 * An Application owner sees the manifest unfiltered, even when not a
	 * member of the gated group.
	 *
	 * @return void
	 */
	public function testOwnerBypassesFiltering(): void {
		$service = $this->buildService(userGroups: [], isAdmin: false);
		$application = ['permissions' => ['owners' => ['user:alice'], 'editors' => []]];
		$caller = $this->buildCaller('alice');

		$result = $service->filterManifestForCaller(
			manifest: $this->gatedManifest(),
			application: $application,
			caller: $caller
		);

		self::assertSame($this->gatedManifest(), $result);
	}//end testOwnerBypassesFiltering()

	/**
	 * An Application editor also sees the manifest unfiltered — a documented
	 * extension of the spec text ("admins and application owners"): an editor
	 * who cannot see a gated entry cannot edit it either.
	 *
	 * @return void
	 */
	public function testEditorBypassesFiltering(): void {
		$service = $this->buildService(userGroups: [], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => ['user:bob']]];
		$caller = $this->buildCaller('bob');

		$result = $service->filterManifestForCaller(
			manifest: $this->gatedManifest(),
			application: $application,
			caller: $caller
		);

		self::assertSame($this->gatedManifest(), $result);
	}//end testEditorBypassesFiltering()

	/**
	 * Fail-closed: an unauthenticated (`null`) caller receives no gated
	 * entries.
	 *
	 * @return void
	 */
	public function testNullCallerIsFailClosed(): void {
		$service = $this->buildService(userGroups: [], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];

		$result = $service->filterManifestForCaller(
			manifest: $this->gatedManifest(),
			application: $application,
			caller: null
		);

		self::assertNotContains('medical', array_column($result['menu'], 'id'));
		self::assertNotContains('MedicalIndex', array_column($result['pages'], 'id'));
	}//end testNullCallerIsFailClosed()

	/**
	 * A manifest with no `permission` fields at all renders unchanged for
	 * any caller (spec Scenario "Apps without permissions render unchanged").
	 *
	 * @return void
	 */
	public function testUngatedManifestIsUnchanged(): void {
		$service = $this->buildService(userGroups: ['finance'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('carol');
		$manifest = [
			'version' => '1.0.0',
			'menu' => [['id' => 'a', 'label' => 'A', 'route' => 'A']],
			'pages' => [['id' => 'A', 'route' => '/a', 'type' => 'index', 'title' => 'A']],
		];

		$result = $service->filterManifestForCaller(manifest: $manifest, application: $application, caller: $caller);

		self::assertSame($manifest, $result);
	}//end testUngatedManifestIsUnchanged()

	/**
	 * Multiple permissions on an item = OR semantics: a caller matching ANY
	 * one of the declared permissions sees the item.
	 *
	 * @return void
	 */
	public function testMultiplePermissionsUseOrSemantics(): void {
		$service = $this->buildService(userGroups: ['finance'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('carol');
		$manifest = [
			'menu' => [],
			'pages' => [
				['id' => 'Shared', 'route' => '/shared', 'type' => 'index', 'title' => 'Shared', 'permission' => ['group:vets', 'group:finance']],
			],
		];

		$result = $service->filterManifestForCaller(manifest: $manifest, application: $application, caller: $caller);

		self::assertContains('Shared', array_column($result['pages'], 'id'));
	}//end testMultiplePermissionsUseOrSemantics()

	/**
	 * A group-scoped dashboard the caller satisfies is promoted to the
	 * landing position (index 0) — `src/builder.js` treats `pages[0]` as the
	 * app's home route.
	 *
	 * @return void
	 */
	public function testGroupScopedDashboardIsPromotedToLandingForMatchingCaller(): void {
		$service = $this->buildService(userGroups: ['vets'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('vera');
		$manifest = [
			'menu' => [],
			'pages' => [
				['id' => 'DefaultDashboard', 'route' => '/', 'type' => 'dashboard', 'title' => 'Dashboard'],
				['id' => 'MedicalDashboard', 'route' => '/vets', 'type' => 'dashboard', 'title' => 'Vet dashboard', 'permission' => 'group:vets'],
			],
		];

		$result = $service->filterManifestForCaller(manifest: $manifest, application: $application, caller: $caller);

		self::assertSame('MedicalDashboard', $result['pages'][0]['id']);
	}//end testGroupScopedDashboardIsPromotedToLandingForMatchingCaller()

	/**
	 * A caller who does NOT satisfy the group-scoped dashboard keeps the
	 * default dashboard as the landing page (falls back per spec).
	 *
	 * @return void
	 */
	public function testNonMatchingCallerKeepsDefaultDashboardAsLanding(): void {
		$service = $this->buildService(userGroups: ['finance'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('carol');
		$manifest = [
			'menu' => [],
			'pages' => [
				['id' => 'DefaultDashboard', 'route' => '/', 'type' => 'dashboard', 'title' => 'Dashboard'],
				['id' => 'MedicalDashboard', 'route' => '/vets', 'type' => 'dashboard', 'title' => 'Vet dashboard', 'permission' => 'group:vets'],
			],
		];

		$result = $service->filterManifestForCaller(manifest: $manifest, application: $application, caller: $caller);

		self::assertSame('DefaultDashboard', $result['pages'][0]['id']);
		self::assertCount(1, $result['pages'], 'The gated dashboard must be absent, not merely reordered.');
	}//end testNonMatchingCallerKeepsDefaultDashboardAsLanding()

	/**
	 * A nested `children[]` entry is filtered independently of its parent.
	 *
	 * @return void
	 */
	public function testNestedMenuChildrenAreFilteredIndependently(): void {
		$service = $this->buildService(userGroups: ['finance'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('carol');
		$manifest = [
			'menu' => [
				[
					'id' => 'parent',
					'label' => 'Parent',
					'children' => [
						['id' => 'child-open', 'label' => 'Open'],
						['id' => 'child-gated', 'label' => 'Gated', 'permission' => 'group:vets'],
					],
				],
			],
			'pages' => [],
		];

		$result = $service->filterManifestForCaller(manifest: $manifest, application: $application, caller: $caller);

		$childIds = array_column($result['menu'][0]['children'], 'id');
		self::assertContains('child-open', $childIds);
		self::assertNotContains('child-gated', $childIds);
	}//end testNestedMenuChildrenAreFilteredIndependently()

	/**
	 * resolveCallerPermissionsForDisplay() returns the caller's real
	 * `group:<gid>` set for a plain viewer (the client-side CnAppNav mirror).
	 *
	 * @return void
	 */
	public function testResolveCallerPermissionsForDisplayReturnsGroupSetForViewer(): void {
		$service = $this->buildService(userGroups: ['vets', 'finance'], isAdmin: false);
		$application = ['permissions' => ['owners' => [], 'editors' => []]];
		$caller = $this->buildCaller('carol');

		$result = $service->resolveCallerPermissionsForDisplay(application: $application, caller: $caller);

		sort($result);
		self::assertSame(['group:finance', 'group:vets'], $result);
	}//end testResolveCallerPermissionsForDisplayReturnsGroupSetForViewer()

	/**
	 * resolveCallerPermissionsForDisplay() returns an EMPTY array for a
	 * privileged caller (admin/owner/editor) — `CnAppNav` treats an empty
	 * `permissions` prop as "show everything", matching the fact that
	 * {@see ManifestResolverService::filterManifestForCaller()} already sent
	 * them the full, unfiltered manifest.
	 *
	 * @return void
	 */
	public function testResolveCallerPermissionsForDisplayIsEmptyForOwner(): void {
		$service = $this->buildService(userGroups: [], isAdmin: false);
		$application = ['permissions' => ['owners' => ['user:alice'], 'editors' => []]];
		$caller = $this->buildCaller('alice');

		$result = $service->resolveCallerPermissionsForDisplay(application: $application, caller: $caller);

		self::assertSame([], $result);
	}//end testResolveCallerPermissionsForDisplayIsEmptyForOwner()
}//end class
