/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormFieldBuilder.vue's field type palette, drag and drop
 * and keyboard reordering (REQ-BQFK-005), and the new field settings
 * sections (REQ-BQFK-001..004).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

const FIELDS = [
	{ key: 'naam', label: 'Naam', type: 'string' },
	{ key: 'email', label: 'E-mailadres', type: 'string' },
]

function mountBuilder(modelValue = FIELDS, props = {}) {
	return mount(FormFieldBuilder, { props: { modelValue, showLogic: true, ...props }, attachTo: document.body })
}

function lastEmit(wrapper) {
	const all = wrapper.emitted('update:modelValue')
	return all[all.length - 1][0]
}

describe('FormFieldBuilder palette', () => {
	it('shows every field type with its name', () => {
		const wrapper = mountBuilder()
		expect(wrapper.find('[data-testid="palette-file"]').text()).toContain('File')
		expect(wrapper.find('[data-testid="palette-child-records"]').exists()).toBe(true)
	})

	it('is not shown in the flat settings-page layout', () => {
		const wrapper = mountBuilder(FIELDS, { showLogic: false })
		expect(wrapper.find('[data-testid="palette-date"]').exists()).toBe(false)
	})

	it('drops a date field between Naam and E-mailadres', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('[data-testid="palette-date"]').trigger('dragstart')
		await wrapper.find('[data-testid="drop-slot-1"]').trigger('drop')
		expect(lastEmit(wrapper).map((f) => f.key || f.type)).toEqual(['naam', 'date', 'email'])
		expect(lastEmit(wrapper)[1]).toEqual({ key: '', label: '', type: 'date' })
	})

	it('ignores a drop with nothing dragged', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('[data-testid="drop-slot-0"]').trigger('drop')
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('reorders a field by dragging its handle to another slot', async () => {
		const wrapper = mountBuilder()
		await wrapper.findAll('.form-field-builder__handle')[0].trigger('dragstart')
		await wrapper.find('[data-testid="drop-slot-2"]').trigger('drop')
		expect(lastEmit(wrapper).map((f) => f.key)).toEqual(['email', 'naam'])
	})

	it('adds a file field at the end with Enter and focuses it', async () => {
		const wrapper = mountBuilder()
		const item = wrapper.find('[data-testid="palette-file"]')
		item.element.focus()
		await item.trigger('click')
		const next = lastEmit(wrapper)
		expect(next).toHaveLength(3)
		expect(next[2]).toEqual({ key: '', label: '', type: 'file' })
		await wrapper.setProps({ modelValue: next })
		await wrapper.vm.$nextTick()
		const keys = wrapper.findAll('.form-field-builder__key')
		expect(document.activeElement).toBe(keys[2].element)
		wrapper.unmount()
	})

	it('moves a field down and up with the move actions', async () => {
		const wrapper = mountBuilder()
		await wrapper.findAll('[aria-label="Move field down"]')[0].trigger('click')
		expect(lastEmit(wrapper).map((f) => f.key)).toEqual(['email', 'naam'])
		await wrapper.findAll('[aria-label="Move field up"]')[1].trigger('click')
		expect(lastEmit(wrapper).map((f) => f.key)).toEqual(['email', 'naam'])
		expect(wrapper.findAll('[aria-label="Move field up"]')[0].element.disabled).toBe(true)
	})

	it('keeps an open details panel on its field when the field moves', async () => {
		const wrapper = mountBuilder()
		await wrapper.findAll('.form-field-builder__disclosure')[0].trigger('click')
		await wrapper.findAll('[aria-label="Move field down"]')[0].trigger('click')
		expect(wrapper.vm.expandedIndices).toEqual([1])
	})
})

describe('FormFieldBuilder field settings', () => {
	it('shows file settings for a file field and writes them on the field', async () => {
		const wrapper = mountBuilder([{ key: 'foto', label: 'Foto van de schade', type: 'file' }])
		await wrapper.find('.form-field-builder__disclosure').trigger('click')
		await wrapper.find('[data-testid="file-capture"]').setValue('environment')
		expect(lastEmit(wrapper)).toEqual([{ key: 'foto', label: 'Foto van de schade', type: 'file', capture: 'environment' }])
	})

	it('shows child-records settings with the register schemas', async () => {
		const schemas = [
			{ slug: 'order', title: 'Order', properties: {} },
			{ slug: 'order-line', title: 'OrderLine', properties: { order: { $ref: 'order' }, product: {} } },
		]
		const wrapper = mountBuilder([{ key: 'lines', label: 'Lines', type: 'child-records' }], { schemas, parentSchema: 'order' })
		await wrapper.find('.form-field-builder__disclosure').trigger('click')
		await wrapper.find('[data-testid="child-schema"]').setValue('order-line')
		expect(lastEmit(wrapper)[0]).toEqual({ key: 'lines', label: 'Lines', type: 'child-records', schema: 'order-line', parentField: 'order' })
	})

	it('writes assign rules from "Fill in from another answer" and summarises them', async () => {
		const fields = [
			{ key: 'country', label: 'Country', type: 'string' },
			{ key: 'currency', label: 'Currency', type: 'string' },
		]
		const wrapper = mountBuilder(fields)
		await wrapper.findAll('.form-field-builder__disclosure')[1].trigger('click')
		await wrapper.find('[data-testid="assign-add"]').trigger('click')
		expect(lastEmit(wrapper)[1].assign).toEqual([{ when: { op: 'eq' }, value: '' }])
		await wrapper.setProps({ modelValue: [fields[0], { ...fields[1], assign: [{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }] }] })
		expect(wrapper.findAll('.form-field-builder__summary')[1].text()).toContain('filled in from another answer')
	})
})
