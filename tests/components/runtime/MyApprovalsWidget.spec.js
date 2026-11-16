/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My approvals with an approval form (logic-approval-task-form, design D4,
 * REQ-BQTF-003): the four form states of a row, the form dialog on Approve,
 * Reject without data, and a refused completion that keeps the dialog open.
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

import axios from '@nextcloud/axios'
import MyApprovalsWidget from '../../../src/components/runtime/MyApprovalsWidget.vue'
import ApprovalTaskFormDialog from '../../../src/dialogs/ApprovalTaskFormDialog.vue'

const flush = () => new Promise((r) => setTimeout(r, 0))

const CnTaskFormDialogStub = {
	name: 'CnTaskFormDialog',
	props: ['task', 'taskUuid', 'outcome', 'dialogTitle'],
	emits: ['completed', 'close'],
	template: '<div class="task-form-dialog" :data-outcome="outcome" />',
}

// The widget loads its form dialog lazily; the stub stands in for it.
const ApprovalTaskFormDialogStub = {
	name: 'ApprovalTaskFormDialog',
	props: ['task', 'dialogTitle'],
	emits: ['completed', 'close'],
	template: '<div class="approval-task-form-dialog" />',
}

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
	ApprovalTaskFormDialog: ApprovalTaskFormDialogStub,
}

/**
 * An inbox row; with a form it carries `metadata.form` as OpenRegister copies it.
 *
 * @param {number} id - the task id.
 * @param {boolean} withForm - whether the task carries a form.
 * @return {object}
 */
function row(id, withForm) {
	return {
		id,
		uuid: `task-${id}`,
		displayTitle: `Approve: Klacht ${id}`,
		candidateGroups: ['teamleiders'],
		...(withForm ? { metadata: { form: { kind: 'fields', fields: [{ field: 'bedrag', required: true }] } } } : {}),
	}
}

const FIELDS = [
	{ field: 'bedrag', required: true, order: 0, renderable: true, reason: null },
	{ field: 'motivering', required: false, order: 1, renderable: true, reason: null },
]

const DETAILS = {
	'task-1': { uuid: 'task-1', title: 'Klacht 1', form: { kind: 'fields', state: 'ready', fields: FIELDS } },
	'task-2': {
		uuid: 'task-2',
		form: { kind: 'fields', state: 'broken', fields: [{ ...FIELDS[0], renderable: false, reason: 'Field bedrag no longer exists' }] },
	},
	'task-3': { uuid: 'task-3', form: { kind: null, state: 'unresolvable', error: 'The schema of this form is gone' } },
}

/**
 * Mount the widget over rows 1-3 with a form, row 4 without, and row 5 whose task read fails.
 *
 * @return {Promise<object>} The wrapper.
 */
