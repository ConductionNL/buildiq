/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ChildRecordsFieldSettings.vue (REQ-BQFK-003).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ChildRecordsFieldSettings from '../../../src/components/page-editor/fields/ChildRecordsFieldSettings.vue'

const SCHEMAS = [
	{ id: 1, slug: 'order', title: 'Order', properties: { number: { type: 'string' } } },
	{
		id: 2,
		slug: 'order-line',
		title: 'OrderLine',
		properties: {
			order: { type: 'string', $ref: '#/components/schemas/order' },
			product: { type: 'string' },
			quantity: { type: 'integer' },
		},
	},
	{ id: 3, slug: 'customer', title: 'Customer', properties: { name: { type: 'string' } } },
]

function mountSettings(field) {
	return mount(ChildRecordsFieldSettings, {
		props: { field, schemas: SCHEMAS, parentSchema: 'order' },
	})
}

function lastEmit(wrapper) {
	const all = wrapper.emitted('update')
	return all[all.length - 1][0]
}

describe('ChildRecordsFieldSettings', () => {
	const base = { key: 'lines', label: 'Order lines', type: 'child-records' }

	it('writes the child schema with the field that points back at the order', async () => {
		const wrapper = mountSettings(base)
		await wrapper.find('[data-testid="child-schema"]').setValue('order-line')
		expect(lastEmit(wrapper)).toEqual({ ...base, schema: 'order-line', parentField: 'order' })
	})

	it('writes the picked columns in the child schema\'s order', async () => {
		const wrapper = mountSettings({ ...base, schema: 'order-line', parentField: 'order' })
		expect(wrapper.find('[data-testid="child-column-order"]').exists()).toBe(false)
		await wrapper.find('[data-testid="child-column-quantity"]').setValue(true)
		expect(lastEmit(wrapper).columns).toEqual(['quantity'])
		await wrapper.setProps({ field: lastEmit(wrapper) })
		await wrapper.find('[data-testid="child-column-product"]').setValue(true)
		expect(lastEmit(wrapper)).toEqual({ ...base, schema: 'order-line', parentField: 'order', columns: ['product', 'quantity'] })
	})

	it('names a schema without a reference to the order as unusable and does not save it', async () => {
		const wrapper = mountSettings(base)
		await wrapper.find('[data-testid="child-schema"]').setValue('customer')
		expect(wrapper.emitted('update')).toBeUndefined()
		const message = wrapper.find('[data-testid="child-schema-unusable"]')
		expect(message.exists()).toBe(true)
		expect(message.text()).toContain('This schema has no field pointing at')
		expect(wrapper.find('[data-testid="child-parent-field"]').exists()).toBe(false)
	})

	it('clears the schema, parent field and columns when the pick is cleared', async () => {
		const wrapper = mountSettings({ ...base, schema: 'order-line', parentField: 'order', columns: ['product'] })
		await wrapper.find('[data-testid="child-schema"]').setValue('')
		expect(lastEmit(wrapper)).toEqual(base)
	})
})
