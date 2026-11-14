// SPDX-License-Identifier: EUPL-1.2
/**
 * Connector-bound pages for the runtime: a page the maker bound to an integriq
 * endpoint (`config.dataSource.connector`) becomes something the renderer can
 * draw, without forking the closed manifest page-type enum.
 *
 * - An `index` page becomes a `custom` page whose `component` is the
 *   `connector-list` runtime page, carrying the page's `dataSource`.
 * - A `dashboard` page gets a `connector-data` widget bound to its
 *   `dataSource`, placed at the top of its layout.
 *
 * The rewrite is in memory only. `normalizeConnectorPages()` never touches the
 * manifest it is given, and `restoreConnectorPages()` turns a rendered manifest
 * back into the shape the maker authored, so a save from the published app
 * stores what the page designer expects to open.
 *
 * @module services/connectorPages
 * @spec openspec/changes/integrations-connector-bound-pages/tasks.md#task-1
 */

/** Registry name of the runtime list page (`src/runtimeRegistry.js`). */
export const CONNECTOR_LIST_COMPONENT = 'connector-list'

/** Registry name of the runtime connector widget (`src/runtimeRegistry.js`). */
export const CONNECTOR_WIDGET_TYPE = 'connector-data'

/** Grid height of the widget a connector-bound dashboard receives. */
const DASHBOARD_WIDGET_HEIGHT = 4

/**
 * What each rendered manifest rewrote, keyed by the rendered manifest object:
 * `Map<pageId, {kind: 'index'|'dashboard', authored: object, widgetId?: string}>`.
 * A WeakMap so the bookkeeping never serialises with the manifest.
 */
const rewrites = new WeakMap()

/**
 * Whether a value is a non-null object that is not an array.
 *
 * @param {unknown} value Any value.
 * @return {boolean}
 */
