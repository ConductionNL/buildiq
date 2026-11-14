import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentRunHistory.vue.
 *
 * Spec: agent-workspace ("Every agent run is transparently logged and reviewable").
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

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

function run(overrides = {}) {
	return {
		id: 'run-1',
		agentId: 'agent-1',
		prompt: 'Add a contact-details step',
		outcome: 'applied',
		createdAt: '2026-07-23T10:00:00+00:00',
		toolCalls: [
			{
				tool: 'buildiq.upsertPage',
				arguments: { pageId: 'contact' },
				result: { isError: false },
			},
		],
		...overrides,
	}
}

describe('AgentRunHistory', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('fetches the agent run-history endpoint on mount', async () => {
		axios.get.mockResolvedValueOnce({ data: [] })
		mount(AgentRunHistory, { propsData: { agentId: 'agent-1' }, stubs })
		await flush()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/buildiq/api/agents/agent-1/runs',
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
		expect(toolCalls.at(0).text()).toContain('buildiq.upsertPage')
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
			'/apps/buildiq/api/agents/agent-2/runs',
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
	describe('model steps (REQ-BQAG-005)', () => {
		const plainT = globalThis.t
		beforeEach(() => {
			globalThis.t = (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
		})
		afterEach(() => {
			globalThis.t = plainT
		})

	it('lists a repaired run\'s model steps above its tool calls, with the refusal and knowledge used (REQ-BQAG-005)', async () => {
		axios.get.mockResolvedValueOnce({
			data: [
				run({
					knowledgeUsed: ['huisstijl-formulieren.md'],
					modelSteps: [
						{ attempt: 1, kind: 'plan', status: 'repair-needed', durationMs: 1200, refusal: 'Syntax error', output: '{steps: oops' },
						{ attempt: 2, kind: 'repair', status: 'successful', durationMs: 900, refusal: '', output: '{"steps": []}' },
					],
				}),
			],
		})
		const wrapper = mount(AgentRunHistory, { propsData: { agentId: 'agent-1' }, stubs })
		await flush()
		await flush()

		const steps = wrapper.findAll('[data-testid="agent-run-model-step"]')
		expect(steps).toHaveLength(2)
		expect(steps.at(0).text()).toContain('Attempt 1, repair needed')
		expect(steps.at(0).find('[data-testid="agent-run-step-refusal"]').text()).toBe('Syntax error')
		expect(steps.at(0).find('details pre').text()).toBe('{steps: oops')
		expect(steps.at(1).text()).toContain('Attempt 2')
		expect(steps.at(1).text()).toContain('900 ms')
		expect(steps.at(1).find('[data-testid="agent-run-step-refusal"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="agent-run-knowledge"]').text()).toBe('Knowledge used: huisstijl-formulieren.md')

		const html = wrapper.html()
		expect(html.indexOf('agent-run-model-step')).toBeLessThan(html.indexOf('agent-run-tool-call'))
		expect(wrapper.find('[data-testid="agent-run-trace-missing"]').exists()).toBe(false)
	})

	it('says so when a run\'s model steps were not kept, and still lists its tool calls', async () => {
		axios.get.mockResolvedValueOnce({ data: [run({ modelSteps: [], traceMissing: true })] })
		const wrapper = mount(AgentRunHistory, { propsData: { agentId: 'agent-1' }, stubs })
		await flush()
		await flush()

		expect(wrapper.find('[data-testid="agent-run-trace-missing"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid="agent-run-model-step"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-testid="agent-run-tool-call"]')).toHaveLength(1)
	})

	it('shows nothing about model steps for a run recorded before steps were kept', async () => {
		axios.get.mockResolvedValueOnce({ data: [run()] })
		const wrapper = mount(AgentRunHistory, { propsData: { agentId: 'agent-1' }, stubs })
		await flush()
		await flush()

		expect(wrapper.find('[data-testid="agent-run-trace-missing"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="agent-run-knowledge"]').exists()).toBe(false)
	})
	})
})
