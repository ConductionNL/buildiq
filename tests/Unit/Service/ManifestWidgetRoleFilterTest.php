<?php

/**
 * Unit tests for ManifestWidgetRoleFilter: which page widgets a caller keeps
 * by `roles`, in every place a page declares widgets, and the references
 * pruned with a removed widget.
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
 * @spec openspec/changes/widget-roles-on-app-pages/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\ManifestWidgetRoleFilter;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestWidgetRoleFilter.
 */
class ManifestWidgetRoleFilterTest extends TestCase {
	/**
	 * Build the filter for a caller in the given groups.
	 *
	 * @param array<string> $userGroups Group ids the caller belongs to.
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 *
	 * @return ManifestWidgetRoleFilter
	 */
	private function buildFilter(array $userGroups = [], bool $isAdmin = false): ManifestWidgetRoleFilter {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($userGroups);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new ManifestWidgetRoleFilter(visibility: new AppVisibilityResolver(), groupManager: $groupManager);
	}//end buildFilter()

	/**
	 * Build a caller with the given uid.
	 *
	 * @param string $uid The caller's uid.
	 *
	 * @return IUser
	 */
	private function buildCaller(string $uid = 'carol'): IUser {
		$caller = $this->createMock(originalClassName: IUser::class);
		$caller->method('getUID')->willReturn($uid);
		return $caller;
	}//end buildCaller()

	/**
	 * A uniform widget entry.
	 *
	 * @param string $id The widget id.
	 * @param array<string>|null $roles The widget's roles, or null for none.
	 *
	 * @return array<string, mixed>
	 */
	private function widget(string $id, ?array $roles = null): array {
		$widget = ['id' => $id, 'widgetKey' => 'stat', 'slot' => 'body', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2];
		if ($roles !== null) {
			$widget['roles'] = $roles;
		}

		return $widget;
	}//end widget()

	/**
	 * Which roles a caller in `finance`, uid `carol`, matches.
	 *
	 * @return array<string, array{0: array<string>, 1: bool}>
	 */
	public static function roleMatchProvider(): array {
		return [
			'bare group id the caller is in' => [['finance'], true],
			'group: prefix the caller is in' => [['group:finance'], true],
			'group:* wildcard' => [['group:*'], true],
			'user: the caller' => [['user:carol'], true],
			'user: someone else' => [['user:dave'], false],
			'group the caller is not in' => [['controllers'], false],
			'one of several matches' => [['controllers', 'finance'], true],
		];
	}//end roleMatchProvider()

	/**
	 * Roles match the way the Nextcloud dashboard matches them.
	 *
	 * @param array<string> $roles The widget's roles.
	 * @param bool $visible Whether the caller should keep the widget.
	 *
	 * @return void
	 *
	 * @dataProvider roleMatchProvider
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('roleMatchProvider')]
	public function testRolesMatchLikeTheDashboard(array $roles, bool $visible): void {
		$filter = $this->buildFilter(userGroups: ['finance']);

		self::assertSame(
			expected: $visible,
			actual: $filter->isWidgetVisible(widget: $this->widget(id: 'w', roles: $roles), caller: $this->buildCaller(uid: 'carol'))
		);
	}//end testRolesMatchLikeTheDashboard()

	/**
	 * A widget without roles, or with empty roles, is visible to every reader.
	 *
	 * @return void
	 */
	public function testWidgetWithoutRolesIsVisibleToEveryone(): void {
		$filter = $this->buildFilter();

		self::assertTrue(condition: $filter->isWidgetVisible(widget: $this->widget(id: 'a'), caller: null));
		self::assertTrue(condition: $filter->isWidgetVisible(widget: $this->widget(id: 'b', roles: []), caller: $this->buildCaller()));
	}//end testWidgetWithoutRolesIsVisibleToEveryone()

	/**
	 * A Nextcloud admin matches any roles.
	 *
	 * @return void
	 */
	public function testAdminMatchesAnyRoles(): void {
		$filter = $this->buildFilter(isAdmin: true);

		self::assertTrue(condition: $filter->isWidgetVisible(widget: $this->widget(id: 'w', roles: ['controllers']), caller: $this->buildCaller()));
	}//end testAdminMatchesAnyRoles()

	/**
	 * An unauthenticated caller matches no roles, not even `group:*`.
	 *
	 * @return void
	 */
	public function testNullCallerMatchesNoRoles(): void {
		$filter = $this->buildFilter();

		self::assertFalse(condition: $filter->isWidgetVisible(widget: $this->widget(id: 'w', roles: ['group:*']), caller: null));
	}//end testNullCallerMatchesNoRoles()

	/**
	 * A `roles` value that is not a list or a string hides the widget.
	 *
	 * @return void
	 */
	public function testMalformedRolesHideTheWidget(): void {
		$filter = $this->buildFilter(userGroups: ['finance']);
		$widget = $this->widget(id: 'w');
		$widget['roles'] = 42;

		self::assertFalse(condition: $filter->isWidgetVisible(widget: $widget, caller: $this->buildCaller()));
	}//end testMalformedRolesHideTheWidget()

