<?php

/**
 * Page Layout Validator
 *
 * The rules a page layout has to satisfy before it is published. Each of them
 * catches something that would otherwise ship as a page that renders, looks
 * plausible, and is wrong.
 *
 * A second published layout for the same tuple means the consuming app renders
 * whichever one the store returned first, which changes between requests and
 * cannot be reproduced. Refused, naming the layout that already holds the
 * tuple.
 *
 * A `leaf` tab with no leaf id renders an empty panel and no error. A
 * `relatedList` tab with no register and schema does the same. Both refused,
 * naming what is missing.
 *
 * A hidden upload field with no default can never be filled, so every document
 * uploaded on that type is stored missing it and nobody finds out until
 * somebody searches on it. Refused.
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
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001, REQ-OBPL-004, REQ-OBPL-005, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Validates a page layout before it is saved.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001)
 */
final class PageLayoutValidator {
	/**
	 * The parts that make a layout unique.
	 *
	 * @var array<int, string>
	 */
	public const TUPLE_PARTS = ['targetApp', 'register', 'schema', 'typeProperty', 'typeValue'];

	/**
	 * The kinds of audience an override may be bound to.
	 *
	 * @var array<int, string>
	 */
	public const AUDIENCE_KINDS = ['everyone', 'group', 'team', 'portal', 'user'];

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
	 * Validate a layout against the layouts already stored.
	 *
	 * @param array<string, mixed> $layout The layout about to be saved.
	 * @param array<int, array<string, mixed>> $existing Layouts already stored.
	 * @param array<int, string>|null $knownLeafIds The leaf ids the integration registry knows, or null when it cannot be read.
	 *
	 * @return array<int, string> Warnings that do not block the save.
	 *
	 * @throws InvalidArgumentException When a rule refuses the layout.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001, REQ-OBPL-004, REQ-OBPL-005, REQ-OBPL-009)
	 */
	public function validate(array $layout, array $existing = [], ?array $knownLeafIds = null): array {
		$this->assertAudience(layout: $layout);
		$this->assertOverride(layout: $layout);
		$this->assertUnique(layout: $layout, existing: $existing);
		$this->assertTabs(layout: $layout);
		$this->assertWidgets(layout: $layout);
		$this->assertUploadFields(layout: $layout);

		return $this->unknownLeafWarnings(layout: $layout, knownLeafIds: $knownLeafIds);
	}//end validate()

	/**
	 * The identity of a layout.
	 *
	 * @param array<string, mixed> $layout The layout or a lookup.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-005)
	 */
	public function tupleKey(array $layout): string {
		$parts = [];
		foreach (self::TUPLE_PARTS as $part) {
			$parts[] = (string)($layout[$part] ?? '');
		}

		// The audience is part of the identity since screen overrides: three
		// screens for one case type is the point, and only two for the SAME
		// audience is the collision (REQ-OBSO-005).
		$audience = ($layout['audience'] ?? null);
		$kind = 'everyone';
		$ref = '';
		if (is_array($audience) === true) {
			$kind = (string)($audience['kind'] ?? 'everyone');
			$ref = (string)($audience['ref'] ?? '');
		}

		$parts[] = $kind;
		$parts[] = $ref;

		return implode('|', $parts);
	}//end tupleKey()

	/**
	 * Refuse an audience the resolver does not know (REQ-OBSO-004).
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the kind is unknown, or a ref is missing.
	 */
	private function assertAudience(array $layout): void {
		$audience = ($layout['audience'] ?? null);
		if (is_array($audience) === false || (string)($audience['kind'] ?? '') === '') {
			// Unset reads as `everyone`, so every layout stored before overrides
			// existed keeps exactly the reach it had.
			return;
		}

		$kind = (string)$audience['kind'];
		if (in_array($kind, self::AUDIENCE_KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown audience kind "%s"; expected one of %s.', $kind, implode(', ', self::AUDIENCE_KINDS))
			);
		}

		if (in_array($kind, ['group', 'team', 'user'], true) === true && (string)($audience['ref'] ?? '') === '') {
			// Without a ref this override would match nobody and be published,
			// correct-looking, and never applied.
			throw new InvalidArgumentException(
				sprintf('An audience of kind "%s" has to say which one; without a ref it matches nobody.', $kind)
			);
		}
	}//end assertAudience()

	/**
	 * Refuse an override that cannot say what it was cut against (REQ-OBSO-002).
	 *
	 * The fingerprint is not optional, because the alternative is an override
	 * whose base may have moved and nothing able to tell. It is written by the
	 * editor on save and never by hand.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a delta carries no fingerprint.
	 */
	private function assertOverride(array $layout): void {
		$delta = ($layout['layoutDelta'] ?? null);
		if (is_array($delta) === false || $delta === []) {
			return;
		}

		if ((string)($layout['baseFingerprint'] ?? '') === '') {
			throw new InvalidArgumentException(
				'An override has to record the base it was cut against; without a baseFingerprint nothing can tell whether that base has since moved.'
			);
		}
	}//end assertOverride()

	/**
	 * Refuse a second published layout for the same tuple (REQ-OBPL-001).
	 *
	 * @param array<string, mixed> $layout The layout.
	 * @param array<int, array<string, mixed>> $existing Layouts already stored.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the tuple is taken.
	 */
	private function assertUnique(array $layout, array $existing): void {
		if ((string)($layout['status'] ?? '') !== 'published') {
			// A draft may sit beside the published layout it will replace.
			return;
		}

		$key = $this->tupleKey(layout: $layout);
		$selfId = (string)($layout['id'] ?? '');

		foreach ($existing as $candidate) {
			if (is_array($candidate) === false
				|| (string)($candidate['status'] ?? '') !== 'published'
				|| $this->tupleKey(layout: $candidate) !== $key
			) {
				continue;
			}

			if ($selfId !== '' && (string)($candidate['id'] ?? '') === $selfId) {
				continue;
			}

			$scope = ' type';
			if ((string)($layout['typeValue'] ?? '') === '') {
				$scope = ' schema';
			}

			throw new InvalidArgumentException(
				sprintf(
					'"%s" already covers this%s for the same audience (%s); retire it before publishing another.',
					(string)($candidate['name'] ?? 'A published layout'),
					$scope,
					(string)($candidate['id'] ?? '?')
				)
			);
		}
	}//end assertUnique()

	/**
	 * Refuse a tab whose kind is unknown, or that is missing the reference its
	 * kind needs (REQ-OBPL-004).
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a tab is not renderable.
	 */
	private function assertTabs(array $layout): void {
		foreach (($layout['tabs'] ?? []) as $tab) {
			if (is_array($tab) === false) {
				continue;
			}

			$kind = (string)($tab['kind'] ?? '');
			$label = (string)($tab['label'] ?? 'a tab');

			if (in_array($kind, self::TAB_KINDS, true) === false) {
				throw new InvalidArgumentException(
					sprintf('%s has an unknown kind "%s"; expected one of %s.', $label, $kind, implode(', ', self::TAB_KINDS))
				);
			}

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

			$this->assertWidgetList(widgets: ($tab['widgets'] ?? []), where: $label);
		}
	}//end assertTabs()

	/**
	 * Validate the page-level widget grid.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a widget is not renderable.
	 */
	private function assertWidgets(array $layout): void {
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
	 */
	private function assertWidgetList(mixed $widgets, string $where): void {
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
	 */
	private function assertUploadFields(array $layout): void {
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

	/**
	 * Warn, do not refuse, on a leaf tab pointing at a leaf the registry does not
	 * know.
	 *
	 * A warning because the registry is read across an app boundary and depends
	 * on which apps happen to be installed: refusing would make a layout
	 * unsaveable on a machine that is simply missing an app, which is a worse
	 * failure than a tab that renders empty on that machine (REQ-OBPL-002).
	 *
	 * @param array<string, mixed> $layout The layout.
	 * @param array<int, string>|null $knownLeafIds The ids the registry knows, or null.
	 *
	 * @return array<int, string> The warnings.
	 */
	private function unknownLeafWarnings(array $layout, ?array $knownLeafIds): array {
		if ($knownLeafIds === null) {
			return [];
		}

		$warnings = [];
		foreach (($layout['tabs'] ?? []) as $tab) {
			if (is_array($tab) === false || (string)($tab['kind'] ?? '') !== 'leaf') {
				continue;
			}

			$ref = (string)($tab['ref'] ?? '');
			if ($ref !== '' && in_array($ref, $knownLeafIds, true) === false) {
				$warnings[] = sprintf('No installed app offers the leaf "%s"; that tab will render empty until one does.', $ref);
			}
		}

		return $warnings;
	}//end unknownLeafWarnings()
}//end class
