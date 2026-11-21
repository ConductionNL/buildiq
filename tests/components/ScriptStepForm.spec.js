/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ScriptStepForm.vue (logic-script-step D1, D5, REQ-BQSS-001).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ScriptStepForm from '../../src/components/ScriptStepForm.vue'

const stubs = {
	CnJsonViewer: {
		name: 'CnJsonViewer',
		props: ['value', 'language', 'readOnly', 'height'],
		emits: ['update:value'],
		template: '<textarea class="cnjsonviewer-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'type'],
		emits: ['update:modelValue'],
		template: '<input class="nctextfield-stub" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

/**
 * Mount the form over one editor action.
 *
 * @param {object} modelValue The action.
 * @return {object} The wrapper.
 */
function mountForm(modelValue) {
	return mount(ScriptStepForm, { props: { modelValue }, global: { stubs } })
}

describe('ScriptStepForm', () => {
	it('edits the code as plain text and shows the time limit and note', () => {
		const wrapper = mountForm({ code: 'return items', timeoutSeconds: 10, note: 'Keeps the items' })

		const viewer = wrapper.findComponent({ name: 'CnJsonViewer' })
		expect(viewer.props('value')).toBe('return items')
		expect(viewer.props('language')).toBe('text')
		expect(viewer.props('readOnly')).toBeUndefined()
		expect(wrapper.find('[data-testid="script-step-timeout"]').element.value).toBe('10')
		expect(wrapper.find('[data-testid="script-step-note"]').element.value).toBe('Keeps the items')
		expect(wrapper.text()).toContain('code runner')
	})

	it('hands every change to the composer, the time limit as a number', async () => {
		const wrapper = mountForm({ code: '', timeoutSeconds: 10, note: '' })

		await wrapper.find('.cnjsonviewer-stub').setValue('return items.slice(0, 1)')
		await wrapper.find('[data-testid="script-step-timeout"]').setValue('5')
		await wrapper.find('[data-testid="script-step-timeout"]').setValue('')
		await wrapper.find('[data-testid="script-step-note"]').setValue('First item only')

		expect(wrapper.emitted('update')).toEqual([
			[{ key: 'code', value: 'return items.slice(0, 1)' }],
			[{ key: 'timeoutSeconds', value: 5 }],
			[{ key: 'timeoutSeconds', value: '' }],
			[{ key: 'note', value: 'First item only' }],
		])
	})
})
