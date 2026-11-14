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
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
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
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
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
	 * The common base of two versions: the `branchBase` of whichever was branched from the other.
	 *
	 * @param array<string,mixed> $source The source version's object data (`slug`, optional `branchBase`).
	 * @param array<string,mixed> $target The target version's object data.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>} The base manifest and schemas.
	 *
	 * @throws InvalidArgumentException When neither was branched from the other.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
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
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function merge(array $base, array $source, array $target): array {
		return $this->resolve(base: $base, source: $source, target: $target, choices: [], requireAll: false);
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
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function apply(array $base, array $source, array $target, array $choices): array {
		return $this->resolve(base: $base, source: $source, target: $target, choices: $choices, requireAll: true);
	}//end apply()

	/**
	 * The merge itself.
	 *
	 * @param array<string,mixed>  $base       The common base.
	 * @param array<string,mixed>  $source     The version merged in.
	 * @param array<string,mixed>  $target     The version merged into.
	 * @param array<string,string> $choices    Conflict unit to a side.
	 * @param bool                 $requireAll Whether every conflict must have a choice.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>, conflicts: array<int,array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When $requireAll and a conflict has no valid choice.
	 */
	private function resolve(array $base, array $source, array $target, array $choices, bool $requireAll): array {
		$conflicts = [];
		$manifest = $this->mergeManifest(
			base: (array)($base['manifest'] ?? []),
			source: (array)($source['manifest'] ?? []),
			target: (array)($target['manifest'] ?? []),
			choices: $choices,
			conflicts: $conflicts
		);
		$schemas = $this->mergeSchemas(
			base: (array)($base['schemas'] ?? []),
			source: (array)($source['schemas'] ?? []),
			target: (array)($target['schemas'] ?? []),
			choices: $choices,
			conflicts: $conflicts
		);

		if ($requireAll === true) {
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
	 * Merge the manifests: keyed lists item by item, every other key whole.
	 *
	 * @param array<string,mixed>              $base      The base manifest.
	 * @param array<string,mixed>              $source    The source manifest.
	 * @param array<string,mixed>              $target    The target manifest.
	 * @param array<string,string>             $choices   Conflict unit to a side.
	 * @param array<int,array<string,mixed>>   $conflicts Collects the conflicts.
	 *
	 * @return array<string,mixed> The merged manifest.
	 */
	private function mergeManifest(array $base, array $source, array $target, array $choices, array &$conflicts): array {
		$merged = [];
		foreach ($this->orderedKeys(target: $target, source: $source, base: $base) as $key) {
			if (isset(self::KEYED_LISTS[$key]) === true) {
				$list = $this->mergeKeyedList(
					kind: self::KEYED_LISTS[$key],
					base: $this->byId(list: ($base[$key] ?? [])),
					source: $this->byId(list: ($source[$key] ?? [])),
					target: $this->byId(list: ($target[$key] ?? [])),
					choices: $choices,
					conflicts: $conflicts
				);
				if ($list !== [] || array_key_exists($key, $target) === true || array_key_exists($key, $source) === true) {
					$merged[$key] = $list;
				}

				continue;
			}

			$this->mergeUnit(
				unit: 'setting:' . $key,
				kind: 'setting',
				key: (string)$key,
				base: $base,
				source: $source,
				target: $target,
				choices: $choices,
				conflicts: $conflicts,
				into: $merged
			);
		}//end foreach

		return $merged;
	}//end mergeManifest()

	/**
	 * Merge a list of items keyed by `id`, keeping the target's order and appending what the source added.
	 *
	 * @param string                         $kind      `page` or `menu`.
	 * @param array<string,mixed>            $base      Base items by id.
	 * @param array<string,mixed>            $source    Source items by id.
	 * @param array<string,mixed>            $target    Target items by id.
	 * @param array<string,string>           $choices   Conflict unit to a side.
	 * @param array<int,array<string,mixed>> $conflicts Collects the conflicts.
	 *
	 * @return array<int,mixed> The merged list.
	 */
	private function mergeKeyedList(string $kind, array $base, array $source, array $target, array $choices, array &$conflicts): array {
		$merged = [];
		foreach ($this->orderedKeys(target: $target, source: $source, base: $base) as $id) {
			$this->mergeUnit(
				unit: $kind . ':' . $id,
				kind: $kind,
				key: (string)$id,
				base: $base,
				source: $source,
				target: $target,
				choices: $choices,
				conflicts: $conflicts,
				into: $merged
			);
		}

		return array_values($merged);
	}//end mergeKeyedList()

	/**
	 * Merge the schemas: each property by name, and the rest of each schema as one unit.
	 *
	 * @param array<string,mixed>            $base      Base schemas by slug.
	 * @param array<string,mixed>            $source    Source schemas by slug.
	 * @param array<string,mixed>            $target    Target schemas by slug.
	 * @param array<string,string>           $choices   Conflict unit to a side.
	 * @param array<int,array<string,mixed>> $conflicts Collects the conflicts.
	 *
	 * @return array<string,mixed> The merged schemas by slug.
	 */
	private function mergeSchemas(array $base, array $source, array $target, array $choices, array &$conflicts): array {
		$merged = [];
		foreach ($this->orderedKeys(target: $target, source: $source, base: $base) as $slug) {
			$sides = [];
			foreach (['base' => $base, 'source' => $source, 'target' => $target] as $side => $schemas) {
				$sides[$side] = null;
				if (array_key_exists($slug, $schemas) === true && is_array($schemas[$slug]) === true) {
					$schema = $schemas[$slug];
					$properties = [];
					if (is_array($schema['properties'] ?? null) === true) {
						$properties = $schema['properties'];
					}

					unset($schema['properties']);
					$sides[$side] = ['meta' => $schema, 'properties' => $properties];
				}
			}

			// Created or deleted on a side: the schema is one unit, so deleting it
			// against a change to any of its properties is a conflict.
			if (in_array(null, $sides, true) === true) {
				$this->mergeUnit(
					unit: 'schema:' . $slug,
					kind: 'schema',
					key: (string)$slug,
					base: $base,
					source: $source,
					target: $target,
					choices: $choices,
					conflicts: $conflicts,
					into: $merged
				);
				continue;
			}

			// Present on every side: its settings are one unit, each property another.
			$meta = [];
			$this->mergeUnit(
				unit: 'schema:' . $slug,
				kind: 'schema',
				key: (string)$slug,
				base: $this->column(sides: $sides['base'], part: 'meta', slug: (string)$slug),
				source: $this->column(sides: $sides['source'], part: 'meta', slug: (string)$slug),
				target: $this->column(sides: $sides['target'], part: 'meta', slug: (string)$slug),
				choices: $choices,
				conflicts: $conflicts,
				into: $meta
			);
			if (array_key_exists($slug, $meta) === false) {
				continue;
			}

			$properties = [];
			foreach ($this->orderedKeys(
				target: $sides['target']['properties'],
				source: $sides['source']['properties'],
				base: $sides['base']['properties']
			) as $name) {
				$this->mergeUnit(
					unit: 'property:' . $slug . '.' . $name,
					kind: 'property',
					key: $slug . '.' . $name,
					base: $this->renamed(values: $sides['base']['properties'], from: (string)$name, to: $slug . '.' . $name),
					source: $this->renamed(values: $sides['source']['properties'], from: (string)$name, to: $slug . '.' . $name),
					target: $this->renamed(values: $sides['target']['properties'], from: (string)$name, to: $slug . '.' . $name),
					choices: $choices,
					conflicts: $conflicts,
					into: $properties
				);
			}

			$schema = $meta[$slug];
			$schema['properties'] = [];
			foreach ($properties as $qualified => $definition) {
				$schema['properties'][substr((string)$qualified, strlen((string)$slug) + 1)] = $definition;
			}

			$merged[$slug] = $schema;
		}//end foreach

		return $merged;
	}//end mergeSchemas()

	/**
	 * Merge one unit, present or absent on each side, into $into.
	 *
	 * @param string                         $unit      The unit id, e.g. `page:permits`.
	 * @param string                         $kind      The unit kind.
	 * @param string                         $key       The unit's key in the three maps.
	 * @param array<string,mixed>            $base      Base map.
	 * @param array<string,mixed>            $source    Source map.
	 * @param array<string,mixed>            $target    Target map.
	 * @param array<string,string>           $choices   Conflict unit to a side.
	 * @param array<int,array<string,mixed>> $conflicts Collects the conflicts.
	 * @param array<string,mixed>            $into      Receives the merged value under $key, or nothing when deleted.
	 *
	 * @return void
	 */
	private function mergeUnit(
		string $unit,
		string $kind,
		string $key,
		array $base,
		array $source,
		array $target,
		array $choices,
		array &$conflicts,
		array &$into
	): void {
		$inBase = array_key_exists($key, $base);
		$inSource = array_key_exists($key, $source);
		$inTarget = array_key_exists($key, $target);
		$baseForm = $this->canonical(present: $inBase, value: ($base[$key] ?? null));
		$sourceForm = $this->canonical(present: $inSource, value: ($source[$key] ?? null));
		$targetForm = $this->canonical(present: $inTarget, value: ($target[$key] ?? null));

		$takeSource = false;
		if ($sourceForm === $targetForm || $sourceForm === $baseForm) {
			// Same on both sides, or only the target changed it.
			$takeSource = false;
		} else if ($targetForm === $baseForm) {
			// Only the source changed it.
			$takeSource = true;
		} else {
			$conflicts[] = [
				'unit' => $unit,
				'kind' => $kind,
				'key' => $key,
				'base' => ($base[$key] ?? null),
				'source' => ($source[$key] ?? null),
				'target' => ($target[$key] ?? null),
			];
			$takeSource = (($choices[$unit] ?? null) === self::TAKE_SOURCE);
		}

		if ($takeSource === true) {
			if ($inSource === true) {
				$into[$key] = $source[$key];
			}

			return;
		}

		if ($inTarget === true) {
			$into[$key] = $target[$key];
		}
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
	 * Every key of target, then source, then base, once.
	 *
	 * @param array<string|int,mixed> $target The target map.
	 * @param array<string|int,mixed> $source The source map.
	 * @param array<string|int,mixed> $base   The base map.
	 *
	 * @return array<int,string|int> The keys in that order.
	 */
	private function orderedKeys(array $target, array $source, array $base): array {
		return array_values(array_unique(array_merge(array_keys($target), array_keys($source), array_keys($base)), SORT_REGULAR));
	}//end orderedKeys()

	/**
	 * One part of a schema side as a one-key map, or empty when the schema is absent.
	 *
	 * @param array<string,mixed>|null $sides The split schema, or null.
	 * @param string                   $part  `meta`.
	 * @param string                   $slug  The schema slug.
	 *
	 * @return array<string,mixed> The map.
	 */
	private function column(?array $sides, string $part, string $slug): array {
		if ($sides === null) {
			return [];
		}

		return [$slug => $sides[$part]];
	}//end column()

	/**
	 * A one-key map holding $values[$from] under $to, or empty when absent.
	 *
	 * @param array<string,mixed> $values The map.
	 * @param string              $from   The key to read.
	 * @param string              $to     The key to write.
	 *
	 * @return array<string,mixed> The map.
	 */
	private function renamed(array $values, string $from, string $to): array {
		if (array_key_exists($from, $values) === false) {
			return [];
		}

		return [$to => $values[$from]];
	}//end renamed()
}//end class
