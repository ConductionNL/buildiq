/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/components/copilot/CopilotProposal.vue
 * (spec ai-copilot REQ-OBAIC-003/007).
 *
 * Covers: step-list render, ManifestDiff mount (one per touched version),
 * Approve disabled while canApprove is false, Discard emits without any
 * network call (the component never talks to the network directly).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import CopilotProposal from '../../src/components/copilot/CopilotProposal.vue'

function makePlan(overrides = {}) {
	return {
		summary: 'A tool library',
		steps: [
			{ tool: 'openbuild.createApp', arguments: { slug: 'tool-library', name: 'Tool Library' } },
			{ tool: 'openbuild.upsertPage', arguments: { appSlug: 'tool-library', pageId: 'home', title: 'Home', type: 'index', route: '/' } },
		],
		manifests: {
			'tool-library@development': {
				current: { version: '1.0.0', menu: [], pages: [] },
				predicted: { version: '1.0.0', menu: [], pages: [{ id: 'home', route: '/', type: 'index' }] },
			},
		},
		...overrides,
	}
}

describe('CopilotProposal.vue — spec ai-copilot REQ-OBAIC-003/007', () => {
	it('renders the summary and every step', () => {
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: true } })
		expect(wrapper.text()).toContain('A tool library')
		expect(wrapper.findAll('.copilot-proposal__step')).toHaveLength(2)
		expect(wrapper.text()).toContain('openbuild.createApp')
		expect(wrapper.text()).toContain('openbuild.upsertPage')
	})

	it('mounts one ManifestDiff per touched version', () => {
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: true } })
		expect(wrapper.findAllComponents({ name: 'ManifestDiff' })).toHaveLength(1)
	})

	it('Approve is disabled while canApprove is false', () => {
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: false } })
		const approve = wrapper.find('[data-testid="copilot-approve"]')
		expect(approve.attributes('disabled')).toBeTruthy()
	})

	it('Approve is enabled when canApprove is true', () => {
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: true } })
		const approve = wrapper.find('[data-testid="copilot-approve"]')
		expect(approve.attributes('disabled')).toBeFalsy()
	})

	it('shows the validation-failed hint when canApprove is false', () => {
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: false } })
		expect(wrapper.find('.copilot-proposal__error').exists()).toBe(true)
	})

	it('Discard emits without any network call', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: true } })
		await wrapper.find('[data-testid="copilot-discard"]').trigger('click')
		expect(wrapper.emitted('discard')).toBeTruthy()
		expect(wrapper.emitted('approve')).toBeFalsy()
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('Approve click emits approve', async () => {
		const wrapper = mount(CopilotProposal, { propsData: { plan: makePlan(), canApprove: true } })
		await wrapper.find('[data-testid="copilot-approve"]').trigger('click')
		expect(wrapper.emitted('approve')).toBeTruthy()
	})
})
