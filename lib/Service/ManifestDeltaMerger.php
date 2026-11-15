<?php

/**
 * Buildiq ManifestDeltaMerger
 *
 * The server-side twin of `@conduction/nextcloud-vue`'s `mergeManifestDelta`:
 * a keyed structural merge of a manifest delta over a base manifest. Plain
 * objects merge recursively; `pages[]`, `widgets[]`, `menu[]` and `children[]`
 * merge by `id` and `defaultSavedViews[]` by `slug`; a `{"$op":"remove"}`
 * entry deletes its match; an `__order` map reorders a keyed array; every
 * other array is replaced. A delta entry that removes something the base does
 * not hold is skipped and its path reported, never fatal. The JS util is the
 * contract; `tests/fixtures/manifest-delta-merge.json` holds its own output
 * and both implementations are tested against it (app-delta-override 0.2,
 * 2.1 to 2.3).
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Applies a keyed manifest delta to a base manifest.
 *
 * Pure and stateless: the inputs are never changed.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
 */
class ManifestDeltaMerger {

	/**
	 * The arrays that merge by key, and the field that identifies their entries.
	 *
	 * @var array<string, string>
	 */
	public const KEYED_ARRAYS = [
		'pages' => 'id',
		'widgets' => 'id',
		'menu' => 'id',
		'children' => 'id',
		'defaultSavedViews' => 'slug',
	];

	/**
	 * The marker value that deletes a keyed entry.
	 *
	 * @var string
	 */
	public const REMOVE = 'remove';

	private const ORDER_KEY = '__order';

	private const OP_KEY = '$op';

	/**
	 * Constructor.
	 *
	 * @param ManifestDeltaShape $shape How JSON shapes are read from PHP arrays.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ManifestDeltaShape $shape=new ManifestDeltaShape(),
	) {
	}//end __construct()

	/**
	 * Merge a delta over a base manifest.
	 *
	 * @param array<string, mixed> $base  The base manifest.
	 * @param array<string, mixed> $delta The delta.
	 *
	 * @return array{manifest: mixed, orphanedDeltaPaths: list<string>} The merged manifest and the
	 *                                                                   paths of delta entries that
	 *                                                                   targeted nothing in the base.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.2
	 */
	public function merge(array $base, array $delta): array {
		$orphans = [];
		$manifest = $this->mergeValue(base: $base, delta: $delta, path: '', orphans: $orphans);

		return ['manifest' => $manifest, 'orphanedDeltaPaths' => $orphans];
	}//end merge()

	/**
	 * Merge one delta value onto one base value at a path.
	 *
	 * @param mixed        $base    The base value.
	 * @param mixed        $delta   The delta value; it wins wherever the two cannot merge.
	 * @param string       $path    Where this is, for orphan reporting.
	 * @param list<string> $orphans The orphan paths found so far.
	 *
	 * @return mixed The merged value.
	 */
	private function mergeValue(mixed $base, mixed $delta, string $path, array &$orphans): mixed {
		if ($this->shape->isObject(value: $base) === false || $this->shape->isObject(value: $delta) === false) {
			return $delta;
		}

		$out = $base;
		foreach ($delta as $key => $deltaChild) {
			if ((string)$key === self::ORDER_KEY) {
				continue;
			}

			$out[$key] = $this->mergeChild(
				key: (string)$key,
				base: ($base[$key] ?? null),
				delta: $deltaChild,
				path: $this->shape->childPath(path: $path, key: (string)$key),
				orphans: $orphans
			);
		}

		// The order applies last, so a reorder-only delta still reorders the base array.
		return $this->applyOrderMap(out: $out, orderMap: ($delta[self::ORDER_KEY] ?? null));
	}//end mergeValue()

	/**
	 * Merge one key of an object: a keyed array by key, an object recursively, anything else replaced.
	 *
	 * @param string       $key     The key.
	 * @param mixed        $base    The base's value under it.
	 * @param mixed        $delta   The delta's value under it.
	 * @param string       $path    Where this is, for orphan reporting.
	 * @param list<string> $orphans The orphan paths found so far.
	 *
	 * @return mixed The merged value.
	 */
	private function mergeChild(string $key, mixed $base, mixed $delta, string $path, array &$orphans): mixed {
		$keyField = (self::KEYED_ARRAYS[$key] ?? null);
		if ($keyField !== null && $this->shape->isList(value: $delta) === true && $this->shape->isList(value: $base) === true) {
			return $this->mergeKeyedArray(base: $base, delta: $delta, keyField: $keyField, path: $path, orphans: $orphans);
		}

		if ($this->shape->isObject(value: $delta) === true && $this->shape->isObject(value: $base) === true) {
			return $this->mergeValue(base: $base, delta: $delta, path: $path, orphans: $orphans);
		}

		return $delta;
	}//end mergeChild()

	/**
	 * Reorder the keyed arrays an `__order` map names.
	 *
	 * @param array<string, mixed> $out      The merged object.
	 * @param mixed                $orderMap The delta's `__order` value.
	 *
	 * @return array<string, mixed> The object with its arrays reordered.
	 */
	private function applyOrderMap(array $out, mixed $orderMap): array {
		if ($this->shape->isObject(value: $orderMap) === false) {
			return $out;
		}

		foreach ($orderMap as $arrayKey => $sequence) {
			$keyField = (self::KEYED_ARRAYS[(string)$arrayKey] ?? null);
			if ($keyField !== null && $this->shape->isList(value: $sequence) === true && $this->shape->isList(value: ($out[$arrayKey] ?? null)) === true) {
				$out[$arrayKey] = $this->applyOrder(entries: $out[$arrayKey], order: $sequence, keyField: $keyField);
			}
		}

		return $out;
	}//end applyOrderMap()

