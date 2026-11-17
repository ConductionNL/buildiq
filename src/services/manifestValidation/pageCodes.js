// SPDX-License-Identifier: EUPL-1.2
/**
 * pageCodes — app-side validation of scanning and codes on pages
 * (pages-qr-and-barcodes, design D5):
 *
 *   - an index page's `config.scan` = `{enabled, field, onMatch}`, whose field
 *     is a property of the page's schema and whose `onMatch` is `open` or
 *     `filter`;
 *   - a form field of type `scan`, with `formats[]` from `qr`, `code128` and
 *     `ean13`, refused on a form in `public` mode (portaliq's surface);
 *   - a detail page `code` widget (`config.widgets[]`), with a `format` and a
 *     `source` of `field` or `address`, where Code 128 and EAN-13 need a field
 *     and an EAN-13 field must hold digits.
 *
 * A property is checked only when the manifest embeds the page's schema; a
 * manifest that references external schemas cannot be disproved and passes.
 * Errors are `<pointer>: <code>` strings for the inline marks.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-index-pages-can-scan-to-find-a-record-req-bqqr-001
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-forms-can-fill-a-field-from-a-scan-req-bqqr-002
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
 */
import { schemaName } from './manifestSchemas.js'

/** The code formats a scan accepts and a code widget draws. */
export const CODE_FORMATS = Object.freeze(['qr', 'code128', 'ean13'])

/** What a scan match does on an index page. */
export const SCAN_MATCH_ACTIONS = Object.freeze(['open', 'filter'])

/** Where a code widget takes its value from. */
export const CODE_SOURCES = Object.freeze(['field', 'address'])

const SCAN_KEYS = Object.freeze(['enabled', 'field', 'onMatch'])

const ERROR = 'buildiq.pageCodes.error.'

/**
 * Whether a value is a non-null, non-array object.
 *
 * @param {unknown} value - the value.
 * @return {boolean}
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Whether a value is a string with more than whitespace in it.
 *
 * @param {unknown} value - the value.
 * @return {boolean}
 */
function isFilledString(value) {
	return typeof value === 'string' && value.trim() !== ''
}

/**
 * The properties of a schema the manifest embeds, or null when it does not.
 *
 * @param {object} manifest - the manifest.
 * @param {unknown} slug - the page's schema slug.
 * @return {?object} The properties by name.
 */
function embeddedProperties(manifest, slug) {
	if (!isFilledString(slug)) {
		return null
	}
	const entry = schemaEntry(manifest && manifest.schemas, slug)
	if (!entry) {
		return null
	}
	const properties = entry.properties || (entry.schema && entry.schema.properties)
	return isPlainObject(properties) ? properties : null
}

/**
 * The embedded schema with this slug, from a list or a map of schemas, or null.
 *
 * @param {unknown} schemas - the manifest's schemas.
 * @param {string} slug - the schema slug.
 * @return {?object} The schema entry.
 */
function schemaEntry(schemas, slug) {
	if (Array.isArray(schemas)) {
		return schemas.find((s) => isPlainObject(s) && schemaName(s) === slug) || null
	}
	if (isPlainObject(schemas)) {
		return isPlainObject(schemas[slug]) ? schemas[slug] : null
	}
	return null
}

/**
 * Whether a schema property holds only digits: an integer, or a string whose
 * pattern admits a run of digits and refuses letters.
 *
 * @param {object} property - the property definition.
 * @return {boolean}
 */
export function holdsDigits(property) {
	if (!isPlainObject(property)) {
		return false
	}
	if (property.type === 'integer') {
		return true
	}
	if (property.type !== 'string' || typeof property.pattern !== 'string') {
		return false
	}
	try {
		const pattern = new RegExp(property.pattern)
		return pattern.test('4006381333931') && !pattern.test('ABC-123')
	} catch {
		return false
	}
}

/**
 * Errors of an index page's `config.scan`.
 *
 * @param {object} scan - the scan block.
 * @param {{pointer: string, properties: ?object}} ctx - where it sits and the schema's properties.
 * @return {string[]}
 */
function scanErrors(scan, ctx) {
	if (!isPlainObject(scan)) {
		return [`${ctx.pointer}: ${ERROR}scan-not-object`]
	}
	const errors = Object.keys(scan)
		.filter((key) => !SCAN_KEYS.includes(key))
		.map((key) => `${ctx.pointer}/${key}: ${ERROR}scan-unknown-key`)
	if (typeof scan.enabled !== 'boolean') {
		errors.push(`${ctx.pointer}/enabled: ${ERROR}scan-enabled-not-boolean`)
	}
	if (scan.enabled !== true) {
		return errors
	}
	if (!isFilledString(scan.field)) {
		errors.push(`${ctx.pointer}/field: ${ERROR}scan-field-required`)
	} else if (ctx.properties && !Object.hasOwn(ctx.properties, scan.field)) {
		errors.push(`${ctx.pointer}/field: ${ERROR}scan-field-unknown`)
	}
	if (!SCAN_MATCH_ACTIONS.includes(scan.onMatch)) {
		errors.push(`${ctx.pointer}/onMatch: ${ERROR}scan-on-match-invalid`)
	}
	return errors
}

