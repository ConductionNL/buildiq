/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the one field type list and its helpers
 * (forms-designer-file-camera-children-and-assign T01, T03, T09).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'
import FormLayoutBuilder from '../../../src/components/page-editor/fields/FormLayoutBuilder.vue'
import {
	bytesToMegabytes,
	FIELD_TYPES,
	fieldTypeLabel,
	insertAt,
	megabytesToBytes,
	moveToSlot,
	parentReferenceFields,
} from '../../../src/components/page-editor/fields/fieldTypes.js'

function optionValues(wrapper) {
	const select = wrapper.findAll('select').find((s) => s.findAll('option').some((o) => o.element.value === 'date'))
	return select.findAll('option').map((o) => o.element.value)
}

describe('FIELD_TYPES', () => {
	it('adds file, child-records and scan to the six plain types', () => {
		expect([...FIELD_TYPES]).toEqual(['string', 'number', 'boolean', 'select', 'textarea', 'date', 'file', 'child-records', 'scan'])
	})

	it('is offered identically by both form builders', () => {
		const pageBuilder = mount(FormFieldBuilder, { props: { modelValue: [{ key: 'a', label: 'A', type: 'string' }] } })
		const layoutBuilder = mount(FormLayoutBuilder, { props: { fields: [{ name: 'a', label: 'A' }] } })
		expect(optionValues(pageBuilder)).toEqual([...FIELD_TYPES])
		expect(optionValues(layoutBuilder)).toEqual([...FIELD_TYPES])
	})

	it('names each type for the palette and falls back to the raw type', () => {
		expect(fieldTypeLabel('file')).toBe('File')
		expect(fieldTypeLabel('date')).toBe('Date')
		expect(fieldTypeLabel('unknown')).toBe('unknown')
	})
})

describe('megabytes and bytes', () => {
	it('stores 10 MB as 10485760 bytes and shows it back as 10', () => {
		expect(megabytesToBytes('10')).toBe(10485760)
		expect(bytesToMegabytes(10485760)).toBe('10')
		expect(megabytesToBytes('0.5')).toBe(524288)
	})

	it('removes the size on blank, zero, negative or text', () => {
		expect(megabytesToBytes('')).toBeNull()
		expect(megabytesToBytes('0')).toBeNull()
		expect(megabytesToBytes('-3')).toBeNull()
		expect(megabytesToBytes('ten')).toBeNull()
		expect(bytesToMegabytes(undefined)).toBe('')
	})
})

describe('parentReferenceFields', () => {
	const order = { id: 7, slug: 'order', title: 'Order' }

	it('finds the child property whose $ref names the parent by slug, id or path', () => {
		const line = { properties: {
			order: { type: 'string', $ref: '#/components/schemas/order' },
			product: { type: 'string' },
			alsoOrder: { type: 'object', $ref: 7 },
			viaItems: { type: 'array', items: { $ref: 'https://x/api/schemas/ORDER' } },
		} }
		expect(parentReferenceFields(line, order)).toEqual(['order', 'alsoOrder', 'viaItems'])
	})

	it('finds nothing when no property points back, or without a parent', () => {
		expect(parentReferenceFields({ properties: { customer: { $ref: 'customer' } } }, order)).toEqual([])
		expect(parentReferenceFields({ properties: { order: { $ref: 'order' } } }, null)).toEqual([])
	})
})

describe('insertAt and moveToSlot', () => {
	it('inserts at a drop slot, clamped to the ends', () => {
		expect(insertAt(['a', 'b'], 1, 'x')).toEqual(['a', 'x', 'b'])
		expect(insertAt(['a', 'b'], 9, 'x')).toEqual(['a', 'b', 'x'])
		expect(insertAt(['a', 'b'], -1, 'x')).toEqual(['x', 'a', 'b'])
	})

	it('moves an item before the item at the slot', () => {
		expect(moveToSlot(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'a', 'c'])
		expect(moveToSlot(['a', 'b', 'c'], 0, 3)).toEqual(['b', 'c', 'a'])
		expect(moveToSlot(['a', 'b', 'c'], 2, 0)).toEqual(['c', 'a', 'b'])
		expect(moveToSlot(['a', 'b', 'c'], 5, 0)).toEqual(['a', 'b', 'c'])
	})
})
