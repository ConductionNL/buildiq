/**
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chart widgets in the dashboard page editor.
 *
 * nextcloud-vue's CnChartWidgetForm speaks in `content`
 * (`{ chartKind, dataSource }`), but the v1 manifest `widgetDef` refuses a
 * `content` key. These helpers map between the two (design D2): the chart type
 * lives in `props.chartKind`, the data binding in the widget's own
 * `dataSource`, which are exactly the keys the dashboard renderer reads.
 *
 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-the-chart-is-saved-in-the-shape-the-manifest-schema-allows-req-bqcw-002
 */

/** Width of the layout item a new chart gets. */
export const CHART_GRID_WIDTH = 6

/** Height of the layout item a new chart gets. */
export const CHART_GRID_HEIGHT = 4

/**
 * The form's `editingWidget` for a saved chart widget, so the form opens
 * with the saved choices filled in.
 *
 * @param {object} widget - the saved widget definition.
 * @return {{ content: { chartKind: (string|undefined), dataSource: object } }} the form input.
 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-the-chart-is-saved-in-the-shape-the-manifest-schema-allows-req-bqcw-002
 */
export function chartFormInput(widget) {
	const props = (widget && widget.props) || {}
	const dataSource = (widget && widget.dataSource) || {}
	return {
		content: {
			chartKind: props.chartKind,
			dataSource: { ...dataSource },
		},
	}
}

/**
 * The widget with the form's content written onto `props.chartKind` and
 * `dataSource`. Other `props` keys are kept; no `content` key is written.
 *
 * @param {object} widget - the widget being edited.
 * @param {{ chartKind: string, dataSource: object }} content - the form's `update:content` payload.
 * @return {object} the updated widget definition.
 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-the-chart-is-saved-in-the-shape-the-manifest-schema-allows-req-bqcw-002
 */
export function widgetWithChartContent(widget, content) {
	const rest = { ...(widget || {}) }
	delete rest.content
	const next = {
		...rest,
		props: { ...(rest.props || {}), chartKind: content && content.chartKind },
		dataSource: { ...((content && content.dataSource) || {}) },
	}
	return next
}

/**
 * The first `chart-<n>` id no widget uses yet.
 *
 * @param {Array<object>} widgets - the page's widgets.
 * @return {string} the id.
 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-a-new-chart-is-placed-on-the-page-req-bqcw-003
 */
export function nextChartId(widgets) {
	const used = new Set((widgets || []).map((widget) => widget && widget.id))
	let n = 1
	while (used.has(`chart-${n}`)) {
		n++
	}
	return `chart-${n}`
}

/**
 * A new, complete chart widget (design D3).
 *
 * @param {string} id - the widget id.
 * @return {object} the widget definition.
 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-a-new-chart-is-placed-on-the-page-req-bqcw-003
 */
export function newChartWidget(id) {
	return {
		id,
		title: '',
		type: 'chart',
		props: { chartKind: 'bar' },
		dataSource: { register: '', schema: '' },
	}
}

/**
 * The layout with an item for this widget at the first free row, unless an
 * item already references it. A widget without a layout item is not drawn.
 *
 * @param {Array<object>} layout - the page's layout items.
 * @param {string} widgetId - the widget to place.
 * @return {Array<object>} the layout, a new array when an item was added.
 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-a-new-chart-is-placed-on-the-page-req-bqcw-003
 */
export function layoutWithItemFor(layout, widgetId) {
	const items = Array.isArray(layout) ? layout : []
	if (items.some((item) => item && item.widgetId === widgetId)) {
		return items
	}

	const firstFreeRow = items.reduce(
		(row, item) => Math.max(row, (Number(item && item.gridY) || 0) + (Number(item && item.gridHeight) || 0)),
		0,
	)
	const nextId = items.reduce((max, item) => Math.max(max, Number(item && item.id) || 0), 0) + 1

	return [
		...items,
		{
			id: nextId,
			widgetId,
			gridX: 0,
			gridY: firstFreeRow,
			gridWidth: CHART_GRID_WIDTH,
			gridHeight: CHART_GRID_HEIGHT,
		},
	]
}
