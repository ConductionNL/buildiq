<?php

/**
 * Layout Delta Service
 *
 * The PHP side of the keyed-delta contract `@conduction/nextcloud-vue` already
 * implements in JavaScript for app overrides: a patch is a map keyed by id, a
 * deletion is `{"$op": "remove"}`, and a reorder is `__order`. There is
 * deliberately no second merge algorithm here. The editor cuts the delta with
 * `diffManifest` in the browser; this applies the same shape on the server, so
 * the two halves cannot drift apart into two subtly different notions of what a
 * patch means.
 *
 * WHY A PATCH AND NOT A COPY
 * --------------------------
 * An override that stored a copy of the layout it extends is correct on the day
 * it is saved and wrong every day after: a fifth tab added to the base never
 * reaches it, and nobody can see that from the override. A patch that names
 * only what it changes inherits everything it did not mention, which is the
 * whole reason a fall-through exists.
 *
 * WHY THE FINGERPRINT IS OVER THE WHOLE BASE
 * -------------------------------------------
 * Checking that every patch still finds its key is not enough. A base can move
 * in ways a key match cannot see: a tab that kept its id and changed its kind,
 * a widget that kept its id and changed what it reads. The fingerprint is over
 * the normalised base, so any of those is drift.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001, REQ-OBSO-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Applies and cuts keyed layout deltas, and fingerprints a base.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
 */
final class LayoutDeltaService {
	/**
	 * The one deletion marker the contract knows.
	 *
	 * @var string
	 */
	public const REMOVE_MARKER = 'remove';

	/**
	 * The key that carries a reordering.
	 *
	 * @var string
	 */
	public const ORDER_KEY = '__order';

	/**
	 * The properties of a layout whose lists are keyed by entry id rather than
	 * replaced wholesale. Only the lists whose entries CARRY an id: a list keyed
	 * by an id its entries do not have would merge two lists into one long one
	 * and look like a patch that did nothing.
	 *
	 * @var array<int, string>
	 */
	public const KEYED_LISTS = ['tabs', 'widgets'];

	/**
	 * Apply a delta to a base.
	 *
	 * @param array<string, mixed> $base The layout being patched.
	 * @param array<string, mixed> $delta The patch.
	 *
	 * @return array<string, mixed> The result.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
	 */
	public function merge(array $base, array $delta): array {
		$result = $base;

		foreach ($delta as $key => $value) {
			if ($key === self::ORDER_KEY) {
				continue;
			}

			if (in_array($key, self::KEYED_LISTS, true) === true && is_array($value) === true) {
				if (array_is_list($value) === true) {
					// A LIST, not a keyed patch. That is a whole layer handing over
					// its own tabs rather than patching somebody else's, and it
					// replaces. Running it through the keyed merge would skip every
					// integer key and silently leave the layer beneath it in place,
					// which reads as a layout that did nothing.
					$result[$key] = $value;
					continue;
				}

				$baseList = [];
				if (is_array($base[$key] ?? null) === true) {
					$baseList = $base[$key];
				}

				$result[$key] = $this->mergeKeyedList(
					baseList: $baseList,
					patch: $value,
					order: $this->orderFor(patch: $value)
				);
				continue;
			}

			if (is_array($value) === true
				&& $this->isMap(value: $value) === true
				&& is_array($base[$key] ?? null) === true
				&& $this->isMap(value: $base[$key]) === true
			) {
				if (($value['$op'] ?? null) === self::REMOVE_MARKER) {
					unset($result[$key]);
					continue;
				}

				$result[$key] = $this->merge(base: $base[$key], delta: $value);
				continue;
			}

			if (is_array($value) === true && ($value['$op'] ?? null) === self::REMOVE_MARKER) {
				unset($result[$key]);
				continue;
			}

			$result[$key] = $value;
		}

		return $result;
	}//end merge()

