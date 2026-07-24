/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationActionListEditor.vue.
 *
 * Spec: automation-approval-steps task 3.1 (on-approve/on-reject nested
 * action-list editors reusing the existing typed-action components).
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AutomationActionListEditor from '../../src/components/AutomationActionListEditor.vue'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'inputLabel', 'label', 'clearable'],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label'],
	template: '<input class="nctextfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
}
const NcTextAreaStub = {
	name: 'NcTextArea',
	props: ['value', 'label'],
	template: '<textarea class="nctextarea-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
}
const NcButtonStub = {
	name: 'NcButton',
	props: ['type'],
	template: '<button @click="$emit(\'click\')"><slot /></button>',
}

const stubs = { NcSelect: NcSelectStub, NcTextField: NcTextFieldStub, NcTextArea: NcTextAreaStub, NcButton: NcButtonStub }

describe('AutomationActionListEditor', () => {
	it('renders an empty state with no actions', () => {
		const wrapper = mount(AutomationActionListEditor, {
			propsData: { modelValue: [], label: 'On approve' },
			stubs,
		})
		expect(wrapper.text()).toContain('No follow-up actions.')
		expect(wrapper.findAll('[data-testid="follow-up-action-row"]')).toHaveLength(0)
	})

	it('hydrates an existing send-notification follow-up action', () => {
		const wrapper = mount(AutomationActionListEditor, {
			propsData: {
				modelValue: [{ type: 'send-notification', subject: { en: 'Approved', nl: 'Goedgekeurd' } }],
				label: 'On approve',
			},
			stubs,
		})
		expect(wrapper.findAll('[data-testid="follow-up-action-row"]')).toHaveLength(1)
		const subjectField = wrapper.find('[data-label="Subject (English)"]')
		expect(subjectField.element.value).toBe('Approved')
	})

	it('adding an action emits update:modelValue with a new send-notification entry', async () => {
		const wrapper = mount(AutomationActionListEditor, {
			propsData: { modelValue: [], label: 'On approve' },
			stubs,
		})

		await wrapper.find('button').trigger('click')

		const emitted = wrapper.emitted('update:modelValue')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0]).toEqual([{ type: 'send-notification', subject: { en: '', nl: '' } }])
	})

	it('editing an object-op follow-up emits the typed action shape', async () => {
		const wrapper = mount(AutomationActionListEditor, {
			propsData: {
				modelValue: [{ type: 'object-op', operation: 'update', schema: 'permit', fieldMapping: {} }],
				label: 'On approve',
			},
			stubs,
		})

		const schemaField = wrapper.find('[data-label="Target schema"]')
		await schemaField.setValue('permit')
		await schemaField.trigger('input')

		const emitted = wrapper.emitted('update:modelValue')
		expect(emitted).toBeTruthy()
		const last = emitted[emitted.length - 1][0]
		expect(last[0]).toEqual({ type: 'object-op', operation: 'update', schema: 'permit', fieldMapping: {} })
	})
})