async function widget() {
	axios.get.mockImplementation((url, config) => {
		const uuid = url.split('/flow-tasks/')[1]
		if (uuid) {
			return DETAILS[uuid] ? Promise.resolve({ data: DETAILS[uuid] }) : Promise.reject(new Error('404'))
		}
		return Promise.resolve({
			data: config?.params?.scope === 'pooled'
				? { results: [row(1, true), row(2, true), row(3, true), row(4, false), row(5, true)] }
				: { results: [] },
		})
	})
	const wrapper = mount(MyApprovalsWidget, { global: { stubs } })
	await flush()
	await flush()
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * The rendered row for a task id.
 *
 * @param {object} wrapper - the widget.
 * @param {number} id - the task id.
 * @return {object}
 */
function rowOf(wrapper, id) {
	return wrapper.findAll('[data-testid="my-approvals-row"]').find((r) => r.text().includes(`Klacht ${id}`))
}

/**
 * Let the lazily loaded form dialog resolve and render.
 *
 * @param {object} wrapper - the widget.
 * @return {Promise<void>}
 */
async function loaded(wrapper) {
	for (let i = 0; i < 5; i++) {
		await flush()
		await wrapper.vm.$nextTick()
	}
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('My approvals with an approval form', () => {
	it('reads the task of every row with a form, and only those', async () => {
		await widget()

		const reads = axios.get.mock.calls.map(([url]) => url).filter((url) => url.includes('/flow-tasks/'))
		expect(reads.sort()).toEqual([
			'/index.php/apps/openregister/api/flow-tasks/task-1',
			'/index.php/apps/openregister/api/flow-tasks/task-2',
			'/index.php/apps/openregister/api/flow-tasks/task-3',
			'/index.php/apps/openregister/api/flow-tasks/task-5',
		])
	})

	it('shows each form state on its row, and offers Approve only without a form or with a ready one', async () => {
		const wrapper = await widget()
		const line = (id) => rowOf(wrapper, id).find('[data-testid="my-approvals-form"]')
		const approve = (id) => rowOf(wrapper, id).find('[data-testid="approve-button"]')

		expect(line(1).text()).toBe('{count} fields to fill in')
		expect(approve(1).attributes('disabled')).toBeUndefined()
		expect(line(2).text()).toBe('Field bedrag no longer exists')
		expect(approve(2).attributes('disabled')).toBeDefined()
		expect(line(3).text()).toBe('The schema of this form is gone')
		expect(approve(3).attributes('disabled')).toBeDefined()
		expect(line(4).exists()).toBe(false)
		expect(approve(4).attributes('disabled')).toBeUndefined()
		expect(line(5).text()).toBe('The form of this approval could not be loaded.')
		expect(approve(5).attributes('disabled')).toBeDefined()
	})

	it('opens the task form on Approve for a ready form, without completing the task itself', async () => {
		const wrapper = await widget()

		await rowOf(wrapper, 1).find('[data-testid="approve-button"]').trigger('click')
		await loaded(wrapper)

		const dialog = wrapper.findComponent(ApprovalTaskFormDialogStub)
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('task')).toEqual(DETAILS['task-1'])
		expect(dialog.props('dialogTitle')).toBe('Klacht 1')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('keeps the dialog open and the inbox as it was until the form completes, then reloads', async () => {
		const wrapper = await widget()
		await rowOf(wrapper, 1).find('[data-testid="approve-button"]').trigger('click')
		await loaded(wrapper)
		const readsBefore = axios.get.mock.calls.length

		// A 400 naming fields is handled inside the task form: it emits nothing.
		await flush()
		expect(wrapper.findComponent(ApprovalTaskFormDialogStub).exists()).toBe(true)
		expect(axios.get.mock.calls.length).toBe(readsBefore)

		wrapper.findComponent(ApprovalTaskFormDialogStub).vm.$emit('completed', { uuid: 'task-1' })
		await flush()
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent(ApprovalTaskFormDialogStub).exists()).toBe(false)
		expect(axios.get.mock.calls.length).toBeGreaterThan(readsBefore)
	})

	it('closes the dialog without completing on close', async () => {
		const wrapper = await widget()
		await rowOf(wrapper, 1).find('[data-testid="approve-button"]').trigger('click')
		await loaded(wrapper)

		wrapper.findComponent(ApprovalTaskFormDialogStub).vm.$emit('close')
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent(ApprovalTaskFormDialogStub).exists()).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('approves a row without a form directly, and rejects a row with a form without data', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await widget()

		await rowOf(wrapper, 4).find('[data-testid="approve-button"]').trigger('click')
		await flush()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/flow-tasks/task-4/complete', { outcome: 'approved' })

		await rowOf(wrapper, 1).find('[data-testid="reject-button"]').trigger('click')
		await flush()
		const [, body] = axios.post.mock.calls.find(([url]) => url.endsWith('/task-1/complete'))
		expect(body).toEqual({ outcome: 'rejected', comment: 'Rejected from the My approvals widget.' })
		expect(wrapper.findComponent(ApprovalTaskFormDialogStub).exists()).toBe(false)
	})
})

describe('ApprovalTaskFormDialog', () => {
	it('completes the task it is given with outcome approved, and passes completion and close on', async () => {
		const task = DETAILS['task-1']
		const wrapper = mount(ApprovalTaskFormDialog, {
			props: { task, dialogTitle: 'Klacht 1' },
			global: { stubs: { CnTaskFormDialog: CnTaskFormDialogStub } },
		})
		const inner = wrapper.findComponent(CnTaskFormDialogStub)

		expect(inner.props()).toEqual({ task, taskUuid: 'task-1', outcome: 'approved', dialogTitle: 'Klacht 1' })
		inner.vm.$emit('completed', { uuid: 'task-1' })
		inner.vm.$emit('close')
		expect(wrapper.emitted('completed')).toEqual([[{ uuid: 'task-1' }]])
		expect(wrapper.emitted('close')).toEqual([[]])
	})
})
