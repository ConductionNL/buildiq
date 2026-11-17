// SPDX-License-Identifier: EUPL-1.2
/**
 * fieldTypes — the one list of field types the form designer offers, and the
 * small pure helpers the field panel uses for the `file` and `child-records`
 * types. Every key written here is one nextcloud-vue reads (`CnFileField`:
 * `accept`, `maxSize` in bytes, `multiple`, `capture`; the child-records
 * table: `schema`, `parentField`, `columns`). buildiq adds no vocabulary.
 *
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The field types both form builders offer, in palette order.
 *
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-file-field-with-its-limits-req-bqfk-001
 */
export const FIELD_TYPES = Object.freeze([
	'string',
	'number',
	'boolean',
	'select',
	'textarea',
	'date',
	'file',
	'child-records',
	'product-list',
])

/** The palette's name and icon per field type. */
const TYPE_META = Object.freeze({
	string: { label: () => t('buildiq', 'Text'), icon: 'Aa' },
	number: { label: () => t('buildiq', 'Number'), icon: '#' },
	boolean: { label: () => t('buildiq', 'Yes or no'), icon: '☑' },
	select: { label: () => t('buildiq', 'Choice'), icon: '☰' },
	textarea: { label: () => t('buildiq', 'Long text'), icon: '¶' },
	date: { label: () => t('buildiq', 'Date'), icon: '📅' },
	file: { label: () => t('buildiq', 'File'), icon: '📎' },
	'child-records': { label: () => t('buildiq', 'Table of records'), icon: '▦' },
	'product-list': { label: () => t('buildiq', 'Product list'), icon: '⊞' },
	// The device fields only the field builder offers (forms-signature-audio-and-location).
	signature: { label: () => t('buildiq', 'Signature'), icon: '✍' },
	audio: { label: () => t('buildiq', 'Audio recording'), icon: '🎤' },
	location: { label: () => t('buildiq', 'Location'), icon: '📍' },
})

/**
 * The translated name of a field type, or the type itself when unknown.
 *
 * @param {string} type - the field type.
 * @return {string}
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-builds-a-form-by-dragging-field-types-from-a-palette-req-bqfk-005
 */
export function fieldTypeLabel(type) {
	return TYPE_META[type] ? TYPE_META[type].label() : String(type)
}

/**
 * The palette icon of a field type.
 *
 * @param {string} type - the field type.
 * @return {string}
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-builds-a-form-by-dragging-field-types-from-a-palette-req-bqfk-005
 */
export function fieldTypeIcon(type) {
	return TYPE_META[type] ? TYPE_META[type].icon : '?'
}

/** The camera settings of a file field: off removes `capture`. */
export const CAPTURE_VALUES = Object.freeze(['', 'environment', 'user'])

/** Bytes in one megabyte, as `CnFileField` counts `maxSize`. */
export const BYTES_PER_MB = 1024 * 1024

/**
 * Megabytes typed by the maker as the bytes `maxSize` stores, or null for
 * blank, zero, negative or non-numeric input (which removes the key).
 *
 * @param {string|number} megabytes - the typed size.
 * @return {number|null}
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-file-field-with-its-limits-req-bqfk-001
 */
export function megabytesToBytes(megabytes) {
	if (megabytes === '' || megabytes === null || megabytes === undefined) {
		return null
	}
	const value = Number(megabytes)
	if (!Number.isFinite(value) || value <= 0) {
		return null
	}
	return Math.round(value * BYTES_PER_MB)
}

/**
 * A stored `maxSize` in bytes as the megabytes shown to the maker.
 *
 * @param {unknown} bytes - the stored `maxSize`.
 * @return {string} '' when unset.
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-file-field-with-its-limits-req-bqfk-001
 */
export function bytesToMegabytes(bytes) {
	if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes <= 0) {
		return ''
	}
	return String(Math.round((bytes / BYTES_PER_MB) * 100) / 100)
}

/**
 * The last path segment of a `$ref` (`#/components/schemas/order`,
 * `https://…/schemas/12`, `order`), lower-cased, or null.
 *
 * @param {unknown} ref - the reference.
 * @return {string|null}
 */
function refTail(ref) {
	if (typeof ref === 'number' && Number.isFinite(ref)) {
		return String(ref)
	}
	if (typeof ref !== 'string' || ref === '') {
		return null
	}
	const tail = ref.includes('/') ? ref.substring(ref.lastIndexOf('/') + 1) : ref
	return tail === '' ? null : tail.toLowerCase()
}

