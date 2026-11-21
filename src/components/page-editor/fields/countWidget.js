/**
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Count widgets in the dashboard page editor (REQ-BQCV-007).
 *
 * A count tile is a `stats-block` widget: nextcloud-vue's CnDashboardPage
 * mounts CnStatsBlockWidget for it and counts the records its `dataSource`
 * names (`register`, `schema`, `filter`, `metric`). The library's own
 * CnStatsBlockWidgetForm speaks in `{ title, props, dataSource }`, which is
 * already the v1 `widgetDef` shape, so the form's content is written straight
 * onto the widget and no `content` key is stored.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-dashboard-page-req-bqcv-007
 */

/** The widget type of a count tile. */
export const COUNT_WIDGET_TYPE = 'stats-block'

/**
 * The form's `editingWidget` for a saved count widget, so the form opens
 * with the saved choices filled in.
 *
 * @param {object} widget - the saved widget definition.
 * @return {{ content: { title: string, props: object, dataSource: object } }} the form input.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-dashboard-page-req-bqcv-007
 */
export function countFormInput(widget) {
	const source = widget || {}
	return {
		content: {
			title: source.title || '',
			props: { ...(source.props || {}) },
			dataSource: { ...(source.dataSource || {}) },
		},
	}
}

/**
 * The widget with the form's content written onto `title`, `props` and
 * `dataSource`. Other `props` keys are kept; no `content` key is written.
 *
 * @param {object} widget - the widget being edited.
 * @param {{ title: string, props: object, dataSource: object }} content - the form's `update:content` payload.
 * @return {object} the updated widget definition.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-dashboard-page-req-bqcv-007
 */
export function widgetWithCountContent(widget, content) {
	const rest = { ...(widget || {}) }
	delete rest.content
	const given = content || {}
	return {
		...rest,
		title: typeof given.title === 'string' ? given.title : rest.title || '',
		props: { ...(rest.props || {}), ...(given.props || {}) },
		dataSource: { ...(given.dataSource || {}) },
	}
}

/**
 * The first `count-<n>` id no widget uses yet.
 *
 * @param {Array<object>} widgets - the page's widgets.
 * @return {string} the id.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-dashboard-page-req-bqcv-007
 */
export function nextCountId(widgets) {
	const used = new Set((widgets || []).map((widget) => widget && widget.id))
	let n = 1
	while (used.has(`count-${n}`)) {
		n++
	}
	return `count-${n}`
}

/**
 * A new count widget: a record count with no source chosen yet.
 *
 * @param {string} id - the widget id.
 * @return {object} the widget definition.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-dashboard-page-req-bqcv-007
 */
export function newCountWidget(id) {
	return {
		id,
		title: '',
		type: COUNT_WIDGET_TYPE,
		props: {},
		dataSource: { register: '', schema: '', metric: 'count', filter: {} },
	}
}
