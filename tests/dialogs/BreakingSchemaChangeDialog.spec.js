/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BreakingSchemaChangeDialog lists the breaking changes of a schema save and
 * asks for confirmation. While the save runs both buttons are disabled.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BreakingSchemaChangeDialog from '../../src/dialogs/BreakingSchemaChangeDialog.vue'

const stubs = {
	NcDialog: { props: ['noClose'], emits: ['closing'], template: '<div class="dialog" :data-no-close="String(noClose)"><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: { template: '<span class="loading" />' },
}

function mountDialog(props = {}) {
	return mount(BreakingSchemaChangeDialog, { props: { open: true, ...props }, global: { stubs } })
}

describe('BreakingSchemaChangeDialog', () => {
	it('renders nothing while closed', () => {
		const wrapper = mountDialog({ open: false })
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('lists every change line', () => {
		const wrapper = mountDialog({ changes: ['Removed name', 'Type of age changed'] })
		const items = wrapper.findAll('.breaking-change__list li')
		expect(items.map((i) => i.text())).toEqual(['Removed name', 'Type of age changed'])
	})

	it('emits confirm and cancel from its buttons', async () => {
		const wrapper = mountDialog()
		const [cancel] = wrapper.findAll('button')

		await cancel.trigger('click')
		await wrapper.find('[data-test="breaking-change-confirm"]').trigger('click')

		expect(wrapper.emitted('cancel')).toEqual([[]])
		expect(wrapper.emitted('confirm')).toEqual([[]])
	})

	it('disables both buttons, blocks closing and shows a spinner while busy', () => {
		const wrapper = mountDialog({ busy: true })
		const buttons = wrapper.findAll('button')

		expect(buttons.every((b) => b.attributes('disabled') !== undefined)).toBe(true)
		expect(wrapper.find('.dialog').attributes('data-no-close')).toBe('true')
		expect(wrapper.find('.loading').exists()).toBe(true)
	})

	it('keeps the buttons enabled and shows no spinner when idle', () => {
		const wrapper = mountDialog()
		expect(wrapper.findAll('button').some((b) => b.attributes('disabled') !== undefined)).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})
})
