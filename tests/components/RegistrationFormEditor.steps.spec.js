/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the registration-form-builder half of
 * RegistrationFormEditor.vue: the applies-to panel, steps with a phase label,
 * save for later and a visibleWhen rule per question (REQ-OBRF-002).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RegistrationFormEditor from '../../src/components/page-editor/fields/RegistrationFormEditor.vue'

const stubs = {
	FormLayoutBuilder: {
		name: 'FormLayoutBuilder',
		props: ['sections', 'fields', 'properties', 'findings'],
		emits: ['update:sections', 'update:fields'],
		template: '<div class="layout" />',
	},
	FormPresetsBuilder: {
		name: 'FormPresetsBuilder',
		props: ['presets', 'properties', 'findings'],
		emits: ['update:presets'],
		template: '<div class="presets" />',
	},
}

const BOUND = {
	name: 'Bouwvergunning',
	targetApp: 'dossiq',
	register: 'dossiq',
	schema: 'case',
	typeProperty: 'caseType',
	typeValue: 'bouwvergunning',
	fields: [
		{ name: 'initiatorType', label: 'Wie vraagt aan', section: 'a' },
		{ name: 'companyName', label: 'Bedrijfsnaam', section: 'a' },
		{ name: 'buildingType', section: 'b' },
	],
}

/**
 * Mount the editor.
 *
 * @param {object} modelValue The form.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function factory(modelValue = BOUND) {
	return mount(RegistrationFormEditor, {
		props: { modelValue },
		global: { stubs },
	})
}

/**
 * The form emitted last.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The editor.
 * @return {object}
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('RegistrationFormEditor steps, phases and rules', () => {
	it('says what the form is bound to', () => {
		const panel = factory().find('[data-testid="registration-form-applies-to"]')
		expect(panel.text()).toContain('dossiq / dossiq / case')
		expect(panel.find('.form-editor__applies-hint').text()).toBe(
			'Only objects whose {property} is {value} get this form.',
		)
	})

	it('says an unbound form covers every object', () => {
		const panel = factory({ name: 'x' }).find('[data-testid="registration-form-applies-to"]')
		expect(panel.text()).toContain('Not bound to a schema yet')
		expect(panel.text()).toContain('every object in the schema')
	})

	it('offers the form fields to the steps by name', () => {
		const steps = factory().findComponent({ name: 'FormStepsManager' })
		expect(steps.props('fields')).toEqual([
			{ key: 'initiatorType' },
			{ key: 'companyName' },
			{ key: 'buildingType' },
		])
		expect(steps.props('withPhase')).toBe(true)
		expect(steps.props('leavesUnassigned')).toBe(true)
	})

	it('writes two steps with their phase labels and keeps the rest of the form', async () => {
		const wrapper = factory()
		const twoSteps = [
			{ id: 'aanvrager', title: 'Aanvrager', phase: 'Aanvrager', fields: ['initiatorType', 'companyName'] },
			{ id: 'bouwwerk', title: 'Bouwwerk', phase: 'Bouwwerk', fields: ['buildingType'] },
		]
		wrapper.findComponent({ name: 'FormStepsManager' }).vm.$emit('update:steps', twoSteps)
		await wrapper.vm.$nextTick()
		const form = lastEmitted(wrapper)
		expect(form.steps).toEqual(twoSteps)
		expect(form.typeValue).toBe('bouwvergunning')
		expect(form.fields).toEqual(BOUND.fields)
	})

	it('drops the steps key when the last step goes', async () => {
		const wrapper = factory({ ...BOUND, steps: [{ id: 'a', fields: [] }] })
		wrapper.findComponent({ name: 'FormStepsManager' }).vm.$emit('update:steps', null)
		await wrapper.vm.$nextTick()
		expect('steps' in lastEmitted(wrapper)).toBe(false)
	})

	it('writes save for later', async () => {
		const wrapper = factory()
		await wrapper.find('[data-testid="registration-form-save-for-later"]').setValue(true)
		expect(lastEmitted(wrapper).allowSaveForLater).toBe(true)
	})

	it('offers a rule per named field, looking only at the other fields', () => {
		const builders = factory().findAllComponents({ name: 'VisibleWhenBuilder' })
		expect(builders).toHaveLength(3)
		expect(builders[1].props('fieldOptions')).toEqual(['initiatorType', 'buildingType'])
		expect(builders[1].props('modelValue')).toBeNull()
	})

	it('writes a rule onto its field and removes it again', async () => {
		const wrapper = factory()
		const rule = { field: 'initiatorType', value: 'company' }
		wrapper.findAllComponents({ name: 'VisibleWhenBuilder' })[1].vm.$emit('update:modelValue', rule)
		await wrapper.vm.$nextTick()
		const withRule = lastEmitted(wrapper)
		expect(withRule.fields[1]).toEqual({ name: 'companyName', label: 'Bedrijfsnaam', section: 'a', visibleWhen: rule })
		expect(withRule.fields[0]).toEqual(BOUND.fields[0])

		await wrapper.setProps({ modelValue: withRule })
		wrapper.findAllComponents({ name: 'VisibleWhenBuilder' })[1].vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		expect(lastEmitted(wrapper).fields[1]).toEqual(BOUND.fields[1])
	})

	it('shows no rules block for a form without named fields', () => {
		expect(factory({ name: 'x', fields: [] }).find('[data-testid="registration-form-rules"]').exists()).toBe(false)
	})
})
