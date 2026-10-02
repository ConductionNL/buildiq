/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: SaveFormToLibraryDialog saves a form with the definitions of its
 * bound properties and refuses a form bound to a property its schema lacks
 * (REQ-BQGL-002).
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'maker' }) }))

import axios from '@nextcloud/axios'
import SaveFormToLibraryDialog from '../../src/dialogs/SaveFormToLibraryDialog.vue'

const stubs = {
	NcDialog: { name: 'NcDialog', props: ['open', 'name', 'size'], template: '<div><slot /><slot name="actions" /></div>' },
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcTextArea: { name: 'NcTextArea', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<textarea />' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel', 'clearable'], emits: ['update:modelValue'], template: '<div class="select" />' },
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const schemaList = {
	results: [{
		id: 7,
		slug: 'subsidies-aanvraag',
		properties: { naam: { type: 'string' }, verbruikKwh: { type: 'number' }, intern: { type: 'string' } },
	}],
}

/**
 * Mount the dialog open for a registration form.
 *
 * @param {object} form The form.
 * @return {Promise<object>}
 */
async function mountWith(form) {
	const wrapper = mount(SaveFormToLibraryDialog, {
		props: {
			open: true,
			kind: 'registration-form',
			form,
			register: 'subsidies',
			schemaSlug: 'subsidies-aanvraag',
			appSlug: 'subsidies',
		},
		global: { stubs },
	})
	await flush()
	return wrapper
}

describe('SaveFormToLibraryDialog', () => {
	beforeEach(() => {
		axios.get.mockReset().mockResolvedValue({ data: schemaList })
		axios.post.mockReset().mockResolvedValue({ data: { id: 'new' } })
	})

	it('saves the form with the definitions of the properties it binds, and no records', async () => {
		const wrapper = await mountWith({ name: 'Aanvraag energiesubsidie', fields: [{ name: 'naam' }, { name: 'verbruikKwh' }] })

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/registers/subsidies/schemas')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'citizen-engagement' })
		await flush()

		const save = wrapper.find('[data-testid="save-form-to-library"]')
		expect(save.attributes('disabled')).toBeUndefined()
		await save.trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, record] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/buildiq/form-template')
		expect(record.slug).toBe('aanvraag-energiesubsidie')
		expect(record.category).toBe('citizen-engagement')
		expect(record.kind).toBe('registration-form')
		expect(Object.keys(record.schemaFragment)).toEqual(['naam', 'verbruikKwh'])
		expect(record.sourceSchema).toBe('aanvraag')
		expect(record.createdBy).toBe('maker')
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('refuses a form bound to a property its schema lacks, naming it', async () => {
		const wrapper = await mountWith({ name: 'Kapot', fields: [{ name: 'naam' }, { name: 'iban' }] })
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'field-work' })
		await flush()

		expect(wrapper.find('[role="alert"]').text()).toContain('iban')
		expect(wrapper.find('[data-testid="save-form-to-library"]').attributes('disabled')).toBeDefined()
		expect(axios.post).not.toHaveBeenCalled()
	})
})
