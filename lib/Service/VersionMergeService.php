<?php

/**
 * Buildiq VersionMergeService
 *
 * The keyed three-way merge of one application version into another.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Merges a source version into a target version against their common base.
 *
 * The units are pages (by `id`), menu items (by `id`), every other top-level
 * manifest key (by key), and schemas: each property by name, plus the rest of
 * the schema as one unit. A unit changed on one side only is taken from that
 * side; changed on both to the same value is taken once; changed on both to
 * different values, or deleted on one side and changed on the other, is a
 * conflict. Units are compared in canonical form, so key order and
 * formatting are never a change.
 *
 * Nothing here reads or writes storage: the caller passes the three states
 * and saves the result.
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
 */
class VersionMergeService {

	/**
	 * The side a conflict choice takes.
	 */
	public const TAKE_SOURCE = 'source';
	public const TAKE_TARGET = 'target';

	/**
	 * Manifest keys merged item by item, by `id`.
	 */
	private const KEYED_LISTS = ['pages' => 'page', 'menu' => 'menu'];

	/**
	 * The three states of a merge, in the order their units are compared.
	 */
	private const SIDES = ['base', 'source', 'target'];

	/**
	 * Conflict unit to a side, for the merge in progress.
	 *
	 * @var array<string,string>
	 */
	private array $choices = [];

	/**
	 * The conflicts the merge in progress found.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $conflicts = [];

	/**
	 * The common base of two versions: the `branchBase` of whichever was branched from the other.
	 *
	 * @param array<string,mixed> $source The source version's object data (`slug`, optional `branchBase`).
	 * @param array<string,mixed> $target The target version's object data.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>} The base manifest and schemas.
	 *
	 * @throws InvalidArgumentException When neither was branched from the other.
	 *
	 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function baseFor(array $source, array $target): array {
		// The target's base first: a merge moves the target's base, so it is the latest one.
		foreach ([[$target, $source], [$source, $target]] as [$branch, $origin]) {
			$base = ($branch['branchBase'] ?? null);
			if (is_array($base) === true
				&& ($base['source'] ?? null) === ($origin['slug'] ?? null)
				&& is_array($base['manifest'] ?? null) === true
			) {
				$schemas = [];
				if (is_array($base['schemas'] ?? null) === true) {
					$schemas = $base['schemas'];
				}

				return ['manifest' => $base['manifest'], 'schemas' => $schemas];
			}
		}

		throw new InvalidArgumentException(
			sprintf(
				'Versions "%s" and "%s" have no recorded common base: neither was branched from the other.',
				(string)($source['slug'] ?? ''),
				(string)($target['slug'] ?? '')
			)
		);
	}//end baseFor()

	/**
	 * Merge source into target against base.
	 *
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $base   The common base.
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $source The version merged in.
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $target The version merged into.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>, conflicts: array<int,array<string,mixed>>}
	 *         The merged state, with every conflict left at the target's side, and the conflicts.
	 *
	 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function merge(array $base, array $source, array $target): array {
		return $this->resolve(sides: ['base' => $base, 'source' => $source, 'target' => $target], choices: null);
	}//end merge()

	/**
	 * Merge source into target with a side chosen for every conflict.
	 *
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $base    The common base.
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $source  The version merged in.
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $target  The version merged into.
	 * @param array<string,string>                                               $choices Conflict unit to TAKE_SOURCE or TAKE_TARGET.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>, conflicts: array<int,array<string,mixed>>}
	 *         The merged state and the conflicts it resolved.
	 *
	 * @throws InvalidArgumentException When a conflict has no valid choice; nothing is merged.
	 *
	 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function apply(array $base, array $source, array $target, array $choices): array {
		return $this->resolve(sides: ['base' => $base, 'source' => $source, 'target' => $target], choices: $choices);
	}//end apply()

	/**
	 * The merge itself.
	 *
	 * @param array<string,array<string,mixed>> $sides   The `base`, `source` and `target` states.
	 * @param array<string,string>|null         $choices Conflict unit to a side, every conflict needing one;
	 *                                                   null leaves every conflict at the target's side.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>, conflicts: array<int,array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When $choices is given and a conflict has no valid choice.
	 */
	private function resolve(array $sides, ?array $choices): array {
		$this->choices = ($choices ?? []);
		$this->conflicts = [];
		try {
			$manifest = $this->mergeManifest(sides: $this->part(sides: $sides, part: 'manifest'));
			$schemas = $this->mergeSchemas(sides: $this->part(sides: $sides, part: 'schemas'));
			// phpcs:ignore Squiz.Commenting.InlineComment.DocBlock -- psalm narrows the property to [] after the reset above.
			/** @var array<int, array<string, mixed>> $conflicts */
			$conflicts = $this->conflicts;
		} finally {
			$this->choices = [];
			$this->conflicts = [];
		}

		if ($choices !== null) {
			$open = array_values(
				array_filter(
					$conflicts,
					static fn (array $conflict): bool => in_array(($choices[$conflict['unit']] ?? null), [self::TAKE_SOURCE, self::TAKE_TARGET], true) === false
				)
			);
			if ($open !== []) {
				throw new InvalidArgumentException(
					sprintf('Choose a side for every conflict first: %s.', implode(', ', array_column($open, 'unit')))
				);
			}
		}

		return ['manifest' => $manifest, 'schemas' => $schemas, 'conflicts' => $conflicts];
	}//end resolve()

