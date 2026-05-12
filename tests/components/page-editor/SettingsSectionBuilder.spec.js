/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SettingsSectionBuilder (task 4.5).
 *
 * Covers:
 *  - bodyKind() classifies fields / component / widgets sections.
 *  - setBodyKind switches the body and drops the inactive body keys.
 *  - addWidget seeds a `version-info` widget; updateWidget edits it;
 *    component widgets carry `componentName`.
 *  - addSection / removeSection.
 *  - FormFieldBuilder forwards through update:modelValue.
 *  - Section extra keys (id, icon) survive a body switch (lossless).
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('../../../src/components/page-editor/fields/FormFieldBuilder.vue', () => ({
	default: {
		name: 'FormFieldBuilder',
		props: ['modelValue'],
		render(h) { return h('div', { staticClass: 'form-field-builder-stub' }) },
	},
}))

const SettingsSectionBuilder = (await import('../../../src/components/page-editor/fields/SettingsSectionBuilder.vue')).default

function mountBuilder(modelValue = []) {
	return mount(SettingsSectionBuilder, { propsData: { modelValue } })
}

describe('SettingsSectionBuilder', () => {
	it('classifies body kinds', () => {
		const wrapper = mountBuilder()
		expect(wrapper.vm.bodyKind({ fields: [] })).toBe('fields')
		expect(wrapper.vm.bodyKind({ component: 'X' })).toBe('component')
		expect(wrapper.vm.bodyKind({ widgets: [] })).toBe('widgets')
		expect(wrapper.vm.bodyKind({})).toBe('fields')
	})

	it('addSection appends a fields section', async () => {
		const wrapper = mountBuilder([])
		wrapper.vm.addSection()
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0]).toHaveProperty('fields')
	})

	it('removeSection drops a section', async () => {
		const wrapper = mountBuilder([{ title: 'a' }, { title: 'b' }])
		wrapper.vm.removeSection(0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].title).toBe('b')
	})

	it('setBodyKind(component) drops fields/widgets and seeds component:""', async () => {
		const wrapper = mountBuilder([{ id: 'sec', title: 't', fields: [{ key: 'x' }] }])
		wrapper.vm.setBodyKind(0, 'component')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0]).not.toHaveProperty('fields')
		expect(next[0].component).toBe('')
		// Identity keys survive.
		expect(next[0].id).toBe('sec')
		expect(next[0].title).toBe('t')
	})

	it('setBodyKind(widgets) drops fields/component and seeds widgets:[]', async () => {
		const wrapper = mountBuilder([{ title: 't', component: 'X', props: { a: 1 } }])
		wrapper.vm.setBodyKind(0, 'widgets')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0]).not.toHaveProperty('component')
		expect(next[0]).not.toHaveProperty('props')
		expect(next[0].widgets).toEqual([])
	})

	it('addWidget seeds a version-info widget', async () => {
		const wrapper = mountBuilder([{ title: 't', widgets: [] }])
		wrapper.vm.addWidget(0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].widgets).toEqual([{ type: 'version-info' }])
	})

	it('updateWidget changes a widget type and forwards componentName', async () => {
		const wrapper = mountBuilder([{ title: 't', widgets: [{ type: 'version-info' }] }])
		wrapper.vm.updateWidget(0, 0, 'type', 'component')
		await wrapper.vm.$nextTick()
		let next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].widgets[0].type).toBe('component')
		wrapper.vm.updateWidget(0, 0, 'componentName', 'AppPanel')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:modelValue')[1][0]
		expect(next[0].widgets[0].componentName).toBe('AppPanel')
	})

	it('removeWidget drops a widget', async () => {
		const wrapper = mountBuilder([{ title: 't', widgets: [{ type: 'version-info' }, { type: 'register-mapping' }] }])
		wrapper.vm.removeWidget(0, 0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].widgets).toEqual([{ type: 'register-mapping' }])
	})

	it('FormFieldBuilder forwards through update:modelValue', async () => {
		const wrapper = mountBuilder([{ title: 't', fields: [] }])
		wrapper.findComponent({ name: 'FormFieldBuilder' }).vm
			.$emit('update:modelValue', [{ key: 'name', label: 'Name', type: 'string' }])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].fields).toHaveLength(1)
		expect(next[0].fields[0].key).toBe('name')
	})

	it('component props JSON parses on input', async () => {
		const wrapper = mountBuilder([{ title: 't', component: 'X' }])
		wrapper.vm.onPropsInput(0, '{"foo":1}')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].props).toEqual({ foo: 1 })
	})

	it('blank component props clears the key', async () => {
		const wrapper = mountBuilder([{ title: 't', component: 'X', props: { foo: 1 } }])
		wrapper.vm.onPropsInput(0, '   ')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0]).not.toHaveProperty('props')
	})
})
