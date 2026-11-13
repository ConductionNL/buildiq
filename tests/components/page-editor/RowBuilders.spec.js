/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for four row builders of the page editor, driven through
 * their rendered inputs and buttons: SidebarSectionBuilder, WidgetBuilder,
 * SidebarTabBuilder and LayoutItemBuilder.
 *
 * Each one edits a list held by its parent: it never mutates the prop, it
 * emits a new list on every edit, add and remove.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LayoutItemBuilder from '../../../src/components/page-editor/fields/LayoutItemBuilder.vue'
import SidebarSectionBuilder from '../../../src/components/page-editor/fields/SidebarSectionBuilder.vue'
import SidebarTabBuilder from '../../../src/components/page-editor/fields/SidebarTabBuilder.vue'
import WidgetBuilder from '../../../src/components/page-editor/fields/WidgetBuilder.vue'

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

/**
 * The input with the given aria-label in a row.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The builder.
 * @param {number} row The row index.
 * @param {string} label The aria-label.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function field(wrapper, row, label) {
	return wrapper.findAll('[class$="__row"]')[row].find(`input[aria-label="${label}"]`)
}

describe('SidebarSectionBuilder', () => {
	const sections = [{ id: 'main', label: 'Main', columns: ['title', 'status'] }]

	it('renders a row per section with its id, label and columns', () => {
		const wrapper = mount(SidebarSectionBuilder, { props: { modelValue: sections } })

		expect(wrapper.findAll('.sidebar-section-builder__row')).toHaveLength(1)
		expect(field(wrapper, 0, 'Section id').element.value).toBe('main')
		expect(field(wrapper, 0, 'Label').element.value).toBe('Main')
		expect(field(wrapper, 0, 'Columns (comma-separated)').element.value).toBe('title,status')
	})

	it('edits a field without touching the prop', async () => {
		const wrapper = mount(SidebarSectionBuilder, { props: { modelValue: sections } })

		await field(wrapper, 0, 'Label').setValue('Overview')

		expect(lastEmitted(wrapper)).toEqual([{ id: 'main', label: 'Overview', columns: ['title', 'status'] }])
		expect(sections[0].label).toBe('Main')
	})

	it('splits columns on commas, trimming and dropping blanks', async () => {
		const wrapper = mount(SidebarSectionBuilder, { props: { modelValue: sections } })

		await field(wrapper, 0, 'Columns (comma-separated)').setValue(' title , ,owner,')

		expect(lastEmitted(wrapper)[0].columns).toEqual(['title', 'owner'])
	})

	it('adds an empty section and removes one', async () => {
		const wrapper = mount(SidebarSectionBuilder, { props: { modelValue: sections } })

		await wrapper.find('.sidebar-section-builder__add').trigger('click')
		expect(lastEmitted(wrapper)).toEqual([...sections, { id: '', label: '', columns: [] }])

		await wrapper.find('.sidebar-section-builder__remove').trigger('click')
		expect(lastEmitted(wrapper)).toEqual([])
	})

	it('treats a value that is not a list as no sections', () => {
		const wrapper = mount(SidebarSectionBuilder, { props: { modelValue: null } })

		expect(wrapper.findAll('.sidebar-section-builder__row')).toHaveLength(0)
	})
})

describe('WidgetBuilder', () => {
	const widgets = [{ id: 'kpi', title: 'Open cases', type: 'stat' }]

	it('edits id, title and type', async () => {
		const wrapper = mount(WidgetBuilder, { props: { modelValue: widgets } })

		expect(field(wrapper, 0, 'Type').element.value).toBe('stat')
		await field(wrapper, 0, 'Widget id').setValue('kpi-open')
		expect(lastEmitted(wrapper)).toEqual([{ id: 'kpi-open', title: 'Open cases', type: 'stat' }])
		await field(wrapper, 0, 'Type').setValue('chart')
		expect(lastEmitted(wrapper)[0].type).toBe('chart')
	})

	it('adds a custom widget and removes one', async () => {
		const wrapper = mount(WidgetBuilder, { props: { modelValue: widgets } })

		await wrapper.find('.widget-builder__add').trigger('click')
		expect(lastEmitted(wrapper)[1]).toEqual({ id: '', title: '', type: 'custom' })

		await wrapper.find('.widget-builder__remove').trigger('click')
		expect(lastEmitted(wrapper)).toEqual([])
		expect(widgets).toHaveLength(1)
	})

	it('treats a value that is not a list as no widgets', () => {
		expect(mount(WidgetBuilder, { props: { modelValue: 'x' } }).findAll('.widget-builder__row')).toHaveLength(0)
	})
})

describe('SidebarTabBuilder', () => {
	const tabs = [{ id: 'info', label: 'Info', icon: 'mdi-info', component: 'InfoTab' }]

	it('sets a field to a value', async () => {
		const wrapper = mount(SidebarTabBuilder, { props: { modelValue: tabs } })

		await field(wrapper, 0, 'Icon').setValue('mdi-help')

		expect(lastEmitted(wrapper)).toEqual([{ id: 'info', label: 'Info', icon: 'mdi-help', component: 'InfoTab' }])
	})

	it('clearing a field removes the key instead of storing an empty string', async () => {
		const wrapper = mount(SidebarTabBuilder, { props: { modelValue: tabs } })

		await field(wrapper, 0, 'Component (registry key)').setValue('')

		expect(lastEmitted(wrapper)).toEqual([{ id: 'info', label: 'Info', icon: 'mdi-info' }])
	})

	it('adds a tab with an id and label only, and removes one', async () => {
		const wrapper = mount(SidebarTabBuilder, { props: { modelValue: tabs } })

		await wrapper.find('.sidebar-tab-builder__add').trigger('click')
		expect(lastEmitted(wrapper)[1]).toEqual({ id: '', label: '' })

		await wrapper.find('.sidebar-tab-builder__remove').trigger('click')
		expect(lastEmitted(wrapper)).toEqual([])
	})

	it('explains that widgets and a component exclude each other', () => {
		const wrapper = mount(SidebarTabBuilder, { props: { modelValue: [] } })

		expect(wrapper.find('.sidebar-tab-builder__hint').text()).toContain('mutually exclusive')
	})
})

describe('LayoutItemBuilder', () => {
	const items = [{ id: 1, widgetId: 'kpi', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2 }]

	it('edits the widget id and parses grid numbers', async () => {
		const wrapper = mount(LayoutItemBuilder, { props: { modelValue: items } })
		const numbers = wrapper.findAll('input[type="number"]')

		await field(wrapper, 0, 'widget id').setValue('chart')
		expect(lastEmitted(wrapper)[0].widgetId).toBe('chart')

		await numbers[2].setValue('12')
		expect(lastEmitted(wrapper)[0]).toEqual({ id: 1, widgetId: 'kpi', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2 })
	})

	it('an empty number becomes 0', async () => {
		const wrapper = mount(LayoutItemBuilder, { props: { modelValue: items } })

		await wrapper.findAll('input[type="number"]')[3].setValue('')

		expect(lastEmitted(wrapper)[0].gridHeight).toBe(0)
	})

	it('adds an item numbered after the existing ones with a 6×2 default, and removes one', async () => {
		const wrapper = mount(LayoutItemBuilder, { props: { modelValue: items } })

		await wrapper.find('.layout-item-builder__add').trigger('click')
		expect(lastEmitted(wrapper)[1]).toEqual({ id: 2, widgetId: '', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2 })

		await wrapper.find('.layout-item-builder__remove').trigger('click')
		expect(lastEmitted(wrapper)).toEqual([])
		expect(items).toHaveLength(1)
	})
})