	/**
	 * Merge two lists of keyed entries: patch a match, append a new key, remove on the marker.
	 *
	 * @param list<mixed>  $base     The base entries.
	 * @param list<mixed>  $delta    The delta entries.
	 * @param string       $keyField The field that identifies an entry.
	 * @param string       $path     Where this list is, for orphan reporting.
	 * @param list<string> $orphans  The orphan paths found so far.
	 *
	 * @return list<mixed> The merged entries.
	 */
	private function mergeKeyedArray(array $base, array $delta, string $keyField, string $path, array &$orphans): array {
		$merged = $base;
		$indexByKey = $this->indexByKey(entries: $merged, keyField: $keyField);
		$removed = [];
		foreach ($delta as $deltaEntry) {
			if ($this->shape->isObject(value: $deltaEntry) === false) {
				continue;
			}

			$key = ($deltaEntry[$keyField] ?? null);
			$index = $indexByKey[(string)$this->shape->lookupOf(value: $key)] ?? null;
			$entryPath = $path . '/' . $this->shape->pathPart(value: $key);

			if (($deltaEntry[self::OP_KEY] ?? null) === self::REMOVE) {
				$this->markRemoved(index: $index, removed: $removed, orphans: $orphans, entryPath: $entryPath);
				continue;
			}

			if ($index !== null) {
				unset($deltaEntry[self::OP_KEY]);
				$merged[$index] = $this->mergeValue(base: $merged[$index], delta: $deltaEntry, path: $entryPath, orphans: $orphans);
				continue;
			}

			unset($deltaEntry[self::OP_KEY], $deltaEntry[self::ORDER_KEY]);
			$merged[] = $deltaEntry;
		}//end foreach

		return array_values(array_diff_key($merged, $removed));
	}//end mergeKeyedArray()

	/**
	 * Mark a matched entry removed, or record the removal as an orphan when nothing matched.
	 *
	 * @param int|null          $index     The matched entry's position, or null.
	 * @param array<int, true>  $removed   The positions removed so far.
	 * @param list<string>      $orphans   The orphan paths found so far.
	 * @param string            $entryPath The delta entry's path.
	 *
	 * @return void
	 */
	private function markRemoved(?int $index, array &$removed, array &$orphans, string $entryPath): void {
		if ($index === null) {
			$orphans[] = $entryPath;
			return;
		}

		$removed[$index] = true;
	}//end markRemoved()

	/**
	 * The position of each keyed entry, by lookup key.
	 *
	 * @param list<mixed> $entries  The entries.
	 * @param string      $keyField The field that identifies an entry.
	 *
	 * @return array<string, int> Positions by lookup key.
	 */
	private function indexByKey(array $entries, string $keyField): array {
		$index = [];
		foreach ($entries as $position => $entry) {
			if ($this->shape->isObject(value: $entry) === true && array_key_exists($keyField, $entry) === true) {
				$lookup = $this->shape->lookupOf(value: $entry[$keyField]);
				if ($lookup !== null) {
					$index[$lookup] = $position;
				}
			}
		}

		return $index;
	}//end indexByKey()

	/**
	 * Put entries in the given key order; entries not named keep their relative order after them.
	 *
	 * @param list<mixed> $entries  The entries.
	 * @param list<mixed> $order    The wanted key sequence.
	 * @param string      $keyField The field that identifies an entry.
	 *
	 * @return list<mixed> The reordered entries.
	 */
	private function applyOrder(array $entries, array $order, string $keyField): array {
		$byKey = $this->firstByKey(entries: $entries, keyField: $keyField);

		$result = [];
		$used = [];
		foreach ($order as $key) {
			$lookup = $this->shape->lookupOf(value: $key);
			if ($lookup !== null && array_key_exists($lookup, $byKey) === true && isset($used[$lookup]) === false) {
				$result[] = $byKey[$lookup];
				$used[$lookup] = true;
			}
		}

		foreach ($entries as $entry) {
			$lookup = $this->shape->lookupOf(value: $this->shape->fieldOf(entry: $entry, keyField: $keyField));
			if ($lookup === null || isset($used[$lookup]) === false) {
				$result[] = $entry;
			}
		}

		return $result;
	}//end applyOrder()

	/**
	 * The first entry for each lookup key.
	 *
	 * @param list<mixed> $entries  The entries.
	 * @param string      $keyField The field that identifies an entry.
	 *
	 * @return array<string, mixed> Entries by lookup key.
	 */
	private function firstByKey(array $entries, string $keyField): array {
		$byKey = [];
		foreach ($entries as $entry) {
			$lookup = $this->shape->lookupOf(value: $this->shape->fieldOf(entry: $entry, keyField: $keyField));
			if ($lookup !== null) {
				$byKey[$lookup] ??= $entry;
			}
		}

		return $byKey;
	}//end firstByKey()
}//end class
