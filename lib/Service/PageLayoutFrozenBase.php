<?php

/**
 * Page Layout Frozen Base
 *
 * The base an override is cut against: the stack of WHOLE layouts beneath it,
 * frozen before the first patch lands.
 *
 * Only layouts for everyone count. A whole layout bound to one group still
 * composes into what that group is served, but letting it move the frozen base
 * would make the same override read as current for one colleague and drifted
 * for the next, which is the caller dependence this design exists to remove.
 *
 * It sits in its own class because the save path and the resolver have to agree
 * on exactly one answer. A second composition anywhere would be a second notion
 * of the base, and the first override to disagree with it would be withheld
 * from every caller for a reason nobody could see.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Picks the layers an override's frozen base is composed from.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
 */
final class PageLayoutFrozenBase {
	/**
	 * Constructor.
	 *
	 * @param PageLayoutLayerStack $layers The audience a layout declares, read the one way the resolver reads it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PageLayoutLayerStack $layers,
	) {
	}//end __construct()

	/**
	 * The layers an override's frozen base is composed from, in the order they
	 * compose.
	 *
	 * Whole layouts only, and only those for everyone: a layout bound to one
	 * group still composes into what that group is served, but letting it move
	 * the frozen base would make the same override read as current for one
	 * colleague and drifted for the next.
	 *
	 * The layer the override names in `baseRef` leads, then the schema-wide
	 * layout, then the one for this type value. A layer already in the list is
	 * not added twice.
	 *
	 * @param array<int, array<string, mixed>> $published The published layouts for this register and schema.
	 * @param array<string, mixed> $override The override about to be saved.
	 *
	 * @return array<int, array<string, mixed>> The ordered layers.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function layersFor(array $published, array $override): array {
		$whole = $this->wholeLayoutsForEveryone(published: $published);
		$layers = $this->layoutNamedBy(layouts: $whole, baseRef: (string)($override['baseRef'] ?? ''));

		$candidates = [
			$this->schemaWideAmong(layouts: $whole),
			$this->typedAmong(
				layouts: $whole,
				typeProperty: (string)($override['typeProperty'] ?? ''),
				typeValue: (string)($override['typeValue'] ?? '')
			),
		];

		foreach ($candidates as $layout) {
			if ($layout !== null && in_array($layout, $layers, true) === false) {
				$layers[] = $layout;
			}
		}

		return $layers;
	}//end layersFor()

	/**
	 * The published layouts that are whole layouts for everyone.
	 *
	 * @param array<int, array<string, mixed>> $published The published layouts.
	 *
	 * @return array<int, array<string, mixed>> The whole layouts for everyone.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	private function wholeLayoutsForEveryone(array $published): array {
		$out = [];
		foreach ($published as $layout) {
			$delta = ($layout['layoutDelta'] ?? null);
			if (is_array($delta) === true && $delta !== []) {
				continue;
			}

			if ((string)($this->layers->audienceOf(layout: $layout)['kind'] ?? 'everyone') !== 'everyone') {
				continue;
			}

			$out[] = $layout;
		}

		return $out;
	}//end wholeLayoutsForEveryone()

	/**
	 * The layout an override pins itself to by id, if it is among these.
	 *
	 * @param array<int, array<string, mixed>> $layouts The candidate layouts.
	 * @param string $baseRef The id the override names, empty when it names none.
	 *
	 * @return array<int, array<string, mixed>> That layout alone, or nothing.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	private function layoutNamedBy(array $layouts, string $baseRef): array {
		if ($baseRef === '') {
			return [];
		}

		foreach ($layouts as $layout) {
			if ((string)($layout['id'] ?? '') === $baseRef) {
				return [$layout];
			}
		}

		return [];
	}//end layoutNamedBy()

	/**
	 * The first schema-wide layout among these, meaning one that names no type
	 * scope at all.
	 *
	 * @param array<int, array<string, mixed>> $layouts The candidate layouts.
	 *
	 * @return array<string, mixed>|null The schema-wide layout.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-007)
	 */
	private function schemaWideAmong(array $layouts): ?array {
		foreach ($layouts as $layout) {
			if ((string)($layout['typeProperty'] ?? '') === '' || (string)($layout['typeValue'] ?? '') === '') {
				return $layout;
			}
		}

		return null;
	}//end schemaWideAmong()

	/**
	 * The first layout among these that is scoped to exactly this type value.
	 *
	 * @param array<int, array<string, mixed>> $layouts The candidate layouts.
	 * @param string $typeProperty The property carrying the type.
	 * @param string $typeValue The type value.
	 *
	 * @return array<string, mixed>|null The layout for this type value.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006)
	 */
	private function typedAmong(array $layouts, string $typeProperty, string $typeValue): ?array {
		if ($typeProperty === '' || $typeValue === '') {
			return null;
		}

		foreach ($layouts as $layout) {
			if ((string)($layout['typeProperty'] ?? '') === $typeProperty
				&& (string)($layout['typeValue'] ?? '') === $typeValue
			) {
				return $layout;
			}
		}

		return null;
	}//end typedAmong()
}//end class
