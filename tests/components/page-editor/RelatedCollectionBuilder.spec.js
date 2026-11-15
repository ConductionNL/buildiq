/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RelatedCollectionBuilder (pages-detail-related-items T01,
 * REQ-BQRI-001): a related list is authored by its link field, and columns
 * and limit are optional.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RelatedCollectionBuilder from '../../../src/components/page-editor/fields/RelatedCollectionBuilder.vue'

const SCHEMAS = [
	{ slug: 'clients', title: 'Clients', properties: { name: {} } },
	{ slug: 'requests', title: 'Requests', properties: { subject: {}, client: {}, status: {} } },
]

function mountBuilder(modelValue = [], picker = null) {
	return mount(RelatedCollectionBuilder, {
		props: {
			modelValue,
			registers: [{ slug: 'crm', title: 'CRM' }],
			defaultRegister: 'crm',
			picker: picker || { fetchSchemas: vi.fn().mockResolvedValue(SCHEMAS) },
		},
	})
}

function lastEmit(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('RelatedCollectionBuilder', () => {
	it('adds a list on the page register', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('.related-collection-builder__add').trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ title: '', register: 'crm' }])
	})

	it('maps the picked link field to @objectId', async () => {
		const wrapper = mountBuilder([{ title: 'Requests', register: 'crm', schema: 'requests' }])
		await flushPromises()

		const selects = wrapper.findAll('select')
		const fieldOptions = selects[2].findAll('option').map((o) => o.element.value)
		expect(fieldOptions).toEqual(['', 'subject', 'client', 'status'])

		await selects[2].setValue('client')
		expect(lastEmit(wrapper)).toEqual([
			{ title: 'Requests', register: 'crm', schema: 'requests', filter: { client: '@objectId' } },
		])
	})

	it('reads the link field back and replaces it, keeping other filter keys', async () => {
		const entry = {
			register: 'crm',
			schema: 'requests',
			filter: { client: '@objectId', status: 'open' },
		}
		const wrapper = mountBuilder([entry])
		await flushPromises()

		expect(wrapper.vm.linkFieldOf(entry)).toBe('client')
		wrapper.vm.updateLinkField(0, 'subject')
		expect(lastEmit(wrapper)[0].filter).toEqual({ status: 'open', subject: '@objectId' })
		wrapper.vm.updateLinkField(0, '')
		expect(lastEmit(wrapper)[0].filter).toEqual({ status: 'open' })
	})

	it('picking another schema drops its link field and columns', () => {
		const wrapper = mountBuilder([
			{ title: 'R', register: 'crm', schema: 'requests', filter: { client: '@objectId' }, columns: ['subject'], limit: 5 },
		])
		wrapper.vm.updateSchema(0, 'clients')
		expect(lastEmit(wrapper)).toEqual([{ title: 'R', register: 'crm', schema: 'clients', limit: 5 }])
	})

	it('leaves the limit out at the default and clamps it to 1..50', () => {
		const wrapper = mountBuilder([{ register: 'crm', schema: 'requests' }])

		wrapper.vm.updateLimit(0, '10')
		expect(lastEmit(wrapper)).toEqual([{ register: 'crm', schema: 'requests' }])
		wrapper.vm.updateLimit(0, '25')
		expect(lastEmit(wrapper)[0].limit).toBe(25)
		wrapper.vm.updateLimit(0, '500')
		expect(lastEmit(wrapper)[0].limit).toBe(50)
		wrapper.vm.updateLimit(0, '')
		expect(lastEmit(wrapper)[0]).not.toHaveProperty('limit')
	})

	it('writes columns from a comma list and removes them when empty', () => {
		const wrapper = mountBuilder([{ register: 'crm', schema: 'requests' }])

		wrapper.vm.updateColumns(0, ' subject, status ,')
		expect(lastEmit(wrapper)[0].columns).toEqual(['subject', 'status'])
		wrapper.vm.updateColumns(0, '  ')
		expect(lastEmit(wrapper)[0]).not.toHaveProperty('columns')
	})

	it('marks a row without a register and schema', () => {
		const wrapper = mountBuilder([{ title: 'x', register: 'crm' }, { register: 'crm', schema: 'requests' }])
		const marks = wrapper.findAll('.inline-field-mark')
		expect(marks).toHaveLength(1)
		expect(wrapper.vm.rowError({ register: 'crm' }).hasError).toBe(true)
		expect(wrapper.vm.rowError({ register: 'crm', schema: 'requests' })).toBeNull()
	})

	it('fetches the schemas of a register once', async () => {
		const picker = { fetchSchemas: vi.fn().mockResolvedValue(SCHEMAS) }
		mountBuilder([{ register: 'crm', schema: 'requests' }, { register: 'crm', schema: 'clients' }], picker)
		await flushPromises()
		expect(picker.fetchSchemas).toHaveBeenCalledTimes(1)
		expect(picker.fetchSchemas).toHaveBeenCalledWith('crm')
	})

	it('removes a list', async () => {
		const wrapper = mountBuilder([{ title: 'a' }, { title: 'b' }])
		await wrapper.findAll('.related-collection-builder__remove')[0].trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ title: 'b' }])
	})
})
