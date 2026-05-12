/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for CustomPageEditor (task 4.9 — `type: "custom"`).
 *
 * Covers:
 *  - `component` free-text propagates and clears on empty.
 *  - `props` JSON textarea: valid JSON emits parsed object, blank deletes
 *    the key, invalid JSON surfaces an inline error and does NOT emit.
 *  - Other config keys are listed as preserved and survive an update.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CustomPageEditor from '../../../src/components/page-editor/CustomPageEditor.vue'

function mountEditor(config = {}) {
	return mount(CustomPageEditor, { propsData: { config } })
}

describe('CustomPageEditor', () => {
	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Custom page')
	})

	it('component free-text propagates and clears on empty', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('component', 'MyDashboard')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].component).toBe('MyDashboard')
		wrapper.vm.update('component', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[1][0]).not.toHaveProperty('component')
	})

	it('valid props JSON emits the parsed object', async () => {
		const wrapper = mountEditor({ component: 'X' })
		wrapper.vm.onPropsInput('{"a":1,"b":["x"]}')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.props).toEqual({ a: 1, b: ['x'] })
		expect(wrapper.vm.propsError).toBe('')
	})

	it('blank props deletes the key', async () => {
		const wrapper = mountEditor({ component: 'X', props: { a: 1 } })
		wrapper.vm.onPropsInput('   ')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('props')
	})

	it('invalid props JSON surfaces an inline error and does NOT emit', async () => {
		const wrapper = mountEditor({ component: 'X' })
		wrapper.vm.onPropsInput('{not json')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.propsError).toBeTruthy()
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})

	it('lists other config keys preserved on save', () => {
		const wrapper = mountEditor({ component: 'X', layout: 'wide', flags: { beta: true } })
		expect(wrapper.vm.otherKeys.sort()).toEqual(['flags', 'layout'])
	})

	it('preserves unsurfaced config keys on update (lossless round-trip)', async () => {
		const wrapper = mountEditor({ component: 'X', layout: 'wide' })
		wrapper.vm.update('component', 'Y')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].layout).toBe('wide')
	})

	it('seeds the props textarea from an existing props object', () => {
		const wrapper = mountEditor({ component: 'X', props: { hello: 'world' } })
		expect(wrapper.vm.propsDraft).toContain('hello')
	})
})
