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

		expect(wrapper.find('[data-testid="copilot-message-input"]').attributes('disabled')).toBeTruthy()
	})
})
