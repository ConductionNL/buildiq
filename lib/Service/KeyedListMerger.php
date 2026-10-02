<?php

/**
 * Keyed List Merger
 *
 * The half of the keyed-delta contract that deals with a LIST whose entries
 * carry an id: tabs and widgets. A patch for one of those is a map keyed by the
 * entry ids, `{"$op": "remove"}` deletes an entry, and `__order` names the order
 * the ids should come back in.
 *
 * It lives beside LayoutDeltaService rather than inside it because the two
 * answer different questions. LayoutDeltaService merges two maps and
 * fingerprints a base; this decides what happens to a list of things that have
 * names. Keeping them apart is also what keeps either of them readable: the
 * list rules alone are six passes over the same data.
 *
 * The map merge is handed in rather than injected, because it is the caller:
 * an entry patch that lands on an entry the base already has is merged with the
 * very service that called in here, and a constructor dependency in both
 * directions is not a dependency, it is a loop.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Merges a patch keyed by entry id into a list of id-bearing entries.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
 */
final class KeyedListMerger {
	/**
	 * Merge a list of id-bearing entries with a patch keyed by those ids.
	 *
	 * @param array<int, mixed> $baseList The base list.
	 * @param array<string, mixed> $patch The patch, keyed by entry id.
	 * @param array<int, string> $order The requested order of ids, or an empty list.
	 * @param LayoutDeltaService $deltas The map merge, for an entry the base already has.
	 *
	 * @return array<int, mixed> The merged list.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
	 */
	public function apply(array $baseList, array $patch, array $order, LayoutDeltaService $deltas): array {
		$indexed = $this->indexBase(baseList: $baseList);
		$patched = $this->applyPatch(
			byId: $indexed['byId'],
			sequence: $indexed['sequence'],
			patch: $patch,
			deltas: $deltas
		);

		$sequence = $this->requestedOrder(
			sequence: $patched['sequence'],
			byId: $patched['byId'],
			order: $order
		);

		return $this->flatten(sequence: $sequence, byId: $patched['byId']);
	}//end apply()

	/**
	 * The requested order inside a keyed patch, if any.
	 *
	 * @param array<string, mixed> $patch The patch.
	 *
	 * @return array<int, string> The ids, in order.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
	 */
	public function orderFor(array $patch): array {
		$order = ($patch[LayoutDeltaService::ORDER_KEY] ?? null);
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
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function looksLikeANewEntry(array $patch): bool {
		return (array_key_exists('kind', $patch) === true || array_key_exists('id', $patch) === true);
	}//end looksLikeANewEntry()

	/**
	 * Index the base list by entry id, keeping the order it came in.
	 *
	 * An entry with no id cannot be patched or reordered, so it is carried
	 * through the sequence whole rather than dropped.
	 *
	 * @param array<int, mixed> $baseList The base list.
	 *
	 * @return array{byId: array<string, mixed>, sequence: array<int, mixed>} The index and the order.
	 */
	private function indexBase(array $baseList): array {
		$byId = [];
		$sequence = [];

		foreach ($baseList as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$id = (string)($entry['id'] ?? '');
			if ($id === '') {
				$sequence[] = $entry;
				continue;
			}

			$byId[$id] = $entry;
			$sequence[] = $id;
		}

		return ['byId' => $byId, 'sequence' => $sequence];
	}//end indexBase()

	/**
	 * Apply the patch to the indexed base.
	 *
	 * @param array<string, mixed> $byId The base entries by id.
	 * @param array<int, mixed> $sequence The order so far.
	 * @param array<string, mixed> $patch The patch, keyed by entry id.
	 * @param LayoutDeltaService $deltas The map merge, for an entry the base already has.
	 *
	 * @return array{byId: array<string, mixed>, sequence: array<int, mixed>} The patched index and order.
	 */
	private function applyPatch(array $byId, array $sequence, array $patch, LayoutDeltaService $deltas): array {
		foreach ($patch as $id => $entryPatch) {
			if ($id === LayoutDeltaService::ORDER_KEY || is_string($id) === false || is_array($entryPatch) === false) {
				continue;
			}

			if (($entryPatch['$op'] ?? null) === LayoutDeltaService::REMOVE_MARKER) {
				unset($byId[$id]);
				continue;
			}

			if (array_key_exists($id, $byId) === true) {
				$byId[$id] = $deltas->merge(base: $byId[$id], delta: $entryPatch);
				continue;
			}

			$entryPatch['id'] = $id;
			$byId[$id] = $entryPatch;
			$sequence[] = $id;
		}

		return ['byId' => $byId, 'sequence' => $sequence];
	}//end applyPatch()

	/**
	 * Put the ids the patch asked for first, then whatever it did not name.
	 *
	 * An order that names an id nothing answers to is ignored rather than
	 * refused: a reorder is not the place to discover a deletion.
	 *
	 * @param array<int, mixed> $sequence The order so far.
	 * @param array<string, mixed> $byId The entries by id.
	 * @param array<int, string> $order The requested order.
	 *
	 * @return array<int, mixed> The order to serve.
	 */
	private function requestedOrder(array $sequence, array $byId, array $order): array {
		if ($order === []) {
			return $sequence;
		}

		$ordered = [];
		foreach ($order as $id) {
			if (array_key_exists($id, $byId) === true) {
				$ordered[] = $id;
			}
		}

		foreach ($sequence as $item) {
			if (is_string($item) === false || array_key_exists($item, $byId) === false) {
				continue;
			}

			if (in_array($item, $ordered, true) === false) {
				$ordered[] = $item;
			}
		}

		return $ordered;
	}//end requestedOrder()

	/**
	 * Turn the sequence back into a list, with anything the patch added and the
	 * sequence did not carry appended.
	 *
	 * @param array<int, mixed> $sequence The order to serve.
	 * @param array<string, mixed> $byId The entries by id.
	 *
	 * @return array<int, mixed> The merged list.
	 */
	private function flatten(array $sequence, array $byId): array {
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

		foreach ($byId as $entry) {
			$out[] = $entry;
		}

		return $out;
	}//end flatten()
}//end class
