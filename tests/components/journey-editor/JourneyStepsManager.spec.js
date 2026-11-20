/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * JourneyStepsManager: a form step stores a reference and never fields,
 * reordering keeps every step's contents, a reference to a deleted form is
 * shown, and adding or removing a step is emitted as a new array.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import JourneyStepsManager from '../../../src/components/journey-editor/JourneyStepsManager.vue'

const forms = { 'f-1': { fields: [{ key: 'name' }] }, 'f-2': { fields: [{ key: 'email' }] } }
const formOptions = [{ id: 'f-1', label: 'Applicant' }, { id: 'f-2', label: 'Contact' }]
const steps = [
	{ id: 'a', type: 'form', title: 'Applicant', formId: 'f-1', branch: [{ when: { field: 'name', op: 'notEmpty' }, goto: 'c' }] },
	{ id: 'b', type: 'form', title: 'Contact', formId: 'f-2', writes: [{ as: 'org' }] },
	{ id: 'c', type: 'review', title: 'Check' },
]

function mountWith(props = {}) {
	return mount(JourneyStepsManager, { props: { modelValue: steps, forms, formOptions, ...props } })
}

function lastEmit(wrapper, name = 'update:modelValue') {
	const emits = wrapper.emitted(name)
	return emits[emits.length - 1][0]
}

describe('JourneyStepsManager', () => {
	it('renders one row per step with its title and the form it references', () => {
		const wrapper = mountWith()
		const rows = wrapper.findAll('.journey-steps__row')

		expect(rows).toHaveLength(3)
		expect(rows[0].find('.journey-steps__title').element.value).toBe('Applicant')
		expect(rows[0].find('select').element.value).toBe('f-1')
		expect(rows[2].find('select').exists()).toBe(false)
		expect(wrapper.find('[data-testid="journey-step-broken"]').exists()).toBe(false)
	})

	it('stores a picked form as a reference and drops pasted fields', async () => {
		const pasted = [{ id: 'a', type: 'form', title: 'A', formId: 'f-1', form: { fields: [{ key: 'x' }] }, fields: [{ key: 'y' }] }]
		const wrapper = mountWith({ modelValue: pasted })

		await wrapper.find('select').setValue('f-2')

		expect(lastEmit(wrapper)).toEqual([{ id: 'a', type: 'form', title: 'A', formId: 'f-2' }])
	})

	it('moves the second step first and leaves every step unchanged', async () => {
		const wrapper = mountWith()

		await wrapper.findAll('[data-testid="journey-step-up"]')[1].trigger('click')

		const moved = lastEmit(wrapper)
		expect(moved.map((s) => s.id)).toEqual(['b', 'a', 'c'])
		expect(moved[0]).toEqual(steps[1])
		expect(moved[1]).toEqual(steps[0])
		expect(moved[2]).toEqual(steps[2])
		expect(steps.map((s) => s.id)).toEqual(['a', 'b', 'c'])
	})

	it('disables moving the first step up and the last step down', () => {
		const wrapper = mountWith()

		expect(wrapper.findAll('[data-testid="journey-step-up"]')[0].attributes('disabled')).toBeDefined()
		expect(wrapper.findAll('[data-testid="journey-step-down"]')[2].attributes('disabled')).toBeDefined()
		expect(wrapper.findAll('[data-testid="journey-step-down"]')[0].attributes('disabled')).toBeUndefined()
	})

	it('shows a reference to a deleted form instead of dropping it', () => {
		const wrapper = mountWith({ forms: { 'f-1': forms['f-1'] } })
		const broken = wrapper.findAll('.journey-steps__row')[1]

		expect(broken.classes()).toContain('journey-steps__row--broken')
		expect(broken.find('[data-testid="journey-step-broken"]').text()).toContain('no longer exists')
		expect(broken.find('select').element.value).toBe('f-2')
		expect(broken.findAll('option').map((o) => o.element.value)).toEqual(['', 'f-2', 'f-1', 'f-2'])
	})

	it('adds a step of each type with a fresh id and selects it', async () => {
		const wrapper = mountWith()

		await wrapper.find('[data-testid="journey-add-confirmation"]').trigger('click')
		expect(lastEmit(wrapper)).toEqual([...steps, { id: 'step-4', type: 'confirmation', title: 'Confirmation' }])
		expect(lastEmit(wrapper, 'select')).toBe('step-4')

		await wrapper.find('[data-testid="journey-add-form"]').trigger('click')
		expect(lastEmit(wrapper)[3]).toEqual({ id: 'step-4', type: 'form', title: 'Form', formId: '' })
	})

	it('skips an id that is already taken when adding', async () => {
		const wrapper = mountWith({ modelValue: [{ id: 'step-2', type: 'review' }] })

		await wrapper.find('[data-testid="journey-add-review"]').trigger('click')
		expect(lastEmit(wrapper)[1].id).toBe('step-3')
	})

	it('removes a step and edits a title and a confirmation text', async () => {
		const wrapper = mountWith({ modelValue: [...steps, { id: 'd', type: 'confirmation', title: 'Done' }] })

		await wrapper.findAll('[data-testid="journey-step-remove"]')[0].trigger('click')
		expect(lastEmit(wrapper).map((s) => s.id)).toEqual(['b', 'c', 'd'])

		await wrapper.findAll('.journey-steps__title')[2].setValue('Overview')
		expect(lastEmit(wrapper)[2]).toEqual({ id: 'c', type: 'review', title: 'Overview' })

		await wrapper.find('.journey-steps__message').setValue('Thanks')
		expect(lastEmit(wrapper)[3]).toEqual({ id: 'd', type: 'confirmation', title: 'Done', message: 'Thanks' })
	})

	it('selects a step from its type button', async () => {
		const wrapper = mountWith({ selected: 'b' })

		expect(wrapper.findAll('.journey-steps__row')[1].classes()).toContain('journey-steps__row--selected')
		await wrapper.findAll('.journey-steps__select')[2].trigger('click')
		expect(lastEmit(wrapper, 'select')).toBe('c')
	})

	it('treats a non-array value as no steps', () => {
		const wrapper = mountWith({ modelValue: null })

		expect(wrapper.findAll('.journey-steps__row')).toHaveLength(0)
	})
})
