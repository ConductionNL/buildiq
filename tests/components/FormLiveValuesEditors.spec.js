/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the three editors of change forms-live-values-and-checks:
 * the default picker (REQ-BQLV-001), the calculated-field binding
 * (REQ-BQLV-002) and the eligibility check (REQ-BQLV-003), and their wiring
 * into FormFieldBuilder and FormPageEditor.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const listRuleSets = vi.fn()
const ruleSetColumns = vi.fn()
vi.mock('../../src/services/formLiveValues.js', () => ({
	listRuleSets: (...a) => listRuleSets(...a),
	ruleSetColumns: (...a) => ruleSetColumns(...a),
}))

const { default: FieldDefaultBuilder } =
	await import('../../src/components/page-editor/fields/FieldDefaultBuilder.vue')
const { default: FieldCalculationBuilder } =
	await import('../../src/components/page-editor/fields/FieldCalculationBuilder.vue')
const { default: EligibilityCheckBuilder } =
	await import('../../src/components/page-editor/fields/EligibilityCheckBuilder.vue')
const { default: FormFieldBuilder } =
	await import('../../src/components/page-editor/fields/FormFieldBuilder.vue')

/**
 * The last payload a wrapper emitted for an event.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} event The event name.
 * @return {*}
 */
function lastEmitted(wrapper, event = 'update:modelValue') {
	const calls = wrapper.emitted(event) || []
	return calls.length ? calls[calls.length - 1][0] : undefined
}

beforeEach(() => {
	listRuleSets.mockReset().mockResolvedValue([
		{ slug: 'event-fee', name: 'Event fee', status: 'active' },
		{ slug: 'loan-eligibility', name: 'Loan eligibility', status: 'active' },
		{ slug: 'old-fee', name: 'Old fee', status: 'archived' },
	])
	ruleSetColumns.mockReset().mockImplementation(async (slug) =>
		slug === 'loan-eligibility'
			? {
					inputs: [],
					outputs: [
						{ name: 'decision', type: 'string' },
						{ name: 'reason', type: 'string' },
					],
				}
			: {
					inputs: [
						{ name: 'Attendees', path: 'attendees', type: 'integer' },
					],
					outputs: [{ name: 'fee', type: 'number' }],
				},
	)
})

describe('FieldDefaultBuilder (REQ-BQLV-001)', () => {
	it('stores the e-mail token when the maker picks their e-mail', async () => {
		const wrapper = mount(FieldDefaultBuilder, { props: { modelValue: null } })
		await wrapper.find('select').setValue('@me.email')
		expect(lastEmitted(wrapper)).toBe('@me.email')
	})

	it('stores a record field as an @object token', async () => {
		const wrapper = mount(FieldDefaultBuilder, {
			props: { modelValue: '@object.' },
		})
		await wrapper.find('select').setValue('object')
		await wrapper.setProps({ modelValue: null })
		await wrapper.find('input').setValue('title')
		expect(lastEmitted(wrapper)).toBe('@object.title')
	})

	it('stores a fixed value as it is, and clears on Nothing', async () => {
		const wrapper = mount(FieldDefaultBuilder, {
			props: { modelValue: 'Amsterdam' },
		})
		expect(wrapper.find('input').element.value).toBe('Amsterdam')
		await wrapper.find('select').setValue('none')
		expect(lastEmitted(wrapper)).toBe(null)
	})
})

