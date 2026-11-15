/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ActionBuilder: one row per action, every edit emitted as
 * a new array, an emptied field other than the id removed from the action,
 * and adding or removing a row emitted the same way.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ActionBuilder from '../../../src/components/page-editor/fields/ActionBuilder.vue'

const actions = [
	{ id: 'edit', label: 'Edit', icon: 'Pencil', target: 'navigate' },
	{ id: 'delete', label: 'Delete' },
]

function lastEmit(wrapper) {
	const emits = wrapper.emitted('update:modelValue')
	return emits[emits.length - 1][0]
}

describe('ActionBuilder', () => {
	it('renders one row per action with its values', () => {
		const wrapper = mount(ActionBuilder, { props: { modelValue: actions } })
		const rows = wrapper.findAll('.action-builder__row')

		expect(rows).toHaveLength(2)
		expect(rows[0].findAll('input').map((i) => i.element.value)).toEqual(['edit', 'Edit', 'Pencil'])
		expect(rows[0].find('select').element.value).toBe('navigate')
		expect(rows[1].find('select').element.value).toBe('')
	})

	it('treats a non-array value as no actions', () => {
		const wrapper = mount(ActionBuilder, { props: { modelValue: null } })

		expect(wrapper.findAll('.action-builder__row')).toHaveLength(0)
	})

	it('emits the edited action in a new array and leaves the input array alone', async () => {
		const input = actions.map((a) => ({ ...a }))
		const wrapper = mount(ActionBuilder, { props: { modelValue: input } })

		await wrapper.findAll('.action-builder__row')[1].findAll('input')[2].setValue('Trash')

		expect(lastEmit(wrapper)).toEqual([actions[0], { id: 'delete', label: 'Delete', icon: 'Trash' }])
		expect(lastEmit(wrapper)).not.toBe(input)
		expect(input[1]).toEqual({ id: 'delete', label: 'Delete' })
	})

	it('drops an emptied field, but keeps an emptied id', async () => {
		const wrapper = mount(ActionBuilder, { props: { modelValue: [actions[0]] } })
		const row = wrapper.find('.action-builder__row')

		await row.find('select').setValue('')
		expect(lastEmit(wrapper)).toEqual([{ id: 'edit', label: 'Edit', icon: 'Pencil' }])

		await row.findAll('input')[0].setValue('')
		expect(lastEmit(wrapper)).toEqual([{ id: '', label: 'Edit', icon: 'Pencil', target: 'navigate' }])
	})

	it('adds an empty action at the end and removes the clicked one', async () => {
		const wrapper = mount(ActionBuilder, { props: { modelValue: actions } })

		await wrapper.find('.action-builder__add').trigger('click')
		expect(lastEmit(wrapper)).toEqual([...actions, { id: '', label: '' }])

		await wrapper.findAll('.action-builder__remove')[0].trigger('click')
		expect(lastEmit(wrapper)).toEqual([actions[1]])
	})
})