function isPlainObject(value) {
	return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

/**
 * A deep copy of JSON data.
 *
 * @param {object|Array|string|number|boolean|null|undefined} value JSON-shaped data.
 * @return {object|Array|string|number|boolean|null|undefined}
 */
function clone(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

/**
 * The connector-bound `dataSource` of a page, or null when it has none.
 *
 * @param {object} page A manifest page.
 * @return {?object}
 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md#requirement-a-connector-bound-index-page-renders-its-records-req-bqcb-001
 */
export function connectorDataSourceOf(page) {
	if (!isPlainObject(page) || !isPlainObject(page.config)) {
		return null
	}
	const dataSource = page.config.dataSource
	if (!isPlainObject(dataSource) || !isPlainObject(dataSource.connector)) {
		return null
	}
	return dataSource
}

/**
 * The rendered form of a connector-bound index page: a `custom` page on the
 * `connector-list` component. Every top-level key the page carries (id, route,
 * title, permission, ...) is kept, so routing and the permission filter treat
 * it exactly as the authored page.
 *
 * @param {object} page The authored index page.
 * @param {object} dataSource Its connector-bound data source.
 * @return {object}
 */
function indexAsListPage(page, dataSource) {
	const rendered = clone(page)
	rendered.type = 'custom'
	rendered.component = CONNECTOR_LIST_COMPONENT
	rendered.config = { dataSource: clone(dataSource) }
	return rendered
}

/**
 * A widget id that no widget of the page uses yet.
 *
 * @param {object} page The dashboard page.
 * @param {Array<object>} widgets Its widget definitions.
 * @return {string}
 */
function freeWidgetId(page, widgets) {
	const taken = new Set(widgets.map((w) => w && w.id))
	const base = `connector-data-${page.id || 'page'}`
	let id = base
	for (let k = 2; taken.has(id); k++) {
		id = `${base}-${k}`
	}
	return id
}

/**
 * The rendered form of a connector-bound dashboard page: a `connector-data`
 * widget bound to the page's data source, placed first and on top of the grid,
 * with the authored layout moved down below it.
 *
 * @param {object} page The authored dashboard page.
 * @param {object} dataSource Its connector-bound data source.
 * @return {{page: object, widgetId: string}}
 */
function dashboardWithWidget(page, dataSource) {
	const rendered = clone(page)
	const config = rendered.config
	const widgets = Array.isArray(config.widgets) ? config.widgets : []
	const layout = Array.isArray(config.layout) ? config.layout : []
	const widgetId = freeWidgetId(rendered, widgets)
	config.widgets = [
		{
			id: widgetId,
			title: rendered.title || '',
			type: CONNECTOR_WIDGET_TYPE,
			content: { dataSource: clone(dataSource) },
		},
		...widgets,
	]
	config.layout = [
		{
			id: widgetId,
			widgetId,
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: DASHBOARD_WIDGET_HEIGHT,
		},
		...layout.map((item) =>
			isPlainObject(item)
				? {
						...item,
						gridY: (Number(item.gridY) || 0) + DASHBOARD_WIDGET_HEIGHT,
					}
				: item,
		),
	]
	return { page: rendered, widgetId }
}

/**
 * Rewrite the connector-bound pages of a manifest for the runtime.
 *
 * Pure: returns a new manifest and leaves the one it is given untouched. A
 * manifest without connector-bound pages comes back as the same object.
 *
 * @param {object} manifest The manifest as stored.
 * @return {object} The manifest the renderer draws.
 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md#requirement-a-connector-bound-index-page-renders-its-records-req-bqcb-001
 */
export function normalizeConnectorPages(manifest) {
	if (!isPlainObject(manifest) || !Array.isArray(manifest.pages)) {
		return manifest
	}
	const done = new Map()
	const pages = manifest.pages.map((page) => {
		const dataSource = connectorDataSourceOf(page)
		if (!dataSource) {
			return page
		}
		if (page.type === 'index') {
			done.set(page.id, { kind: 'index', authored: clone(page) })
			return indexAsListPage(page, dataSource)
		}
		if (page.type === 'dashboard') {
			const { page: rendered, widgetId } = dashboardWithWidget(
				page,
				dataSource,
			)
			done.set(page.id, { kind: 'dashboard', authored: clone(page), widgetId })
			return rendered
		}
		return page
	})
	if (done.size === 0) {
		return manifest
	}
	const rendered = { ...manifest, pages }
	rewrites.set(rendered, done)
	return rendered
}

/**
 * Whether a manifest is the output of `normalizeConnectorPages()` with at least
 * one rewritten page.
 *
 * @param {object} manifest Any manifest.
 * @return {boolean}
 */
export function hasConnectorRewrites(manifest) {
	return isPlainObject(manifest) && rewrites.has(manifest)
}

/**
 * Undo the dashboard rewrite on a (possibly edited) rendered dashboard page:
 * drop the injected widget and its layout cell and move the cells below it
 * back up.
 *
 * @param {object} page The rendered dashboard page.
 * @param {string} widgetId The injected widget's id.
 * @return {object} The page in its authored shape.
 */
function dashboardWithoutWidget(page, widgetId) {
	const restored = clone(page)
	const config = isPlainObject(restored.config) ? restored.config : {}
	const layout = Array.isArray(config.layout) ? config.layout : []
	const cell = layout.find(
		(item) => isPlainObject(item) && item.widgetId === widgetId,
	)
	const height = cell ? Number(cell.gridHeight) || 0 : 0
	const below = cell ? (Number(cell.gridY) || 0) + height : Infinity
	if (Array.isArray(config.widgets)) {
		config.widgets = config.widgets.filter(
			(w) => !(isPlainObject(w) && w.id === widgetId),
		)
	}
	if (Array.isArray(config.layout)) {
		config.layout = layout
			.filter((item) => item !== cell)
			.map((item) =>
				isPlainObject(item) && (Number(item.gridY) || 0) >= below
					? { ...item, gridY: (Number(item.gridY) || 0) - height }
					: item,
			)
	}
	restored.config = config
	return restored
}

/**
 * The manifest to store for a rendered manifest: every rewritten page back in
 * the shape the maker authored. An index page that became a list page comes
 * back as the authored index page, with a title or route edited in the app
 * kept. A dashboard keeps its in-app edits and loses only the injected widget.
 *
 * Pure: returns a new manifest. A manifest without rewrites comes back as is.
 *
 * @param {object} manifest The rendered manifest (possibly edited in place).
 * @return {object} The manifest to persist.
 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md#requirement-a-connector-bound-index-page-renders-its-records-req-bqcb-001
 */
export function restoreConnectorPages(manifest) {
	const done = hasConnectorRewrites(manifest) ? rewrites.get(manifest) : null
	if (!done || !Array.isArray(manifest.pages)) {
		return manifest
	}
	const pages = manifest.pages.map((page) => {
		const entry = isPlainObject(page) ? done.get(page.id) : undefined
		if (!entry) {
			return page
		}
		if (entry.kind === 'index') {
			const restored = clone(entry.authored)
			for (const key of ['title', 'route', 'permission']) {
				if (page[key] !== undefined) {
					restored[key] = page[key]
				}
			}
			return restored
		}
		return dashboardWithoutWidget(page, entry.widgetId)
	})
	return { ...manifest, pages }
}
