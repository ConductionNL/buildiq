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

/**
 * One `/api/flow-tasks` row as OpenRegister sends it: `Task::jsonSerialize()`
 * plus the derived fields `TaskInboxService::row()` adds (`subject`,
 * `displayTitle`, `overdue`, `daysUntilDue`, `daysOverdue`). A task names its
 * candidates in `candidateGroups` / `candidateRole`; it has NO `role` and NO
 * `status` field, which is exactly what the retired fixture assumed.
 *
 * @param {object} overrides Fields to override.
 * @return {object} A task row.
 */
function taskRow(overrides = {}) {
	return {
		id: 1,
		uuid: 'task-uuid-1',
		key: null,
		title: null,
		kind: null,
		state: 'available',
		isTerminal: false,
		lastAction: 'approve',
		outcome: null,
		performerType: 'user',
		assignee: null,
		candidateUsers: null,
		candidateGroups: ['permit-reviewers'],
		candidateRole: null,
		dueAt: null,
		expiresAt: null,
		priority: 'normal',
		objectUuid: 'obj-1',
		sequenceUuid: 'seq-1',
		sequencePosition: 0,
		subject: { uuid: 'obj-1', title: 'Permit 2026-001' },
		displayTitle: 'Approve: Permit 2026-001',
		overdue: false,
		daysUntilDue: null,
		daysOverdue: null,
		...overrides,
	}
}

const pooledPage = {
	results: [taskRow()],
	total: 1,
	limit: 50,
	offset: 0,
}
const assignedPage = {
	results: [
		taskRow({
			id: 2,
			uuid: 'task-uuid-2',
			state: 'active',
			assignee: 'admin',
			candidateGroups: ['finance-reviewers'],
			objectUuid: 'obj-2',
			subject: { uuid: 'obj-2', title: 'Invoice 7' },
			displayTitle: 'Approve: Invoice 7',
		}),
	],
	total: 1,
	limit: 50,
	offset: 0,
}

/**
 * Answer the widget's inbox reads per scope, the way OpenRegister does: the
 * server already narrows each scope to the caller's user and groups.
 *
 * @param {object} pages Map of scope to page.
 * @return {Function} An axios.get implementation.
 */
function inboxByScope(pages) {
	return (url, config) =>
		Promise.resolve({
			data: pages[config?.params?.scope] ?? { results: [], total: 0 },
		})
}

describe('MyApprovalsWidget', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		loadState.mockReset()
	})

	it('lists the open tasks OpenRegister returns for the viewer, by title', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockImplementation(
			inboxByScope({ pooled: pooledPage, assigned: assignedPage }),
		)

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		const rows = wrapper.findAll('[data-testid="my-approvals-row"]')
		expect(rows).toHaveLength(2)
		expect(wrapper.text()).toContain('Approve: Permit 2026-001')
		expect(wrapper.text()).toContain('Approve: Invoice 7')
		// The raw record uuid is not what a person reads.
		expect(wrapper.text()).not.toContain('obj-1')
	})

	it('asks the task API for open tasks with its own parameters, not status', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockImplementation(inboxByScope({ pooled: pooledPage }))

		mount(MyApprovalsWidget, { stubs })
		await flush()

		const scopes = axios.get.mock.calls.map(([url, config]) => {
			expect(url).toBe('/apps/openregister/api/flow-tasks')
			expect(config.params).not.toHaveProperty('status')
			expect(config.params.isTerminal).toBe('false')
			return config.params.scope
		})
		expect(scopes.sort()).toEqual(['assigned', 'pooled'])
	})

	it('shows a task once when it comes back in both scopes', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockImplementation(
			inboxByScope({ pooled: pooledPage, assigned: pooledPage }),
		)

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('[data-testid="my-approvals-row"]')).toHaveLength(1)
	})

	it('renders an empty state when no task is waiting for the viewer', async () => {
		loadState.mockReturnValue(['no-match-group'])
		axios.get.mockImplementation(inboxByScope({}))

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
		axios.get.mockImplementation(inboxByScope({ pooled: pooledPage }))
		axios.post.mockResolvedValue({ data: {} })

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="approve-button"]').trigger('click')
		await flush()

		// openregister #3302 replaced the approve/reject verbs with one
		// `complete` call carrying an outcome.
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flow-tasks/task-uuid-1/complete',
			{ outcome: 'approved' },
		)
	})

	it('reject completes the task with a rejecting outcome and a comment', async () => {
		loadState.mockReturnValue(['permit-reviewers'])
		axios.get.mockImplementation(inboxByScope({ pooled: pooledPage }))
		axios.post.mockResolvedValue({ data: {} })

		const wrapper = mount(MyApprovalsWidget, { stubs })
		await flush()
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="reject-button"]').trigger('click')
		await flush()

		// A rejecting outcome REFUSES an empty comment server-side
		// (TaskService::completeInternal), so one must always be sent.
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flow-tasks/task-uuid-1/complete',
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
