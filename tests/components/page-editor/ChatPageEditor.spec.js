/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ChatPageEditor (task 4.6 — `type: "chat"`).
 *
 * Covers:
 *  - Renders both transport-shape radios.
 *  - One-of: conversationSource vs postUrl — setting one clears the other.
 *  - Switching radios clears the inactive key.
 *  - Optional `schema` propagates and clears on empty.
 *  - Lossless round-trip of an unsurfaced config key.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ChatPageEditor from '../../../src/components/page-editor/ChatPageEditor.vue'

function mountEditor(config = {}) {
	return mount(ChatPageEditor, { propsData: { config } })
}

describe('ChatPageEditor', () => {
	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Chat page')
	})

	it('renders both transport-shape radios', () => {
		expect(mountEditor().findAll('input[type="radio"]')).toHaveLength(2)
	})

	it('defaults to the conversationSource shape', () => {
		expect(mountEditor().vm.transportShape).toBe('conversationSource')
	})

	it('a config with only postUrl reports the postUrl shape', () => {
		expect(mountEditor({ postUrl: '/api/x/messages' }).vm.transportShape).toBe('postUrl')
	})

	it('setTransport(conversationSource) clears postUrl (mutex)', async () => {
		const wrapper = mountEditor({ postUrl: '/api/x' })
		wrapper.vm.setTransport('conversationSource', '/api/x/stream')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.conversationSource).toBe('/api/x/stream')
		expect(next).not.toHaveProperty('postUrl')
	})

	it('setTransport(postUrl) clears conversationSource (mutex)', async () => {
		const wrapper = mountEditor({ conversationSource: '/api/x/stream' })
		wrapper.vm.setTransport('postUrl', '/api/x/messages')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.postUrl).toBe('/api/x/messages')
		expect(next).not.toHaveProperty('conversationSource')
	})

	it('clearing the transport value with empty string removes the key', async () => {
		const wrapper = mountEditor({ conversationSource: '/x' })
		wrapper.vm.setTransport('conversationSource', '')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('conversationSource')
	})

	it('radio toggle to postUrl clears conversationSource', async () => {
		const wrapper = mountEditor({ conversationSource: '/x' })
		wrapper.vm.setTransportShape('postUrl')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('conversationSource')
	})

	it('optional schema propagates and clears on empty', async () => {
		const wrapper = mountEditor({ conversationSource: '/x' })
		wrapper.vm.update('schema', 'message')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].schema).toBe('message')
		wrapper.vm.update('schema', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[1][0]).not.toHaveProperty('schema')
	})

	it('preserves unsurfaced config keys on update (lossless round-trip)', async () => {
		const wrapper = mountEditor({ postUrl: '/x', heading: 'Talk to us' })
		wrapper.vm.update('schema', 'message')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].heading).toBe('Talk to us')
	})
})
