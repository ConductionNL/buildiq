/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CustomPageEditor "Write your own" (pages-custom-code T04, REQ-BQCC-001) and
 * the CodeComponentEditor it uses.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import CustomPageEditor from '../../../src/components/page-editor/CustomPageEditor.vue'
import CodeComponentEditor from '../../../src/components/page-editor/fields/CodeComponentEditor.vue'

const stubs = {
	CnJsonViewer: {
		name: 'CnJsonViewer',
		props: ['value', 'language'],
		emits: ['update:value'],
		render: () => h('pre'),
	},
}

function lastEmit (wrapper, event) {
	const all = wrapper.emitted(event)
	return all[all.length - 1][0]
}

describe('CustomPageEditor — write your own', () => {
	it('starts in component mode and shows the code editor only for config.code', () => {
		const component = mount(CustomPageEditor, { props: { config: { component: 'X' } }, global: { stubs } })
		expect(component.vm.mode).toBe('component')
		expect(component.findComponent(CodeComponentEditor).exists()).toBe(false)

		const code = mount(CustomPageEditor, { props: { config: { code: { html: '<p>hi</p>' } } }, global: { stubs } })
		expect(code.vm.mode).toBe('code')
		expect(code.findComponent(CodeComponentEditor).props('modelValue')).toEqual({ html: '<p>hi</p>' })
	})

	it('switching to code takes component and props out, and switching back restores them', async () => {
		const wrapper = mount(CustomPageEditor, { props: { config: { component: 'X', props: { a: 1 }, layout: 'wide' } }, global: { stubs } })
		wrapper.vm.setMode('code')
		const toCode = lastEmit(wrapper, 'update:config')
		expect(toCode).toEqual({ layout: 'wide', code: {} })

		await wrapper.setProps({ config: { ...toCode, code: { js: 'x()' } } })
		wrapper.vm.setMode('component')
		expect(lastEmit(wrapper, 'update:config')).toEqual({ layout: 'wide', component: 'X', props: { a: 1 } })

		await wrapper.setProps({ config: lastEmit(wrapper, 'update:config') })
		wrapper.vm.setMode('code')
		expect(lastEmit(wrapper, 'update:config').code).toEqual({ js: 'x()' })
	})

	it('choosing the mode it is already in emits nothing', () => {
		const wrapper = mount(CustomPageEditor, { props: { config: { component: 'X' } }, global: { stubs } })
		wrapper.vm.setMode('component')
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})

	it('writes code edits to config.code and keeps other keys', () => {
		const wrapper = mount(CustomPageEditor, { props: { config: { code: { html: 'a' }, layout: 'wide' } }, global: { stubs } })
		wrapper.findComponent(CodeComponentEditor).vm.$emit('update:modelValue', { html: 'b', css: 'p{}' })
		expect(lastEmit(wrapper, 'update:config')).toEqual({ code: { html: 'b', css: 'p{}' }, layout: 'wide' })
		expect(wrapper.vm.otherKeys).toEqual(['layout'])
	})
})

describe('CodeComponentEditor', () => {
	const mountCode = (modelValue) => mount(CodeComponentEditor, { props: { modelValue }, global: { stubs } })

	it('offers an HTML editor and plain-text CSS and JavaScript editors', () => {
		const viewers = mountCode({ html: '<i>x</i>' }).findAllComponents({ name: 'CnJsonViewer' })
		expect(viewers.map((v) => v.props('language'))).toEqual(['html', 'text', 'text'])
		expect(viewers.map((v) => v.props('value'))).toEqual(['<i>x</i>', '', ''])
	})

	it('writes a part and drops an emptied one', () => {
		const wrapper = mountCode({ html: 'a', js: 'b' })
		wrapper.vm.setPart('css', 'i{}')
		expect(lastEmit(wrapper, 'update:modelValue')).toEqual({ html: 'a', js: 'b', css: 'i{}' })
		wrapper.vm.setPart('js', '')
		expect(lastEmit(wrapper, 'update:modelValue')).toEqual({ html: 'a' })
	})

	it('keeps the inputs in their fixed order and removes the list when nothing is ticked', () => {
		const wrapper = mountCode({ html: 'a', inputs: ['user'] })
		wrapper.vm.toggleInput('object', true)
		expect(lastEmit(wrapper, 'update:modelValue').inputs).toEqual(['object', 'user'])
		wrapper.vm.toggleInput('user', false)
		expect(lastEmit(wrapper, 'update:modelValue')).toEqual({ html: 'a' })
	})

	it('renders one checkbox per input, ticked as the value says', () => {
		const boxes = mountCode({ inputs: ['rows', 'route'] }).findAll('input[type="checkbox"]')
		expect(boxes.map((b) => b.element.checked)).toEqual([false, true, true, false])
	})
})