	/**
	 * One part (`manifest` or `schemas`) of each side, as an array.
	 *
	 * @param array<string,array<string,mixed>> $sides The `base`, `source` and `target` states.
	 * @param string                            $part  The part to take.
	 *
	 * @return array<string,array<string,mixed>> That part per side.
	 */
	private function part(array $sides, string $part): array {
		$parts = [];
		foreach (self::SIDES as $side) {
			$parts[$side] = (array)($sides[$side][$part] ?? []);
		}

		return $parts;
	}//end part()

	/**
	 * Merge the manifests: keyed lists item by item, every other key whole.
	 *
	 * @param array<string,array<string,mixed>> $sides The base, source and target manifests.
	 *
	 * @return array<string,mixed> The merged manifest.
	 */
	private function mergeManifest(array $sides): array {
		$merged = [];
		foreach ($this->orderedKeys(sides: $sides) as $key) {
			if (isset(self::KEYED_LISTS[$key]) === false) {
				$merged += $this->mergeUnit(unit: 'setting:' . $key, sides: $sides);
				continue;
			}

			$list = $this->mergeKeyedList(kind: self::KEYED_LISTS[$key], sides: $this->listsById(sides: $sides, key: (string)$key));
			if ($list !== [] || array_key_exists($key, $sides['target']) === true || array_key_exists($key, $sides['source']) === true) {
				$merged[$key] = $list;
			}
		}

		return $merged;
	}//end mergeManifest()

	/**
	 * The list under $key on each side, keyed by item id.
	 *
	 * @param array<string,array<string,mixed>> $sides The base, source and target manifests.
	 * @param string                            $key   The manifest key of the list.
	 *
	 * @return array<string,array<string,mixed>> The items by id per side.
	 */
	private function listsById(array $sides, string $key): array {
		$lists = [];
		foreach (self::SIDES as $side) {
			$lists[$side] = $this->byId(list: ($sides[$side][$key] ?? []));
		}

		return $lists;
	}//end listsById()

	/**
	 * Merge a list of items keyed by `id`, keeping the target's order and appending what the source added.
	 *
	 * @param string                            $kind  `page` or `menu`.
	 * @param array<string,array<string,mixed>> $sides Base, source and target items by id.
	 *
	 * @return array<int,mixed> The merged list.
	 */
	private function mergeKeyedList(string $kind, array $sides): array {
		$merged = [];
		foreach ($this->orderedKeys(sides: $sides) as $id) {
			$merged += $this->mergeUnit(unit: $kind . ':' . $id, sides: $sides);
		}

		return array_values($merged);
	}//end mergeKeyedList()

	/**
	 * Merge the schemas: each property by name, and the rest of each schema as one unit.
	 *
	 * @param array<string,array<string,mixed>> $sides Base, source and target schemas by slug.
	 *
	 * @return array<string,mixed> The merged schemas by slug.
	 */
	private function mergeSchemas(array $sides): array {
		$merged = [];
		foreach ($this->orderedKeys(sides: $sides) as $slug) {
			$split = [];
			foreach (self::SIDES as $side) {
				$split[$side] = $this->splitSchema(schemas: $sides[$side], slug: (string)$slug);
			}

			// Created or deleted on a side: the schema is one unit, so deleting it
			// against a change to any of its properties is a conflict.
			if (in_array(null, $split, true) === true) {
				$merged += $this->mergeUnit(unit: 'schema:' . $slug, sides: $sides);
				continue;
			}

			$schema = $this->mergeSchema(slug: (string)$slug, split: $split);
			if ($schema !== null) {
				$merged[$slug] = $schema;
			}
		}

		return $merged;
	}//end mergeSchemas()

	/**
	 * Merge one schema present on every side: its settings are one unit, each property another.
	 *
	 * @param string                                                           $slug  The schema slug.
	 * @param array<string,array{meta: array<string,mixed>, properties: array}> $split Each side's settings and properties.
	 *
	 * @return array<string,mixed>|null The merged schema, or null when its settings were deleted.
	 */
	private function mergeSchema(string $slug, array $split): ?array {
		$meta = [];
		$qualified = [];
		foreach (self::SIDES as $side) {
			$meta[$side] = [$slug => $split[$side]['meta']];
			$qualified[$side] = [];
			foreach ($split[$side]['properties'] as $name => $definition) {
				$qualified[$side][$slug . '.' . $name] = $definition;
			}
		}

		$merged = $this->mergeUnit(unit: 'schema:' . $slug, sides: $meta);
		if (array_key_exists($slug, $merged) === false) {
			return null;
		}

		$schema = $merged[$slug];
		$schema['properties'] = [];
		foreach ($this->orderedKeys(sides: $qualified) as $name) {
			foreach ($this->mergeUnit(unit: 'property:' . $name, sides: $qualified) as $definition) {
				$schema['properties'][substr((string)$name, strlen($slug) + 1)] = $definition;
			}
		}

		return $schema;
	}//end mergeSchema()

