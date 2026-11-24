/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for adding a chat page (capability-requirements-backfill T09,
 * REQ-BQCV-009), as far as buildiq builds it: the page list adds a page of
 * type `chat`, and ChatPageEditor writes the conversation source into
 * `config.conversationSource`, the key nextcloud-vue's CnChatPage reads
 * (CnPageRenderer passes `config` to it as props).
 *
 * Not asserted here, because buildiq does not build it: CnChatPage v1 shows
 * `conversationSource` in an iframe (an embeddable Nextcloud Talk
 * conversation, where posting is Talk's own), and passes `postUrl` and
 * `schema` through to a `#conversation` slot only. A conversation kept as
 * OpenRegister message records is not rendered and cannot be posted to. That
 * gap is reported on T09.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ChatPageEditor from '../../src/components/page-editor/ChatPageEditor.vue'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
import stubDraggable from './stubs/vuedraggable.js'

describe('a maker adds a chat page (REQ-BQCV-009)', () => {
	it('adds a page of type chat with an empty conversation source', async () => {
		const wrapper = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'chat'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()

		const page = wrapper.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('chat')
		expect(page.config).toEqual({ conversationSource: '' })
	})

	it('writes the conversation source the maker sets into config.conversationSource', async () => {
		const wrapper = mount(ChatPageEditor, { props: { config: { conversationSource: '' } } })
		const talk = 'https://cloud.example.nl/call/abc123'
		await wrapper.find('input[type="text"]').setValue(talk)

		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ conversationSource: talk })
	})

	it('shows the saved conversation source when the page is opened again', () => {
		const talk = 'https://cloud.example.nl/call/abc123'
		const wrapper = mount(ChatPageEditor, { props: { config: { conversationSource: talk } } })

		expect(wrapper.find('input[type="text"]').element.value).toBe(talk)
		expect(wrapper.vm.transportShape).toBe('conversationSource')
	})

	it('keeps the optional message schema next to the source', async () => {
		const wrapper = mount(ChatPageEditor, { props: { config: { conversationSource: '/x' } } })
		const inputs = wrapper.findAll('input[type="text"]')
		await inputs[inputs.length - 1].setValue('message')

		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ conversationSource: '/x', schema: 'message' })
	})
})