/**
 * The identifiers a schema can be referenced by: id, uuid, slug.
 *
 * @param {object|null} schema - the schema.
 * @return {string[]}
 */
function schemaIdentifiers(schema) {
	if (!schema || typeof schema !== 'object') {
		return []
	}
	return [schema.id, schema.uuid, schema.slug]
		.filter((v) => v !== undefined && v !== null && v !== '')
		.map((v) => String(v).toLowerCase())
}

/**
 * The child schema's properties that reference the parent schema: a `$ref`
 * (on the property, or on `items` for a list) naming the parent's id, uuid
 * or slug.
 *
 * @param {object|null} childSchema - the child schema with its `properties`.
 * @param {object|null} parentSchema - the form's own schema.
 * @return {string[]} the property names, in declaration order.
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-table-of-child-records-req-bqfk-003
 */
export function parentReferenceFields(childSchema, parentSchema) {
	const ids = schemaIdentifiers(parentSchema)
	const props = childSchema && childSchema.properties
	if (ids.length === 0 || !props || typeof props !== 'object') {
		return []
	}
	return Object.keys(props).filter((name) => {
		const prop = props[name] || {}
		const tail = refTail(prop.$ref) ?? refTail(prop.items && prop.items.$ref)
		return tail !== null && ids.includes(tail)
	})
}

/**
 * The property names of a schema, in declaration order.
 *
 * @param {object|null} schema - the schema.
 * @return {string[]}
 */
export function schemaPropertyNames(schema) {
	const props = schema && schema.properties
	return props && typeof props === 'object' ? Object.keys(props) : []
}

/**
 * A copy of `list` with `item` inserted at `index` (clamped to the ends).
 *
 * @param {Array} list - the list.
 * @param {number} index - the drop position.
 * @param {unknown} item - the item to insert.
 * @return {Array}
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-builds-a-form-by-dragging-field-types-from-a-palette-req-bqfk-005
 */
export function insertAt(list, index, item) {
	const next = list.slice()
	const at = Math.max(0, Math.min(Number.isInteger(index) ? index : next.length, next.length))
	next.splice(at, 0, item)
	return next
}

/**
 * A copy of `list` with the item at `from` moved so it lands before what was
 * at drop position `to` (a drop slot index, 0..length).
 *
 * @param {Array} list - the list.
 * @param {number} from - the index of the moved item.
 * @param {number} to - the drop slot.
 * @return {Array}
 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-builds-a-form-by-dragging-field-types-from-a-palette-req-bqfk-005
 */
export function moveToSlot(list, from, to) {
	if (from < 0 || from >= list.length) {
		return list.slice()
	}
	const next = list.slice()
	const [item] = next.splice(from, 1)
	const slot = to > from ? to - 1 : to
	next.splice(Math.max(0, Math.min(slot, next.length)), 0, item)
	return next
}

/** How many rows a product list holds by default, and at most (forms-multi-product-request D4). */
export const PRODUCT_LIST_DEFAULT_ITEMS = 10
export const PRODUCT_LIST_MAX_ITEMS = 25

/**
 * A product list field: the sub-objects widget over one answer key, whose
 * first column chooses a product and whose other columns are the details,
 * bounded by `maxItems` (default 10, at most 25). `forEach` names its key.
 *
 * @param {object} field - the field as stored, possibly without settings yet.
 * @param {{products?: Array<string>, details?: Array<string>, maxItems?: number|string}} [patch] - changed settings.
 * @return {object} The field.
 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
 */
export function productListField(field, patch = {}) {
	const columns = Array.isArray(field.columns) ? field.columns : []
	const productColumn = columns.find((col) => col && col.key === 'product') || {}
	const products = patch.products ?? (Array.isArray(productColumn.options) ? productColumn.options : [])
	const details = patch.details ?? columns.filter((col) => col && col.key !== 'product').map((col) => col.key)
	const wanted = Number(patch.maxItems ?? field.maxItems ?? PRODUCT_LIST_DEFAULT_ITEMS)
	const maxItems = Number.isFinite(wanted) ? Math.min(PRODUCT_LIST_MAX_ITEMS, Math.max(1, Math.round(wanted))) : PRODUCT_LIST_DEFAULT_ITEMS
	return {
		...field,
		type: 'product-list',
		widget: 'sub-objects',
		columns: [
			{ key: 'product', label: t('buildiq', 'Product'), options: products.map(String).filter((p) => p !== '') },
			...details.map(String).filter((d) => d !== '' && d !== 'product').map((key) => ({ key, label: key })),
		],
		maxItems,
	}
}
