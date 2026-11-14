/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RelationLinkBuilder (pages-detail-related-items T02,
 * REQ-BQRI-002): the saved entry and the inline mark on a missing fkField.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RelationLinkBuilder from '../../../src/components/page-editor/fields/RelationLinkBuilder.vue'

const SCHEMAS = [
	{ slug: 'clients', properties: { name: {}, primaryContact: {} } },
	{ slug: 'contacts', properties: { fullName: {}, email: {} } },
]

function mountBuilder(modelValue = []) {
	return mount(RelationLinkBuilder, {
		props: {
			modelValue,
			registers: [{ slug: 'crm' }],
			hostRegister: 'crm',
			hostSchema: 'clients',
			picker: { fetchSchemas: vi.fn().mockResolvedValue(SCHEMAS) },
		},
	})
}

function lastEmit(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('RelationLinkBuilder', () => {
	it('saves a link button storing into the open record field', async () => {
		const wrapper = mountBuilder([{ label: 'Link contact', register: 'crm', schema: 'contacts' }])
		await flushPromises()

		const selects = wrapper.findAll('select')
		expect(selects[2].findAll('option').map((o) => o.element.value)).toEqual(['', 'name', 'primaryContact'])
		expect(selects[3].findAll('option').map((o) => o.element.value)).toEqual(['', 'fullName', 'email'])

		await selects[2].setValue('primaryContact')
		expect(lastEmit(wrapper)).toEqual([
			{ label: 'Link contact', register: 'crm', schema: 'contacts', fkField: 'primaryContact' },
		])
	})

	it('writes labelField and allowCreate, and removes allowCreate when off', async () => {
		const entry = { label: 'L', register: 'crm', schema: 'contacts', fkField: 'primaryContact' }
		const wrapper = mountBuilder([entry])

		wrapper.vm.updateField(0, 'labelField', 'fullName')
		expect(lastEmit(wrapper)[0].labelField).toBe('fullName')

		await wrapper.find('input[type="checkbox"]').setValue(true)
		expect(lastEmit(wrapper)[0].allowCreate).toBe(true)
		wrapper.vm.updateAllowCreate(0, false)
		expect(lastEmit(wrapper)[0]).toEqual(entry)
	})

	it('marks a button without fkField', () => {
		const wrapper = mountBuilder([{ label: 'L', register: 'crm', schema: 'contacts' }])
		const mark = wrapper.find('.inline-field-mark')
		expect(mark.exists()).toBe(true)
		expect(mark.text()).toContain('Pick the field on this record that stores the link.')
	})

	it('shows no mark on a complete button', () => {
		const wrapper = mountBuilder([{ register: 'crm', schema: 'contacts', fkField: 'primaryContact' }])
		expect(wrapper.find('.inline-field-mark').exists()).toBe(false)
	})

	it('picking another schema drops the picker label field', () => {
		const wrapper = mountBuilder([{ register: 'crm', schema: 'contacts', fkField: 'x', labelField: 'email' }])
		wrapper.vm.updateSchema(0, 'clients')
		expect(lastEmit(wrapper)).toEqual([{ register: 'crm', schema: 'clients', fkField: 'x' }])
	})

	it('adds a button on the page register', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('.relation-link-builder__add').trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ label: '', register: 'crm' }])
	})
})
