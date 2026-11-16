/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Write your own" for a widget in the dashboard and detail editors
 * (pages-custom-code T04, REQ-BQCC-001).
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn().mockResolvedValue([]),
		fetchSchemas: vi.fn().mockResolvedValue([]),
	}),
}))

import DetailPageEditor from '../../../src/components/page-editor/DetailPageEditor.vue'
import CodeComponentEditor from '../../../src/components/page-editor/fields/CodeComponentEditor.vue'
import WidgetBuilder from '../../../src/components/page-editor/fields/WidgetBuilder.vue'

const stubs = {
	CnJsonViewer: {
		name: 'CnJsonViewer',
		props: ['value', 'language'],
		emits: ['update:value'],
		render: () => h('pre'),
	},
}

function mountBuilder(widgets, props = {}) {
	return mount(WidgetBuilder, { props: { modelValue: widgets, ...props }, global: { stubs } })
}

function lastEmit(wrapper, event) {
	const all = wrapper.emitted(event)
	return all[all.length - 1][0]
}

describe('WidgetBuilder — write your own', () => {
	it('offers the toggle on custom widgets only, and the editor only for a widget with code', () => {
		const wrapper = mountBuilder([
			{ id: 'plain', title: 'Plain', type: 'custom' },
			{ id: 'plan', title: 'Plan', type: 'custom', config: { code: { html: '<svg/>' } } },
			{ id: 'cal', title: 'Calendar', type: 'calendar' },
		])
		const items = wrapper.findAll('.widget-builder__item')
		expect(items.map((item) => item.find('[data-testid="widget-builder-write-your-own"]').exists())).toEqual([true, true, false])
		expect(items[0].find('input[type="checkbox"]').element.checked).toBe(false)
		expect(items[1].find('input[type="checkbox"]').element.checked).toBe(true)

		const editors = wrapper.findAllComponents(CodeComponentEditor)
		expect(editors).toHaveLength(1)
		expect(editors[0].props('modelValue')).toEqual({ html: '<svg/>' })
	})

	it('switching on writes an empty config.code and keeps the rest of the widget', async () => {
		const wrapper = mountBuilder([{ id: 'w', title: 'W', type: 'custom', props: { a: 1 } }])
		await wrapper.find('[data-testid="widget-builder-write-your-own"] input').setValue(true)
		expect(lastEmit(wrapper, 'update:modelValue')).toEqual([
			{ id: 'w', title: 'W', type: 'custom', props: { a: 1 }, config: { code: {} } },
		])
	})

	it('switching off takes the code out, drops an empty config, and switching back restores it', async () => {
		const wrapper = mountBuilder([{ id: 'w', title: 'W', type: 'custom', config: { code: { js: 'draw()', inputs: ['object'] } } }])
		wrapper.vm.setCodeMode(0, false)
		const off = lastEmit(wrapper, 'update:modelValue')
		expect(off).toEqual([{ id: 'w', title: 'W', type: 'custom' }])

		await wrapper.setProps({ modelValue: off })
		wrapper.vm.setCodeMode(0, true)
		expect(lastEmit(wrapper, 'update:modelValue')[0].config).toEqual({ code: { js: 'draw()', inputs: ['object'] } })
	})

	it('keeps other config keys when the code goes', () => {
		const wrapper = mountBuilder([{ id: 'w', title: 'W', type: 'custom', config: { code: { html: 'x' }, height: 2 } }])
		wrapper.vm.setCodeMode(0, false)
		expect(lastEmit(wrapper, 'update:modelValue')[0].config).toEqual({ height: 2 })
	})

	it('choosing the state it is already in emits nothing', () => {
		const wrapper = mountBuilder([{ id: 'w', title: 'W', type: 'custom' }])
		wrapper.vm.setCodeMode(0, false)
		expect(wrapper.emitted('update:modelValue')).toBeFalsy()
	})

	it('writes the editor\'s code into config.code of that widget only', () => {
		const widgets = [
			{ id: 'a', title: 'A', type: 'custom' },
			{ id: 'b', title: 'B', type: 'custom', config: { code: { html: 'old' }, height: 2 } },
		]
		const wrapper = mountBuilder(widgets)
		wrapper.findComponent(CodeComponentEditor).vm.$emit('update:modelValue', { html: 'new', inputs: ['user'] })
		expect(lastEmit(wrapper, 'update:modelValue')).toEqual([
			widgets[0],
			{ id: 'b', title: 'B', type: 'custom', config: { code: { html: 'new', inputs: ['user'] }, height: 2 } },
		])
	})

	it('hides the external data widget with withoutConnectors', () => {
		expect(mountBuilder([]).find('[data-testid="widget-builder-add-connector"]').exists()).toBe(true)
		expect(mountBuilder([], { withoutConnectors: true }).find('[data-testid="widget-builder-add-connector"]').exists()).toBe(false)
	})
})

describe('DetailPageEditor — widgets', () => {
	function mountDetail(config) {
		return mount(DetailPageEditor, {
			props: { config, parentRoute: '/gebouwen/:id', appSlug: 'hello-world', pageType: 'detail' },
			global: { stubs },
		})
	}

	it('edits config.widgets without the external data widget', () => {
		const builder = mountDetail({ widgets: [{ id: 'plan', title: 'Plan', type: 'custom' }] }).findComponent(WidgetBuilder)
		expect(builder.props('modelValue')).toEqual([{ id: 'plan', title: 'Plan', type: 'custom' }])
		expect(builder.props('withoutConnectors')).toBe(true)
	})

	it('saves a floor plan widget with code and input object onto the page config', () => {
		const wrapper = mountDetail({ register: 'r', schema: 'gebouw' })
		const code = { html: '<div id="plan"></div>', js: 'draw(object.ruimtes)', inputs: ['object'] }
		wrapper.findComponent(WidgetBuilder).vm.$emit('update:modelValue', [
			{ id: 'plan', title: 'Plan', type: 'custom', config: { code } },
		])
		expect(lastEmit(wrapper, 'update:config')).toEqual({
			register: 'r',
			schema: 'gebouw',
			widgets: [{ id: 'plan', title: 'Plan', type: 'custom', config: { code } }],
		})
	})

	it('removes config.widgets when the last widget goes', () => {
		const wrapper = mountDetail({ register: 'r', widgets: [{ id: 'a', title: 'A', type: 'custom' }] })
		wrapper.findComponent(WidgetBuilder).vm.$emit('update:modelValue', [])
		expect(lastEmit(wrapper, 'update:config')).toEqual({ register: 'r' })
	})
})