	/**
	 * One side's schema split into its settings and its properties, or null when absent.
	 *
	 * @param array<string,mixed> $schemas The side's schemas by slug.
	 * @param string              $slug    The schema slug.
	 *
	 * @return array{meta: array<string,mixed>, properties: array}|null The split schema.
	 */
	private function splitSchema(array $schemas, string $slug): ?array {
		if (array_key_exists($slug, $schemas) === false || is_array($schemas[$slug]) === false) {
			return null;
		}

		$schema = $schemas[$slug];
		$properties = [];
		if (is_array($schema['properties'] ?? null) === true) {
			$properties = $schema['properties'];
		}

		unset($schema['properties']);
		return ['meta' => $schema, 'properties' => $properties];
	}//end splitSchema()

	/**
	 * Merge one unit, present or absent on each side.
	 *
	 * The unit id is `<kind>:<key>`, e.g. `page:permits`; $key is its key in the
	 * three maps. A conflict is recorded in $this->conflicts and resolved by
	 * $this->choices, the target's side when there is no choice.
	 *
	 * @param string                            $unit  The unit id.
	 * @param array<string,array<string,mixed>> $sides The base, source and target maps.
	 *
	 * @return array<string,mixed> The merged value under its key, or empty when deleted.
	 */
	private function mergeUnit(string $unit, array $sides): array {
		[$kind, $key] = explode(':', $unit, 2);
		$forms = [];
		foreach (self::SIDES as $side) {
			$forms[$side] = $this->canonical(present: array_key_exists($key, $sides[$side]), value: ($sides[$side][$key] ?? null));
		}

		// Same on both sides, or only the target changed it: the target. Only the source changed it: the source.
		$take = 'target';
		if ($forms['source'] !== $forms['target'] && $forms['source'] !== $forms['base']) {
			if ($forms['target'] === $forms['base']) {
				$take = 'source';
			} else {
				$this->conflicts[] = [
					'unit' => $unit,
					'kind' => $kind,
					'key' => $key,
					'base' => ($sides['base'][$key] ?? null),
					'source' => ($sides['source'][$key] ?? null),
					'target' => ($sides['target'][$key] ?? null),
				];
				if (($this->choices[$unit] ?? null) === self::TAKE_SOURCE) {
					$take = 'source';
				}
			}
		}

		if (array_key_exists($key, $sides[$take]) === false) {
			return [];
		}

		return [$key => $sides[$take][$key]];
	}//end mergeUnit()

	/**
	 * A value's canonical form, or a marker for "absent".
	 *
	 * The same form `ApplicationVersionService::canonicaliseManifest()` gives:
	 * object keys sorted at every depth, list order kept.
	 *
	 * @param bool  $present Whether the unit exists on this side.
	 * @param mixed $value   Its value.
	 *
	 * @return string The canonical JSON, or `absent`.
	 */
	private function canonical(bool $present, mixed $value): string {
		if ($present === false) {
			return 'absent';
		}

		return (string)json_encode(
			$this->sorted(value: $value),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}//end canonical()

	/**
	 * Sort object keys at every depth, keeping list order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value with sorted keys.
	 */
	private function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->sorted(value: $item), $value);
		}

		ksort($value);
		return array_map(fn (mixed $item): mixed => $this->sorted(value: $item), $value);
	}//end sorted()

	/**
	 * A list of items keyed by their `id`; an item without one keeps its position as key.
	 *
	 * @param mixed $list The list.
	 *
	 * @return array<string,mixed> The items by id.
	 */
	private function byId(mixed $list): array {
		if (is_array($list) === false) {
			return [];
		}

		$keyed = [];
		foreach ($list as $position => $item) {
			$id = '#' . $position;
			if (is_array($item) === true && is_scalar($item['id'] ?? null) === true) {
				$id = (string)$item['id'];
			}

			$keyed[$id] = $item;
		}

		return $keyed;
	}//end byId()

	/**
	 * Every key of the target, then the source, then the base map, once.
	 *
	 * @param array<string,array<string|int,mixed>> $sides The base, source and target maps.
	 *
	 * @return array<int,string|int> The keys in that order.
	 */
	private function orderedKeys(array $sides): array {
		return array_values(
			array_unique(
				array_merge(array_keys($sides['target']), array_keys($sides['source']), array_keys($sides['base'])),
				SORT_REGULAR
			)
		);
	}//end orderedKeys()
}//end class
