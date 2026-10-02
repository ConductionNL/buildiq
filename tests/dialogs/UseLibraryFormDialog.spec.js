/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: UseLibraryFormDialog lists the properties the target schema lacks,
 * writes nothing before the maker confirms, and adds them on "Add these
 * properties" (REQ-BQGL-004, scenario "A second app reuses the form").
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, saveRegistrationForm } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() },
	saveRegistrationForm: vi.fn(),
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('../../src/services/registrationForms.js', () => ({ saveRegistrationForm }))

import UseLibraryFormDialog from '../../src/dialogs/UseLibraryFormDialog.vue'

const stubs = {
	NcDialog: { name: 'NcDialog', props: ['open', 'name', 'size'], template: '<div><slot /><slot name="actions" /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'disabled', 'loading', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="select" :data-label="inputLabel" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'value', 'name'],
		emits: ['update:modelValue'],
		template: '<label><slot /></label>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const template = {
	slug: 'aanvraag-energiesubsidie',
	name: 'Aanvraag energiesubsidie',
	kind: 'registration-form',
	category: 'citizen-engagement',
	sourceSchema: 'aanvraag',
	form: { fields: [{ name: 'naam', label: 'Naam' }, { name: 'verbruikKwh', label: 'Verbruik' }], confirmationText: 'Bedankt.' },
	schemaFragment: { naam: { type: 'string' }, verbruikKwh: { type: 'number' } },
}

/**
 * Mount the dialog and walk it to app `subsidies`, version `v1`, schema `aanvraag`.
 *
 * @return {Promise<object>}
 */
async function mountAndPick() {
	const wrapper = mount(UseLibraryFormDialog, { props: { open: true, template }, global: { stubs } })
	await flush()
	const select = (label) => wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === label)

	select('App').vm.$emit('update:modelValue', { id: 'subsidies' })
	await flush()
	select('Version').vm.$emit('update:modelValue', { id: 'v1' })
	await flush()
	select('Schema the form saves into').vm.$emit('update:modelValue', { id: 'aanvraag' })
	await flush()

	const fields = wrapper.findAllComponents({ name: 'NcTextField' })
	fields[0].vm.$emit('update:modelValue', 'soort')
	fields[1].vm.$emit('update:modelValue', 'energie')
	await flush()
	return wrapper
}

describe('UseLibraryFormDialog', () => {
	beforeEach(() => {
		axiosMock.get.mockReset().mockImplementation((url) => {
			if (url.includes('built-app')) {
				return Promise.resolve({ data: { results: [{ slug: 'subsidies', name: 'Subsidies' }] } })
			}
			if (url.endsWith('/versions')) {
				return Promise.resolve({ data: [{ slug: 'v1', name: 'v1' }] })
			}
			if (url.endsWith('/versions/v1')) {
				return Promise.resolve({ data: { slug: 'v1', register: 'subsidies-reg', '@self': { id: 'v-uuid' }, manifest: { pages: [] } } })
			}
			if (url.includes('/registers/subsidies-reg/schemas')) {
				return Promise.resolve({ data: { results: [{ id: 9, slug: 'aanvraag', properties: { naam: { type: 'string' } } }] } })
			}
			return Promise.resolve({ data: [] })
		})
		axiosMock.patch.mockReset().mockResolvedValue({ data: {} })
		saveRegistrationForm.mockReset().mockImplementation(async (form) => ({ form, warnings: [] }))
	})

	it('shows the whole form and the property the schema lacks, and writes nothing yet', async () => {
		const wrapper = await mountAndPick()

		expect(wrapper.text()).toContain('Naam')
		expect(wrapper.text()).toContain('Verbruik')
		expect(wrapper.find('[data-testid="missing-properties"]').text()).toContain('verbruikKwh')
		expect(wrapper.find('[data-testid="confirm-use-form"]').attributes('disabled')).toBeDefined()
		expect(axiosMock.patch).not.toHaveBeenCalled()
		expect(saveRegistrationForm).not.toHaveBeenCalled()
	})

	it('adds the property and the registration form once the maker confirms', async () => {
		const wrapper = await mountAndPick()

		wrapper.findComponent('[data-testid="add-properties"]').vm.$emit('update:modelValue', true)
		await flush()
		await wrapper.find('[data-testid="confirm-use-form"]').trigger('click')
		await flush()

		expect(axiosMock.patch).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.patch.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/schemas/9')
		expect(Object.keys(body.properties)).toEqual(['naam', 'verbruikKwh'])

		expect(saveRegistrationForm).toHaveBeenCalledTimes(1)
		const form = saveRegistrationForm.mock.calls[0][0]
		expect(form.register).toBe('subsidies-reg')
		expect(form.schema).toBe('aanvraag')
		expect(form.typeProperty).toBe('soort')
		expect(form.typeValue).toBe('energie')
		expect(form.targetApp).toBe('subsidies')
		expect(form.fields).toEqual(template.form.fields)
		expect(wrapper.emitted('used')).toHaveLength(1)
	})
})
