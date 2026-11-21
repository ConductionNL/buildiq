<?php

/**
 * Buildiq ServedManifestShape
 *
 * Checks that a manifest has what the renderer needs before it is served: a
 * string `version`, a `pages` array, a `menu` array when the manifest has a
 * menu, and on every page a unique non-empty string `id` and a string `route`,
 * `type` and `title`. It is the same kind of structural check
 * {@see AppRepoParser} applies to an imported manifest, extended to the pages,
 * because a delta merged over a base can drop or blank a page key the base had.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The renderer's minimum for a served manifest.
 */
final class ServedManifestShape {

	/**
	 * The page keys the renderer reads as strings.
	 *
	 * @var list<string>
	 */
	private const PAGE_STRING_KEYS = ['route', 'type', 'title'];

	/**
	 * The first problem that keeps a manifest from rendering, or null when it is sound.
	 *
	 * @param array<string, mixed> $manifest The manifest about to be served.
	 *
	 * @return string|null What is wrong, naming the property path, or null.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
	 */
	public function problem(array $manifest): ?string {
		if (is_string($manifest['version'] ?? null) === false) {
			return 'the manifest has no string `version`';
		}

		if (array_key_exists('menu', $manifest) === true && is_array($manifest['menu']) === false) {
			return '`menu` is not an array';
		}

		$pages = ($manifest['pages'] ?? null);
		if (is_array($pages) === false || array_is_list($pages) === false) {
			return 'the manifest has no `pages` array';
		}

		return $this->pagesProblem(pages: $pages);
	}//end problem()

	/**
	 * The first page that lacks a key the renderer needs, or repeats an id.
	 *
	 * @param list<mixed> $pages The manifest's pages.
	 *
	 * @return string|null What is wrong, or null.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
	 */
	private function pagesProblem(array $pages): ?string {
		$seen = [];
		foreach ($pages as $index => $page) {
			if (is_array($page) === false) {
				return '`pages[' . $index . ']` is not an object';
			}

			$id = ($page['id'] ?? null);
			if (is_string($id) === false || trim($id) === '') {
				return '`pages[' . $index . '].id` is not a non-empty string';
			}

			if (isset($seen[$id]) === true) {
				return '`pages[' . $index . '].id` repeats the id "' . $id . '"';
			}

			$seen[$id] = true;
			foreach (self::PAGE_STRING_KEYS as $key) {
				if (is_string($page[$key] ?? null) === false) {
					return '`pages[' . $index . '].' . $key . '` is not a string';
				}
			}
		}

		return null;
	}//end pagesProblem()
}//end class
