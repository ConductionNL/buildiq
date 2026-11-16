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
	 * Append a widget, and its layout placement, to one page.
	 *
	 * The id is derived in this order: the caller's `widgetId`, else a slug of
	 * the title, else a slug of the widget type. A collision inside the page
	 * gets a numeric suffix. Nothing here reads the clock or a random source,
	 * so predicting a plan and executing it yield the same ids.
	 *
	 * @param array<string, mixed> $page The page to append to.
	 * @param WidgetSpec $spec The widget type, settings bag, and optional id and title.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The updated page and the widget that was appended.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function appendTo(array $page, WidgetSpec $spec): array {
		$pageConfig = self::asMap(value: ($page['config'] ?? []));
		$widgets = self::asList(value: ($pageConfig['widgets'] ?? []));
		$layout = self::asList(value: ($pageConfig['layout'] ?? []));
		$widgetType = $spec->type;
		$widgetConfig = $spec->config;

		$resolvedTitle = self::resolveTitle(spec: $spec);
		$resolvedId = WidgetIdAllocator::allocate(
			sources: [$spec->id, $resolvedTitle, $widgetType],
			existing: ['widgets' => $widgets, 'layout' => $layout]
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
			$widget['content'] = WidgetDataBinding::apply(widgetConfig: $widgetConfig, widgetType: $widgetType);
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
	 * @param WidgetSpec $spec The requested widget; its title may be empty.
	 *
	 * @return string A non-empty title.
	 */
	private static function resolveTitle(WidgetSpec $spec): string {
		$candidates = [$spec->title, ($spec->config['title'] ?? null), ($spec->config['label'] ?? null)];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false) {
				continue;
			}

			$trimmed = trim($candidate);
			if ($trimmed !== '') {
				return $trimmed;
			}
		}

		$fromType = trim(str_replace(['-', '_'], ' ', $spec->type));
		if ($fromType === '') {
			return 'Widget';
		}

		return (mb_strtoupper(mb_substr($fromType, 0, 1)) . mb_substr($fromType, 1));
	}//end resolveTitle()

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
