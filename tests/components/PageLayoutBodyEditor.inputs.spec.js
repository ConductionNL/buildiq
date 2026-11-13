/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PageLayoutBodyEditor.vue driven through its own inputs: every text field,
 * select, checkbox and button writes its key on the right tab, widget,
 * condition, header field, task-list entry or upload field, and the remove
 * and move buttons renumber what is left (change
 * case-page-layout-per-case-type, REQ-OBPL-004 to 009).
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

const { default: PageLayoutBodyEditor } = await import('../../src/components/page-editor/fields/PageLayoutBodyEditor.vue')

function mountEditor(modelValue) {
	return mount(PageLayoutBodyEditor, { props: { modelValue } })
}

function lastEmit(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

function button(scope, text) {
	return scope.findAll('button').find((b) => b.text() === text)
}

function labelled(scope, text) {
	return scope.findAll('label').find((l) => l.text().startsWith(text))
}

const LAYOUT = {
	tabs: [
		{ id: 'leaf-1', kind: 'leaf', label: 'Documents', ref: 'filinq-documents', order: 0 },
		{ id: 'group-1', kind: 'fieldGroup', label: 'Details', fields: ['name'], order: 1 },
		{ id: 'related-1', kind: 'relatedList', label: 'Related', relatedRegister: 'r', relatedSchema: 's', order: 2 },
		{
			id: 'widgets-1',
			kind: 'widgets',
			label: 'Overview',
			order: 3,
			widgets: [
				{ id: 'w1', order: 0, width: 'medium', highContrast: false, conditions: [{ field: 'status', operator: 'equals', value: 'open' }, { field: 'x', operator: 'isEmpty' }] },
				{ id: 'w2', order: 1, width: 'small', conditions: [] },
			],
		},
	],
	header: { titleField: 'title', fields: [{ field: 'a', order: 0 }, { field: 'b', order: 1 }] },
	taskList: { columns: [{ field: 'c1', order: 0 }, { field: 'c2', order: 1 }], searchFields: [{ field: 's1', operator: 'contains' }, { field: 's2', operator: 'contains' }] },
	uploadFields: [{ field: 'u1', order: 0, visibility: 'editable' }, { field: 'u2', order: 1, visibility: 'hidden', default: 'x' }],
}

describe('PageLayoutBodyEditor inputs — tabs', () => {
	it('writes the label and the reference of each tab kind', async () => {
		const wrapper = mountEditor(LAYOUT)
		const tabs = wrapper.findAll('[data-tab]')

		await labelled(tabs[0], 'Label').find('input').setValue('Docs')
		expect(lastEmit(wrapper).tabs[0].label).toBe('Docs')
		await labelled(tabs[0], 'Leaf id').find('input').setValue('filinq-mail')
		expect(lastEmit(wrapper).tabs[0].ref).toBe('filinq-mail')
		await labelled(tabs[1], 'Fields, separated by commas').find('input').setValue(' name, , email ,')
		expect(lastEmit(wrapper).tabs[1].fields).toEqual(['name', 'email'])
		await labelled(tabs[2], 'Related register').find('input').setValue('reg2')
		expect(lastEmit(wrapper).tabs[2].relatedRegister).toBe('reg2')
		await labelled(tabs[2], 'Related schema').find('input').setValue('sch2')
		expect(lastEmit(wrapper).tabs[2].relatedSchema).toBe('sch2')
		expect(lastEmit(wrapper).header).toEqual(LAYOUT.header)
	})

	it('shows the kind of each tab by name', () => {
		const wrapper = mountEditor(LAYOUT)

		expect(wrapper.findAll('.layout-body__kind').map((k) => k.text())).toEqual([
			'Content from another app',
			'Group of fields',
			'List of related records',
			'Widgets',
		])
	})

	it('removes a tab and renumbers the rest', async () => {
		const wrapper = mountEditor(LAYOUT)

		await button(wrapper.find('[data-tab="group-1"]'), 'Remove').trigger('click')

		expect(lastEmit(wrapper).tabs.map((t) => [t.id, t.order])).toEqual([['leaf-1', 0], ['related-1', 1], ['widgets-1', 2]])
	})

	it('adds a tab of the chosen kind', async () => {
		const wrapper = mountEditor({})

		await wrapper.findAll('select').at(-1).setValue('relatedList')
		await button(wrapper, 'Add tab').trigger('click')

		expect(lastEmit(wrapper).tabs.map((t) => t.kind)).toEqual(['relatedList'])
	})
})

describe('PageLayoutBodyEditor inputs — widgets', () => {
	function widgetTab(wrapper) {
		return wrapper.find('[data-tab="widgets-1"]')
	}

	function widgets(wrapper) {
		return lastEmit(wrapper).tabs[3].widgets
	}

	it('writes the id, width and high contrast of a widget', async () => {
		const wrapper = mountEditor(LAYOUT)
		const items = widgetTab(wrapper).findAll('.layout-body__widgets > ol > li')

		await labelled(items[1], 'Widget id').find('input').setValue('w2b')
		expect(widgets(wrapper)[1].id).toBe('w2b')
		await labelled(items[1], 'Width').find('select').setValue('extraLarge')
		expect(widgets(wrapper)[1].width).toBe('extraLarge')
		await items[0].find('input[type="checkbox"]').setValue(true)
		expect(widgets(wrapper)[0].highContrast).toBe(true)
		expect(widgets(wrapper)[1]).toEqual(LAYOUT.tabs[3].widgets[1])
	})

	it('moves and removes a widget, renumbering the rest', async () => {
		const wrapper = mountEditor(LAYOUT)

		const items = widgetTab(wrapper).findAll('.layout-body__widgets > ol > li')
		expect(button(items[0], 'Up').attributes('disabled')).toBeDefined()
		await button(items[1], 'Up').trigger('click')
		expect(widgets(wrapper).map((w) => [w.id, w.order])).toEqual([['w2', 0], ['w1', 1]])

		await button(items[0], 'Remove').trigger('click')
		expect(widgets(wrapper).map((w) => [w.id, w.order])).toEqual([['w2', 0]])
	})

	it('writes, hides the value of, and removes a display condition', async () => {
		const wrapper = mountEditor(LAYOUT)
		const first = widgetTab(wrapper).findAll('.layout-body__widgets > ol > li')[0]
		const rows = first.findAll('.layout-body__row').filter((r) => r.text().startsWith('Show when field'))

		expect(labelled(rows[1], 'Value')).toBeUndefined()

		await labelled(rows[0], 'Show when field').find('input').setValue('phase')
		expect(widgets(wrapper)[0].conditions[0]).toEqual({ field: 'phase', operator: 'equals', value: 'open' })
		await labelled(rows[0], 'Condition').find('select').setValue('contains')
		expect(widgets(wrapper)[0].conditions[0]).toEqual({ field: 'status', operator: 'contains', value: 'open' })
		await labelled(rows[0], 'Value').find('input').setValue('closed')
		expect(widgets(wrapper)[0].conditions[0]).toEqual({ field: 'status', operator: 'equals', value: 'closed' })

		await button(rows[0], 'Remove condition').trigger('click')
		expect(widgets(wrapper)[0].conditions).toEqual([{ field: 'x', operator: 'isEmpty' }])
	})

	it('lists the operators and widths by name', () => {
		const wrapper = mountEditor(LAYOUT)
		const first = widgetTab(wrapper).findAll('.layout-body__widgets > ol > li')[0]

		expect(labelled(first, 'Width').findAll('option').map((o) => o.text())).toEqual([
			'Small, a quarter of the row',
			'Medium, half the row',
			'Large, three quarters of the row',
			'Extra large, the whole row',
		])
		const condition = first.findAll('.layout-body__row').find((r) => r.text().startsWith('Show when field'))
		expect(labelled(condition, 'Condition').findAll('option').map((o) => o.text())).toEqual(['is', 'is not', 'is empty', 'is not empty', 'contains'])
	})
})

describe('PageLayoutBodyEditor inputs — header, task list and uploads', () => {
	it('writes the header title, subtitle and chips', async () => {
		const wrapper = mountEditor(LAYOUT)

		await labelled(wrapper, 'Title field').find('input').setValue('naam')
		expect(lastEmit(wrapper).header.titleField).toBe('naam')
		await labelled(wrapper, 'Subtitle field').find('input').setValue('sub')
		expect(lastEmit(wrapper).header.subtitleField).toBe('sub')
		await labelled(wrapper, 'Chips, separated by commas').find('input').setValue('status, fase')
		expect(lastEmit(wrapper).header).toEqual({ ...LAYOUT.header, chips: ['status', 'fase'] })
	})

	it('removes a header field and renumbers the rest', async () => {
		const wrapper = mountEditor(LAYOUT)

		const header = wrapper.findAll('fieldset')[1]
		expect(header.find('legend').text()).toBe('Header')
		await button(header.findAll('li.layout-body__row')[0], 'Remove').trigger('click')

		expect(lastEmit(wrapper).header.fields).toEqual([{ field: 'b', order: 0 }])
	})

	it('removes a task-list column and a search field', async () => {
		const wrapper = mountEditor(LAYOUT)
		const taskList = wrapper.findAll('fieldset')[2]
		expect(taskList.find('legend').text()).toBe('Task list')
		const rows = taskList.findAll('li.layout-body__row')
		expect(rows).toHaveLength(4)

		await button(rows[0], 'Remove').trigger('click')
		expect(lastEmit(wrapper).taskList.columns).toEqual([{ field: 'c2', order: 0 }])

		await button(rows[3], 'Remove').trigger('click')
		expect(lastEmit(wrapper).taskList).toEqual({ ...LAYOUT.taskList, searchFields: [{ field: 's1', operator: 'contains' }] })
	})

	it('adds, edits and removes an upload field', async () => {
		const wrapper = mountEditor(LAYOUT)
		expect(button(wrapper, 'Add upload field').attributes('disabled')).toBeDefined()

		await labelled(wrapper, 'Upload field').find('input').setValue('besluit')
		await button(wrapper, 'Add upload field').trigger('click')
		expect(lastEmit(wrapper).uploadFields[2]).toEqual({ field: 'besluit', label: '', order: 2, visibility: 'editable' })
		expect(labelled(wrapper, 'Upload field').find('input').element.value).toBe('')

		const u1 = wrapper.find('[data-upload="u1"]')
		await labelled(u1, 'Visibility').find('select').setValue('readOnly')
		expect(lastEmit(wrapper).uploadFields[0].visibility).toBe('readOnly')
		await labelled(u1, 'Default').find('input').setValue('n.v.t.')
		expect(lastEmit(wrapper).uploadFields[0].default).toBe('n.v.t.')

		await button(u1, 'Remove').trigger('click')
		expect(lastEmit(wrapper).uploadFields).toEqual([{ field: 'u2', order: 0, visibility: 'hidden', default: 'x' }])
	})
})
