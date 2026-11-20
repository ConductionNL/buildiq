// SPDX-License-Identifier: EUPL-1.2
/**
 * indexViews — the view modes an index page offers, and the fields each needs.
 *
 * An index page lists the views it offers in `config.viewModes` (`table` is
 * always one of them). Calendar, board and date axis each need fields from the
 * page's schema, kept in `config.calendar`, `config.board` and
 * `config.dateAxis`. A missing required field, or one the schema lacks, is a
 * designer finding shaped `<pointer>: <code>` like the other validators.
 *
 * The manifest-wide check has no schema at hand, so it reports missing fields
 * only; the page editor passes the schema's properties and also gets the
 * findings for fields the schema lacks.
 *
 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Every view mode an index page can offer, in the order the editor shows them.
 *
 * @type {string[]}
 */
export const VIEW_MODES = ['table', 'cards', 'calendar', 'board', 'dateAxis']

/**
 * The settings block, required fields and optional fields of each view mode that has them.
 *
 * @type {Record<string, {required: string[], optional: string[]}>}
 */
export const VIEW_FIELDS = {
	calendar: { required: ['dateField'], optional: ['endDateField', 'titleField'] },
	board: { required: ['statusField'], optional: ['swimlaneField'] },
	dateAxis: { required: ['startField', 'endField'], optional: ['laneField'] },
}

/**
 * The view modes a page config offers, `table` always first.
 *
 * @param {object} config - the index page's config.
 * @return {string[]} The modes.
 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
 */
export function viewModesOf(config) {
	const declared = Array.isArray(config && config.viewModes) ? config.viewModes : []
	return ['table'].concat(VIEW_MODES.filter((mode) => mode !== 'table' && declared.includes(mode)))
}

/**
 * The findings for one index page's view settings.
 *
 * @param {object} config - the index page's config.
 * @param {string} pointer - JSON pointer of the config, e.g. `/pages/0/config`.
 * @param {object|null} [properties] - the schema's properties; null skips the "schema lacks it" check.
 * @return {string[]} `<pointer>: <code>` findings.
 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
 */
export function validateIndexViewConfig(config, pointer, properties = null) {
	const errors = []
	const modes = viewModesOf(config)
	for (const [mode, fields] of Object.entries(VIEW_FIELDS)) {
		if (!modes.includes(mode)) {
			continue
		}
		const block = (config && typeof config[mode] === 'object' && config[mode]) || {}
		for (const field of fields.required) {
			if (typeof block[field] !== 'string' || block[field].trim() === '') {
				errors.push(`${pointer}/${mode}/${field}: buildiq.indexViews.error.${mode}-${field}-required`)
			}
		}
		if (properties === null || typeof properties !== 'object') {
			continue
		}
		for (const field of fields.required.concat(fields.optional)) {
			const name = block[field]
			if (typeof name === 'string' && name.trim() !== '' && !Object.hasOwn(properties, name)) {
				errors.push(`${pointer}/${mode}/${field}: buildiq.indexViews.error.field-unknown`)
			}
		}
	}
	return errors
}

/**
 * The view findings of every index page in a manifest (missing fields only).
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} `<pointer>: <code>` findings.
 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
 */
export function validateIndexViews(manifest) {
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	return pages.flatMap((page, idx) => {
		if (!page || page.type !== 'index' || !page.config || typeof page.config !== 'object') {
			return []
		}
		return validateIndexViewConfig(page.config, `/pages/${idx}/config`)
	})
}

/**
 * One sentence per finding code.
 *
 * @return {Record<string, string>} Code to message.
 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
 */
export function indexViewMessages() {
	return {
		'buildiq.indexViews.error.calendar-dateField-required': t('buildiq', 'The calendar needs a date field.'),
		'buildiq.indexViews.error.board-statusField-required': t('buildiq', 'The board needs a status field.'),
		'buildiq.indexViews.error.dateAxis-startField-required': t('buildiq', 'The date axis needs a start date field.'),
		'buildiq.indexViews.error.dateAxis-endField-required': t('buildiq', 'The date axis needs an end date field.'),
		'buildiq.indexViews.error.field-unknown': t('buildiq', 'The schema has no field with this name.'),
	}
}

/**
 * Whether a schema property holds a date or a date and time.
 *
 * @param {object} property - a JSON-Schema property.
 * @return {boolean}
 */
function isDate(property) {
	return !!property && (property.format === 'date' || property.format === 'date-time')
}

/**
 * The property names a view field may be picked from.
 *
 * @param {object} properties - the schema's properties.
 * @param {'date'|'status'|'text'|'any'} kind - which properties suit the field.
 * @return {string[]} Property names, in schema order.
 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
 */
export function propertiesOfKind(properties, kind) {
	return Object.entries(properties || {})
		.filter(([, property]) => {
			if (!property || typeof property !== 'object') {
				return false
			}
			if (kind === 'date') {
				return isDate(property)
			}
			if (kind === 'status') {
				return Array.isArray(property.enum) || !!property.lifecycle || !!property['x-lifecycle']
			}
			if (kind === 'text') {
				return property.type === 'string' && !isDate(property)
			}
			return true
		})
		.map(([name]) => name)
}
