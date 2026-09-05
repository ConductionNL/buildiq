import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for MyApprovalsWidget.vue.
 *
 * Spec: automation-approval-action ("My Approvals runtime widget lists
 * pending steps for the viewer's groups").
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import MyApprovalsWidget from '../../src/components/runtime/MyApprovalsWidget.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="ncnotecard-stub"><slot /></div>',
}

const stubs = { NcButton: NcButtonStub, NcNoteCard: NcNoteCardStub }

const flush = () => new Promise((r) => setTimeout(r, 0))

const stepsFixture = [
	{ id: 1, role: 'permit-reviewers', objectUuid: 'obj-1', status: 'pending' },
	{ id: 2, role: 'finance-reviewers', objectUuid: 'obj-2', status: 'pending' },
]

describe('MyApprovalsWidget', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		loadState.mockReset()
	})

	it("lists only pending steps whose role is in the viewer's groups", async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockResolvedValue({ data: stepsFixture })

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		const rows = wrapper.findAll('[data-testid="my-approvals-row"]')
		expect(rows).toHaveLength(1)
		expect(wrapper.text()).toContain('permit-reviewers')
		expect(wrapper.text()).not.toContain('finance-reviewers')
	})

	it("renders an empty state when the viewer's groups match no pending step", async () => {
		loadState.mockReturnValue(['no-match-group'])
		axios.get.mockResolvedValue({ data: stepsFixture })

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="my-approvals-empty"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="my-approvals-row"]').exists()).toBe(false)
	})

	it('approve completes the task with an approving outcome', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockResolvedValue({ data: stepsFixture })
		axios.post.mockResolvedValue({ data: {} })

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="approve-button"]').trigger('click')
		await flush()

		// openregister #3302 replaced the approve/reject verbs with one
		// `complete` call carrying an outcome.
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flow-tasks/1/complete',
			{ outcome: 'approved' },
		)
	})

	it('reject completes the task with a rejecting outcome and a comment', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockResolvedValue({ data: stepsFixture })
		axios.post.mockResolvedValue({ data: {} })

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="reject-button"]').trigger('click')
		await flush()

		// A rejecting outcome REFUSES an empty comment server-side
		// (TaskService::completeInternal), so one must always be sent.
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flow-tasks/1/complete',
			{
				outcome: 'rejected',
				comment: 'Rejected from the My approvals widget.',
			},
		)
	})

	it('shows an error state with retry when the load fails', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockRejectedValue(new Error('network error'))

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Could not load pending approvals.')
	})
})
