<?php

/**
 * OpenBuild AppOverrideDeltaValidator
 *
 * Pure (stateless) validation of a fleet-app manifest delta before it is
 * persisted by AppOverrideService (design D4). Two checks:
 *   - validateDeltaShape(): the body is a keyed delta — a plain object whose
 *     page/widget entries carry ids, whose `$op` values are the known deletion
 *     marker, and whose `__order` value is an array/object of ids.
 *   - wouldBlankApp(): the delta, applied over an EMPTY base, resolves to a
 *     manifest with no renderable pages or menu (an app-blanking delta), which
 *     would brick the per-instance-shared app for every user.
 *
 * Split out of AppOverrideService so the persistence surface stays small and
 * the validation grammar (which mirrors the nextcloud-vue mergeManifestDelta
 * contract) has one home.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/app-override-persistence/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

/**
 * Stateless validator for fleet-app manifest deltas (design D4).
 *
 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/app-override-persistence/spec.md
 */
class AppOverrideDeltaValidator {
	/**
	 * The known per-entry deletion marker recognised by mergeManifestDelta.
	 */
	private const REMOVE_MARKER = 'remove';

	/**
	 * Validate that a body is a well-formed keyed manifest delta.
	 *
	 * Returns a (possibly empty) list of human-readable violation strings.
	 * An empty list means the delta passed validation. The checks mirror the
	 * nextcloud-vue delta contract: the body must be a plain object;
	 * page/widget entries must carry ids; `$op` values must be the known
	 * deletion marker; `__order` must be an array (list of ids) or an object
	 * of id-keyed orderings.
	 *
	 * @param array<array-key, mixed> $delta The candidate delta body (may arrive list-shaped from a bad PUT).
	 *
	 * @return array<int, string> A list of violations; empty when the delta is valid.
	 *
	 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/app-override-persistence/spec.md
	 */
	public function validateDeltaShape(array $delta): array {
		$violations = [];

		// A keyed delta is a plain (associative or empty) object. A list-shaped
		// payload at the top level is a whole-manifest POST mistake, not a delta.
		if ($delta !== [] && array_is_list($delta) === true) {
			$violations[] = 'delta must be a plain object, not a list';
			return $violations;
		}

		$this->collectDeltaViolations(node: $delta, path: '$', violations: $violations);

		return $violations;
	}//end validateDeltaShape()

	/**
	 * Detect a delta that, applied over an EMPTY base, resolves to a manifest
	 * with no renderable pages and no menu (an "app-blanking" delta).
	 *
	 * OpenBuild cannot fully merge the delta over the real base (it lacks the
	 * fleet base — design D2), but it CAN detect the obvious self-blanking
	 * cases on write: a delta whose only page/menu effect is removals, or one
	 * that explicitly sets `pages`/`menu` to empty. Such a delta would brick
	 * the app for every user of the instance (it is shared), so the write is
	 * refused with 422 (design D4).
	 *
	 * Conservative by design: returns true ONLY for deltas that demonstrably
	 * leave nothing renderable; a delta that adds or keeps at least one page or
	 * menu entry passes.
	 *
	 * @param array<string, mixed> $delta The candidate delta body.
	 *
	 * @return bool True when the delta resolves (over an empty base) to no pages/menu.
	 *
	 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/app-override-persistence/spec.md
	 */
	public function wouldBlankApp(array $delta): bool {
		// An empty delta is a no-op (the bundled manifest passes through), so it
		// never blanks the app.
		if ($delta === []) {
			return false;
		}

		// If the delta touches neither pages nor menu, it cannot blank them
		// (whatever the base had survives) — not a blanking delta.
		$touchesPages = array_key_exists('pages', $delta);
		$touchesMenu = array_key_exists('menu', $delta);
		if ($touchesPages === false && $touchesMenu === false) {
			return false;
		}

		$pagesRenderable = $this->branchHasRenderableEntries(node: ($delta['pages'] ?? null));
		$menuRenderable = $this->branchHasRenderableEntries(node: ($delta['menu'] ?? null));

		// Over an empty base, an untouched branch contributes nothing. The app
		// is blanked iff neither touched branch leaves anything renderable.
		if ($pagesRenderable === true || $menuRenderable === true) {
			return false;
		}

		return true;
	}//end wouldBlankApp()

