<?php

/**
 * Buildiq Manifest Widget Reference Pruner
 *
 * Removes what still names a widget after {@see ManifestWidgetRoleFilter}
 * dropped it from a page: rows of `config.layout[]` (`widgetId`) and the
 * `content.tabs[]` entries of a tabs widget, which would otherwise render a
 * tab announcing the missing widget's id.
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
 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Prunes references to widgets that were removed from a page.
 *
 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
 */
class ManifestWidgetReferencePruner {
	/**
	 * Drop the `content.tabs[]` entries of surviving tabs widgets that name a
	 * removed widget, in both the uniform (`props.content`) and the legacy
	 * (`content`) shape.
	 *
	 * @param array<string, mixed> $page The page.
	 * @param array<string, true> $removedIds Ids of removed widgets.
	 *
	 * @return array<string, mixed> The page without dangling tab references.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
	 */
	public function pruneTabReferences(array $page, array $removedIds): array {
		if (is_array($page['widgets'] ?? null) === true) {
			$page['widgets'] = $this->pruneTabsInWidgets(widgets: $page['widgets'], uniform: true, removedIds: $removedIds);
		}

		if (is_array($page['config']['widgets'] ?? null) === true) {
			$page['config']['widgets'] = $this->pruneTabsInWidgets(widgets: $page['config']['widgets'], uniform: false, removedIds: $removedIds);
		}

		return $page;
	}//end pruneTabReferences()

	/**
	 * Drop the entries that name a removed widget: a bare id or an object
	 * with `widgetId` (a `config.layout[]` row or a `content.tabs[]` entry).
	 *
	 * @param mixed $entries The list.
	 * @param array<string, true> $removedIds Ids of removed widgets.
	 *
	 * @return mixed The list without references to removed widgets, or the
	 *               value unchanged when it is not a list.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
	 */
	public function dropReferences(mixed $entries, array $removedIds): mixed {
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
}//end class
