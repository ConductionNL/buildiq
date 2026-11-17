<?php

/**
 * WidgetIdAllocator — the widget ids ManifestWidgetShape mints.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Ids are derived from the caller's id, the title or the type, deduplicated
 * against the widgets and layout rows already on the page. Nothing here reads
 * the clock or a random source.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Mints page-unique widget ids.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */
final class WidgetIdAllocator {

	/**
	 * Longest id this class will mint, matching the tool schema's maxLength.
	 *
	 * @var int
	 */
	private const MAX_ID_LENGTH = 48;

	/**
	 * A page-unique, kebab-case id for a widget about to be appended.
	 *
	 * @param array<int, string> $sources Candidate sources in order: the caller-supplied id
	 *                                    (possibly empty), the already-resolved title, the widget type.
	 * @param array{widgets: array<int, mixed>, layout: array<int, mixed>} $existing The page's
	 *                                    existing widgets and layout rows.
	 *
	 * @return string A non-empty id no widget or layout row on the page uses.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function allocate(array $sources, array $existing): string {
		return self::resolveFree(
			sources: $sources,
			taken: self::takenIds(widgets: $existing['widgets'], layout: $existing['layout'])
		);
	}//end allocate()

	/**
	 * Resolve a page-unique, kebab-case widget id.
	 *
	 * @param array<int, string> $sources Candidate sources in order: the caller-supplied id
	 *                                    (possibly empty), the already-resolved title, the widget type.
	 * @param array<string, bool> $taken Ids already used on this page, keyed by id.
	 *
	 * @return string A non-empty id not present in $taken.
	 */
	private static function resolveFree(array $sources, array $taken): string {
		$base = '';
		foreach ($sources as $source) {
			$base = self::slug(value: $source);
			if ($base !== '') {
				break;
			}
		}

		if ($base === '') {
			$base = 'widget';
		}

		if (isset($taken[$base]) === false) {
			return $base;
		}

		$suffix = 2;
		while (isset($taken[$base . '-' . $suffix]) === true) {
			$suffix++;
		}

		return ($base . '-' . $suffix);
	}//end resolveFree()
	/**
	 * Collect the ids already spoken for on a page.
	 *
	 * Layout rows are read as well as widgets: a layout row keyed by an id no
	 * widget carries still owns that id, and reusing it would silently place
	 * the new widget in the old row.
	 *
	 * @param array<int, mixed> $widgets The page's existing widgets.
	 * @param array<int, mixed> $layout The page's existing layout rows.
	 *
	 * @return array<string, bool> Set of taken ids.
	 */
	private static function takenIds(array $widgets, array $layout): array {
		$taken = [];
		self::collectIds(rows: $widgets, keys: ['id'], taken: $taken);
		self::collectIds(rows: $layout, keys: ['id', 'widgetId'], taken: $taken);

		return $taken;
	}//end takenIds()
	/**
	 * Add every non-empty string found under $keys in $rows to $taken.
	 *
	 * @param array<int, mixed> $rows Entries to read.
	 * @param array<int, string> $keys Keys on each entry that hold an id.
	 * @param array<string, bool> $taken Accumulator, keyed by id.
	 *
	 * @return void
	 */
	private static function collectIds(array $rows, array $keys, array &$taken): void {
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			foreach ($keys as $key) {
				$id = ($row[$key] ?? null);
				if (is_string($id) === true && $id !== '') {
					$taken[$id] = true;
				}
			}
		}
	}//end collectIds()
	/**
	 * Reduce a string to a kebab-case identifier.
	 *
	 * @param string $value Free text.
	 *
	 * @return string Kebab-case slug, or '' when nothing usable remains.
	 */
	private static function slug(string $value): string {
		$lower = mb_strtolower(trim($value));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $lower);
		if (is_string($slug) === false) {
			return '';
		}

		$slug = trim($slug, '-');
		if (mb_strlen($slug) > self::MAX_ID_LENGTH) {
			$slug = trim(mb_substr($slug, 0, self::MAX_ID_LENGTH), '-');
		}

		return $slug;
	}//end slug()
}//end class