/**
 * Errors of a form field of type `scan`.
 *
 * @param {object} field - the field.
 * @param {{pointer: string, isPublic: boolean}} ctx - where it sits and whether the form is public.
 * @return {string[]}
 */
function scanFieldErrors(field, ctx) {
	const errors = []
	if (ctx.isPublic) {
		errors.push(`${ctx.pointer}/type: ${ERROR}scan-on-public-form`)
	}
	const formats = field.formats
	if (!Array.isArray(formats) || formats.length === 0) {
		errors.push(`${ctx.pointer}/formats: ${ERROR}formats-required`)
	} else {
		formats.forEach((format, index) => {
			if (!CODE_FORMATS.includes(format)) {
				errors.push(`${ctx.pointer}/formats/${index}: ${ERROR}format-unknown`)
			}
		})
	}
	return errors
}

/**
 * Errors of a detail page `code` widget.
 *
 * @param {object} widget - the widget.
 * @param {{pointer: string, properties: ?object}} ctx - where it sits and the schema's properties.
 * @return {string[]}
 */
function codeWidgetErrors(widget, ctx) {
	const errors = []
	const known = CODE_FORMATS.includes(widget.format)
	if (!known) {
		errors.push(`${ctx.pointer}/format: ${ERROR}format-unknown`)
	}
	if (!CODE_SOURCES.includes(widget.source)) {
		errors.push(`${ctx.pointer}/source: ${ERROR}source-invalid`)
		return errors
	}
	if (widget.source === 'address') {
		if (widget.format === 'code128' || widget.format === 'ean13') {
			errors.push(`${ctx.pointer}/source: ${ERROR}address-needs-qr`)
		}
		return errors
	}
	return errors.concat(codeFieldErrors(widget, ctx))
}

/**
 * Errors of the field a `code` widget with a field source shows.
 *
 * @param {object} widget - the widget.
 * @param {{pointer: string, properties: ?object}} ctx - where it sits and the schema's properties.
 * @return {string[]}
 */
function codeFieldErrors(widget, ctx) {
	if (!isFilledString(widget.field)) {
		return [`${ctx.pointer}/field: ${ERROR}code-field-required`]
	}
	if (!ctx.properties) {
		return []
	}
	if (!Object.hasOwn(ctx.properties, widget.field)) {
		return [`${ctx.pointer}/field: ${ERROR}code-field-unknown`]
	}
	if (widget.format === 'ean13' && !holdsDigits(ctx.properties[widget.field])) {
		return [`${ctx.pointer}/field: ${ERROR}ean13-field-not-digits`]
	}
	return []
}

/**
 * Errors of one page.
 *
 * @param {object} manifest - the manifest.
 * @param {object} page - the page.
 * @param {string} pointer - the page's pointer.
 * @return {string[]}
 */
function pageErrors(manifest, page, pointer) {
	const config = isPlainObject(page.config) ? page.config : {}
	const properties = embeddedProperties(manifest, config.schema)
	if (page.type === 'index' && config.scan !== undefined) {
		return scanErrors(config.scan, { pointer: `${pointer}/config/scan`, properties })
	}
	if (page.type === 'form' && Array.isArray(config.fields)) {
		return formScanErrors(config, pointer)
	}
	if (page.type === 'detail' && Array.isArray(config.widgets)) {
		return detailCodeErrors(config.widgets, pointer, properties)
	}
	return []
}

/**
 * Errors of a form page's scan fields.
 *
 * @param {object} config - the form page's config, with a `fields` list.
 * @param {string} pointer - the page's pointer.
 * @return {string[]}
 */
function formScanErrors(config, pointer) {
	const isPublic = (config.mode || 'public') === 'public'
	return config.fields.flatMap((field, index) =>
		isPlainObject(field) && field.type === 'scan'
			? scanFieldErrors(field, { pointer: `${pointer}/config/fields/${index}`, isPublic })
			: [])
}

/**
 * Errors of a detail page's code widgets.
 *
 * @param {Array} widgets - the detail page's widgets.
 * @param {string} pointer - the page's pointer.
 * @param {?object} properties - the schema's properties.
 * @return {string[]}
 */
function detailCodeErrors(widgets, pointer, properties) {
	return widgets.flatMap((widget, index) =>
		isPlainObject(widget) && widget.type === 'code'
			? codeWidgetErrors(widget, { pointer: `${pointer}/config/widgets/${index}`, properties })
			: [])
}

/**
 * Validate scanning and codes across a manifest's pages.
 *
 * @param {object} manifest - the manifest.
 * @return {string[]} `<pointer>: <code>` errors; empty when there is nothing to say.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-index-pages-can-scan-to-find-a-record-req-bqqr-001
 */
export function validatePageCodes(manifest) {
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	return pages.flatMap((page, index) =>
		isPlainObject(page) ? pageErrors(manifest, page, `/pages/${index}`) : [])
}
