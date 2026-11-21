/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the "Fill with demo records" switch on the wizard's review step.
 *
 * Spec: capability-requirements-backfill (REQ-BQCV-030).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Step4Review from '../../../src/dialogs/CreateApplicationWizard/Step4Review.vue'

const SwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['modelValue', 'type'],
	emits: ['update:modelValue'],
	template: '<label class="switch-stub" :data-checked="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>',
}

/**
 * Mount the review step with a payload.
 *
 * @param {object} payload What differs from a minimal payload.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountStep(payload = {}) {
	return mount(Step4Review, {
		propsData: { payload: { name: 'Meldingen', slug: 'meldingen', versions: [], ...payload } },
		stubs: { NcCheckboxRadioSwitch: SwitchStub },
	})
}

describe('Step4Review — demo records', () => {
	it('shows the switch off until the maker asks', () => {
		const wrapper = mountStep()
		const toggle = wrapper.find('[data-testid="wizard-review-demo-records"] .switch-stub')

		expect(toggle.text()).toBe('Fill with demo records')
		expect(toggle.attributes('data-checked')).toBe('false')
	})

	it('reports the choice to the wizard', async () => {
		const wrapper = mountStep()
		await wrapper.find('.switch-stub').trigger('click')

		expect(wrapper.emitted('update:payload')).toEqual([[{ demoRecords: true }]])
	})

	it('shows a choice already made', () => {
		expect(mountStep({ demoRecords: true }).find('.switch-stub').attributes('data-checked')).toBe('true')
	})
})
