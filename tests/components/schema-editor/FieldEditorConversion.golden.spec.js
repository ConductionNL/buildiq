/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the FieldEditor conversions between JSON Schema properties
 * and editor rows: schemaToFields over every field type with its validation
 * keys set, unset, falsy and null, and fieldsToSchema over the rows that
 * come out of it and over hand-written rows.
 */

import { describe, expect, it } from 'vitest'
import {
	fieldsToSchema,
	schemaToFields,
} from '../../../src/components/schema-editor/FieldEditor.vue'

const VALUES = {
	set: {
		format: 'email',
		pattern: '^a',
		minLength: 1,
		maxLength: 9,
		minimum: -2,
		maximum: 2,
		multipleOf: 0.5,
		minItems: 0,
		maxItems: 3,
	},
	falsy: {
		format: '',
		pattern: '',
		minLength: 0,
		maxLength: 0,
		minimum: 0,
		maximum: 0,
		multipleOf: 0,
		minItems: 0,
		maxItems: 0,
	},
	null: {
		format: null,
		pattern: null,
		minLength: null,
		maxLength: null,
		minimum: null,
		maximum: null,
		multipleOf: null,
		minItems: null,
		maxItems: null,
	},
	none: {},
}

const TYPES = [
	'string',
	'number',
	'integer',
	'boolean',
	'array',
	'object',
	'relation',
	'unknown',
	'constructor',
	'toString',
	undefined,
]

/**
 * A row without its generated key, so the golden does not depend on how
 * many rows were made before.
 *
 * @param {object} row An editor row.
 * @return {object} The row without `_key`.
 */
function withoutKey(row) {
	const { _key, ...rest } = row
	return rest
}

/**
 * One property of each type, with the given validation values.
 *
 * @param {object} values Validation keys and values.
 * @return {object} A JSON Schema fragment.
 */
function schemaFor(values) {
	const properties = {}
	for (const type of TYPES) {
		const prop = { ...values, description: 'd', default: 'x', enum: ['a'], 'x-extra': 1 }
		if (type === 'relation') {
			prop['x-openregister-relation'] = {
				target: 'person',
				cardinality: 'many',
				inverseOf: 'cases',
				extra: true,
			}
		} else if (type !== undefined) {
			prop.type = type
		}
		if (type === 'array') {
			prop.items = { type: 'integer', title: 'item' }
		}
		properties['p_' + String(type)] = prop
	}
	properties.empty = null
	properties.relationEmpty = { 'x-openregister-relation': {} }
	properties.arrayNoItems = { type: 'array', items: {} }
	properties.stringWithItems = { type: 'string', items: { type: 'integer' } }
	return {
		properties,
		required: ['p_string', 'p_array', 'missing'],
		'x-property-order': ['p_array', 'missing', 'p_string'],
	}
}

/**
 * Hand-written rows of each type with the given validation values.
 *
 * @param {object} values Validation keys and values.
 * @return {Array<object>} Editor rows.
 */
function rowsFor(values) {
	return TYPES.map((type, i) => ({
		name: 'r' + i,
		type,
		required: i % 2 === 0,
		default: i % 3 === 0 ? '' : i,
		description: i % 2 === 0 ? '' : 'desc',
		validation: {
			...values,
			itemsType: i % 2 === 0 ? 'number' : '',
			target: i % 2 === 0 ? 't' : '',
			cardinality: i % 2 === 0 ? '' : 'many',
			inverseOf: i % 2 === 0 ? 'back' : '',
		},
		_raw: i % 4 === 0 ? null : { title: 'kept', type: 'gone', items: { title: 'it', type: 'x' } },
	})).concat([{ name: '', type: 'string' }, { name: 'noValidation', type: 'array' }])
}

/**
 * The value as indented JSON text, so the golden also pins key order (the
 * snapshot serialiser sorts object keys).
 *
 * @param {*} value Any JSON value.
 * @return {string} The JSON text.
 */
function ordered(value) {
	return JSON.stringify(value, null, 1)
}

describe('FieldEditor conversions golden', () => {
	for (const [name, values] of Object.entries(VALUES)) {
		it(`schemaToFields and back, values ${name}`, () => {
			const fields = schemaToFields(schemaFor(values))
			expect(ordered(fields.map(withoutKey))).toMatchSnapshot()
			expect(ordered(fieldsToSchema(fields))).toMatchSnapshot()
		})

		it(`fieldsToSchema over hand-written rows, values ${name}`, () => {
			expect(ordered(fieldsToSchema(rowsFor(values)))).toMatchSnapshot()
		})
	}

	it('schemaToFields of an empty or missing schema', () => {
		expect(schemaToFields(null)).toEqual([])
		expect(schemaToFields({})).toEqual([])
	})
})
