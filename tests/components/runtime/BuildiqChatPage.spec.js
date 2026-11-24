/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BuildiqChatPage: a chat page bound to a messages schema
 * shows the conversation kept as its records and posts a new message as a
 * new record (capability-requirements-backfill T09, REQ-BQCV-009).
 *
 * CnConversationThread is replaced by a stand-in with its send contract: it
 * POSTs `{ [messageKey]: text }` to `endpoint` and emits `sent`.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.fn()
const axiosPost = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => axiosGet(...args),
		post: (...args) => axiosPost(...args),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'olaf' }) }))

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const ThreadStandIn = {
	name: 'CnConversationThread',
	props: {
		messages: { type: Array, default: () => [] },
		endpoint: { type: String, default: '' },
		messageKey: { type: String, default: 'message' },
		title: { type: String, default: '' },
		loading: { type: Boolean, default: false },
		placeholder: { type: String, default: '' },
	},
	emits: ['sent'],
	methods: {
		async send(text) {
			const response = await axiosPost(this.endpoint, { [this.messageKey]: text })
			this.$emit('sent', response.data)
		},
	},
	render() {
		return h('ol', this.messages.map((m) => h('li', { class: m.side }, `${m.author}: ${m.text}`)))
	},
}

const ChatPageStandIn = {
	name: 'CnChatPage',
	props: { conversationSource: { type: String, default: '' }, postUrl: { type: String, default: '' } },
	render: () => h('iframe'),
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnConversationThread: ThreadStandIn, CnChatPage: ChatPageStandIn }))

const { default: BuildiqChatPage } = await import('../../../src/components/runtime/BuildiqChatPage.vue')

const PATH = '/apps/openregister/api/objects/permits/permit-message'

/**
 * A record of the messages schema.
 *
 * @param {string} id The id.
 * @param {string} owner Who wrote it.
 * @param {string} body The text.
 * @return {object} The record.
 */
function record(id, owner, body) {
	return { body, '@self': { id, owner, created: '2026-11-24T10:0' + id + ':00+00:00' } }
}

describe('BuildiqChatPage (REQ-BQCV-009)', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
	})

	it('shows the conversation and a posted message appears in it', async () => {
		const thread = [record('1', 'sanne', 'Is the permit ready?')]
		axiosGet.mockImplementation(() => Promise.resolve({ data: { results: [...thread] } }))
		axiosPost.mockImplementation((url, body) => {
			thread.push(record('2', 'olaf', body.body))
			return Promise.resolve({ data: {} })
		})
		const wrapper = mount(BuildiqChatPage, {
			props: { register: 'permits', schema: 'permit-message', messageField: 'body', title: 'Questions' },
		})
		await flushPromises()

		expect(axiosGet.mock.calls[0][0]).toBe('/index.php' + PATH + '?_limit=100&_order=%7B%22%40self.created%22%3A%22ASC%22%7D')
		expect(wrapper.findAll('li').map((li) => li.text())).toEqual(['sanne: Is the permit ready?'])

		await wrapper.findComponent(ThreadStandIn).vm.send('Yes, signed today.')
		await flushPromises()

		expect(axiosPost).toHaveBeenCalledWith(PATH, { body: 'Yes, signed today.' })
		const items = wrapper.findAll('li')
		expect(items.map((li) => li.text())).toEqual(['sanne: Is the permit ready?', 'olaf: Yes, signed today.'])
		expect(items[1].classes()).toContain('us')
	})

	it('reads the text from the message field, by default `message`, and skips records without text', async () => {
		axiosGet.mockResolvedValue({ data: { results: [{ message: 'Hello', '@self': { id: 'a', owner: 'vera' } }, { title: 'no text', '@self': { id: 'b' } }] } })
		const wrapper = mount(BuildiqChatPage, { props: { register: 'permits', schema: 'permit-message' } })
		await flushPromises()

		expect(wrapper.findAll('li').map((li) => li.text())).toEqual(['vera: Hello'])
		expect(wrapper.findComponent(ThreadStandIn).props('messageKey')).toBe('message')
	})

	it('says so when the conversation cannot be loaded', async () => {
		axiosGet.mockRejectedValue(new Error('403'))
		const wrapper = mount(BuildiqChatPage, { props: { register: 'permits', schema: 'permit-message' } })
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The conversation could not be loaded.')
	})

	it('renders an embedded conversation as CnChatPage', async () => {
		const wrapper = mount(BuildiqChatPage, { props: { conversationSource: 'https://cloud.example.nl/call/abc' } })
		await flushPromises()

		expect(wrapper.findComponent(ChatPageStandIn).props('conversationSource')).toBe('https://cloud.example.nl/call/abc')
		expect(axiosGet).not.toHaveBeenCalled()
	})
})
