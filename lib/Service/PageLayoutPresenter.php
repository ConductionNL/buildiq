<?php

/**
 * Page Layout Presenter
 *
 * The shape a resolved layout takes on its way OUT: tabs in their stored order,
 * the parts a narrower layer does not declare filled in from the schema-wide
 * one, and the widgets whose display conditions do not hold left out.
 *
 * Left OUT, not flagged. A widget sent with a "hidden" flag is still on the wire
 * and still readable by anyone who opens the network tab, which is the same
 * mistake as a hidden form field.
 *
 * A type header REPLACES the schema-wide header whole. The fall-back is for a
 * layer that declares no header, task list or upload fields AT ALL, which is a
 * different thing from one that declares a shorter header: merging those field
 * by field would leave a page showing four fields an administrator thought they
 * had removed, with nothing on the page to say where they came from.
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
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006, REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Turns a resolved page layout into what the consumer renders.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006)
 */
final class PageLayoutPresenter {
	/**
	 * Turn a resolved layout into what the consumer renders.
	 *
	 * @param array<string, mixed> $layout The resolved layout.
	 * @param array<string, mixed>|null $schemaWide The schema-wide layout, for the parts this one does not declare.
	 * @param array<string, mixed> $object The host object, for the display conditions.
	 *
	 * @return array<string, mixed> The served layout.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006, REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
	 */
	public function serve(array $layout, ?array $schemaWide, array $object): array {
		return [
			'id' => (string)($layout['id'] ?? ''),
			'targetApp' => (string)($layout['targetApp'] ?? ''),
			'register' => (string)($layout['register'] ?? ''),
			'schema' => (string)($layout['schema'] ?? ''),
			'typeProperty' => (string)($layout['typeProperty'] ?? ''),
			'typeValue' => (string)($layout['typeValue'] ?? ''),
			'header' => $this->declaredPart(layout: $layout, schemaWide: $schemaWide, key: 'header'),
			'tabs' => $this->orderedTabs(layout: $layout, object: $object),
			'widgets' => $this->keepWidgetsThatHold(widgets: ($layout['widgets'] ?? []), object: $object),
			'taskList' => $this->declaredPart(layout: $layout, schemaWide: $schemaWide, key: 'taskList'),
			'uploadFields' => ($this->declaredPart(layout: $layout, schemaWide: $schemaWide, key: 'uploadFields') ?? []),
		];
	}//end serve()

	/**
	 * One part of the layout, falling through to the schema-wide layer when this
	 * layer does not declare it at all.
	 *
	 * An empty upload-field list counts as not declaring it: a type layout that
	 * saved with no upload fields would otherwise strip the ones the schema-wide
	 * layout asks every document for.
	 *
	 * @param array<string, mixed> $layout The resolved layout.
	 * @param array<string, mixed>|null $schemaWide The schema-wide layout.
	 * @param string $key The part.
	 *
	 * @return array<string, mixed>|null The part, or null when neither declares it.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-007)
	 */
	private function declaredPart(array $layout, ?array $schemaWide, string $key): ?array {
		$own = ($layout[$key] ?? null);
		if (is_array($own) === true && ($key !== 'uploadFields' || $own !== [])) {
			return $own;
		}

		$inherited = ($schemaWide[$key] ?? null);
		if (is_array($inherited) === true) {
			return $inherited;
		}

		return null;
	}//end declaredPart()

	/**
	 * The tabs in their stored order, each carrying only the widgets that hold.
	 *
	 * @param array<string, mixed> $layout The resolved layout.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The tabs.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-008)
	 */
	private function orderedTabs(array $layout, array $object): array {
		$tabs = [];

		foreach (($layout['tabs'] ?? []) as $tab) {
			if (is_array($tab) === false) {
				continue;
			}

			if (is_array($tab['widgets'] ?? null) === true) {
				$tab['widgets'] = $this->keepWidgetsThatHold(widgets: $tab['widgets'], object: $object);
			}

			$tabs[] = $tab;
		}

		usort($tabs, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));

		return $tabs;
	}//end orderedTabs()

	/**
	 * Drop the widgets whose display conditions do not hold, keeping the stored
	 * order of the rest.
	 *
	 * Dropped, not flagged. A widget sent with a "hidden" flag is still on the
	 * wire and still readable by anyone who opens the network tab.
	 *
	 * @param mixed $widgets The widget list.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The widgets that hold.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006)
	 */
	public function keepWidgetsThatHold(mixed $widgets, array $object): array {
		if (is_array($widgets) === false) {
			return [];
		}

		$kept = [];
		foreach ($widgets as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			if ($this->conditionsHold(conditions: ($widget['conditions'] ?? []), object: $object) === true) {
				$kept[] = $widget;
			}
		}

		usort($kept, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));

		return $kept;
	}//end keepWidgetsThatHold()

	/**
	 * Whether every condition holds against the host object. An empty list always
	 * holds, which is what makes conditions opt-in.
	 *
	 * @param mixed $conditions The conditions.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return bool True when they all hold.
	 */
	private function conditionsHold(mixed $conditions, array $object): bool {
		if (is_array($conditions) === false || $conditions === []) {
			return true;
		}

		foreach ($conditions as $condition) {
			if (is_array($condition) === false) {
				continue;
			}

			$field = (string)($condition['field'] ?? '');
			$operator = (string)($condition['operator'] ?? '');
			$expected = (string)($condition['value'] ?? '');
			$actual = ($object[$field] ?? null);
			$actualText = '';
			if (is_scalar($actual) === true) {
				$actualText = (string)$actual;
			}


			$holds = match ($operator) {
				'equals' => ($actualText === $expected),
				'notEquals' => ($actualText !== $expected),
				'isEmpty' => ($actualText === ''),
				'isNotEmpty' => ($actualText !== ''),
				'contains' => ($expected !== '' && str_contains($actualText, $expected)),
				// An operator nothing evaluates must not silently pass: a widget
				// shown on every case because of a typo is worse than one missing.
				default => false,
			};

			if ($holds === false) {
				return false;
			}
		}

		return true;
	}//end conditionsHold()
}//end class
