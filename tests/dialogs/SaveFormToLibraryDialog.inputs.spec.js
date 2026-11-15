/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SaveFormToLibraryDialog.vue: the schema the form saves into
 * being missing or unreadable, the name suggesting the slug until the slug is
 * typed, the category and publisher inputs, and closing.
 *
 * Spec: form-library (REQ-BQGL-002).
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

/**
 * A text-field stub that renders its label and passes typing on.
 *
 * @param {string} name - the component name.
 * @param {string} tag - the element to render.
 * @return {object} The stub.
 */
function field(name, tag) {
	return {
		name,
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: `<${tag} :data-label="label" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />`,
	}
}

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'size'],
		emits: ['update:open', 'closing'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcTextField: field('NcTextField', 'input'),
	NcTextArea: field('NcTextArea', 'textarea'),
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const schemaList = {
	results: [{ id: 7, slug: 'subsidies-aanvraag', properties: { naam: { type: 'string' } } }],
}

const FORM = { name: 'Aanvraag', fields: [{ name: 'naam' }] }

/**
 * Mount the dialog open and let the schema load.
 *
 * @param {object} props - props to override.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(props = {}) {
	const wrapper = mount(SaveFormToLibraryDialog, {
		props: {
			open: true,
			kind: 'registration-form',
			form: FORM,
			register: 'subsidies',
			schemaSlug: 'subsidies-aanvraag',
			appSlug: 'subsidies',
			...props,
		},
		global: { stubs },
	})
	await flush()
	return wrapper
}

/**
 * The input or textarea with a label.
 *
 * @param {object} wrapper - the wrapper.
 * @param {string} label - the label.
 * @return {object} The element.
 */
function byLabel(wrapper, label) {
	return wrapper.find(`[data-label="${label}"]`)
}

describe('SaveFormToLibraryDialog inputs', () => {
	beforeEach(() => {
		axios.get.mockReset().mockResolvedValue({ data: schemaList })
		axios.post.mockReset().mockResolvedValue({ data: { id: 'new' } })
	})

	it('asks for the schema first when the form does not name one', async () => {
		const wrapper = await mountWith({ schemaSlug: '' })

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('[role="alert"]').text()).toBe(
			'Pick the schema this form saves into before you save it to the library.',
		)
		expect(wrapper.vm.capture).toEqual({ record: null, missing: [] })
	})

	it('says the schema could not be read when it is absent or the request fails', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		const absent = await mountWith()
		expect(absent.find('[role="alert"]').text()).toBe('The schema this form saves into could not be read.')

		axios.get.mockRejectedValueOnce(new Error('offline'))
		const failing = await mountWith()
		expect(failing.find('[role="alert"]').text()).toBe('The schema this form saves into could not be read.')
		expect(failing.vm.loadingSchema).toBe(false)
	})

	it('the name suggests the slug until the slug is typed by hand', async () => {
		const wrapper = await mountWith()

		await byLabel(wrapper, 'Form name').setValue('Energie Subsidie')
		expect(byLabel(wrapper, 'Slug').element.value).toBe('energie-subsidie')

		await byLabel(wrapper, 'Slug').setValue('eigen-slug')
		await byLabel(wrapper, 'Form name').setValue('Iets anders')
		expect(byLabel(wrapper, 'Slug').element.value).toBe('eigen-slug')
	})

	it('Save sends the description, category and publisher that were entered', async () => {
		const wrapper = await mountWith()

		await byLabel(wrapper, 'Description').setValue('Voor de energietoeslag')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'intake', label: 'Intake' })
		await byLabel(wrapper, 'Publisher').setValue('Gemeente Voorbeeld')
		await wrapper.vm.$nextTick()
		await wrapper.vm.save()

		expect(axios.post.mock.calls[0][1]).toMatchObject({
			description: 'Voor de energietoeslag',
			category: 'intake',
			publisher: 'Gemeente Voorbeeld',
		})

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)
		expect(wrapper.vm.category).toBe('')
	})

	it('closing closes the dialog, but not while saving', async () => {
		const wrapper = await mountWith()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })

		dialog.vm.$emit('closing')
		dialog.vm.$emit('update:open', true)
		expect(wrapper.emitted('update:open')).toEqual([[false], [true]])

		wrapper.vm.saving = true
		dialog.vm.$emit('closing')
		expect(wrapper.emitted('update:open')).toHaveLength(2)
	})
})
