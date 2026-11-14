// SPDX-License-Identifier: EUPL-1.2
/**
 * Page defaults for the standalone virtual-app runtime (`builder.js`): a loaded
 * manifest's pages get a plain-object `config` and, on data pages, a visible title.
 *
 * @module services/manifestPageDefaults
 */

/**
 * Normalise a loaded manifest's pages for the standalone runtime, in place:
 *
 * 1. `config` MUST be a plain object. An empty `config: {}` round-trips through
 *    PHP/JSON as `[]` (PHP can't tell an empty object from an empty list), and a
 *    page rendered with an array config silently loses its register/schema.
 * 2. Data pages (`index` / `detail`) default to `showTitle: true` so the app
 *    shows its page title inline — the standalone runtime renders the app as a
 *    real app, where a visible page header is expected (CnIndexPage's own
 *    default is `false`, which routes the title to an index sidebar that this
 *    runtime does not surface). An explicit `showTitle` is always respected.
 *
 * @param {object} manifest The resolved manifest (mutated in place).
 * @return {void}
 */
export function normalizeManifestPages(manifest) {
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	for (const page of pages) {
		if (page && typeof page === 'object') {
			normalizePage(page)
		}
	}
}

/**
 * Give one page a plain-object `config` and, on a data page, a visible title
 * unless it sets `showTitle` itself.
 *
 * @param {object} page A manifest page (mutated in place).
 * @return {void}
 */
function normalizePage(page) {
	if (!isPlainObject(page.config)) {
		page.config = {}
	}
	if (isDataPage(page) && page.config.showTitle === undefined) {
		page.config.showTitle = true
	}
}

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
 * Whether a page shows register data (`index` or `detail`).
 *
 * @param {object} page A manifest page.
 * @return {boolean}
 */
function isDataPage(page) {
	return page.type === 'index' || page.type === 'detail'
}
