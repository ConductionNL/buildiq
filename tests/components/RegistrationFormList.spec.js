import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RegistrationFormList.vue.
 *
 * Spec: registration-form-builder (REQ-OBRF-004).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/registrationForms.js', () => ({
	fetchRegistrationForms: vi.fn(),
	fetchTargetSchema: vi.fn(),
	saveRegistrationForm: vi.fn(),
}))

import RegistrationFormList from '../../src/components/page-editor/fields/RegistrationFormList.vue'
import {
	fetchRegistrationForms,
	fetchTargetSchema,
	saveRegistrationForm,
} from '../../src/services/registrationForms.js'

const flush = () => new Promise((r) => setTimeout(r, 0))

const SCOPE = {
	register: 'dossiq',
	schema: 'Zaak',
	typeProperty: 'caseType',
	typeValue: 'bouwvergunning',
	targetApp: 'dossiq',
}

/**
 * Mount the list over a set of forms.
 *
 * @param {Array<object>} forms - what the endpoint answers.
 * @param {object} props - props to override.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountList(forms, props = {}) {
	fetchRegistrationForms.mockResolvedValue(forms)
	const wrapper = mount(RegistrationFormList, {
		props: { ...SCOPE, ...props },
	})
	await flush()
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('RegistrationFormList', () => {
	beforeEach(() => {
		fetchRegistrationForms.mockReset()
		fetchTargetSchema.mockReset()
		fetchTargetSchema.mockResolvedValue({
			properties: ['naam', 'intakeChannel'],
			channels: ['portal', 'desk'],
			note: null,
		})
		saveRegistrationForm.mockReset()
		saveRegistrationForm.mockResolvedValue({
			form: { id: 'rf-1' },
			warnings: [],
		})
	})

	it('asks for a case type before listing anything', () => {
		const wrapper = mount(RegistrationFormList, {
			props: { ...SCOPE, typeValue: '' },
		})

		expect(wrapper.text()).toContain('A form belongs to one type')
		expect(fetchRegistrationForms).not.toHaveBeenCalled()
	})

	it('lists only the forms for this type', async () => {
		// The endpoint answers a whole schema. Rendering it unfiltered would put
		// another type's forms under this type's heading, and both would look
		// correct.
		const wrapper = await mountList([
			{
				id: 'rf-1',
				name: 'Aanvraag bouwvergunning',
				audience: 'client',
				isDefault: true,
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
			{
				id: 'rf-2',
				name: 'Melding overlast',
				audience: 'client',
				typeProperty: 'caseType',
				typeValue: 'melding',
			},
		])

		expect(wrapper.text()).toContain('Aanvraag bouwvergunning')
		expect(wrapper.text()).not.toContain('Melding overlast')
	})

	it('marks which form the intake picks', async () => {
		const wrapper = await mountList([
			{
				id: 'rf-1',
				name: 'Aanvraag',
				audience: 'client',
				isDefault: true,
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
		])

		expect(wrapper.find('.form-list__default').exists()).toBe(true)
	})

	it('adds a form bound to this type and nobody else', async () => {
		const wrapper = await mountList([])

		await wrapper.find('input[type="text"]').setValue('Snelle aanvraag')
		await wrapper.find('button').trigger('click')
		await flush()

		const sent = saveRegistrationForm.mock.calls[0][0]
		expect(sent.name).toBe('Snelle aanvraag')
		expect(sent.typeProperty).toBe('caseType')
		expect(sent.typeValue).toBe('bouwvergunning')
		expect(sent.audience).toBe('client')
		expect(sent.status).toBe('draft')
	})

	it('opens the builder on a form, so the fields can actually be authored', async () => {
		// The panel could add a form and nothing else: every field, section and
		// preset had to be written into the store by hand. This asserts the
		// builder from its caller, because a builder with no call site looks
		// exactly like a builder that works.
		const wrapper = await mountList([
			{
				id: 'rf-1',
				name: 'Aanvraag',
				audience: 'client',
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
		])

		expect(
			wrapper.findComponent({ name: 'RegistrationFormEditor' }).exists(),
		).toBe(false)

		await wrapper.find('.form-list__item button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		const editor = wrapper.findComponent({ name: 'RegistrationFormEditor' })
		expect(editor.exists()).toBe(true)
		expect(editor.findComponent({ name: 'FormLayoutBuilder' }).exists()).toBe(
			true,
		)
		expect(editor.findComponent({ name: 'FormPresetsBuilder' }).exists()).toBe(
			true,
		)
	})

	it('hands the builder the target schema, so its pickers are real', async () => {
		const wrapper = await mountList([
			{
				id: 'rf-1',
				name: 'Aanvraag',
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
		])

		await wrapper.find('.form-list__item button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		const editor = wrapper.findComponent({ name: 'RegistrationFormEditor' })
		expect(editor.props('properties')).toEqual(['naam', 'intakeChannel'])
		expect(editor.props('channels')).toEqual(['portal', 'desk'])
	})

	it('saves the sections, the fields and the presets the builder wrote', async () => {
		const wrapper = await mountList([
			{
				id: 'rf-1',
				name: 'Aanvraag',
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
		])

		await wrapper.find('.form-list__item button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		const editor = wrapper.findComponent({ name: 'RegistrationFormEditor' })
		editor.vm.$emit('update:modelValue', {
			...editor.props('modelValue'),
			channel: 'portal',
			sections: [{ name: 'uw-gegevens', label: 'Uw gegevens', order: 1 }],
			fields: [{ name: 'naam', section: 'uw-gegevens', order: 1 }],
			presets: [{ field: 'intakeChannel', value: 'portal', hidden: true }],
		})
		await wrapper.vm.$nextTick()

		editor.vm.$emit('save')
		await flush()

		const sent = saveRegistrationForm.mock.calls[0][0]
		expect(sent.id).toBe('rf-1')
		expect(sent.channel).toBe('portal')
		expect(sent.sections[0].name).toBe('uw-gegevens')
		expect(sent.fields[0]).toEqual({
			name: 'naam',
			section: 'uw-gegevens',
			order: 1,
		})
		expect(sent.presets[0].hidden).toBe(true)
	})

	it('repeats the warning the save returned rather than swallowing it', async () => {
		saveRegistrationForm.mockResolvedValue({
			form: { id: 'rf-1' },
			warnings: [
				'The preset "verzonnen" names a property the target schema does not have.',
			],
		})

		const wrapper = await mountList([
			{
				id: 'rf-1',
				name: 'Aanvraag',
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
		])

		await wrapper.find('.form-list__item button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'RegistrationFormEditor' }).vm.$emit('save')
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('does not have')
	})

	it('shows the sentence a refusal wrote', async () => {
		saveRegistrationForm.mockRejectedValue({
			status: 422,
			error: 'refused',
			message: '"Aanvraag" already stands on this type.',
		})

		const wrapper = await mountList([])
		await wrapper.find('input[type="text"]').setValue('Aanvraag')
		await wrapper.find('button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('already stands on this type')
	})
})
