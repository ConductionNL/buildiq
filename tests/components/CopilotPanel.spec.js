/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/components/copilot/CopilotPanel.vue
 * (spec ai-copilot REQ-OBAIC-007).
 *
 * Covers: user bubble renders synchronously, a proposal card appears once
 * the plan response resolves, Approve calls executePlan exactly once,
 * Discard never calls it.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const axiosGet = vi.fn()
const axiosPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import CopilotPanel from '../../src/components/copilot/CopilotPanel.vue'
import { clearCopilotHealthCache } from '../../src/composables/useCopilot.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('CopilotPanel.vue — spec ai-copilot REQ-OBAIC-007', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		clearCopilotHealthCache()
	})

	it('renders a user bubble synchronously on send', async () => {
		axiosPost.mockReturnValueOnce(new Promise(() => {})) // never resolves in this test
		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library' } })
		const input = wrapper.find('[data-testid="copilot-message-input"]')
		await input.setValue('Add a suppliers page with a table widget')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')

		expect(wrapper.text()).toContain('Add a suppliers page with a table widget')
	})

	it('renders a CopilotProposal card once the plan response resolves', async () => {
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'Adds a suppliers page',
				steps: [{ tool: 'openbuild.upsertPage', arguments: { appSlug: 'tool-library', pageId: 'suppliers', title: 'Suppliers', type: 'index', route: '/suppliers' } }],
				manifests: {},
			},
		})

		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a suppliers page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.findAllComponents({ name: 'CopilotProposal' })).toHaveLength(1)
	})

	it('Approve calls executePlan exactly once', async () => {
		axiosPost
			.mockResolvedValueOnce({ data: { summary: 'x', steps: [{ tool: 'openbuild.upsertPage', arguments: {} }], manifests: {} } })
			.mockResolvedValueOnce({ data: { results: [{ success: true }] } })

		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')
		await flush()
		await wrapper.vm.$nextTick()

		await wrapper.findComponent({ name: 'CopilotProposal' }).vm.$emit('approve')
		await flush()
		await wrapper.vm.$nextTick()

		expect(axiosPost).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('executed')).toBeTruthy()
	})

	it('Discard never calls executePlan', async () => {
		axiosPost.mockResolvedValueOnce({ data: { summary: 'x', steps: [{ tool: 'openbuild.upsertPage', arguments: {} }], manifests: {} } })

		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')
		await flush()
		await wrapper.vm.$nextTick()

		axiosPost.mockClear()
		await wrapper.findComponent({ name: 'CopilotProposal' }).vm.$emit('discard')
		await flush()

		expect(axiosPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('executed')).toBeFalsy()
	})

	it('the input is disabled while a proposal is pending review', async () => {
		axiosPost.mockResolvedValueOnce({ data: { summary: 'x', steps: [{ tool: 'openbuild.upsertPage', arguments: {} }], manifests: {} } })

		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')
		await flush()
		await wrapper.vm.$nextTick()

		// Vue 3 renders a true boolean attribute as `disabled=""` — a falsy
		// empty string — so presence is the signal, not truthiness.
		expect(wrapper.find('[data-testid="copilot-message-input"]').attributes('disabled')).toBeDefined()
	})

	// -------------------------------------------------------------------
	// Agent-scoping (spec agent-workspace design.md Decision 3)
	// -------------------------------------------------------------------

	it('omitting agentId renders no "acting as" header (bare copilot, unchanged)', () => {
		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library' } })
		expect(wrapper.find('[data-testid="copilot-acting-as"]').exists()).toBe(false)
	})

	it('an agentId renders the "acting as" header with the agent name', () => {
		const wrapper = mount(CopilotPanel, {
			propsData: { appSlug: 'tool-library', agentId: 'agent-1', name: 'Page builder assistant', instructions: 'Be helpful.' },
		})
		const header = wrapper.find('[data-testid="copilot-acting-as"]')
		expect(header.exists()).toBe(true)
		expect(header.text()).toContain('Page builder assistant')
		expect(header.text()).toContain('Be helpful.')
	})

	it('sending a message with an agentId includes it in the plan request', async () => {
		axiosPost.mockReturnValueOnce(new Promise(() => {}))
		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library', agentId: 'agent-1' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')

		expect(axiosPost).toHaveBeenCalledWith(
			'/apps/openbuild/api/copilot/plan',
			expect.objectContaining({ brief: 'add a page', appSlug: 'tool-library', agentId: 'agent-1' }),
		)
	})

	it('approving a proposal with an agentId includes it in the execute request', async () => {
		axiosPost
			.mockResolvedValueOnce({ data: { summary: 'x', steps: [{ tool: 'openbuild.upsertPage', arguments: {} }], manifests: {} } })
			.mockResolvedValueOnce({ data: { results: [{ success: true }] } })

		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library', agentId: 'agent-1' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')
		await flush()
		await wrapper.vm.$nextTick()

		await wrapper.findComponent({ name: 'CopilotProposal' }).vm.$emit('approve')
		await flush()

		expect(axiosPost).toHaveBeenNthCalledWith(
			2,
			'/apps/openbuild/api/copilot/execute',
			expect.objectContaining({ agentId: 'agent-1', prompt: 'add a page' }),
		)
	})

	it('discarding a proposal with an agentId logs it via the discard endpoint', async () => {
		axiosPost.mockResolvedValueOnce({ data: { summary: 'x', steps: [{ tool: 'openbuild.upsertPage', arguments: {} }], manifests: {} } })

		const wrapper = mount(CopilotPanel, { propsData: { appSlug: 'tool-library', agentId: 'agent-1' } })
		await wrapper.find('[data-testid="copilot-message-input"]').setValue('add a page')
		await wrapper.find('[data-testid="copilot-message-input"]').trigger('keydown.enter')
		await flush()
		await wrapper.vm.$nextTick()

		axiosPost.mockClear()
		axiosPost.mockResolvedValueOnce({ data: { status: 'logged' } })
		await wrapper.findComponent({ name: 'CopilotProposal' }).vm.$emit('discard')
		await flush()

		expect(axiosPost).toHaveBeenCalledWith(
			'/apps/openbuild/api/copilot/discard',
			expect.objectContaining({ agentId: 'agent-1', prompt: 'add a page' }),
		)
	})
})
