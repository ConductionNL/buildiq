/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/dialogs/EditTemplateMetadataDialog.vue`: what typing
 * into the dialog and pressing its buttons sends, and that closing is
 * refused while a save is in flight.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { put: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import EditTemplateMetadataDialog from '../../src/dialogs/EditTemplateMetadataDialog.vue'

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

const TEMPLATE = {
	slug: 'my-permits',
	title: 'My permits',
	category: 'field-work',
	'@self': { id: 'tpl-1' },
}

/**
 * Mount the dialog and open it.
 *
 * @param {object|null} template - the template prop.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(template = TEMPLATE) {
	const wrapper = mount(EditTemplateMetadataDialog, {
		props: { open: false, template },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
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

describe('EditTemplateMetadataDialog inputs', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
	})

	it('what is typed is what Save sends', async () => {
		axiosMock.put.mockResolvedValue({ data: {} })
		const wrapper = await openDialog()

		await byLabel(wrapper, 'Template title').setValue('  Permits 2  ')
		await byLabel(wrapper, 'Use case (one line)').setValue('Issue permits')
		await byLabel(wrapper, 'Description').setValue('For the front office')
		await byLabel(wrapper, 'Source URL (optional)').setValue('https://example.org/permits')
		await wrapper.findAll('button').at(1).trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axiosMock.put.mock.calls[0][1]).toEqual({
			...TEMPLATE,
			title: 'Permits 2',
			useCase: 'Issue permits',
			description: 'For the front office',
			category: 'field-work',
			sourceUrl: 'https://example.org/permits',
		})
		expect(wrapper.emitted('saved')[0][0]).toEqual({ slug: 'my-permits' })
	})

	it('Cancel and the dialog closing both close it, and its own open changes are passed on', async () => {
		const wrapper = await openDialog()

		await wrapper.findAll('button').at(0).trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)

		expect(wrapper.emitted('update:open')).toEqual([[false], [false], [true]])
	})

	it('does not close while saving', async () => {
		const wrapper = await openDialog()
		wrapper.vm.saving = true

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('without a template there is nothing to save', async () => {
		const wrapper = await openDialog(null)
		await byLabel(wrapper, 'Template title').setValue('Something')

		await wrapper.vm.save()

		expect(axiosMock.put).not.toHaveBeenCalled()
	})
})
