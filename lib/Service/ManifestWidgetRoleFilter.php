<?php

/**
 * Buildiq Manifest Widget Role Filter
 *
 * Removes the page widgets a caller does not match by `roles` from a resolved
 * manifest, so a widget meant for one group never reaches anyone else's
 * browser. `@conduction/nextcloud-vue` does not evaluate `roles`; the schema
 * leaves the check to the host app, on the read.
 *
 * Widgets live in four places on a page, and each is filtered:
 *
 *   - `widgets[]`, the uniform v2 placement entries.
 *   - `config.widgets[]`, the legacy dashboard and detail-grid catalog.
 *   - `config.sidebarTabs[].widgets[]`, legacy sidebar tab widgets.
 *   - The same shapes inside `pageTemplates[].page` and
 *     `pageInstances[].override`, which expand into pages on the client.
 *
 * Anything that names a removed widget by id is pruned with it: rows of
 * `config.layout[]` (`widgetId`) and the `content.tabs[]` of a tabs widget,
 * which would otherwise render a tab announcing the missing widget's id.
 *
 * Matching reuses {@see AppVisibilityResolver}, so a page widget and a widget
 * promoted to the Nextcloud dashboard read `roles` the same way.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

use OCP\IGroupManager;
use OCP\IUser;

/**
 * Strips page widgets whose `roles` the caller does not match.
 *
 * @spec openspec/changes/widget-roles-on-app-pages/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
 */
class ManifestWidgetRoleFilter {
	/**
	 * Constructor.
	 *
	 * @param AppVisibilityResolver $visibility Shared principal matching (group:*, user:, group:, admin).
	 * @param IGroupManager $groupManager Group membership lookup for the caller.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppVisibilityResolver $visibility,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Filter every page-shaped part of a manifest for the caller.
	 *
	 * @param array<string, mixed> $manifest The resolved manifest.
	 * @param IUser|null $caller The caller, or null when unauthenticated
	 *                           (every widget with `roles` is removed).
	 *
	 * @return array<string, mixed> The manifest without the widgets the caller
	 *                              does not match.
	 *
	 * @spec openspec/changes/widget-roles-on-app-pages/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
	 */
	public function filterManifest(array $manifest, ?IUser $caller): array {
		if (is_array($manifest['pages'] ?? null) === true) {
			foreach ($manifest['pages'] as $index => $page) {
				if (is_array($page) === true) {
					$manifest['pages'][$index] = $this->filterPage(page: $page, caller: $caller);
				}
			}
		}

		$manifest = $this->filterNestedPages(manifest: $manifest, listKey: 'pageTemplates', pageKey: 'page', caller: $caller);

		return $this->filterNestedPages(manifest: $manifest, listKey: 'pageInstances', pageKey: 'override', caller: $caller);
	}//end filterManifest()

	/**
	 * Filter the widgets of one page and prune references to removed ones.
	 *
	 * @param array<string, mixed> $page The page (or page-shaped delta).
	 * @param IUser|null $caller The caller, or null.
	 *
	 * @return array<string, mixed> The page without the caller's hidden widgets.
	 *
	 * @spec openspec/changes/widget-roles-on-app-pages/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
	 */
	public function filterPage(array $page, ?IUser $caller): array {
		$removedIds = [];

		if (is_array($page['widgets'] ?? null) === true) {
			$page['widgets'] = $this->filterWidgetList(widgets: $page['widgets'], caller: $caller, removedIds: $removedIds);
		}

		if (is_array($page['config'] ?? null) === true) {
			$page['config'] = $this->filterConfig(config: $page['config'], caller: $caller, removedIds: $removedIds);
		}

		return $this->pruneTabReferences(page: $page, removedIds: $removedIds);
	}//end filterPage()

	/**
	 * Filter the legacy widget lists in a page's `config` and drop the
	 * `config.layout[]` rows of removed widgets.
	 *
	 * @param array<string, mixed> $config The page's `config`.
	 * @param IUser|null $caller The caller, or null.
	 * @param array<string, true> $removedIds Collects the ids of removed widgets.
	 *
	 * @return array<string, mixed> The filtered `config`.
	 */
	private function filterConfig(array $config, ?IUser $caller, array &$removedIds): array {
		if (is_array($config['widgets'] ?? null) === true) {
			$config['widgets'] = $this->filterWidgetList(widgets: $config['widgets'], caller: $caller, removedIds: $removedIds);
		}

		if (is_array($config['sidebarTabs'] ?? null) === true) {
			$config['sidebarTabs'] = $this->filterSidebarTabs(tabs: $config['sidebarTabs'], caller: $caller, removedIds: $removedIds);
		}

		if (is_array($config['layout'] ?? null) === true) {
			$config['layout'] = $this->dropReferences(entries: $config['layout'], removedIds: $removedIds);
		}

		return $config;
	}//end filterConfig()

	/**
	 * Filter the `widgets[]` of every legacy sidebar tab.
	 *
	 * @param array<int|string, mixed> $tabs The `config.sidebarTabs[]` entries.
	 * @param IUser|null $caller The caller, or null.
	 * @param array<string, true> $removedIds Collects the ids of removed widgets.
	 *
	 * @return array<int|string, mixed> The tabs with filtered widgets.
	 */
	private function filterSidebarTabs(array $tabs, ?IUser $caller, array &$removedIds): array {
		foreach ($tabs as $index => $tab) {
			if (is_array($tab) === true && is_array($tab['widgets'] ?? null) === true) {
				$tabs[$index]['widgets'] = $this->filterWidgetList(widgets: $tab['widgets'], caller: $caller, removedIds: $removedIds);
			}
		}

		return $tabs;
	}//end filterSidebarTabs()

