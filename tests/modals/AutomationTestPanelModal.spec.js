/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationTestPanelModal — an automation dry-run.
 *
 * Covers:
 *  - the sample payload is parsed and posted to the automation's dry-run route.
 *  - an empty payload posts {}.
 *  - invalid JSON is refused without a request.
 *  - a failed run says so; a new run clears the previous result and error.
 *  - the approval state label.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AutomationTestPanelModal from '../../src/modals/AutomationTestPanelModal.vue'

function mountModal() {
	return shallowMount(AutomationTestPanelModal, { props: { automation: { id: 'auto-7' } } })
}

beforeEach(() => {
	axios.post.mockReset()
})

describe('AutomationTestPanelModal', () => {
	it('posts the parsed payload to the dry-run route', async () => {
		axios.post.mockResolvedValue({ data: { approvalState: 'approved', steps: [] } })
		const wrapper = mountModal()
		wrapper.vm.payloadText = '{"case":"C-1"}'
		await wrapper.vm.run()

		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/automations/auto-7/dry-run', { payload: { case: 'C-1' } })
		expect(wrapper.vm.result).toEqual({ approvalState: 'approved', steps: [] })
		expect(wrapper.vm.running).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('an empty payload is sent as an empty object', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountModal()
		wrapper.vm.payloadText = ''
		await wrapper.vm.run()
		expect(axios.post.mock.calls[0][1]).toEqual({ payload: {} })
	})

	it('refuses invalid JSON without calling the server', async () => {
		const wrapper = mountModal()
		wrapper.vm.payloadText = '{case'
		await wrapper.vm.run()
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.errorMessage).toBe('The sample payload is not valid JSON.')
		expect(wrapper.vm.running).toBe(false)
		expect(wrapper.vm.result).toBeNull()
	})

	it('reports a failed run', async () => {
		axios.post.mockRejectedValue(new Error('500'))
		const wrapper = mountModal()
		await wrapper.vm.run()
		expect(wrapper.vm.errorMessage).toBe('Dry-run failed.')
		expect(wrapper.vm.result).toBeNull()
		expect(wrapper.vm.running).toBe(false)
	})

	it('a new run clears the previous result and error', async () => {
		axios.post.mockResolvedValueOnce({ data: { approvalState: 'pending' } })
		const wrapper = mountModal()
		await wrapper.vm.run()
		axios.post.mockReturnValueOnce(new Promise(() => {}))
		wrapper.vm.errorMessage = 'old'
		wrapper.vm.run()
		await flushPromises()
		expect(wrapper.vm.result).toBeNull()
		expect(wrapper.vm.errorMessage).toBe('')
		expect(wrapper.vm.running).toBe(true)
	})

	it('labels the approval state, and nothing for an unknown one', () => {
		const wrapper = mountModal()
		expect(wrapper.vm.approvalStateLabel).toBe('')
		for (const [state, label] of [['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['other', '']]) {
			wrapper.vm.result = { approvalState: state }
			expect(wrapper.vm.approvalStateLabel).toBe(label)
		}
	})

	describe('logic-approval-task-form: the fields an approval step asks for', () => {
		const automation = {
			id: 'auto-7',
			actions: [
				{ type: 'send-notification' },
				{ type: 'approval', assigneeGroup: 'a', form: { fields: [{ field: 'bedrag', required: true }, { field: 'motivering' }] } },
				{ type: 'approval', assigneeGroup: 'b' },
				{ type: 'approval', assigneeGroup: 'c', form: { fields: [{ field: 'besluit' }] } },
			],
		}

		it('names each approval step\'s fields, in their order, with a plural for the count', () => {
			const spy = vi.spyOn(globalThis, 'n')
			const wrapper = shallowMount(AutomationTestPanelModal, { props: { automation } })

			expect(wrapper.vm.approvalFormLines).toEqual([
				'asks for {count} fields: {fields}',
				'asks for {count} field: {fields}',
			])
			expect(spy).toHaveBeenCalledWith('buildiq', 'asks for {count} field: {fields}', 'asks for {count} fields: {fields}', 2, { count: 2, fields: 'bedrag, motivering' })
			expect(spy).toHaveBeenCalledWith('buildiq', 'asks for {count} field: {fields}', 'asks for {count} fields: {fields}', 1, { count: 1, fields: 'besluit' })
			spy.mockRestore()
		})

		it('shows the lines with a matched dry run, and none without a form', async () => {
			axios.post.mockResolvedValue({ data: { conditionMatched: true, actions: ['approval'] } })
			const wrapper = shallowMount(AutomationTestPanelModal, {
				props: { automation },
				global: { stubs: { NcModal: { template: '<div><slot /></div>' } } },
			})
			await wrapper.vm.run()
			await flushPromises()
			const lines = wrapper.findAll('[data-testid="dry-run-approval-form"]')
			expect(lines).toHaveLength(2)
			expect(lines[0].isVisible()).toBe(true)

			const plain = shallowMount(AutomationTestPanelModal, { props: { automation: { id: 'x', actions: [{ type: 'approval' }] } } })
			expect(plain.vm.approvalFormLines).toEqual([])
		})
	})
})
