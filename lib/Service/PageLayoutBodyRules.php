<?php

/**
 * Page Layout Body Rules
 *
 * What a layout's BODY has to satisfy to be renderable at all: the kinds a tab
 * may be and the reference each kind needs, the widths the grid knows, the
 * operators a display condition may use, and the upload fields a person can
 * actually fill.
 *
 * These sit apart from PageLayoutValidator, which answers a different question:
 * whether this layout may exist beside the ones already stored, for this
 * audience, under this identity. One is about the shape of a page, the other
 * about the place of a layout in a register.
 *
 * Every rule here REFUSES rather than warns, because each names something that
 * cannot render: a tab kind nothing draws, a width the grid reads as zero, an
 * operator nothing evaluates, a hidden field with no default. A layout that
 * saved and then rendered blank is the failure these prevent.
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
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-004, REQ-OBPL-005, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Refuses a layout body that cannot render.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-004)
 */
final class PageLayoutBodyRules {
	/**
	 * The kinds a tab may be.
	 *
	 * @var array<int, string>
	 */
	public const TAB_KINDS = ['leaf', 'widgets', 'fieldGroup', 'relatedList'];

	/**
	 * The widths the grid knows.
	 *
	 * @var array<int, string>
	 */
	public const WIDGET_WIDTHS = ['small', 'medium', 'large', 'extraLarge'];

	/**
	 * How visible an upload field is.
	 *
	 * @var array<int, string>
	 */
	public const VISIBILITIES = ['editable', 'readOnly', 'hidden'];

	/**
	 * The operators a display condition may use.
	 *
	 * @var array<int, string>
	 */
	public const CONDITION_OPERATORS = ['equals', 'notEquals', 'isEmpty', 'isNotEmpty', 'contains'];


	/**
	 * Refuse a tab whose kind is unknown, or that is missing the reference its
	 * kind needs (REQ-OBPL-004).
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a tab is not renderable.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-004)
	 */
	public function assertTabs(array $layout): void {
		foreach (($layout['tabs'] ?? []) as $tab) {
			if (is_array($tab) === false) {
				continue;
			}

			$label = (string)($tab['label'] ?? 'a tab');

			$this->assertTabKind(tab: $tab, label: $label);
			$this->assertWidgetList(widgets: ($tab['widgets'] ?? []), where: $label);
		}
	}//end assertTabs()

	/**
	 * Refuse one tab whose kind is unknown or under-specified.
	 *
	 * @param array<string, mixed> $tab The tab.
	 * @param string $label The tab's label, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the tab is not renderable.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-004)
	 */
	private function assertTabKind(array $tab, string $label): void {
		$kind = (string)($tab['kind'] ?? '');

		if (in_array($kind, self::TAB_KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('%s has an unknown kind "%s"; expected one of %s.', $label, $kind, implode(', ', self::TAB_KINDS))
			);
		}

		$this->assertTabReference(tab: $tab, kind: $kind, label: $label);
	}//end assertTabKind()

	/**
	 * Refuse a tab of a known kind that is missing what that kind needs to draw.
	 *
	 * @param array<string, mixed> $tab The tab.
	 * @param string $kind The tab's kind, already known to be one of TAB_KINDS.
	 * @param string $label The tab's label, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the reference is missing.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-004)
	 */
	private function assertTabReference(array $tab, string $kind, string $label): void {
		if ($kind === 'leaf' && (string)($tab['ref'] ?? '') === '') {
			throw new InvalidArgumentException(
				sprintf('%s is a leaf tab with no leaf id, so it would render an empty panel and no error.', $label)
			);
		}

		if ($kind === 'fieldGroup' && (is_array($tab['fields'] ?? null) === false || $tab['fields'] === [])) {
			throw new InvalidArgumentException(sprintf('%s is a field group with no fields in it.', $label));
		}

		if ($kind === 'relatedList'
			&& ((string)($tab['relatedRegister'] ?? '') === '' || (string)($tab['relatedSchema'] ?? '') === '')
		) {
			throw new InvalidArgumentException(
				sprintf('%s is a related list that does not say which register and schema to list.', $label)
			);
		}
	}//end assertTabReference()

	/**
	 * Validate the page-level widget grid.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a widget is not renderable.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-005)
	 */
	public function assertWidgets(array $layout): void {
		$this->assertWidgetList(widgets: ($layout['widgets'] ?? []), where: 'the page');
	}//end assertWidgets()

	/**
	 * Refuse a widget with a width the grid does not know, or a condition with an
	 * operator nothing evaluates (REQ-OBPL-005, REQ-OBPL-006).
	 *
	 * @param mixed $widgets The widget list.
	 * @param string $where Where the widgets sit, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a widget is not renderable.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-005, REQ-OBPL-006)
	 */
	public function assertWidgetList(mixed $widgets, string $where): void {
		if (is_array($widgets) === false) {
			return;
		}

		foreach ($widgets as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			$id = (string)($widget['id'] ?? '');
			if ($id === '') {
				throw new InvalidArgumentException(sprintf('A widget on %s does not say which widget it is.', $where));
			}

			$width = (string)($widget['width'] ?? 'medium');
			if (in_array($width, self::WIDGET_WIDTHS, true) === false) {
				// A width the grid does not know renders at zero, which looks
				// exactly like a widget that failed to load.
				throw new InvalidArgumentException(
					sprintf('"%s" has width "%s"; the grid knows %s.', $id, $width, implode(', ', self::WIDGET_WIDTHS))
				);
			}

			foreach (($widget['conditions'] ?? []) as $condition) {
				if (is_array($condition) === false) {
					continue;
				}

				$operator = (string)($condition['operator'] ?? '');
				if (in_array($operator, self::CONDITION_OPERATORS, true) === false) {
					throw new InvalidArgumentException(
						sprintf(
							'"%s" has a condition with operator "%s", which nothing evaluates; expected one of %s.',
							$id,
							$operator,
							implode(', ', self::CONDITION_OPERATORS)
						)
					);
				}
			}
		}
	}//end assertWidgetList()

	/**
	 * Refuse a hidden upload field with no default: it can never be filled, so
	 * every document on this type is stored missing it (REQ-OBPL-009).
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When such a field exists.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-009)
	 */
	public function assertUploadFields(array $layout): void {
		foreach (($layout['uploadFields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['field'] ?? '');
			$visibility = (string)($field['visibility'] ?? 'editable');

			if (in_array($visibility, self::VISIBILITIES, true) === false) {
				throw new InvalidArgumentException(
					sprintf('"%s" has visibility "%s"; expected one of %s.', $name, $visibility, implode(', ', self::VISIBILITIES))
				);
			}

			if ($visibility === 'hidden' && (string)($field['default'] ?? '') === '') {
				throw new InvalidArgumentException(
					sprintf('"%s" is hidden with no default, so nothing can ever fill it. Give it a default or make it read-only.', $name)
				);
			}
		}
	}//end assertUploadFields()
}//end class
