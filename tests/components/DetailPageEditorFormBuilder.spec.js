/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the registration-form builder's call site.
 *
 * WHY THIS EXISTS BESIDE THE COMPONENT SPECS
 * The component specs prove the builder works when something mounts it. They
 * cannot see whether anything does. A builder with a full green suite and no
 * call site has shipped in this fleet before, and it is indistinguishable from
 * a working one until somebody opens the page.
 *
 * So this asserts the chain from the page editor the page designer renders:
 * DetailPageEditor mounts the form list, the form list opens the editor, and
 * the editor carries the layout and preset builders.
 *
 * Spec: registration-form-builder (REQ-OBRF-004, REQ-OBRF-008).
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/registrationForms.js', () => ({
	fetchRegistrationForms: vi.fn(),
	fetchTargetSchema: vi.fn(),
	saveRegistrationForm: vi.fn(),
}))

vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn().mockResolvedValue([]),
		fetchSchemas: vi.fn().mockResolvedValue([]),
		fetchSchemaProperties: vi.fn().mockResolvedValue([]),
		fetchDataSources: vi.fn().mockResolvedValue([]),
		resolveAppRegister: vi.fn().mockResolvedValue(''),
	}),
}))

import DetailPageEditor from '../../src/components/page-editor/DetailPageEditor.vue'
import {
	fetchRegistrationForms,
	fetchTargetSchema,
} from '../../src/services/registrationForms.js'

const flush = () => new Promise((r) => setTimeout(r, 0))

describe('the registration-form builder on the page the designer renders', () => {
	beforeEach(() => {
		fetchRegistrationForms.mockReset()
		fetchRegistrationForms.mockResolvedValue([
			{
				id: 'rf-1',
				name: 'Aanvraag bouwvergunning',
				audience: 'client',
				typeProperty: 'caseType',
				typeValue: 'bouwvergunning',
			},
		])
		fetchTargetSchema.mockReset()
		fetchTargetSchema.mockResolvedValue({
			properties: ['naam', 'intakeChannel'],
			channels: ['portal'],
			note: null,
		})
	})

	it('reaches the builder from the detail page editor', async () => {
		const wrapper = mount(DetailPageEditor, {
			props: {
				config: {
					register: 'dossiq',
					schema: 'Zaak',
					pageLayout: {
						typeProperty: 'caseType',
						typeValue: 'bouwvergunning',
					},
				},
				appSlug: 'dossiq',
			},
			global: { stubs: { AppliesToPanel: true, ScreenOverrideList: true } },
		})

		await flush()
		await wrapper.vm.$nextTick()

		const list = wrapper.findComponent({ name: 'RegistrationFormList' })
		expect(list.exists()).toBe(true)

		await list.find('.form-list__item button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		expect(
			wrapper.findComponent({ name: 'FormLayoutBuilder' }).exists(),
			'the layout builder is not reachable from the page the designer renders',
		).toBe(true)
		expect(wrapper.findComponent({ name: 'FormPresetsBuilder' }).exists()).toBe(
			true,
		)
	})
})
