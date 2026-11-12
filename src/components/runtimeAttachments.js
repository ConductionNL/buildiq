/**
 * Manifest edits shared by the attachment sections of the application
 * detail surface (Docudesk documents, Procest workflows): each keeps a list
 * under one `runtime.<key>` of the manifest and can add a sidebar tab to the
 * detail page of the attached schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * A copy of `list` with `entry` replacing the item of the same id, or
 * appended when there is none.
 *
 * @param {Array<object>} list - the current entries.
 * @param {object} entry - the added or edited entry.
 * @return {Array<object>} the new list.
 */
export function upsertById(list, entry) {
	const next = list.slice()
	const idx = next.findIndex((item) => item.id === entry.id)
	if (idx >= 0) {
		next.splice(idx, 1, entry)
	} else {
		next.push(entry)
	}
	return next
}

/**
 * A writer of the `runtime.<key>` list: it returns a manifest copy with the
 * list set, or with the key removed when the list is empty (and `runtime`
 * removed when nothing is left), so zero-attachment manifests serialize
 * byte-identically.
 *
 * @param {string} key - the runtime key, e.g. `documents`.
 * @return {function(object, Array<object>): object} the writer.
 */
export function runtimeListWriter(key) {
	return (manifest, list) => {
		const next = { ...manifest }
		const runtime = { ...(next.runtime || {}) }
		if (list.length === 0) {
			delete runtime[key]
		} else {
			runtime[key] = list
		}
		if (Object.keys(runtime).length === 0) {
			delete next.runtime
		} else {
			next.runtime = runtime
		}
		return next
	}
}

/**
 * An injector of a sidebar tab: it returns a manifest copy in which every
 * detail page that targets the entry's schema carries `tab` in
 * `config.sidebarProps.tabs`, unless a tab with the same component is there.
 *
 * @param {{id: string, label: string, component: string}} tab - the tab.
 * @return {function(object, object): object} the injector, taking the manifest and the attachment entry.
 */
export function detailTabInjector(tab) {
	return (manifest, entry) => {
		const next = { ...manifest, pages: (manifest.pages || []).slice() }
		next.pages = next.pages.map((page) =>
			(isDetailPageOf(page, entry.schema) ? withSidebarTab(page, tab) : page),
		)
		return next
	}
}

/**
 * @param {object|null} page - a manifest page.
 * @param {string} schema - the schema slug.
 * @return {boolean} whether it is a detail page bound to that schema.
 */
function isDetailPageOf(page, schema) {
	const cfg = page && page.config
	return !!(page && page.type === 'detail' && cfg && cfg.schema === schema)
}

/**
 * @param {object} page - a detail page.
 * @param {{id: string, label: string, component: string}} tab - the tab.
 * @return {object} a page copy whose sidebar tabs include the tab.
 */
function withSidebarTab(page, tab) {
	const cfg = page.config
	const sidebarProps = { ...(cfg.sidebarProps || {}) }
	const tabs = (sidebarProps.tabs || []).slice()
	if (!tabs.some((t2) => t2.component === tab.component)) {
		tabs.push({ ...tab })
	}
	sidebarProps.tabs = tabs
	return { ...page, config: { ...cfg, sidebarProps } }
}