	/**
	 * Which paths of a delta find nothing in the base.
	 *
	 * Used to say WHICH parts of a withheld override no longer apply. A
	 * maintainer told only that something drifted has to diff two layouts by
	 * hand to find out what.
	 *
	 * @param array<string, mixed> $base The base.
	 * @param array<string, mixed> $delta The patch.
	 *
	 * @return array<int, string> The orphaned paths.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function orphanedPaths(array $base, array $delta): array {
		$orphans = [];

		foreach ($delta as $key => $value) {
			if ($key === self::ORDER_KEY || in_array($key, self::KEYED_LISTS, true) === false) {
				continue;
			}

			if (is_array($value) === false) {
				continue;
			}

			$baseEntries = [];
			if (is_array($base[$key] ?? null) === true) {
				$baseEntries = $base[$key];
			}

			$existing = [];
			foreach ($baseEntries as $entry) {
				if (is_array($entry) === true && (string)($entry['id'] ?? '') !== '') {
					$existing[] = (string)$entry['id'];
				}
			}

			foreach ($value as $id => $patch) {
				if ($id === self::ORDER_KEY || is_string($id) === false) {
					continue;
				}

				// A patch that ADDS an entry is not an orphan: it names an id the
				// base does not have on purpose.
				if (is_array($patch) === true && ($patch['$op'] ?? null) !== self::REMOVE_MARKER
					&& $this->looksLikeANewEntry(patch: $patch) === true
				) {
					continue;
				}

				if (in_array($id, $existing, true) === false) {
					$orphans[] = $key . '.' . $id;
				}
			}
		}

		return $orphans;
	}//end orphanedPaths()

	/**
	 * A stable hash of a base layout, over the parts an override can patch.
	 *
	 * Normalised first: key order in a stored object is not meaningful, and a
	 * fingerprint that changed when a store reordered its keys would report
	 * drift on every layout that was never touched.
	 *
	 * @param array<string, mixed> $base The base layout.
	 *
	 * @return string The fingerprint.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function fingerprint(array $base): string {
		$patchable = [];
		foreach (['header', 'tabs', 'widgets', 'taskList', 'uploadFields'] as $key) {
			if (array_key_exists($key, $base) === true) {
				$patchable[$key] = $base[$key];
			}
		}

		return hash('sha256', (string)json_encode($this->normalise(value: $patchable)));
	}//end fingerprint()

	/**
	 * Whether a stored fingerprint still describes the base in hand.
	 *
	 * An override with no fingerprint at all is NOT treated as current. It was
	 * written by something that did not pin its base, and the honest answer is
	 * that nobody knows what it was cut against.
	 *
	 * @param array<string, mixed> $base The base layout.
	 * @param string $storedFingerprint The fingerprint the override recorded.
	 *
	 * @return bool True when the base is unchanged.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function isCurrent(array $base, string $storedFingerprint): bool {
		if ($storedFingerprint === '') {
			return false;
		}

		return ($this->fingerprint(base: $base) === $storedFingerprint);
	}//end isCurrent()

	/**
	 * Merge a list of id-bearing entries with a patch keyed by those ids.
	 *
	 * @param array<int, mixed> $baseList The base list.
	 * @param array<string, mixed> $patch The patch, keyed by entry id.
	 * @param array<int, string> $order The requested order of ids, or an empty list.
	 *
	 * @return array<int, mixed> The merged list.
	 */
	private function mergeKeyedList(array $baseList, array $patch, array $order): array {
		$byId = [];
		$sequence = [];
		foreach ($baseList as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$id = (string)($entry['id'] ?? '');
			if ($id === '') {
				// An entry with no id cannot be patched or reordered, so it is
				// carried through untouched rather than dropped.
				$sequence[] = $entry;
				continue;
			}

			$byId[$id] = $entry;
			$sequence[] = $id;
		}

		foreach ($patch as $id => $entryPatch) {
			if ($id === self::ORDER_KEY || is_string($id) === false || is_array($entryPatch) === false) {
				continue;
			}

			if (($entryPatch['$op'] ?? null) === self::REMOVE_MARKER) {
				unset($byId[$id]);
				continue;
			}

			if (array_key_exists($id, $byId) === true) {
				$byId[$id] = $this->merge(base: $byId[$id], delta: $entryPatch);
				continue;
			}

			$entryPatch['id'] = $id;
			$byId[$id] = $entryPatch;
			$sequence[] = $id;
		}

		if ($order !== []) {
			$ordered = [];
			foreach ($order as $id) {
				if (array_key_exists($id, $byId) === true) {
					$ordered[] = $id;
				}
			}

			foreach ($sequence as $item) {
				if (is_string($item) === true && in_array($item, $ordered, true) === false && array_key_exists($item, $byId) === true) {
					$ordered[] = $item;
				}
			}

			$sequence = $ordered;
		}

		$out = [];
		foreach ($sequence as $item) {
			if (is_array($item) === true) {
				$out[] = $item;
				continue;
			}

			if (array_key_exists($item, $byId) === true) {
				$out[] = $byId[$item];
				unset($byId[$item]);
			}
		}

		// Anything added by the patch that the sequence did not already carry.
		foreach ($byId as $entry) {
			$out[] = $entry;
		}

		return $out;
	}//end mergeKeyedList()

	/**
	 * The requested order inside a keyed patch, if any.
	 *
	 * @param array<string, mixed> $patch The patch.
	 *
	 * @return array<int, string> The ids, in order.
	 */
	private function orderFor(array $patch): array {
		$order = ($patch[self::ORDER_KEY] ?? null);
		if (is_array($order) === false) {
			return [];
		}

		$ids = [];
		foreach ($order as $id) {
			if (is_string($id) === true && $id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end orderFor()

	/**
	 * Whether a patch entry looks like a whole new entry rather than a patch of
	 * an existing one.
	 *
	 * @param array<string, mixed> $patch The entry patch.
	 *
	 * @return bool True when it carries enough to stand on its own.
	 */
	private function looksLikeANewEntry(array $patch): bool {
		return (array_key_exists('kind', $patch) === true || array_key_exists('id', $patch) === true);
	}//end looksLikeANewEntry()

	/**
	 * Whether an array is a map rather than a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when it is a map.
	 */
	private function isMap(mixed $value): bool {
		return (is_array($value) === true && ($value === [] || array_is_list($value) === false));
	}//end isMap()

	/**
	 * Sort a structure's keys recursively, so a reordered store does not read as
	 * a changed base.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The normalised value.
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return array_map(fn (mixed $entry): mixed => $this->normalise(value: $entry), $value);
		}

		ksort($value);

		$out = [];
		foreach ($value as $key => $entry) {
			$out[$key] = $this->normalise(value: $entry);
		}

		return $out;
	}//end normalise()
}//end class
