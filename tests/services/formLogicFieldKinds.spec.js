/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the designer findings on child-records fields and assign
 * rules (forms-designer-file-camera-children-and-assign T05, REQ-BQFK-003,
 * REQ-BQFK-004).
 */
import { describe, expect, it } from 'vitest'
import { validateFormLogic } from '../../src/services/manifestValidation/formLogic.js'

function withFields(fields) {
	return { pages: [{ type: 'form', config: { fields } }] }
}

describe('validateFormLogic: child-records fields', () => {
	it('passes a child-records field with its schema and parent field', () => {
		expect(validateFormLogic(withFields([
			{ key: 'lines', type: 'child-records', schema: 'order-line', parentField: 'order', columns: ['product'] },
		]))).toEqual([])
	})

	it('reports a child-records field without schema or parent field', () => {
		expect(validateFormLogic(withFields([{ key: 'lines', type: 'child-records' }]))).toEqual([
			'/pages/0/config/fields/0/schema: buildiq.formLogic.error.child-records-schema-required',
			'/pages/0/config/fields/0/parentField: buildiq.formLogic.error.child-records-parent-field-required',
		])
	})

	it('does not ask other field types for a schema', () => {
		expect(validateFormLogic(withFields([{ key: 'f', type: 'file' }]))).toEqual([])
	})
})

describe('validateFormLogic: assign rules', () => {
	const country = { key: 'country', type: 'string' }

	it('passes "when country equals NL, set EUR" and an @answer copy', () => {
		expect(validateFormLogic(withFields([
			country,
			{ key: 'currency', type: 'string', assign: [
				{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' },
				{ when: { field: 'country', op: 'neq', value: 'NL' }, value: '@answer.country' },
			] },
		]))).toEqual([])
	})

	it('reports a rule naming an unknown field, in its condition or its answer', () => {
		expect(validateFormLogic(withFields([
			country,
			{ key: 'currency', type: 'string', assign: [
				{ when: { field: 'land', value: 'NL' }, value: '@answer.valuta.code' },
			] },
		]))).toEqual([
			'/pages/0/config/fields/1/assign/0/when/field: buildiq.formLogic.error.dangling-assign-field',
			'/pages/0/config/fields/1/assign/0/value: buildiq.formLogic.error.dangling-assign-field',
		])
	})

	it('reports a rule reading the field it sets', () => {
		expect(validateFormLogic(withFields([
			country,
			{ key: 'currency', assign: [{ when: { field: 'currency', value: 'x' }, value: '@answer.currency' }] },
		]))).toEqual([
			'/pages/0/config/fields/1/assign/0/when/field: buildiq.formLogic.error.assign-reads-itself',
			'/pages/0/config/fields/1/assign/0/value: buildiq.formLogic.error.assign-reads-itself',
		])
	})

	it('reports a blank condition field, a bad operator, a non-object rule and a non-list assign', () => {
		expect(validateFormLogic(withFields([
			country,
			{ key: 'a', assign: [{ when: { field: '', op: 'like' }, value: 1 }, 'x'] },
			{ key: 'b', assign: { when: {} } },
		]))).toEqual([
			'/pages/0/config/fields/1/assign/0/when/field: buildiq.formLogic.error.assign-field-required',
			'/pages/0/config/fields/1/assign/0/when/op: buildiq.formLogic.error.condition-op-not-allowed',
			'/pages/0/config/fields/1/assign/1: buildiq.formLogic.error.assign-rule-not-object',
			'/pages/0/config/fields/2/assign: buildiq.formLogic.error.assign-not-array',
		])
	})

	it('leaves remote conditions and token values alone', () => {
		expect(validateFormLogic(withFields([
			country,
			{ key: 'c', assign: [{ when: { endpoint: '/x', field: 'nope' }, value: '@me.email' }] },
		]))).toEqual([])
	})
})
