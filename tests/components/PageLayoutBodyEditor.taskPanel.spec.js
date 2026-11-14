/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task panel controls of PageLayoutBodyEditor.vue: the placement picker
 * and the tab checklist (change pages-task-panel-beside-case-tabs, T04,
 * REQ-BQTP-001 and REQ-BQTP-002).
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PageLayoutBodyEditor from '../../src/components/page-editor/fields/PageLayoutBodyEditor.vue'

const TABS = [
	{ id: 'besluiten', label: 'Besluiten', kind: 'leaf', ref: 'decisions', order: 2 },
	{ id: 'overzicht', label: 'Overzicht', kind: 'fieldGroup', fields: [], order: 0 },
	{ id: 'documenten', label: 'Documenten', kind: 'leaf', ref: 'documents', order: 1 },
]

function mountEditor(modelValue) {
	return mount(PageLayoutBodyEditor, { props: { modelValue } })
}

function placement(wrapper, value) {
	return wrapper.find(`input[name="task-panel-placement"][value="${value}"]`)
}

function lastEmit(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('task panel', () => {
	it('picking beside the tabs writes a side panel and keeps the columns', async () => {
		const wrapper = mountEditor({ tabs: TABS, taskList: { columns: [{ field: 'title' }] } })

		await placement(wrapper, 'side').setValue(true)

		expect(lastEmit(wrapper).taskList).toEqual({ columns: [{ field: 'title' }], panel: { placement: 'side' } })
	})

	it('ticking two tabs stores their ids in the layout order, not the click order', async () => {
		const wrapper = mountEditor({ tabs: TABS, taskList: { panel: { placement: 'side', tabs: ['documenten'] } } })

		const boxes = wrapper.findAll('.layout-body__panel-tabs input[type="checkbox"][value]')
		expect(boxes.map((box) => box.element.value)).toEqual(['overzicht', 'documenten', 'besluiten'])
		expect(boxes.map((box) => box.element.checked)).toEqual([false, true, false])

		await boxes[0].setValue(true)

		expect(lastEmit(wrapper).taskList.panel).toEqual({ placement: 'side', tabs: ['overzicht', 'documenten'] })
	})

	it('unticking a tab removes its id, and with none ticked the hint says every tab', async () => {
		const wrapper = mountEditor({ tabs: TABS, taskList: { panel: { placement: 'side', tabs: ['overzicht'] } } })
		expect(wrapper.find('.layout-body__hint').exists()).toBe(false)

		await wrapper.findAll('.layout-body__panel-tabs input[type="checkbox"][value]')[0].setValue(false)
		expect(lastEmit(wrapper).taskList.panel.tabs).toEqual([])

		const empty = mountEditor({ tabs: TABS, taskList: { panel: { placement: 'side' } } })
		expect(empty.find('.layout-body__hint').exists()).toBe(true)
	})

	it('folding the side panel sets collapsed', async () => {
		const wrapper = mountEditor({ tabs: TABS, taskList: { panel: { placement: 'side' } } })

		await wrapper.find('.layout-body__panel-collapsed').setValue(true)

		expect(lastEmit(wrapper).taskList.panel).toEqual({ placement: 'side', collapsed: true })
	})

	it('a tab or no placement drops the side-only keys, and the checklist hides', async () => {
		const wrapper = mountEditor({ tabs: TABS, taskList: { panel: { placement: 'side', tabs: ['overzicht'], collapsed: true } } })

		await placement(wrapper, 'none').setValue(true)

		expect(lastEmit(wrapper).taskList.panel).toEqual({ placement: 'none' })
		const hidden = mountEditor({ tabs: TABS, taskList: { panel: { placement: 'none' } } })
		expect(hidden.find('.layout-body__panel-tabs').exists()).toBe(false)
	})

	it('the schema-wide choice removes the panel, so the layout falls back', async () => {
		const wrapper = mountEditor({ tabs: TABS, taskList: { columns: [], panel: { placement: 'tab' } } })

		await placement(wrapper, '').setValue(true)

		expect(lastEmit(wrapper).taskList).toEqual({ columns: [] })
	})
})
