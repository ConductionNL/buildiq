/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for StubPageEditor — the deferred-type fallback editor used
 * by the v1.1 stubs (logs, settings, chat, files, custom). Round-trips
 * the config block via a raw-JSON textarea so externally-authored
 * manifests are never blanked by the placeholder UI.
 *
 * Covers (openbuilt#9 task 7.1):
 *  - renders the supplied title + message strings
 *  - the textarea seed reflects the incoming config (stable JSON form)
 *  - typing valid JSON emits an `update:config` with the parsed payload
 *    AND clears any prior parse error
 *  - typing invalid JSON surfaces the parser error inline AND does NOT emit
 *  - external config updates re-seed the textarea without bouncing edits
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import StubPageEditor from '../../../src/components/page-editor/StubPageEditor.vue'

function mountEditor(config = {}, propsOverrides = {}) {
	return mount(StubPageEditor, {
		propsData: {
			title: 'Stub editor',
			message: 'Coming in v1.1',
			config,
			...propsOverrides,
		},
	})
}

describe('StubPageEditor', () => {
	it('renders the supplied title + message', () => {
		const wrapper = mountEditor({}, { title: 'Logs editor', message: 'Hold tight' })
		expect(wrapper.text()).toContain('Logs editor')
		expect(wrapper.text()).toContain('Hold tight')
	})

	it('seeds the textarea with the JSON-stringified config', () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		const textarea = wrapper.find('.stub-page-editor__textarea')
		const seeded = JSON.parse(textarea.element.value)
		expect(seeded).toEqual({ register: 'r', schema: 's' })
	})

	it('valid JSON typed by the user emits update:config with the parsed payload', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.onInput('{"register":"newman","schema":"hello"}')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:config')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0]).toEqual({ register: 'newman', schema: 'hello' })
		expect(wrapper.vm.parseError).toBe('')
	})

	it('invalid JSON surfaces the parser error inline and does NOT emit', async () => {
		const wrapper = mountEditor({ register: 'r' })
		wrapper.vm.onInput('{not json')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.parseError).toBeTruthy()
		expect(wrapper.find('.stub-page-editor__error').exists()).toBe(true)
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})

	it('an external config update re-seeds the textarea', async () => {
		const wrapper = mountEditor({ register: 'r' })
		await wrapper.setProps({ config: { register: 'r-updated', extra: 1 } })
		await wrapper.vm.$nextTick()
		const seeded = JSON.parse(wrapper.find('.stub-page-editor__textarea').element.value)
		expect(seeded).toEqual({ register: 'r-updated', extra: 1 })
	})

	it('an external config update that matches the seed-form does not bounce the draft', async () => {
		// Stability check: re-setting the same canonical config (the watcher
		// re-emits the same 2-space-indented form) leaves the draft alone
		// because the fresh string equals the existing draft.
		const wrapper = mountEditor({ register: 'r' })
		const initial = wrapper.vm.jsonDraft
		await wrapper.setProps({ config: { register: 'r' } })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.jsonDraft).toBe(initial)
	})
})
