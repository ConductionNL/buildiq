/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/dialogs/SaveAsTemplateDialog.vue`: what typing into
 * the dialog and pressing its buttons sends, a validator that throws, and
 * that closing is refused while a save is in flight.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const { validateMock } = vi.hoisted(() => ({
	validateMock: vi.fn(() => ({ valid: true, errors: [] })),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ validateManifest: validateMock }))

import SaveAsTemplateDialog from '../../src/dialogs/SaveAsTemplateDialog.vue'

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

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'size'],
		emits: ['update:open', 'closing'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: field('NcTextField', 'input'),
	NcTextArea: field('NcTextArea', 'textarea'),
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], template: '<div />' },
}

const application = { slug: 'my-permits', name: 'My permits', version: '0.3.0', description: 'Permits app' }
const schemas = [{ slug: 'my-permits-permit-application', title: 'Permit', properties: {} }]
const manifest = { pages: [{ id: 'idx', config: { schema: 'my-permits-permit-application' } }] }

/**
 * Mount the dialog, closed, then open it.
 *
 * @return {Promise<object>} The wrapper.
 */
async function openDialog() {
	const wrapper = mount(SaveAsTemplateDialog, {
		props: { open: false, application, manifest, schemas, existingTemplates: [] },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

/**
 * The input or textarea whose label starts with a text.
 *
 * @param {object} wrapper - the wrapper.
 * @param {string} label - the label prefix.
 * @return {object} The element.
 */
function byLabel(wrapper, label) {
	return wrapper.find(`[data-label^="${label}"]`)
}

describe('SaveAsTemplateDialog inputs', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
		axiosMock.put.mockReset()
		validateMock.mockReset()
		validateMock.mockReturnValue({ valid: true, errors: [] })
	})

	it('Save sends what was typed', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const wrapper = await openDialog()

		await byLabel(wrapper, 'Template title').setValue('Permit Desk')
		await byLabel(wrapper, 'Slug').setValue('permit-desk')

		await byLabel(wrapper, 'Use case').setValue('Issue permits')
		await byLabel(wrapper, 'Description').setValue('Front office')
		await byLabel(wrapper, 'Source URL').setValue('https://example.org/src')
		await wrapper.findAll('button').at(1).trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axiosMock.post.mock.calls[0][1]).toMatchObject({
			slug: 'permit-desk',
			title: 'Permit Desk',
			useCase: 'Issue permits',
			description: 'Front office',
			sourceUrl: 'https://example.org/src',
		})
		expect(wrapper.emitted('saved')[0][0]).toEqual({ slug: 'permit-desk', mode: 'create' })
	})

	it('a validator that throws is reported as a validation error and blocks the save', async () => {
		validateMock.mockImplementation(() => {
			throw new Error('boom')
		})
		const wrapper = await openDialog()

		expect(wrapper.vm.validationErrors).toEqual(['validator threw: boom'])
		expect(wrapper.text()).toContain('validator threw: boom')
		await wrapper.vm.save()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('Cancel and the dialog closing both close it, but not while saving', async () => {
		const wrapper = await openDialog()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })

		await wrapper.findAll('button').at(0).trigger('click')
		dialog.vm.$emit('closing')
		dialog.vm.$emit('update:open', true)
		expect(wrapper.emitted('update:open')).toEqual([[false], [false], [true]])

		wrapper.vm.saving = true
		dialog.vm.$emit('closing')
		expect(wrapper.emitted('update:open')).toHaveLength(3)
	})
})
