/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec driving ColumnBuilder through its rendered rows: the key
 * picker offers the schema's properties and the @self metadata keys, a key
 * change keeps the row's shape, and add and remove edit the list without
 * mutating the prop.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ColumnBuilder from '../../../src/components/page-editor/fields/ColumnBuilder.vue'

const schemaProperties = { title: { type: 'string' }, status: { type: 'string' } }

/**
 * Mount the builder.
 *
 * @param {Array} modelValue The columns.
 * @param {object} props Other props.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function render(modelValue, props = {}) {
	return mount(ColumnBuilder, { props: { modelValue, schemaProperties, ...props } })
}

/**
 * The list emitted last.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The builder.
 * @return {Array}
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('ColumnBuilder rows', () => {
	it('offers the schema properties and the @self metadata keys', () => {
		const select = render(['title']).find('select')
		const groups = select.findAll('optgroup')

		expect(groups.map((g) => g.attributes('label'))).toEqual(['Schema properties', 'Metadata (@self.*)'])
		expect(groups[0].findAll('option').map((o) => o.text())).toEqual(['title', 'status'])
		expect(groups[1].findAll('option').map((o) => o.element.value)).toEqual([
			'@self.uuid',
			'@self.created',
			'@self.updated',
			'@self.owner',
			'@self.organisation',
			'@self.locked',
		])
	})

	it('shows each row\'s key and label, whatever its shape', () => {
		const wrapper = render(['title', { key: 'status', label: 'State' }, { property: '@self.owner' }, null])
		const rows = wrapper.findAll('.column-builder__row')

		expect(rows.map((r) => r.find('select').element.value)).toEqual(['title', 'status', '@self.owner', ''])
		expect(rows.map((r) => r.find('input').element.value)).toEqual(['', 'State', '', ''])
	})

	it('a key change keeps a string row a string', async () => {
		const columns = ['title']
		const wrapper = render(columns)

		await wrapper.find('select').setValue('status')

		expect(lastEmitted(wrapper)).toEqual(['status'])
		expect(columns).toEqual(['title'])
	})

	it('a key change keeps an object row\'s other settings', async () => {
		const wrapper = render([{ key: 'title', label: 'Title', width: 2 }])

		await wrapper.find('select').setValue('@self.created')

		expect(lastEmitted(wrapper)).toEqual([{ key: '@self.created', label: 'Title', width: 2 }])
	})

	it('typing a label through the input promotes the row', async () => {
		const wrapper = render(['title'])

		await wrapper.find('input').setValue('Name')

		expect(lastEmitted(wrapper)).toEqual([{ key: 'title', label: 'Name' }])
	})

	it('adds an empty column and removes the one asked for', async () => {
		const wrapper = render(['title', 'status'])

		await wrapper.find('.column-builder__add').trigger('click')
		expect(lastEmitted(wrapper)).toEqual(['title', 'status', ''])

		await wrapper.findAll('.column-builder__remove')[0].trigger('click')
		expect(lastEmitted(wrapper)).toEqual(['status'])
	})

	it('a value that is not a list renders no rows, and no schema offers only the metadata keys', () => {
		const wrapper = render('x', { schemaProperties: null })

		expect(wrapper.findAll('.column-builder__row')).toHaveLength(0)
		expect(wrapper.vm.schemaPropertyKeys).toEqual([])
	})
})
