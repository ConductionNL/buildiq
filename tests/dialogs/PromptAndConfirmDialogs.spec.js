/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two generic dialogs: PromptTextDialog submits a trimmed, non-blank value;
 * ConfirmActionDialog confirms or cancels and locks itself while busy.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ConfirmActionDialog from '../../src/dialogs/ConfirmActionDialog.vue'
import PromptTextDialog from '../../src/dialogs/PromptTextDialog.vue'

const stubs = {
	NcDialog: { props: ['noClose'], emits: ['closing'], template: '<div class="dialog" :data-no-close="String(noClose)"><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcTextField: { props: ['modelValue'], emits: ['update:modelValue', 'keydown'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keydown="$emit(\'keydown\', $event)" />' },
	NcLoadingIcon: { template: '<span class="loading" />' },
}

describe('PromptTextDialog', () => {
	const mountPrompt = (props = {}) => mount(PromptTextDialog, { props: { open: true, ...props }, global: { stubs } })

	it('renders nothing while closed', () => {
		expect(mountPrompt({ open: false }).find('.dialog').exists()).toBe(false)
	})

	it('starts from the initial value and submits it trimmed', async () => {
		const wrapper = mountPrompt({ initialValue: '  Draft  ' })
		expect(wrapper.vm.value).toBe('  Draft  ')

		await wrapper.findAll('button')[1].trigger('click')

		expect(wrapper.emitted('submit')).toEqual([['Draft']])
	})

	it('submits on enter', async () => {
		const wrapper = mountPrompt()
		await wrapper.find('input').setValue('Name')
		await wrapper.find('input').trigger('keydown.enter')
		expect(wrapper.emitted('submit')).toEqual([['Name']])
	})

	it('disables confirm and submits nothing for a blank value', async () => {
		const wrapper = mountPrompt({ initialValue: '   ' })
		expect(wrapper.findAll('button')[1].attributes('disabled')).toBeDefined()

		wrapper.vm.onSubmit()

		expect(wrapper.emitted('submit')).toBeUndefined()
	})

	it('restores the initial value when reopened', async () => {
		const wrapper = mountPrompt({ open: false, initialValue: 'Start' })
		await wrapper.setData({ value: 'typed' })

		await wrapper.setProps({ open: true })

		expect(wrapper.vm.value).toBe('Start')
	})

	it('uses the confirm label, else Confirm, and closes on cancel', async () => {
		expect(mountPrompt().findAll('button')[1].text()).toBe('Confirm')
		const wrapper = mountPrompt({ confirmLabel: 'Rename' })
		expect(wrapper.findAll('button')[1].text()).toBe('Rename')

		await wrapper.findAll('button')[0].trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('submit')).toBeUndefined()
	})
})

describe('ConfirmActionDialog', () => {
	const mountConfirm = (props = {}) => mount(ConfirmActionDialog, { props: { open: true, message: 'Delete it?', ...props }, global: { stubs } })

	it('renders nothing while closed', () => {
		expect(mountConfirm({ open: false }).find('.dialog').exists()).toBe(false)
	})

	it('shows the message and the detail only when given', () => {
		expect(mountConfirm().find('.confirm-action__detail').exists()).toBe(false)
		const wrapper = mountConfirm({ detail: 'It cannot be undone.' })
		expect(wrapper.find('.confirm-action__message').text()).toBe('Delete it?')
		expect(wrapper.find('.confirm-action__detail').text()).toBe('It cannot be undone.')
	})

	it('confirms and cancels', async () => {
		const wrapper = mountConfirm()
		const [cancel, confirm] = wrapper.findAll('button')
		await confirm.trigger('click')
		await cancel.trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('styles a destructive confirm as an error', () => {
		expect(mountConfirm().findAll('button')[1].attributes('data-variant')).toBe('primary')
		expect(mountConfirm({ destructive: true }).findAll('button')[1].attributes('data-variant')).toBe('error')
	})

	it('locks both buttons and closing, with a spinner, while busy', () => {
		const wrapper = mountConfirm({ busy: true, confirmLabel: 'Delete' })
		expect(wrapper.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
		expect(wrapper.find('.dialog').attributes('data-no-close')).toBe('true')
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.findAll('button')[1].text()).toBe('Delete')
	})
})
