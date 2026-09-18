<?php

/**
 * ManifestWidgetShape — the one place a dashboard widget entry is built.
 *
 * The canonical manifest validator (`validateManifest()` in
 * `@conduction/nextcloud-vue`, ADR-024) demands three non-empty strings on
 * every `pages[].config.widgets[]` entry: `id`, `title` and `type`. The
 * builder tools used to append `{type, config}` and nothing else, so a
 * manifest carrying a single AI-proposed widget failed with
 * `/pages/0/config/widgets/0/id: must be a non-empty string` and the wizard
 * refused to create the app. The same shape was written into stored
 * manifests by the MCP handler, so the defect outlived the proposal.
 *
 * Two call sites produced it: `CopilotService::applyAddWidget()` predicting
 * the manifest for the review screen, and `AddWidgetHandler::appendWidget()`
 * writing the real one. They drifted because each spelled the shape out
 * itself. They both call this class now, so the manifest a user approves and
 * the manifest that gets stored cannot disagree.
 *
 * WHY A LAYOUT ENTRY COMES WITH IT. `CnDashboardPage` renders `config.layout[]`,
 * not `config.widgets[]`: a widget with no layout row is stored, valid, and
 * invisible, and the page says "No widgets configured". Appending the widget
 * without its placement is therefore not adding a widget to a page. The
 * layout entry has its own required fields (`id`, `widgetId`, four integers),
 * so it is built here too.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * Builds a valid dashboard widget entry plus its layout row.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */
final class ManifestWidgetShape {

	/**
	 * Grid columns a dashboard page is laid out over.
	 *
	 * @var int
	 */
	private const GRID_COLUMNS = 12;

	/**
	 * Column span given to an appended widget: a half-width card.
	 *
	 * @var int
	 */
	private const WIDGET_WIDTH = 6;

	/**
	 * Row span given to an appended widget.
	 *
	 * @var int
	 */
	private const WIDGET_HEIGHT = 2;

	/**
	 * Longest id this class will mint, matching the tool schema's maxLength.
	 *
	 * @var int
	 */
	private const MAX_ID_LENGTH = 48;

	/**
	 * Append a widget, and its layout placement, to one page.
	 *
	 * The id is derived in this order: the caller's `widgetId`, else a slug of
	 * the title, else a slug of the widget type. A collision inside the page
	 * gets a numeric suffix. Nothing here reads the clock or a random source,
	 * so predicting a plan and executing it yield the same ids.
	 *
	 * @param array<string, mixed> $page The page to append to.
	 * @param string $widgetType Widget type identifier, already allow-listed by the caller.
	 * @param array<string, mixed> $widgetConfig Widget-type-specific settings bag.
	 * @param string $widgetId Caller-supplied id, or '' to derive one.
	 * @param string $title Caller-supplied title, or '' to derive one.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The updated page and the widget that was appended.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function appendTo(
		array $page,
		string $widgetType,
		array $widgetConfig,
		string $widgetId = '',
		string $title = '',
	): array {
		$pageConfig = self::asMap(value: ($page['config'] ?? []));
		$widgets = self::asList(value: ($pageConfig['widgets'] ?? []));
		$layout = self::asList(value: ($pageConfig['layout'] ?? []));

		$resolvedTitle = self::resolveTitle(title: $title, widgetConfig: $widgetConfig, widgetType: $widgetType);
		$resolvedId = self::resolveId(
			widgetId: $widgetId,
			title: $resolvedTitle,
			widgetType: $widgetType,
			taken: self::takenIds(widgets: $widgets, layout: $layout)
		);

		$widget = [
			'id' => $resolvedId,
			'title' => $resolvedTitle,
			'type' => $widgetType,
		];

		// The settings bag rides `content`, which is what CnDashboardPage hands
		// to a registry card widget. It used to ride `config`, a key nothing in
		// this app or in the component library ever reads, so every widget the
		// tool wrote rendered with its defaults whatever the model asked for.
		// Chart widgets take their inputs from `props` instead, so a chart gets
		// the same bag under both keys rather than a type-specific guess here.
		if ($widgetConfig !== []) {
			$widget['content'] = self::withDataBinding(widgetConfig: $widgetConfig, widgetType: $widgetType);
			if ($widgetType === 'chart') {
				$widget['props'] = $widget['content'];
			}
		}

		$widgets[] = $widget;
		$layout[] = self::layoutEntry(widgetId: $resolvedId, index: (count($layout)));

		$pageConfig['widgets'] = $widgets;
		$pageConfig['layout'] = $layout;
		$page['config'] = $pageConfig;

		return [$page, $widget];
	}//end appendTo()

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
	private static function withDataBinding(array $widgetConfig, string $widgetType): array {
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
	}//end withDataBinding()

	/**
	 * Build the layout row placing one widget on the grid.
	 *
	 * Widgets flow left to right, two per row, in the order they were added.
	 *
	 * @param string $widgetId Id of the widget this row places.
	 * @param int $index Zero-based position among the page's existing layout rows.
	 *
	 * @return array<string, mixed>
	 */
	private static function layoutEntry(string $widgetId, int $index): array {
		$perRow = intdiv(self::GRID_COLUMNS, self::WIDGET_WIDTH);

		return [
			'id' => $widgetId,
			'widgetId' => $widgetId,
			'gridX' => (($index % $perRow) * self::WIDGET_WIDTH),
			'gridY' => (intdiv($index, $perRow) * self::WIDGET_HEIGHT),
			'gridWidth' => self::WIDGET_WIDTH,
			'gridHeight' => self::WIDGET_HEIGHT,
		];
	}//end layoutEntry()

