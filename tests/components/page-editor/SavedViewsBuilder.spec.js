/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SavedViewsBuilder — the maker's "views for everyone" of an
 * index page (data-index-grid-and-saved-views T05, REQ-BQIX-003). The emitted
 * shape is nextcloud-vue CnIndexPage's `defaultSavedViews` entry:
 * `{ slug, name, filters, sort: [{ key, order }] }`.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SavedViewsBuilder from '../../../src/components/page-editor/fields/SavedViewsBuilder.vue'

const schemaProperties = { status: { type: 'string' }, date: { type: 'string' } }

/**
 * Mount the builder.
 *
 * @param {Array<object>} modelValue - the views.
 * @return {object} the wrapper.
 */
function mountBuilder(modelValue = []) {
	return mount(SavedViewsBuilder, { props: { modelValue, schemaProperties } })
}

const last = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]

describe('SavedViewsBuilder', () => {
	it('adds an empty view with a slug no other view has', async () => {
		const wrapper = mountBuilder([{ slug: 'view-2', name: 'A', filters: {}, sort: [] }])
		await wrapper.find('.saved-views-builder__add').trigger('click')
		expect(last(wrapper)).toEqual([
			{ slug: 'view-2', name: 'A', filters: {}, sort: [] },
			{ slug: 'view-3', name: '', filters: {}, sort: [] },
		])
	})

	it('builds the "Open requests" view: a name, a filter and a newest-first sort', async () => {
		const wrapper = mountBuilder([{ slug: 'view-1', name: '', filters: {}, sort: [] }])
		await wrapper.find('input[type="text"]').setValue('Open requests')
		expect(last(wrapper)[0].name).toBe('Open requests')
		expect(last(wrapper)[0].slug).toBe('view-1')

		await wrapper.setProps({ modelValue: last(wrapper) })
		await wrapper.findAll('button').find((b) => b.text().includes('Add filter')).trigger('click')
		expect(last(wrapper)[0].filters).toEqual({ status: '' })

		await wrapper.setProps({ modelValue: last(wrapper) })
		const valueInput = wrapper.findAll('input[type="text"]')[1]
		await valueInput.setValue('open')
		expect(last(wrapper)[0].filters).toEqual({ status: 'open' })

		await wrapper.setProps({ modelValue: last(wrapper) })
		const selects = wrapper.findAll('select')
		// [filter field, sort key, sort order]
		await selects[1].setValue('date')
		expect(last(wrapper)[0].sort).toEqual([{ key: 'date', order: 'asc' }])
		await wrapper.setProps({ modelValue: last(wrapper) })
		await wrapper.findAll('select')[2].setValue('desc')
		expect(last(wrapper)).toEqual([
			{ slug: 'view-1', name: 'Open requests', filters: { status: 'open' }, sort: [{ key: 'date', order: 'desc' }] },
		])
	})

	it('moves a filter to another property keeping its value and place', async () => {
		const wrapper = mountBuilder([{ slug: 'v', name: 'X', filters: { status: 'open', date: '2026' }, sort: [] }])
		const fieldSelect = wrapper.findAll('select')[0]
		await fieldSelect.setValue('date')
		// `date` already had a filter; the moved one replaces it.
		expect(last(wrapper)[0].filters).toEqual({ date: 'open' })
	})

	it('offers no new filter once every property is filtered on', () => {
		const wrapper = mountBuilder([{ slug: 'v', name: 'X', filters: { status: 'a', date: 'b' }, sort: [] }])
		const add = wrapper.findAll('button').find((b) => b.text().includes('Add filter'))
		expect(add.attributes('disabled')).toBeDefined()
	})

	it('removes a filter, clears the sort and removes a view', async () => {
		const views = [{ slug: 'v', name: 'X', filters: { status: 'a' }, sort: [{ key: 'date', order: 'desc' }] }]
		const wrapper = mountBuilder(views)
		await wrapper.find('button[aria-label="Remove filter"]').trigger('click')
		expect(last(wrapper)[0].filters).toEqual({})

		await wrapper.findAll('select')[1].setValue('')
		expect(last(wrapper)[0].sort).toEqual([])

		await wrapper.find('.saved-views-builder__remove-view').trigger('click')
		expect(last(wrapper)).toEqual([])
	})

	it('offers the schema properties and the record dates to sort on', () => {
		const wrapper = mountBuilder([{ slug: 'v', name: 'X', filters: {}, sort: [] }])
		const sortOptions = wrapper.findAll('select')[0].findAll('option').map((o) => o.element.value)
		expect(sortOptions).toEqual(['', 'status', 'date', '@self.created', '@self.updated'])
	})
})
