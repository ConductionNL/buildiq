/**
 * The runs dialog reads the run log through buildiq, filters on outcome,
 * expands a run to its "Action | Outcome" table with the duration, says when
 * there is no run, and labels the steps OpenRegister runs instead of
 * inventing an outcome (logic-automation-run-log REQ-BQRL-003, REQ-BQRL-004).
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const axios = (await import('@nextcloud/axios')).default
const AutomationRunsModal = (await import('../../src/modals/AutomationRunsModal.vue')).default

const stubs = {
	NcModal: { name: 'NcModal', template: '<div class="modal"><slot /></div>' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel'], template: '<div class="select" />' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<div class="loading" />' },
}

const RUN = {
	id: 'run-1',
	startedAt: '2026-10-07T14:02:00Z',
	trigger: 'lifecycle-transition',
	subject: { schema: 'klacht', uuid: 'C-2026-0412' },
	outcome: 'failed',
	reason: 'Webhook answered 500',
	durationMs: 184,
	steps: [
		{ action: 'approval', outcome: 'succeeded', message: 'Approved' },
		{ action: 'generateDocument', outcome: 'failed', message: 'Webhook answered 500' },
	],
}

const approvalAutomation = {
	id: 'a1',
	name: 'Klacht ter goedkeuring',
	trigger: { type: 'lifecycle-transition', schema: 'klacht' },
	actions: [{ type: 'approval', onApprove: [{ type: 'generateDocument' }] }, { type: 'send-notification' }],
}

/**
 * Mount the dialog and let its first read settle.
 *
 * @param {object} automation The automation.
 * @return {Promise<object>} The wrapper.
 */
async function open(automation) {
	const wrapper = mount(AutomationRunsModal, { props: { automation }, global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('AutomationRunsModal', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('lists the runs and expands one into the action and outcome table with its duration', async () => {
		axios.get.mockResolvedValue({ data: { results: [RUN], total: 1 } })

		const wrapper = await open(approvalAutomation)

		expect(axios.get).toHaveBeenCalledWith('/apps/buildiq/api/automations/a1/runs', { params: {} })
		const row = wrapper.get('[data-testid="run-row"]')
		expect(row.text()).toContain('lifecycle-transition')
		expect(row.text()).toContain('failed')
		expect(row.get('a').attributes('href')).toBe('/apps/openregister/objects/C-2026-0412')

		await row.trigger('click')
		const steps = wrapper.findAll('[data-testid="run-step"]').map((step) => step.findAll('td').map((cell) => cell.text().replace(/\s+/g, ' ')))
		expect(steps).toEqual([
			['approval', 'succeeded: Approved'],
			['generateDocument', 'failed: Webhook answered 500'],
			['send-notification', 'Runs in OpenRegister. No per-run record yet.'],
		])
		expect(wrapper.get('[data-testid="run-detail"]').text()).toContain('Duration 184 ms')

		await row.trigger('click')
		expect(wrapper.find('[data-testid="run-detail"]').exists()).toBe(false)
	})

	it('asks for one outcome when the filter names one', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await open(approvalAutomation)

		wrapper.vm.outcomeFilter = { id: 'failed', label: 'failed' }
		await wrapper.vm.load()

		expect(axios.get).toHaveBeenLastCalledWith('/apps/buildiq/api/automations/a1/runs', { params: { outcome: 'failed' } })
	})

	it('says there was no run, with no empty table', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })

		const wrapper = await open(approvalAutomation)

		expect(wrapper.get('[data-testid="runs-empty"]').text()).toBe('No run in the last 90 days')
		expect(wrapper.find('[data-testid="runs-table"]').exists()).toBe(false)
	})

	it('says a notification-only automation runs entirely in OpenRegister and reads nothing', async () => {
		const wrapper = await open({ id: 'a2', trigger: { type: 'object-created', schema: 'ticket' }, actions: [{ type: 'send-notification' }] })

		expect(wrapper.get('[data-testid="runs-entirely-in-openregister"]').text()).toBe('This automation runs entirely in OpenRegister')
		expect(wrapper.find('[data-testid="run-row"]').exists()).toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('says the runs could not be loaded when the read is refused', async () => {
		axios.get.mockRejectedValue(new Error('403'))

		const wrapper = await open(approvalAutomation)

		expect(wrapper.get('.note').text()).toBe('Could not load the runs.')
		expect(wrapper.find('[data-testid="run-row"]').exists()).toBe(false)
	})
})
