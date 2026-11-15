/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for WidgetSelectionPanel — picking widgets on a page to save
 * as a component block.
 *
 * Covers:
 *  - toggling a widget in and out of the selection.
 *  - the capture fragment: nothing, the single widget, or a section wrapper
 *    whose id is built from the widget ids and capped at 60 characters.
 *  - the save dialog opens only with a selection; a save resets it and is
 *    forwarded.
 *  - a re-fetched widget list drops selections that no longer exist.
 *  - the button label and disabled state follow the selection.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WidgetSelectionPanel from '../../../src/components/page-editor/WidgetSelectionPanel.vue'

const WIDGETS = [
	{ id: 'w1', widgetKey: 'table', slot: 'main' },
	{ id: 'w2', widgetKey: 'chart', slot: 'side' },
	{ id: 'w3', slot: 'main' },
]

function mountPanel(widgets = WIDGETS) {
	return shallowMount(WidgetSelectionPanel, { props: { widgets } })
}

describe('WidgetSelectionPanel', () => {
	it('says so when the page has no widgets', () => {
		const wrapper = mountPanel([])
		expect(wrapper.text()).toContain('This page has no widgets yet.')
		expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
	})

	it('lists each widget by its key, falling back to its id', () => {
		const labels = mountPanel().findAll('.widget-selection-panel__label').map((l) => l.text())
		expect(labels).toEqual(['table', 'chart', 'w3'])
	})

	it('toggles a widget in and out of the selection', () => {
		const wrapper = mountPanel()
		wrapper.vm.toggle('w2')
		wrapper.vm.toggle('w1')
		expect(wrapper.vm.selectedIds).toEqual(['w2', 'w1'])
		expect(wrapper.vm.isSelected('w1')).toBe(true)

		wrapper.vm.toggle('w2')
		expect(wrapper.vm.selectedIds).toEqual(['w1'])
		expect(wrapper.vm.isSelected('w2')).toBe(false)
	})

	it('a checkbox change toggles its widget', async () => {
		const wrapper = mountPanel()
		await wrapper.findAll('input[type="checkbox"]')[1].trigger('change')
		expect(wrapper.vm.selectedIds).toEqual(['w2'])
	})

	it('captures nothing without a selection', () => {
		expect(mountPanel().vm.captureFragment).toBeNull()
	})

	it('captures the widget itself for a single selection', () => {
		const wrapper = mountPanel()
		wrapper.vm.toggle('w2')
		expect(wrapper.vm.captureFragment).toEqual(WIDGETS[1])
	})

	it('captures a section in page order for several widgets', () => {
		const wrapper = mountPanel()
		wrapper.vm.toggle('w3')
		wrapper.vm.toggle('w1')
		expect(wrapper.vm.captureFragment).toEqual({ id: 'section-w1-w3', widgets: [WIDGETS[0], WIDGETS[2]] })
	})

	it('caps the section id at 60 characters', () => {
		const long = [{ id: 'a'.repeat(40) }, { id: 'b'.repeat(40) }]
		const wrapper = mountPanel(long)
		wrapper.vm.toggle(long[0].id)
		wrapper.vm.toggle(long[1].id)
		const id = wrapper.vm.captureFragment.id
		expect(id).toHaveLength(60)
		expect(id).toBe(`section-${'a'.repeat(40)}-${'b'.repeat(40)}`.slice(0, 60))
	})

	it('opens the save dialog only with a selection', () => {
		const wrapper = mountPanel()
		wrapper.vm.openSaveDialog()
		expect(wrapper.vm.saveDialogOpen).toBe(false)

		wrapper.vm.toggle('w1')
		wrapper.vm.openSaveDialog()
		expect(wrapper.vm.saveDialogOpen).toBe(true)
	})

	it('forwards a save and clears the selection', () => {
		const wrapper = mountPanel()
		wrapper.vm.toggle('w1')
		wrapper.vm.onSaved({ slug: 'my-block' })
		expect(wrapper.emitted('saved')).toEqual([[{ slug: 'my-block' }]])
		expect(wrapper.vm.selectedIds).toEqual([])
	})

	it('drops selections for widgets that are gone after a re-fetch', async () => {
		const wrapper = mountPanel()
		wrapper.vm.toggle('w1')
		wrapper.vm.toggle('w2')
		await wrapper.setProps({ widgets: [WIDGETS[1], { id: 'w9' }] })
		expect(wrapper.vm.selectedIds).toEqual(['w2'])
	})

	it('names the button after the size of the selection', async () => {
		const wrapper = mountPanel()
		const button = () => wrapper.find('.widget-selection-panel__save-btn')
		expect(button().attributes('disabled')).toBeDefined()
		expect(button().text()).toBe('Save selected widget as block')

		wrapper.vm.toggle('w1')
		await wrapper.vm.$nextTick()
		expect(button().attributes('disabled')).toBeUndefined()

		wrapper.vm.toggle('w2')
		await wrapper.vm.$nextTick()
		expect(button().text()).toBe('Save selected section as block')
	})
})
