/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/dialogs/SaveBlockDialog.vue`: what typing into the
 * dialog and pressing its buttons sends, a slug another block already uses,
 * and that closing is refused while a save is in flight.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))

import SaveBlockDialog from '../../src/dialogs/SaveBlockDialog.vue'

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
}

const WIDGET = { id: 'w1', widgetKey: 'permit-list', config: { schema: 'my-app-permit' } }

/**
 * Mount the dialog open on one widget.
 *
 * @param {Array<object>} existingBlocks - blocks already saved.
 * @return {object} The wrapper.
 */
function mountDialog(existingBlocks = []) {
	return mount(SaveBlockDialog, {
		props: { open: true, application: { slug: 'my-app' }, fragment: WIDGET, existingBlocks },
		global: { stubs: STUBS },
	})
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

/**
 * The Save button.
 *
 * @param {object} wrapper - the wrapper.
 * @return {object} The button.
 */
function saveButton(wrapper) {
	return wrapper.findAll('button').at(1)
}

describe('SaveBlockDialog inputs', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('Save stays disabled until a category is typed, then sends what was typed', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const wrapper = mountDialog()

		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

		await byLabel(wrapper, 'Block name').setValue('Permit list')
		await byLabel(wrapper, 'Slug').setValue('permits')
		await byLabel(wrapper, 'Description').setValue('All permits in a table')
		await byLabel(wrapper, 'Category').setValue('display')
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

		await saveButton(wrapper).trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axiosMock.post.mock.calls[0][1]).toMatchObject({
			name: 'Permit list',
			slug: 'permits',
			description: 'All permits in a table',
			category: 'display',
		})
		expect(wrapper.emitted('saved')[0][0]).toEqual({ slug: 'permits' })
	})

	it('a slug another block uses is refused with a message', async () => {
		const wrapper = mountDialog([{ slug: 'taken' }, null])

		await byLabel(wrapper, 'Category').setValue('display')
		await byLabel(wrapper, 'Slug').setValue('taken')

		expect(wrapper.text()).toContain('That slug is already used by a block in your organisation.')
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		await wrapper.vm.save()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('Cancel and the dialog closing both close it, but not while saving', async () => {
		const wrapper = mountDialog()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })

		await wrapper.findAll('button').at(0).trigger('click')
		dialog.vm.$emit('closing')
		dialog.vm.$emit('update:open', true)
		expect(wrapper.emitted('update:open')).toEqual([[false], [false], [true]])

		wrapper.vm.saving = true
		dialog.vm.$emit('closing')
		expect(wrapper.emitted('update:open')).toHaveLength(3)
	})

	it('re-opening resets what was typed', async () => {
		const wrapper = mountDialog()
		await byLabel(wrapper, 'Description').setValue('draft')

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(byLabel(wrapper, 'Description').element.value).toBe('')
		expect(byLabel(wrapper, 'Block name').element.value).toBe('permit-list')
	})
})
