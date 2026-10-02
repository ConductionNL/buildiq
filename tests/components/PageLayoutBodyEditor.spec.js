/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for PageLayoutBodyEditor.vue, the tabs, widgets, header, task
 * list and upload fields of one page layout (change
 * case-page-layout-per-case-type, tasks 6.1 to 6.6, REQ-OBPL-004 to 009).
 * The shapes asserted are the pageLayout schema in
 * lib/Settings/register.d/51-page-layouts.json.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

const {
	default: PageLayoutBodyEditor,
	addTab,
	moveItem,
	missingReference,
	previewRows,
	hiddenWithoutDefault,
	WIDTHS,
	CONDITION_OPERATORS,
	TAB_KINDS,
} = await import('../../src/components/page-editor/fields/PageLayoutBodyEditor.vue')

function mountEditor(modelValue = {}) {
	return mount(PageLayoutBodyEditor, { props: { modelValue } })
}

function lastEmit(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('helpers', () => {
	it('offers the schema enums', () => {
		expect(TAB_KINDS).toEqual(['leaf', 'widgets', 'fieldGroup', 'relatedList'])
		expect(WIDTHS).toEqual(['small', 'medium', 'large', 'extraLarge'])
		expect(CONDITION_OPERATORS).toEqual([
			'equals',
			'notEquals',
			'isEmpty',
			'isNotEmpty',
			'contains',
		])
	})

	it('REQ-OBPL-004: adds a tab of a kind with a unique id and the next order', () => {
		const tabs = addTab(addTab([], 'fieldGroup'), 'widgets')
		expect(tabs.map((t) => [t.kind, t.order])).toEqual([
			['fieldGroup', 0],
			['widgets', 1],
		])
		expect(new Set(tabs.map((t) => t.id)).size).toBe(2)
		expect(tabs[1].widgets).toEqual([])
		expect(tabs[0].fields).toEqual([])
	})

	it('REQ-OBPL-004: moving an item renumbers the order', () => {
		const moved = moveItem([{ id: 'a' }, { id: 'b' }, { id: 'c' }], 2, -2)
		expect(moved.map((t) => [t.id, t.order])).toEqual([
			['c', 0],
			['a', 1],
			['b', 2],
		])
		expect(moveItem([{ id: 'a' }], 0, -1).map((t) => t.id)).toEqual(['a'])
	})

	it('REQ-OBPL-004: names the reference a tab kind lacks', () => {
		expect(missingReference({ kind: 'leaf', ref: '' })).toBe('ref')
		expect(missingReference({ kind: 'leaf', ref: 'filinq-documents' })).toBe('')
		expect(missingReference({ kind: 'fieldGroup', fields: [] })).toBe('fields')
		expect(missingReference({ kind: 'relatedList', relatedRegister: 'r' })).toBe(
			'relatedSchema',
		)
		expect(missingReference({ kind: 'widgets' })).toBe('')
	})

	it('REQ-OBPL-005: two medium widgets share a row and an extra large one fills the next', () => {
		const rows = previewRows([
			{ id: 'b', width: 'extraLarge', order: 2 },
			{ id: 'a1', width: 'medium', order: 0 },
			{ id: 'a2', width: 'medium', order: 1 },
		])
		expect(rows.map((row) => row.map((w) => w.id))).toEqual([
			['a1', 'a2'],
			['b'],
		])
	})

	it('REQ-OBPL-009: finds a hidden upload field with no default', () => {
		expect(
			hiddenWithoutDefault([
				{ field: 'documentType', visibility: 'hidden', default: '' },
				{
					field: 'confidentiality',
					visibility: 'hidden',
					default: 'intern',
				},
				{ field: 'title', visibility: 'editable' },
			]),
		).toEqual(['documentType'])
	})
})

describe('PageLayoutBodyEditor', () => {
	it('REQ-OBPL-004: adding three tabs and moving the leaf tab to the front emits them in that order', async () => {
		let value = { typeValue: 'bouwvergunning' }
		const wrapper = mountEditor(value)
		for (const kind of ['fieldGroup', 'widgets', 'leaf']) {
			wrapper.vm.newTabKind = kind
			wrapper.vm.onAddTab()
			value = lastEmit(wrapper)
			await wrapper.setProps({ modelValue: value })
		}
		wrapper.vm.onMoveTab(2, -2)
		value = lastEmit(wrapper)
		expect(value.tabs.map((t) => [t.kind, t.order])).toEqual([
			['leaf', 0],
			['fieldGroup', 1],
			['widgets', 2],
		])
		expect(value.typeValue).toBe('bouwvergunning')
	})

	it('REQ-OBPL-005 and 006: sets a widget width, a condition and high contrast', async () => {
		const tab = {
			id: 't1',
			kind: 'widgets',
			label: 'Overzicht',
			order: 0,
			widgets: [],
		}
		const wrapper = mountEditor({ tabs: [tab] })
		wrapper.vm.onAddWidget(0)
		let value = lastEmit(wrapper)
		await wrapper.setProps({ modelValue: value })
		wrapper.vm.onWidget(0, 0, 'width', 'extraLarge')
		value = lastEmit(wrapper)
		await wrapper.setProps({ modelValue: value })
		wrapper.vm.onAddCondition(0, 0)
		value = lastEmit(wrapper)
		await wrapper.setProps({ modelValue: value })
		wrapper.vm.onCondition(0, 0, 0, {
			field: 'resultType',
			operator: 'isNotEmpty',
			value: '',
		})
		value = lastEmit(wrapper)
		await wrapper.setProps({ modelValue: value })
		wrapper.vm.onWidget(0, 0, 'highContrast', true)
		const widget = lastEmit(wrapper).tabs[0].widgets[0]
		expect(widget).toMatchObject({
			width: 'extraLarge',
			highContrast: true,
			conditions: [{ field: 'resultType', operator: 'isNotEmpty', value: '' }],
		})
		expect(widget.id).not.toBe('')
	})

	it('REQ-OBPL-007: authors the header fields in order', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.onHeader('titleField', 'title')
		let value = lastEmit(wrapper)
		await wrapper.setProps({ modelValue: value })
		for (const field of ['dueDate', 'location']) {
			wrapper.vm.onAddHeaderField(field)
			value = lastEmit(wrapper)
			await wrapper.setProps({ modelValue: value })
		}
		wrapper.vm.onMoveHeaderField(1, -1)
		value = lastEmit(wrapper)
		expect(value.header.titleField).toBe('title')
		expect(value.header.fields.map((f) => [f.field, f.order])).toEqual([
			['location', 0],
			['dueDate', 1],
		])
	})

	it('REQ-OBPL-008: adds a task-list column and a search field', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.onAddColumn('location')
		let value = lastEmit(wrapper)
		await wrapper.setProps({ modelValue: value })
		wrapper.vm.onAddSearchField('location')
		value = lastEmit(wrapper)
		expect(value.taskList.columns).toEqual([
			{ field: 'location', label: '', order: 0 },
		])
		expect(value.taskList.searchFields).toEqual([
			{ field: 'location', operator: 'contains' },
		])
	})

	it('REQ-OBPL-009: a hidden upload field without a default is flagged', async () => {
		const wrapper = mountEditor({
			uploadFields: [
				{
					field: 'documentType',
					label: '',
					order: 0,
					visibility: 'hidden',
					default: '',
				},
			],
		})
		expect(wrapper.vm.blocked).toBe(true)
		expect(
			wrapper.find('[data-upload="documentType"] [role="alert"]').exists(),
		).toBe(true)
		wrapper.vm.onUploadField(0, 'default', 'intern')
		expect(lastEmit(wrapper).uploadFields[0].default).toBe('intern')
	})

	it('REQ-OBPL-004: a leaf tab without a leaf id shows which reference is missing', () => {
		const wrapper = mountEditor({
			tabs: [
				{ id: 't', kind: 'leaf', label: 'Documenten', order: 0, ref: '' },
			],
		})
		expect(wrapper.find('[data-tab="t"] [role="alert"]').exists()).toBe(true)
		expect(wrapper.vm.blocked).toBe(true)
	})
})
