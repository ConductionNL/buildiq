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
	// pages-qr-and-barcodes REQ-BQQR-002: a field filled by scanning a code.
	'scan',
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
	scan: { label: () => t('buildiq', 'Scanned code'), icon: '⌗' },
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
