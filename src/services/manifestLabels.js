// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestLabels — every label text a built app's manifest shows, with where
 * it is used (experience-multilingual-apps, design.md D3).
 *
 * The Translations view lists these: one row per distinct text, because the
 * manifest's `i18n.labels` is keyed by the text as written, the way
 * Nextcloud's own `t()` is. The same text in two places is one row and gets
 * one translation.
 *
 * The walk covers the menu (and its children), every page's title, and every
 * `label`, `title`, `placeholder`, `help` and `emptyText` inside a page's
 * config and widgets: index columns and actions, form fields, widget titles
 * and empty states, and the many other labelled shapes the manifest schema
 * allows. Keys that hold data rather than display text are not walked.
 *
 * An index column given only as a property name (a string, or an object
 * without a `label`) is shown with the schema property's title. That text is
 * listed with `fromSchema: true`: it is the schema's, not the app's, so it
 * is not translatable here.
 *
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
 */

/** Keys whose string value is display text. */
export const LABEL_KEYS = Object.freeze([
	'label',
	'title',
	'placeholder',
	'help',
	'emptyText',
])

/**
 * Keys never walked: they hold data, queries, code or references, not
 * display text, and a `title` inside them is a record field, not a label.
 */
const SKIPPED_KEYS = new Set([
	'register',
	'schema',
	'dataSource',
	'query',
	'filters',
	'filter',
	'defaults',
	'visibleWhen',
	'validation',
	'formatterOptions',
	'colorMap',
	'enum',
	'mcp',
])

/**
 * Add one place a text is used at.
 *
 * @param {Map<string, object>} found - texts collected so far.
 * @param {string} text - the label text.
 * @param {string} place - JSON-Pointer-ish location, e.g. `/pages/0/title`.
 * @param {boolean} fromSchema - whether the text is a schema property's.
 * @return {void}
 */
function add(found, text, place, fromSchema) {
	if (typeof text !== 'string' || text.trim() === '') {
		return
	}
	const key = `${fromSchema ? 'schema' : 'app'}\u0000${text}`
	const entry = found.get(key) || { text, places: [], fromSchema }
	entry.places.push(place)
	found.set(key, entry)
}

/**
 * Walk a node, collecting display text under the label keys.
 *
 * @param {Map<string, object>} found - texts collected so far.
 * @param {unknown} node - the node.
 * @param {string} path - its location.
 * @return {void}
 */
function walk(found, node, path) {
	if (Array.isArray(node)) {
		node.forEach((child, index) => walk(found, child, `${path}/${index}`))
		return
	}
	if (!node || typeof node !== 'object') {
		return
	}
	for (const [key, value] of Object.entries(node)) {
		if (SKIPPED_KEYS.has(key)) {
			continue
		}
		if (key === 'columns' && Array.isArray(value)) {
			walkColumns(found, value, `${path}/columns`)
			continue
		}
		if (LABEL_KEYS.includes(key) && typeof value === 'string') {
			add(found, value, `${path}/${key}`, false)
			continue
		}
		walk(found, value, `${path}/${key}`)
	}
}

/**
 * Index columns: an own `label` is the app's; a bare property name shows the
 * schema property's title and is listed as the schema's.
 *
 * @param {Map<string, object>} found - texts collected so far.
 * @param {Array} columns - the columns.
 * @param {string} path - their location.
 * @return {void}
 */
function walkColumns(found, columns, path) {
	columns.forEach((column, index) => {
		const at = `${path}/${index}`
		if (typeof column === 'string') {
			add(found, column, at, true)
			return
		}
		if (!column || typeof column !== 'object') {
			return
		}
		if (typeof column.label !== 'string' || column.label.trim() === '') {
			add(found, column.key, `${at}/key`, true)
		}
		walk(found, column, at)
	})
}

/**
 * Every distinct label text of a manifest, with where it is used.
 *
 * App texts come first, in the order first met; schema texts after them.
 *
 * @param {object} manifest - a version's manifest.
 * @return {Array<{text: string, places: string[], fromSchema: boolean}>} The labels.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
 */
export function collectManifestLabels(manifest) {
	const found = new Map()
	if (!manifest || typeof manifest !== 'object') {
		return []
	}
	walk(found, Array.isArray(manifest.menu) ? manifest.menu : [], '/menu')
	walk(found, Array.isArray(manifest.pages) ? manifest.pages : [], '/pages')
	const all = Array.from(found.values())
	return all.filter((l) => !l.fromSchema).concat(all.filter((l) => l.fromSchema))
}

/**
 * The texts a maker can translate: the app's own labels, not the schema's.
 *
 * @param {object} manifest - a version's manifest.
 * @return {Set<string>} The texts.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
 */
export function translatableTexts(manifest) {
	return new Set(
		collectManifestLabels(manifest)
			.filter((label) => !label.fromSchema)
			.map((label) => label.text),
	)
}