	/**
	 * Recursively collect delta-shape violations from a node.
	 *
	 * @param mixed $node The current node.
	 * @param string $path The JSON-path-ish breadcrumb for messages.
	 * @param array<int,string> $violations Accumulator (by reference).
	 *
	 * @return void
	 */
	private function collectDeltaViolations(mixed $node, string $path, array &$violations): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			$this->checkDeltaKey(key: (string)$key, value: $value, path: $path, violations: $violations);
		}

	}//end collectDeltaViolations()

	/**
	 * Validate a single key/value pair inside a delta node.
	 *
	 * Dispatches on the key: `$op` must be the known marker, `__order` must be
	 * an array, a list value is validated as a page/widget entry list, and a
	 * nested object is recursed into.
	 *
	 * @param string $key The current key.
	 * @param mixed $value The value at that key.
	 * @param string $path The breadcrumb for messages.
	 * @param array<int,string> $violations Accumulator (by reference).
	 *
	 * @return void
	 */
	private function checkDeltaKey(string $key, mixed $value, string $path, array &$violations): void {
		// `$op` markers must be the known deletion marker.
		if ($key === '$op') {
			if ($value !== self::REMOVE_MARKER) {
				$shown = 'non-scalar';
				if (is_scalar($value) === true) {
					$shown = (string)$value;
				}

				$violations[] = $path . '.$op has unknown marker "' . $shown . '" (only "' . self::REMOVE_MARKER . '" is supported)';
			}

			return;
		}

		// `__order` must be an array (list of ids) or an object of id orderings.
		if ($key === '__order') {
			if (is_array($value) === false) {
				$violations[] = $path . '.__order must be an array or object of ids';
			}

			return;
		}

		if (is_array($value) === false) {
			return;
		}

		// Lists of page/widget entries: every object entry must carry an id
		// (or be a deletion marker), so mergeManifestDelta can key it.
		if (array_is_list($value) === true) {
			$this->validateEntryList(list: $value, path: $path . '.' . $key, violations: $violations);
			return;
		}

		// Recurse into nested plain objects.
		$this->collectDeltaViolations(node: $value, path: $path . '.' . $key, violations: $violations);

	}//end checkDeltaKey()

	/**
	 * Validate a list of page/widget entries inside a delta.
	 *
	 * Each object entry must carry an `id` (the merge key) unless it is itself
	 * a pure deletion marker (`{ "$op": "remove" }`). Scalar entries are
	 * tolerated (e.g. a bare id in an `__order` list, handled elsewhere).
	 *
	 * @param array<int,mixed> $list The list of entries.
	 * @param string $path The breadcrumb for messages.
	 * @param array<int,string> $violations Accumulator (by reference).
	 *
	 * @return void
	 */
	private function validateEntryList(array $list, string $path, array &$violations): void {
		foreach ($list as $index => $entry) {
			if (is_array($entry) === false) {
				// A non-object entry in a keyed list is not a page/widget entry — skip.
				continue;
			}

			// A pure deletion marker needs no id.
			if (($entry['$op'] ?? null) === self::REMOVE_MARKER) {
				$this->collectDeltaViolations(node: $entry, path: $path . '[' . $index . ']', violations: $violations);
				continue;
			}

			if (isset($entry['id']) === false || is_string($entry['id']) === false || $entry['id'] === '') {
				$violations[] = $path . '[' . $index . '] entry is missing a string "id" (page/widget entries must carry ids for keyed merge)';
			}

			$this->collectDeltaViolations(node: $entry, path: $path . '[' . $index . ']', violations: $violations);
		}//end foreach

	}//end validateEntryList()

	/**
	 * Determine whether a delta branch (e.g. `pages` or `menu`) contributes at
	 * least one renderable entry over an empty base.
	 *
	 * A branch is renderable when it is a non-empty list (or object) carrying
	 * at least one entry that is NOT a pure deletion marker, or any non-empty
	 * scalar. An explicit empty array / null / all-removals branch is not.
	 *
	 * @param mixed $node The branch value from the delta.
	 *
	 * @return bool True when the branch leaves at least one renderable entry.
	 */
	private function branchHasRenderableEntries(mixed $node): bool {
		if ($node === null) {
			return false;
		}

		if (is_array($node) === false) {
			// A non-empty scalar (unusual for pages/menu) counts as "something".
			return true;
		}

		if ($node === []) {
			return false;
		}

		foreach ($node as $key => $entry) {
			// Structural keys do not themselves render.
			if ($key === '__order') {
				continue;
			}

			if (is_array($entry) === true && ($entry['$op'] ?? null) === self::REMOVE_MARKER) {
				// A removal contributes nothing renderable.
				continue;
			}

			// Any surviving entry (object addition/edit or scalar) is renderable.
			return true;
		}//end foreach

		return false;
	}//end branchHasRenderableEntries()
}//end class
