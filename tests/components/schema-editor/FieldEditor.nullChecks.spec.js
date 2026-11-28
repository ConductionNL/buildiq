/**
 * The schema designer treats a missing value and a null the same way, and
 * keeps a falsy value such as 0 or false that a maker set on purpose.
 *
 * @spec openspec/changes/archive/2026-11-24-data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 */
import { describe, expect, it, vi } from 'vitest'
import FieldEditor, {
	fieldsToSchema,
	schemaToFields,
} from '../../../src/components/schema-editor/FieldEditor.vue'

/**
 * The one field a one-property schema loads into.
 *
 * @param {object} prop The property.
 * @return {object} The field row.
 */
function fieldOf(prop) {
	return schemaToFields({ properties: { f: prop } })[0]
}

/**
 * The property a one-field schema saves.
 *
 * @param {object} field The field row.
 * @return {object} The saved property.
 */
function propertyOf(field) {
	return fieldsToSchema([field]).properties.f
}

describe('FieldEditor null and undefined checks', () => {
	it('loads a missing default and a null default as null', () => {
		expect(fieldOf({ type: 'string' }).default).toBeNull()
		expect(fieldOf({ type: 'string', default: null }).default).toBeNull()
	})

	it('loads and saves a default of 0 or false', () => {
		const zero = fieldOf({ type: 'integer', default: 0 })
		expect(zero.default).toBe(0)
		expect(propertyOf(zero).default).toBe(0)

		const no = fieldOf({ type: 'boolean', default: false })
		expect(no.default).toBe(false)
		expect(propertyOf(no).default).toBe(false)
	})

	it('saves no default for a null, undefined or empty one', () => {
		const field = fieldOf({ type: 'string' })
		for (const value of [null, undefined, '']) {
			expect(propertyOf({ ...field, default: value })).not.toHaveProperty('default')
		}
	})

	it('gives a choice without a label, or with a null label, an empty one', () => {
		const field = fieldOf({
			type: 'string',
			enum: ['a', 'b', 'c'],
			'x-enum-labels': { a: 'Alpha', b: null },
		})
		const options = JSON.stringify(field)
		expect(options).toContain('"value":"a","label":"Alpha"')
		expect(options).toContain('"value":"b","label":""')
		expect(options).toContain('"value":"c","label":""')
	})

	it('drops a validation rule set to empty, null or undefined, and keeps 0', () => {
		const { updateValidation } = FieldEditor.methods
		for (const value of ['', null, undefined]) {
			const host = { fields: [{ validation: { minLength: 2, maxLength: 9 } }], emitFields: vi.fn() }
			updateValidation.call(host, 0, 'minLength', value)
			expect(host.emitFields.mock.calls[0][0][0].validation).toEqual({ maxLength: 9 })
		}
		const host = { fields: [{ validation: {} }], emitFields: vi.fn() }
		updateValidation.call(host, 0, 'minLength', 0)
		expect(host.emitFields.mock.calls[0][0][0].validation).toEqual({ minLength: 0 })
	})

	it('coerces an empty, null or undefined input to null', () => {
		const { toIntOrNull, toNumberOrNull } = FieldEditor.methods
		for (const value of ['', null, undefined]) {
			expect(toIntOrNull(value)).toBeNull()
			expect(toNumberOrNull(value)).toBeNull()
		}
		expect(toIntOrNull('0')).toBe(0)
		expect(toNumberOrNull('0.5')).toBe(0.5)
	})
})
