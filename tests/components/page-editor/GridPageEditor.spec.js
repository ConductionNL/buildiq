/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for GridPageEditor — the editor of a "Grid" page
 * (data-index-grid-and-saved-views T04, REQ-BQIX-001). It writes the page's
 * `config`, which CnPageRenderer hands the runtime `record-grid` page as
 * props: register, schema, columns and the editable column keys.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const stockProperties = {
	name: { type: 'string' },
	quantity: { type: 'integer' },
	location: { type: 'string' },
	sku: { type: 'string', readOnly: true },
}

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [{ slug: 'stock', title: 'Stock' }],
		fetchSchemas: async () => [{ slug: 'item', title: 'Stock item', properties: stockProperties }],
		fetchSchemaProperties: async () => stockProperties,
		resolveAppRegister: () => 'stock',
	}),
}))

const GridPageEditor = (
	await import('../../../src/components/page-editor/GridPageEditor.vue')
).default

/**
 * Mount the editor on a grid page config.
 *
 * @param {object} config - the page config.
 * @return {Promise<object>} the wrapper.
 */
async function mountEditor(config) {
	const wrapper = mount(GridPageEditor, { props: { config, appSlug: 'app' } })
	await flushPromises()
	return wrapper
}

const last = (wrapper) => wrapper.emitted('update:config').at(-1)[0]

/**
 * The editable toggle of one column.
 *
 * @param {object} wrapper - the mounted editor.
 * @param {string} key - the column key.
 * @return {object} the checkbox.
 */
function toggle(wrapper, key) {
	return wrapper
		.findAll('.grid-page-editor__inline')
		.find((label) => label.text().startsWith(key))
		.find('input[type="checkbox"]')
}

describe('GridPageEditor', () => {
	it('marks quantity editable for the stock grid', async () => {
		const config = { register: 'stock', schema: 'item', columns: ['name', 'quantity', 'location'], editable: [] }
		const wrapper = await mountEditor(config)
		expect(toggle(wrapper, 'quantity').element.checked).toBe(false)
		await toggle(wrapper, 'quantity').setValue(true)
		expect(last(wrapper)).toEqual({ ...config, editable: ['quantity'] })
	})

	it('unmarks a column', async () => {
		const config = { register: 'stock', schema: 'item', columns: ['name', 'quantity'], editable: ['name', 'quantity'] }
		const wrapper = await mountEditor(config)
		await toggle(wrapper, 'name').setValue(false)
		expect(last(wrapper).editable).toEqual(['quantity'])
	})

	it('disables the toggle of a read-only property and of metadata', async () => {
		const wrapper = await mountEditor({ register: 'stock', schema: 'item', columns: ['sku', '@self.created', 'name'], editable: [] })
		expect(toggle(wrapper, 'sku').attributes('disabled')).toBeDefined()
		expect(toggle(wrapper, '@self.created').attributes('disabled')).toBeDefined()
		expect(toggle(wrapper, 'name').attributes('disabled')).toBeUndefined()
		expect(wrapper.vm.canBeEditable('sku')).toBe(false)
	})

	it('drops editable keys whose column was removed', async () => {
		const wrapper = await mountEditor({ register: 'stock', schema: 'item', columns: ['name', 'quantity'], editable: ['name', 'quantity'] })
		wrapper.findComponent({ name: 'ColumnBuilder' }).vm.$emit('update:modelValue', [{ key: 'quantity', label: 'Qty' }])
		expect(last(wrapper)).toEqual({
			register: 'stock',
			schema: 'item',
			columns: [{ key: 'quantity', label: 'Qty' }],
			editable: ['quantity'],
		})
	})

	it('a register switch drops the schema, the columns and the editable list', async () => {
		const wrapper = await mountEditor({ register: 'stock', schema: 'item', columns: ['name'], editable: ['name'] })
		wrapper.findComponent({ name: 'RegisterSchemaFields' }).vm.$emit('register', 'other')
		expect(last(wrapper)).toEqual({ register: 'other', columns: [], editable: [] })
	})

	it('a schema switch keeps the register and drops the columns', async () => {
		const wrapper = await mountEditor({ register: 'stock', schema: 'item', columns: ['name'], editable: ['name'] })
		wrapper.findComponent({ name: 'RegisterSchemaFields' }).vm.$emit('schema', 'other')
		expect(last(wrapper)).toEqual({ register: 'stock', schema: 'other', columns: [], editable: [] })
	})

	it('lists each column once and says to add columns first when there are none', async () => {
		const wrapper = await mountEditor({ register: 'stock', schema: 'item', columns: ['name', { key: 'name', label: 'N' }, ''] })
		expect(wrapper.vm.columnKeys).toEqual(['name'])
		const empty = await mountEditor({ register: 'stock', schema: 'item', columns: [] })
		expect(empty.text()).toContain('Add columns first.')
	})
})
