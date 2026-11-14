import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FlowPickerDialog lists the flows of an app and emits the picked flow id, or
 * 'new'. A failed load must say so instead of claiming there are no flows.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const FlowPickerDialog = (await import('../../src/dialogs/FlowPickerDialog.vue')).default

const stubs = {
	NcDialog: { emits: ['closing'], template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	Plus: true,
}

async function mountDialog(props = {}) {
	const wrapper = mount(FlowPickerDialog, { props, global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('FlowPickerDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('asks for the flows of the given app only', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		await mountDialog({ app: 'petstore' })
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/flows', { params: { app: 'petstore' } })
	})

	it('asks for all flows without an app', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		await mountDialog()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/flows', { params: {} })
	})

	it('shows a loading icon until the flows arrive', () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(FlowPickerDialog, { global: { stubs } })
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.vm.loading).toBe(true)
	})

	it('lists the flows and emits the picked id as a string', async () => {
		axios.get.mockResolvedValue({
			data: { results: [{ id: 7, name: 'Intake', description: 'New cases' }, { id: 8, name: 'Archive' }] },
		})
		const wrapper = await mountDialog()

		const rows = wrapper.findAll('.flow-picker__row')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Intake')
		expect(rows[0].text()).toContain('New cases')
		expect(rows[1].find('.flow-picker__hint').exists()).toBe(false)

		await rows[1].trigger('click')
		expect(wrapper.emitted('pick')).toEqual([['8']])
	})

	it('says there are no flows when the list is empty or missing', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountDialog()
		expect(wrapper.vm.flows).toEqual([])
		expect(wrapper.text()).toContain('This application has no flows yet.')
	})

	it('reports a failed load instead of an empty list', async () => {
		const failure = new Error('boom')
		axios.get.mockRejectedValue(failure)
		const wrapper = await mountDialog()

		expect(wrapper.vm.error).toBe(failure)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.note').text()).toContain('The flows could not be loaded.')
		expect(wrapper.text()).not.toContain('This application has no flows yet.')
	})

	it('emits close on cancel and new for the create button', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await mountDialog()
		const buttons = wrapper.findAll('.nc-button')

		await buttons[0].trigger('click')
		await buttons[1].trigger('click')

		expect(wrapper.emitted('close')).toEqual([[]])
		expect(wrapper.emitted('pick')).toEqual([['new']])
	})
})