describe('FieldCalculationBuilder (REQ-BQLV-002)', () => {
	it('binds a field to a rule set output and the answers it reads', async () => {
		const wrapper = mount(FieldCalculationBuilder, {
			props: { modelValue: null, fieldOptions: ['attendees', 'remarks'] },
		})
		await flushPromises()

		await wrapper.find('select[data-test="calc-rule-set"]').setValue('event-fee')
		await flushPromises()
		expect(ruleSetColumns).toHaveBeenCalledWith('event-fee')
		expect(lastEmitted(wrapper)).toEqual({
			ruleSet: 'event-fee',
			output: 'fee',
			inputs: ['attendees'],
		})

		await wrapper.setProps({ modelValue: lastEmitted(wrapper) })
		await wrapper.find('input[type="checkbox"][value="remarks"]').setValue(true)
		expect(lastEmitted(wrapper)).toEqual({
			ruleSet: 'event-fee',
			output: 'fee',
			inputs: ['attendees', 'remarks'],
		})
	})

	it('marks a binding to a rule set that is no longer active', async () => {
		const wrapper = mount(FieldCalculationBuilder, {
			props: {
				modelValue: { ruleSet: 'old-fee', output: 'fee', inputs: [] },
				fieldOptions: [],
			},
		})
		await flushPromises()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
	})

	it('removes the binding', async () => {
		const wrapper = mount(FieldCalculationBuilder, {
			props: {
				modelValue: {
					ruleSet: 'event-fee',
					output: 'fee',
					inputs: ['attendees'],
				},
				fieldOptions: ['attendees'],
			},
		})
		await flushPromises()
		await wrapper.find('select[data-test="calc-rule-set"]').setValue('')
		expect(lastEmitted(wrapper)).toBe(null)
	})
})

describe('EligibilityCheckBuilder (REQ-BQLV-003)', () => {
	it('stores the rule set, the passing value, the explanation and the submit block', async () => {
		const wrapper = mount(EligibilityCheckBuilder, {
			props: { modelValue: null },
		})
		await flushPromises()

		await wrapper
			.find('select[data-test="elig-rule-set"]')
			.setValue('loan-eligibility')
		await flushPromises()
		await wrapper.setProps({ modelValue: lastEmitted(wrapper) })
		await wrapper
			.find('select[data-test="elig-pass-output"]')
			.setValue('decision')
		await wrapper.setProps({ modelValue: lastEmitted(wrapper) })
		await wrapper.find('input[data-test="elig-pass-value"]').setValue('approved')
		await wrapper.setProps({ modelValue: lastEmitted(wrapper) })
		await wrapper.find('select[data-test="elig-explain"]').setValue('reason')
		await wrapper.setProps({ modelValue: lastEmitted(wrapper) })
		await wrapper.find('input[data-test="elig-block"]').setValue(true)

		expect(lastEmitted(wrapper)).toEqual({
			ruleSet: 'loan-eligibility',
			passWhen: { output: 'decision', equals: 'approved' },
			explainWith: 'reason',
			blockSubmit: true,
		})
	})
})

describe('FormFieldBuilder wiring', () => {
	it('writes a default and a calculation on the field from its details area', async () => {
		const wrapper = mount(FormFieldBuilder, {
			props: {
				showLogic: true,
				modelValue: [
					{ key: 'attendees', label: 'Attendees', type: 'number' },
					{ key: 'fee', label: 'Fee', type: 'number' },
				],
			},
		})
		await wrapper.findAll('.form-field-builder__disclosure')[1].trigger('click')
		await flushPromises()

		wrapper
			.findComponent(FieldDefaultBuilder)
			.vm.$emit('update:modelValue', '@me.email')
		expect(lastEmitted(wrapper)[1]).toEqual({
			key: 'fee',
			label: 'Fee',
			type: 'number',
			default: '@me.email',
		})

		const calc = { ruleSet: 'event-fee', output: 'fee', inputs: ['attendees'] }
		wrapper
			.findComponent(FieldCalculationBuilder)
			.vm.$emit('update:modelValue', calc)
		expect(lastEmitted(wrapper)[1]).toEqual({
			key: 'fee',
			label: 'Fee',
			type: 'number',
			calculate: calc,
		})
		expect(
			wrapper.findComponent(FieldCalculationBuilder).props('fieldOptions'),
		).toEqual(['attendees'])
	})
})
