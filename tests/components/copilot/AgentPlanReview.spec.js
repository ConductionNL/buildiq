import { flushPromises, mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentPlanReview.vue: a person opens a plan an outside agent
 * submitted, reads its steps and approves or rejects it
 * (openbuild-mcp-adoption, task 2).
 *
 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

import axios from '@nextcloud/axios'
import AgentPlanReview from '../../../src/components/copilot/AgentPlanReview.vue'

const PLAN_ID = '0b9b6c1e-6e5c-4a43-9a51-0f4f3f6b2a10'

const stubs = {
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input class="nctextfield-stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant', 'type'],
		emits: ['click'],
		template: '<button class="ncbutton-stub" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

function plan(overrides = {}) {
	return {
		planId: PLAN_ID,
		appSlug: 'vergunningen',
		submittedBy: 'anna',
		status: 'pending',
		steps: [{ tool: 'buildiq.upsertPage', arguments: { slug: 'vergunningen', page: { id: 'home' } } }],
		...overrides,
	}
}

function mountReview() {
	return mount(AgentPlanReview, { global: { stubs, mocks: { t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)) } } })
}

describe('AgentPlanReview', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('opens only an id shaped like a plan id', async () => {
		const wrapper = mountReview()
		wrapper.vm.planId = 'not-a-plan'
		await expect(wrapper.vm.open()).resolves.toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.vm.validId).toBe(false)
	})

	it('shows the steps of an opened plan with approve and reject', async () => {
		axios.get.mockResolvedValue({ data: plan() })
		const wrapper = mountReview()
		await wrapper.find('.nctextfield-stub').setValue(` ${PLAN_ID} `)

		await expect(wrapper.vm.open()).resolves.toBe(true)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`/apps/buildiq/api/agent-plans/${PLAN_ID}`)
		const shown = wrapper.find('[data-testid="agent-plan"]')
		expect(shown.text()).toContain('For vergunningen, submitted by anna.')
		expect(shown.text()).toContain('Waiting for a decision')
		expect(shown.text()).toContain('buildiq.upsertPage')
		expect(shown.text()).toContain('"id": "home"')
		expect(wrapper.find('[data-testid="agent-plan-approve"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="agent-plan-reject"]').exists()).toBe(true)
	})

	it('approves the plan and says the agent may go ahead', async () => {
		axios.get.mockResolvedValue({ data: plan() })
		axios.post.mockResolvedValue({ data: { planId: PLAN_ID, status: 'approved' } })
		const wrapper = mountReview()
		wrapper.vm.planId = PLAN_ID
		await wrapper.vm.open()
		await flushPromises()

		await wrapper.find('[data-testid="agent-plan-approve"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`/apps/buildiq/api/agent-plans/${PLAN_ID}/approve`)
		expect(wrapper.vm.plan.status).toBe('approved')
		expect(wrapper.find('[role="status"]').text()).toBe('The plan is approved. The agent can now make these changes.')
		expect(wrapper.find('[data-testid="agent-plan-approve"]').exists()).toBe(false)
	})

	it('rejects the plan and says nothing changed', async () => {
		axios.get.mockResolvedValue({ data: plan() })
		axios.post.mockResolvedValue({ data: { planId: PLAN_ID, status: 'rejected' } })
		const wrapper = mountReview()
		wrapper.vm.planId = PLAN_ID
		await wrapper.vm.open()

		await expect(wrapper.vm.decide(false)).resolves.toBe(true)

		expect(axios.post).toHaveBeenCalledWith(`/apps/buildiq/api/agent-plans/${PLAN_ID}/reject`)
		expect(wrapper.vm.statusLabel).toBe('Rejected')
		expect(wrapper.vm.notice).toEqual({ text: 'The plan is rejected. Nothing was changed.', failed: false })
	})

	it('offers no decision on a plan already decided', async () => {
		axios.get.mockResolvedValue({ data: plan({ status: 'approved' }) })
		const wrapper = mountReview()
		wrapper.vm.planId = PLAN_ID
		await wrapper.vm.open()
		await flushPromises()

		expect(wrapper.find('[data-testid="agent-plan-approve"]').exists()).toBe(false)
		expect(wrapper.vm.statusLabel).toBe('Approved')
	})

	it('names the refusal of a person without a write role, and of a missing plan', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 403, data: { error: 'forbidden' } } })
		const wrapper = mountReview()
		wrapper.vm.planId = PLAN_ID
		await expect(wrapper.vm.open()).resolves.toBe(false)
		expect(wrapper.vm.notice).toEqual({ text: 'Only an owner or editor of this app can see and decide its plans.', failed: true })
		expect(wrapper.vm.plan).toBeNull()

		axios.get.mockRejectedValueOnce({ response: { status: 404, data: { error: 'not_found' } } })
		await wrapper.vm.open()
		expect(wrapper.vm.notice.text).toBe('There is no plan with this id.')
	})

	it('names a plan decided in the meantime', async () => {
		axios.get.mockResolvedValue({ data: plan() })
		axios.post.mockRejectedValue({ response: { status: 409, data: { error: 'plan_decided' } } })
		const wrapper = mountReview()
		wrapper.vm.planId = PLAN_ID
		await wrapper.vm.open()

		await expect(wrapper.vm.decide(true)).resolves.toBe(false)
		expect(wrapper.vm.notice).toEqual({ text: 'This plan was already approved or rejected.', failed: true })
		expect(wrapper.vm.busy).toBe(false)
	})
})
