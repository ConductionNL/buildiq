/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentRunHistory.vue.
 *
 * Spec: agent-workspace ("Every agent run is transparently logged and reviewable").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import AgentRunHistory from '../../src/components/agents/AgentRunHistory.vue'

const NcLoadingIconStub = {
	name: 'NcLoadingIcon',
	template: '<div class="ncloading-stub" />',
}
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="ncempty-stub">{{ name }}</div>',
}
const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="ncnotecard-stub"><slot /></div>',
}

const stubs = {
	NcLoadingIcon: NcLoadingIconStub,
	NcEmptyContent: NcEmptyContentStub,
	NcNoteCard: NcNoteCardStub,
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const run = (overrides = {}) => ({
	id: 'run-1',
	agentId: 'agent-1',
	prompt: 'Add a contact-details step',
	outcome: 'applied',
	createdAt: '2026-07-23T10:00:00+00:00',
	toolCalls: [
		{
			tool: 'openbuild.upsertPage',
			arguments: { pageId: 'contact' },
			result: { isError: false },
		},
	],
	...overrides,
})

describe('AgentRunHistory', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('fetches the agent run-history endpoint on mount', async () => {
		axios.get.mockResolvedValueOnce({ data: [] })
		mount(AgentRunHistory, { propsData: { agentId: 'agent-1' }, stubs })
		await flush()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openbuild/api/agents/agent-1/runs',
		)
	})

	it('renders each run with outcome badge, prompt, and tool-call detail', async () => {
		axios.get.mockResolvedValueOnce({ data: [run()] })
		const wrapper = mount(AgentRunHistory, {
			propsData: { agentId: 'agent-1' },
			stubs,
		})
		await flush()
		await flush()

		const rows = wrapper.findAll('[data-testid="agent-run-row"]')
		expect(rows).toHaveLength(1)
		expect(rows.at(0).text()).toContain('Add a contact-details step')

		const toolCalls = wrapper.findAll('[data-testid="agent-run-tool-call"]')
		expect(toolCalls).toHaveLength(1)
		expect(toolCalls.at(0).text()).toContain('openbuild.upsertPage')
	})

	it('shows an empty state when the agent has no runs yet', async () => {
		axios.get.mockResolvedValueOnce({ data: [] })
		const wrapper = mount(AgentRunHistory, {
			propsData: { agentId: 'agent-1' },
			stubs,
		})
		await flush()
		await flush()

		expect(wrapper.find('.ncempty-stub').exists()).toBe(true)
	})

	it('shows an error note card when the fetch fails', async () => {
		axios.get.mockRejectedValueOnce(new Error('network error'))
		const wrapper = mount(AgentRunHistory, {
			propsData: { agentId: 'agent-1' },
			stubs,
		})
		await flush()
		await flush()

		expect(wrapper.find('.ncnotecard-stub').exists()).toBe(true)
	})

	it('re-fetches when the agentId prop changes', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const wrapper = mount(AgentRunHistory, {
			propsData: { agentId: 'agent-1' },
			stubs,
		})
		await flush()

		await wrapper.setProps({ agentId: 'agent-2' })
		await flush()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openbuild/api/agents/agent-2/runs',
		)
	})

	it('a discarded run with no tool calls renders the no-calls hint', async () => {
		axios.get.mockResolvedValueOnce({
			data: [run({ outcome: 'discarded', toolCalls: [] })],
		})
		const wrapper = mount(AgentRunHistory, {
			propsData: { agentId: 'agent-1' },
			stubs,
		})
		await flush()
		await flush()

		expect(wrapper.find('.agent-run-history__no-calls').exists()).toBe(true)
		expect(wrapper.find('[data-testid="agent-run-outcome"]').text()).toBe(
			'Discarded',
		)
	})
})
