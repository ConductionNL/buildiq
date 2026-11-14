/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the sign-in level in RegistrationFormEditor.vue
 * (buildiq#935): a maker can require DigiD or eHerkenning per form.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RegistrationFormEditor from '../../src/components/page-editor/fields/RegistrationFormEditor.vue'

const stubs = { FormLayoutBuilder: true, FormPresetsBuilder: true }

function factory(modelValue = {}) {
	return mount(RegistrationFormEditor, {
		props: { modelValue: { name: 'intake', ...modelValue } },
		global: { stubs },
	})
}

describe('RegistrationFormEditor sign-in level', () => {
	it('offers no sign-in and the three portaliq levels', () => {
		const select = factory().find(
			'[data-testid="registration-form-sign-in-level"]',
		)
		expect(select.exists()).toBe(true)
		expect(select.findAll('option').map((o) => o.element.value)).toEqual([
			'',
			'low',
			'substantial',
			'high',
		])
	})

	it('writes the chosen level, and a form that asks for a sign-in stops being public', async () => {
		const wrapper = factory({ isPublic: true, audience: 'client' })
		await wrapper
			.find('[data-testid="registration-form-sign-in-level"]')
			.setValue('substantial')
		const written = wrapper.emitted()['update:modelValue'].at(-1)[0]
		expect(written).toMatchObject({
			name: 'intake',
			audience: 'client',
			minTrust: 'substantial',
			isPublic: false,
		})
	})

	it('no sign-in removes the level rather than storing an anonymous value', async () => {
		const wrapper = factory({ minTrust: 'high' })
		const select = wrapper.find(
			'[data-testid="registration-form-sign-in-level"]',
		)
		expect(select.element.value).toBe('high')
		await select.setValue('')
		const written = wrapper.emitted()['update:modelValue'].at(-1)[0]
		expect(written).not.toHaveProperty('minTrust')
		expect(written.name).toBe('intake')
	})
})
