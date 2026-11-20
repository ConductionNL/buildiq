/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CopilotAssistDialog (ai-copilot-documents-and-code-help REQ-BQCH-002/003/004):
 * the request goes to the assist endpoint with the editor's names; Accept
 * emits the staged value and closes, Reject emits no value, explain mode
 * shows the description and offers no Accept, and a refusal shows its message.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const assist = vi.fn()
vi.mock('../../src/services/copilot.js', () => ({ assist: (...args) => assist(...args) }))

const { default: CopilotAssistDialog } = await import('../../src/dialogs/CopilotAssistDialog.vue')

const stubs = {
	NcDialog: { emits: ['closing'], template: '<div class="dialog"><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcTextArea: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}

function mountDialog (props = {}) {
  return mount(CopilotAssistDialog, {
	props: { open: true, target: 'feel-condition', appSlug: 'permits', ...props },
	global: { stubs },
})
}

describe('CopilotAssistDialog', () => {
	beforeEach(() => {
		assist.mockReset()
	})

	it('renders nothing while closed', () => {
		expect(mountDialog({ open: false }).find('.dialog').exists()).toBe(false)
	})

	it('sends the request with the editor names and shows the checked proposal', async () => {
		assist.mockResolvedValue({ mode: 'write', value: 'applicant.age < 18', explanation: 'Applicants younger than 18.' })
		const wrapper = mountDialog({ context: { paths: ['applicant.age'] } })

		await wrapper.find('textarea').setValue('  applicants younger than 18 ')
		await wrapper.find('[data-testid="copilot-assist-write"]').trigger('click')
		await flushPromises()

		expect(assist).toHaveBeenCalledWith({
			target: 'feel-condition',
			mode: 'write',
			request: 'applicants younger than 18',
			current: '',
			appSlug: 'permits',
			context: { paths: ['applicant.age'] },
		})
		expect(wrapper.find('[data-testid="copilot-assist-value"]').text()).toBe('applicant.age < 18')
		expect(wrapper.find('[data-testid="copilot-assist-explanation"]').text()).toBe('Applicants younger than 18.')
		expect(wrapper.find('[data-testid="copilot-assist-accept"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="copilot-assist-reject"]').exists()).toBe(true)
	})

	it('Accept writes the staged value and closes', async () => {
		assist.mockResolvedValue({ mode: 'write', value: { conditions: { age: '>= 18' }, decision: 'approve' }, explanation: 'Adults.' })
		const wrapper = mountDialog({ target: 'decision-rule' })
		await wrapper.find('textarea').setValue('approve adults')
		await wrapper.find('[data-testid="copilot-assist-write"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="copilot-assist-value"]').text()).toContain('"age": ">= 18"')
		await wrapper.find('[data-testid="copilot-assist-accept"]').trigger('click')

		expect(wrapper.emitted('accept')).toEqual([[{ conditions: { age: '>= 18' }, decision: 'approve' }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('Reject writes nothing and closes', async () => {
		assist.mockResolvedValue({ mode: 'write', value: 'applicant.age < 18', explanation: 'x' })
		const wrapper = mountDialog()
		await wrapper.find('textarea').setValue('under 18')
		await wrapper.find('[data-testid="copilot-assist-write"]').trigger('click')
		await flushPromises()

		await wrapper.find('[data-testid="copilot-assist-reject"]').trigger('click')

		expect(wrapper.emitted('accept')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('explains the current value and offers no Accept', async () => {
		assist.mockResolvedValue({ mode: 'explain', explanation: 'Approve applicants aged 18 or over.' })
		const current = { conditions: { age: '>= 18' }, decision: 'approve' }
		const wrapper = mountDialog({ target: 'decision-rule', current })

		await wrapper.find('[data-testid="copilot-assist-explain"]').trigger('click')
		await flushPromises()

		expect(assist).toHaveBeenCalledWith(expect.objectContaining({ mode: 'explain', request: '', current: JSON.stringify(current) }))
		expect(wrapper.find('[data-testid="copilot-assist-explanation"]').text()).toBe('Approve applicants aged 18 or over.')
		expect(wrapper.find('[data-testid="copilot-assist-accept"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="copilot-assist-value"]').exists()).toBe(false)
	})

	it('cannot send an empty request or explain an empty value', () => {
		const wrapper = mountDialog({ current: '  ' })
		expect(wrapper.find('[data-testid="copilot-assist-write"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="copilot-assist-explain"]').attributes('disabled')).toBeDefined()
	})

	it('shows the refusal and no proposal', async () => {
		assist.mockRejectedValue({ status: 422, error: 'assist_invalid', message: 'The target schema has no property ownerEmail.' })
		const wrapper = mountDialog({ target: 'field-mapping' })
		await wrapper.find('textarea').setValue('store the email')
		await wrapper.find('[data-testid="copilot-assist-write"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="copilot-assist-error"]').text()).toBe('The target schema has no property ownerEmail.')
		expect(wrapper.find('[data-testid="copilot-assist-answer"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="copilot-assist-accept"]').exists()).toBe(false)
	})

	it('starts empty again when reopened', async () => {
		assist.mockResolvedValue({ mode: 'write', value: 'x', explanation: 'y' })
		const wrapper = mountDialog()
		await wrapper.find('textarea').setValue('under 18')
		await wrapper.find('[data-testid="copilot-assist-write"]').trigger('click')
		await flushPromises()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.find('textarea').element.value).toBe('')
		expect(wrapper.find('[data-testid="copilot-assist-answer"]').exists()).toBe(false)
	})
})
