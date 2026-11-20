// SPDX-License-Identifier: EUPL-1.2
/**
 * labels — warn the maker when an action, a widget or a form field has an
 * empty label (REQ-PLAT-005). The renderer cannot give an unlabelled control
 * an accessible name, so a screen reader announces nothing useful for it.
 *
 * Returned entries are `<pointer>: <code> "<id>"` strings, the convention of
 * `formLogic.js` and `schedules.js`, so the page designer's path-prefix inline
 * marks (REQ-OBPD-011) light up the item; the quoted id names it. Codes are
 * `buildiq.labels.warning.*`: warnings, never errors.
 *
 * An action needs a `label` (or a `title` or `ariaLabel` standing in for it):
 * missing or blank both warn. A widget or a form field warns when its `title`
 * or `label` is declared but blank; one that declares neither keeps the name
 * its renderer gives it (a widget type's own heading, a field's schema title).
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-005
 */

/** The page and config keys that hold action lists. */
const ACTION_LISTS = Object.freeze(['actions', 'headerActions', 'rowActions'])

/**
 * Whether `value` is a plain object (not an array, not null).
 *
 * @param {unknown} value - candidate value.
 * @return {boolean}
 */
function isPlainObject(value) {
	return !!value && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Whether a value is missing, not text, or only white space.
 *
 * @param {unknown} value - candidate label.
 * @return {boolean}
 */
function isBlank(value) {
	return typeof value !== 'string' || value.trim() === ''
}

/**
 * The name to quote for an item: its id, key or name, else its position.
 *
 * @param {object} item - the action, widget or field.
 * @param {number} index - its position in its list.
 * @return {string}
 */
function nameOf(item, index) {
	for (const key of ['id', 'key', 'name']) {
		if (typeof item[key] === 'string' && item[key] !== '') {
			return item[key]
		}
	}
	return `#${index + 1}`
}

/**
 * Whether an action has no label of any kind.
 *
 * @param {object} action - the action.
 * @return {boolean}
 */
function actionUnlabelled(action) {
	return isBlank(action.label) && isBlank(action.title) && isBlank(action.ariaLabel)
}

/**
 * Whether a widget or field declares a title or label that is blank.
 *
 * @param {object} item - the widget or field.
 * @return {boolean}
 */
function declaredBlank(item) {
	return ['title', 'label'].some((key) => key in item && isBlank(item[key]))
}

/**
 * The warnings for one list of items under a pointer.
 *
 * @param {unknown} list - the list (anything else yields nothing).
 * @param {string} path - its JSON pointer.
 * @param {string} kind - action, widget or field.
 * @param {(item: object) => boolean} unlabelled - whether one item needs a label.
 * @return {string[]}
 */
function listWarnings(list, path, kind, unlabelled) {
	if (!Array.isArray(list)) {
		return []
	}
	const out = []
	list.forEach((item, index) => {
		if (isPlainObject(item) && unlabelled(item)) {
			out.push(`${path}/${index}: buildiq.labels.warning.${kind}-needs-a-label "${nameOf(item, index)}"`)
		}
	})
	return out
}

/**
 * Warn about every action, widget and form field without a label, on every
 * page of the manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - `<pointer>: <code> "<id>"` warning strings.
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-005
 */
export function validateLabels(manifest) {
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	const warnings = []
	pages.forEach((page, pIndex) => {
		if (!isPlainObject(page)) {
			return
		}
		const base = `/pages/${pIndex}`
		warnings.push(...listWarnings(page.actions, `${base}/actions`, 'action', actionUnlabelled))
		const config = isPlainObject(page.config) ? page.config : {}
		for (const key of ACTION_LISTS) {
			warnings.push(...listWarnings(config[key], `${base}/config/${key}`, 'action', actionUnlabelled))
		}
		warnings.push(...listWarnings(config.widgets, `${base}/config/widgets`, 'widget', declaredBlank))
		if (page.type === 'form') {
			warnings.push(...listWarnings(config.fields, `${base}/config/fields`, 'field', declaredBlank))
		}
	})
	return warnings
}