	/**
	 * Resolve the widget's display title.
	 *
	 * A settings bag written for a stat or a chart usually already names the
	 * thing, so `widgetConfig.title` is read before falling back to a label
	 * made from the type.
	 *
	 * @param string $title Caller-supplied title, possibly empty.
	 * @param array<string, mixed> $widgetConfig Widget settings bag.
	 * @param string $widgetType Widget type identifier.
	 *
	 * @return string A non-empty title.
	 */
	private static function resolveTitle(string $title, array $widgetConfig, string $widgetType): string {
		$candidates = [$title, ($widgetConfig['title'] ?? null), ($widgetConfig['label'] ?? null)];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false) {
				continue;
			}

			$trimmed = trim($candidate);
			if ($trimmed !== '') {
				return $trimmed;
			}
		}

		$fromType = trim(str_replace(['-', '_'], ' ', $widgetType));
		if ($fromType === '') {
			return 'Widget';
		}

		return (mb_strtoupper(mb_substr($fromType, 0, 1)) . mb_substr($fromType, 1));
	}//end resolveTitle()

	/**
	 * Resolve a page-unique, kebab-case widget id.
	 *
	 * @param string $widgetId Caller-supplied id, possibly empty.
	 * @param string $title Already-resolved title, used as the second source.
	 * @param string $widgetType Widget type identifier, used as the last source.
	 * @param array<string, bool> $taken Ids already used on this page, keyed by id.
	 *
	 * @return string A non-empty id not present in $taken.
	 */
	private static function resolveId(string $widgetId, string $title, string $widgetType, array $taken): string {
		$base = '';
		foreach ([$widgetId, $title, $widgetType] as $source) {
			$base = self::slug(value: $source);
			if ($base !== '') {
				break;
			}
		}

		if ($base === '') {
			$base = 'widget';
		}

		if (isset($taken[$base]) === false) {
			return $base;
		}

		$suffix = 2;
		while (isset($taken[$base . '-' . $suffix]) === true) {
			$suffix++;
		}

		return ($base . '-' . $suffix);
	}//end resolveId()

	/**
	 * Collect the ids already spoken for on a page.
	 *
	 * Layout rows are read as well as widgets: a layout row keyed by an id no
	 * widget carries still owns that id, and reusing it would silently place
	 * the new widget in the old row.
	 *
	 * @param array<int, mixed> $widgets The page's existing widgets.
	 * @param array<int, mixed> $layout The page's existing layout rows.
	 *
	 * @return array<string, bool> Set of taken ids.
	 */
	private static function takenIds(array $widgets, array $layout): array {
		$taken = [];
		self::collectIds(rows: $widgets, keys: ['id'], taken: $taken);
		self::collectIds(rows: $layout, keys: ['id', 'widgetId'], taken: $taken);

		return $taken;
	}//end takenIds()

	/**
	 * Add every non-empty string found under $keys in $rows to $taken.
	 *
	 * @param array<int, mixed> $rows Entries to read.
	 * @param array<int, string> $keys Keys on each entry that hold an id.
	 * @param array<string, bool> $taken Accumulator, keyed by id.
	 *
	 * @return void
	 */
	private static function collectIds(array $rows, array $keys, array &$taken): void {
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			foreach ($keys as $key) {
				$id = ($row[$key] ?? null);
				if (is_string($id) === true && $id !== '') {
					$taken[$id] = true;
				}
			}
		}
	}//end collectIds()

	/**
	 * Reduce a string to a kebab-case identifier.
	 *
	 * @param string $value Free text.
	 *
	 * @return string Kebab-case slug, or '' when nothing usable remains.
	 */
	private static function slug(string $value): string {
		$lower = mb_strtolower(trim($value));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $lower);
		if (is_string($slug) === false) {
			return '';
		}

		$slug = trim($slug, '-');
		if (mb_strlen($slug) > self::MAX_ID_LENGTH) {
			$slug = trim(mb_substr($slug, 0, self::MAX_ID_LENGTH), '-');
		}

		return $slug;
	}//end slug()

	/**
	 * Read a value as a string-keyed map, tolerating the JSON-decoded `[]`
	 * that an empty PHP config block round-trips as.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return array<string, mixed>
	 */
	private static function asMap(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end asMap()

	/**
	 * Read a value as a zero-indexed list.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return array<int, mixed>
	 */
	private static function asList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values($value);
	}//end asList()
}//end class
