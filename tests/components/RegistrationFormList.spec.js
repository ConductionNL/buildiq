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
	saveRegistrationForm: vi.fn(),
}))

import RegistrationFormList from '../../src/components/page-editor/fields/RegistrationFormList.vue'
import {
	fetchRegistrationForms,
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