	/**
	 * Uniform `widgets[]` entries are filtered, and a tabs widget's
	 * `props.content.tabs[]` loses the tab of a removed widget.
	 *
	 * @return void
	 */
	public function testUniformWidgetsAndTabReferencesAreFiltered(): void {
		$tabs = $this->widget(id: 'tabs');
		$tabs['widgetKey'] = 'tabs';
		$tabs['props'] = ['content' => ['tabs' => [['widgetId' => 'notes'], 'budget']]];
		$page = [
			'id' => 'Case',
			'type' => 'detail',
			'widgets' => [$tabs, $this->widget(id: 'notes'), $this->widget(id: 'budget', roles: ['finance'])],
		];

		$result = $this->buildFilter(userGroups: ['vets'])->filterPage(page: $page, caller: $this->buildCaller());

		self::assertSame(expected: ['tabs', 'notes'], actual: array_column($result['widgets'], 'id'));
		self::assertSame(expected: [['widgetId' => 'notes']], actual: $result['widgets'][0]['props']['content']['tabs']);
	}//end testUniformWidgetsAndTabReferencesAreFiltered()

	/**
	 * Legacy `config.widgets[]` entries are filtered and their
	 * `config.layout[]` rows go with them; a legacy tabs widget's
	 * `content.tabs[]` is pruned too.
	 *
	 * @return void
	 */
	public function testLegacyConfigWidgetsAndLayoutRowsAreFiltered(): void {
		$page = [
			'id' => 'Dashboard',
			'type' => 'dashboard',
			'config' => [
				'widgets' => [
					['id' => 'kpis', 'type' => 'stat', 'title' => 'KPIs'],
					['id' => 'budget', 'type' => 'stat', 'title' => 'Budget', 'roles' => ['finance']],
					['id' => 'tabs', 'type' => 'tabs', 'title' => 'More', 'content' => ['tabs' => ['kpis', 'budget']]],
				],
				'layout' => [
					['id' => 1, 'widgetId' => 'kpis', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2],
					['id' => 2, 'widgetId' => 'budget', 'gridX' => 6, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2],
					['id' => 3, 'widgetId' => 'tabs', 'gridX' => 0, 'gridY' => 2, 'gridWidth' => 12, 'gridHeight' => 4],
				],
			],
		];

		$result = $this->buildFilter(userGroups: ['vets'])->filterPage(page: $page, caller: $this->buildCaller());

		self::assertSame(expected: ['kpis', 'tabs'], actual: array_column($result['config']['widgets'], 'id'));
		self::assertSame(expected: ['kpis', 'tabs'], actual: array_column($result['config']['layout'], 'widgetId'));
		self::assertSame(expected: ['kpis'], actual: $result['config']['widgets'][1]['content']['tabs']);
	}//end testLegacyConfigWidgetsAndLayoutRowsAreFiltered()

	/**
	 * Legacy `config.sidebarTabs[].widgets[]` entries are filtered.
	 *
	 * @return void
	 */
	public function testSidebarTabWidgetsAreFiltered(): void {
		$page = [
			'id' => 'Case',
			'type' => 'detail',
			'config' => [
				'sidebarTabs' => [
					['id' => 'info', 'label' => 'Info', 'widgets' => [['id' => 'notes'], ['id' => 'budget', 'roles' => ['finance']]]],
					['id' => 'files', 'label' => 'Files', 'component' => 'FilesTab'],
				],
			],
		];

		$result = $this->buildFilter(userGroups: ['vets'])->filterPage(page: $page, caller: $this->buildCaller());

		self::assertSame(expected: [['id' => 'notes']], actual: $result['config']['sidebarTabs'][0]['widgets']);
		self::assertSame(expected: $page['config']['sidebarTabs'][1], actual: $result['config']['sidebarTabs'][1]);
	}//end testSidebarTabWidgetsAreFiltered()

	/**
	 * Pages, page templates and page instance overrides are all filtered;
	 * the rest of the manifest is untouched.
	 *
	 * @return void
	 */
	public function testEveryPageShapedPartOfTheManifestIsFiltered(): void {
		$manifest = [
			'version' => '1.0.0',
			'menu' => [['id' => 'home', 'route' => 'Home']],
			'pages' => [['id' => 'Home', 'widgets' => [$this->widget(id: 'open'), $this->widget(id: 'budget', roles: ['finance'])]]],
			'pageTemplates' => [['id' => 'tpl', 'page' => ['id' => '{{id}}', 'widgets' => [$this->widget(id: 'budget', roles: ['finance'])]]]],
			'pageInstances' => [['templateRef' => 'tpl', 'override' => ['widgets' => [$this->widget(id: 'extra', roles: ['user:dave'])]]]],
		];

		$result = $this->buildFilter(userGroups: ['vets'])->filterManifest(manifest: $manifest, caller: $this->buildCaller());

		self::assertSame(expected: $manifest['menu'], actual: $result['menu']);
		self::assertSame(expected: ['open'], actual: array_column($result['pages'][0]['widgets'], 'id'));
		self::assertSame(expected: [], actual: $result['pageTemplates'][0]['page']['widgets']);
		self::assertSame(expected: [], actual: $result['pageInstances'][0]['override']['widgets']);
	}//end testEveryPageShapedPartOfTheManifestIsFiltered()

	/**
	 * A manifest without widgets with roles comes back unchanged.
	 *
	 * @return void
	 */
	public function testManifestWithoutRolesIsUnchanged(): void {
		$manifest = [
			'version' => '1.0.0',
			'pages' => [
				['id' => 'Home', 'widgets' => [$this->widget(id: 'a')], 'config' => ['layout' => [['id' => 1, 'widgetId' => 'a']]]],
				'not-a-page',
			],
		];

		self::assertSame(expected: $manifest, actual: $this->buildFilter()->filterManifest(manifest: $manifest, caller: null));
	}//end testManifestWithoutRolesIsUnchanged()
}//end class
