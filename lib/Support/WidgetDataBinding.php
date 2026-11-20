<?php

/**
 * WidgetDataBinding — the data binding of a widget appended by ManifestWidgetShape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A data widget reads its register and schema from a nested block whose key
 * differs per widget type; this lifts a flat binding into that block.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Gives a widget settings bag the nested binding block its component reads.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */
final class WidgetDataBinding {

	/**
	 * Widget types whose component reads its register and schema from a
	 * nested block, and the key each one reads.
	 *
	 * Not every data widget spells this the same way, which is the whole
	 * reason this map exists rather than a rule. Read out of the shipped
	 * `@conduction/nextcloud-vue` bundle, and each one confirmed against the
	 * live instance on 2026-09-18:
	 *
	 *  - `CnStatWidget::fetchValue()` opens `const s = this.content.source || {}`
	 *    and returns early when `!s.register || !s.schema`. `CnGaugeWidget` and
	 *    `CnDeltaWidget` read the same key;
	 *  - `CnChartWidget` and `CnStatsBlockWidget` take a `dataSource` block;
	 *  - `CnObjectListWidget` reads `content.register` / `content.schema` flat,
	 *    so it is deliberately absent from this map and left alone.
	 *
	 * A stat tile written flat is stored, valid, and renders an em dash
	 * forever. Measured: the three tiles the live plan proposed all read
	 * `—`, and the same tile written with a `source` block read `1`.
	 *
	 * @var array<string, string>
	 */
	private const NESTED_BINDING_KEY = [
		'stat' => 'source',
		'gauge' => 'source',
		'delta' => 'source',
		'chart' => 'dataSource',
		'stats-block' => 'dataSource',
	];

	/**
	 * The keys that make up a data binding, as opposed to presentation.
	 *
	 * @var array<int, string>
	 */
	private const BINDING_KEYS = ['register', 'schema', 'filter', 'aggregate', 'metric', 'field', 'kind'];

	/**
	 * Give a widget's settings bag the nested binding block its component
	 * actually reads, when it was written flat.
	 *
	 * The flat keys are kept alongside. They cost nothing to a component that
	 * does not read them, and removing them would break `object-list`, which
	 * does.
	 *
	 * @param array<string, mixed> $widgetConfig The settings bag as the tool was handed it.
	 * @param string $widgetType The widget type.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function apply(array $widgetConfig, string $widgetType): array {
		$key = (self::NESTED_BINDING_KEY[$widgetType] ?? '');
		if ($key === '' || isset($widgetConfig[$key]) === true) {
			return $widgetConfig;
		}

		// Nothing is invented: without a register AND a schema at the top level
		// there is no binding to lift, and the widget stays as it was written.
		$register = ($widgetConfig['register'] ?? null);
		$schema = ($widgetConfig['schema'] ?? null);
		if (is_string($register) === false || trim($register) === '' || $schema === null || $schema === '') {
			return $widgetConfig;
		}

		$binding = [];
		foreach (self::BINDING_KEYS as $bindingKey) {
			if (array_key_exists($bindingKey, $widgetConfig) === true) {
				$binding[$bindingKey] = $widgetConfig[$bindingKey];
			}
		}

		$widgetConfig[$key] = $binding;

		return $widgetConfig;
	}//end apply()
}//end class