	/**
	 * Whether the caller may see a widget entry. A widget without `roles`
	 * (absent, null, empty) is visible to every reader of the page; a
	 * `roles` value that is neither a string nor a list hides the widget.
	 *
	 * @param array<string, mixed> $widget The widget entry.
	 * @param IUser|null $caller The caller, or null.
	 *
	 * @return bool True when the caller may see the widget.
	 *
	 * @spec openspec/changes/widget-roles-on-app-pages/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
	 */
	public function isWidgetVisible(array $widget, ?IUser $caller): bool {
		$roles = ($widget['roles'] ?? null);
		if ($roles === null || $roles === '' || $roles === []) {
			return true;
		}

		if (is_string($roles) === true) {
			$roles = [$roles];
		}

		if (is_array($roles) === false) {
			return false;
		}

		return $this->visibility->isVisibleToUser(
			permissions: [],
			extraPrincipals: array_values($roles),
			user: $caller,
			groupManager: $this->groupManager
		);
	}//end isWidgetVisible()

	/**
	 * Apply {@see filterPage()} to the page held under `$pageKey` in every
	 * entry of a top-level manifest list.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 * @param string $listKey The top-level list (`pageTemplates`, `pageInstances`).
	 * @param string $pageKey The page-shaped key inside each entry.
	 * @param IUser|null $caller The caller, or null.
	 *
	 * @return array<string, mixed> The manifest with that list filtered.
	 */
	private function filterNestedPages(array $manifest, string $listKey, string $pageKey, ?IUser $caller): array {
		if (is_array($manifest[$listKey] ?? null) === false) {
			return $manifest;
		}

		foreach ($manifest[$listKey] as $index => $entry) {
			if (is_array($entry) === true && is_array($entry[$pageKey] ?? null) === true) {
				$manifest[$listKey][$index][$pageKey] = $this->filterPage(page: $entry[$pageKey], caller: $caller);
			}
		}

		return $manifest;
	}//end filterNestedPages()

	/**
	 * Keep the widgets the caller may see; record the ids of the others.
	 *
	 * @param array<int|string, mixed> $widgets The widget list.
	 * @param IUser|null $caller The caller, or null.
	 * @param array<string, true> $removedIds Collects the ids of removed widgets.
	 *
	 * @return array<int, mixed> The visible widgets, reindexed.
	 */
	private function filterWidgetList(array $widgets, ?IUser $caller, array &$removedIds): array {
		$kept = [];
		foreach ($widgets as $widget) {
			if (is_array($widget) === false || $this->isWidgetVisible(widget: $widget, caller: $caller) === true) {
				$kept[] = $widget;
				continue;
			}

			$id = ($widget['id'] ?? null);
			if (is_string($id) === true && $id !== '') {
				$removedIds[$id] = true;
			}
		}

		return $kept;
	}//end filterWidgetList()

	/**
	 * Drop the `content.tabs[]` entries of surviving tabs widgets that name a
	 * removed widget, in both the uniform (`props.content`) and the legacy
	 * (`content`) shape.
	 *
	 * @param array<string, mixed> $page The page.
	 * @param array<string, true> $removedIds Ids of removed widgets.
	 *
	 * @return array<string, mixed> The page without dangling tab references.
	 */
	private function pruneTabReferences(array $page, array $removedIds): array {
		if (is_array($page['widgets'] ?? null) === true) {
			$page['widgets'] = $this->pruneTabsInWidgets(widgets: $page['widgets'], uniform: true, removedIds: $removedIds);
		}

		if (is_array($page['config']['widgets'] ?? null) === true) {
			$page['config']['widgets'] = $this->pruneTabsInWidgets(widgets: $page['config']['widgets'], uniform: false, removedIds: $removedIds);
		}

		return $page;
	}//end pruneTabReferences()

	/**
	 * Prune the `content.tabs[]` of every tabs widget in a list. A uniform
	 * entry carries `content` under `props`; a legacy entry carries it at the
	 * top level.
	 *
	 * @param array<int|string, mixed> $widgets The widget list.
	 * @param bool $uniform True for uniform `widgets[]` entries.
	 * @param array<string, true> $removedIds Ids of removed widgets.
	 *
	 * @return array<int|string, mixed> The widgets with pruned tab lists.
	 */
	private function pruneTabsInWidgets(array $widgets, bool $uniform, array $removedIds): array {
		foreach ($widgets as $index => $widget) {
			if ($uniform === true && isset($widget['props']['content']['tabs']) === true) {
				$widgets[$index]['props']['content']['tabs'] = $this->dropReferences(
					entries: $widget['props']['content']['tabs'],
					removedIds: $removedIds
				);
			}

			if ($uniform === false && isset($widget['content']['tabs']) === true) {
				$widgets[$index]['content']['tabs'] = $this->dropReferences(entries: $widget['content']['tabs'], removedIds: $removedIds);
			}
		}

		return $widgets;
	}//end pruneTabsInWidgets()

	/**
	 * Drop the entries that name a removed widget: a bare id or an object
	 * with `widgetId` (a `config.layout[]` row or a `content.tabs[]` entry).
	 *
	 * @param mixed $entries The list.
	 * @param array<string, true> $removedIds Ids of removed widgets.
	 *
	 * @return mixed The list without references to removed widgets, or the
	 *               value unchanged when it is not a list.
	 */
	private function dropReferences(mixed $entries, array $removedIds): mixed {
		if (is_array($entries) === false) {
			return $entries;
		}

		$kept = [];
		foreach ($entries as $entry) {
			$widgetId = $entry;
			if (is_array($entry) === true) {
				$widgetId = ($entry['widgetId'] ?? null);
			}

			if (is_string($widgetId) === true && isset($removedIds[$widgetId]) === true) {
				continue;
			}

			$kept[] = $entry;
		}

		return $kept;
	}//end dropReferences()
}//end class
