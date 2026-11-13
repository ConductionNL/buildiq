/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec driving four confirm dialogs through their rendered buttons
 * and fields rather than their methods: DeleteFieldDialog,
 * DeleteSchemaDialog, AddSchemaDialog and RollbackConfirmModal.
 *
 * Covers:
 *  - the action buttons are wired to the right events.
 *  - the delete-schema button stays disabled until the slug is typed exactly.
 *  - the add-schema button is disabled while invalid or submitting, and says so.
 *  - the rollback dialog shows the version and when it was published.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AddSchemaDialog from '../../src/modals/AddSchemaDialog.vue'
import DeleteFieldDialog from '../../src/modals/DeleteFieldDialog.vue'
import DeleteSchemaDialog from '../../src/modals/DeleteSchemaDialog.vue'
import RollbackConfirmModal from '../../src/modals/RollbackConfirmModal.vue'

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template: '<div class="dialog"><h2>{{ name }}</h2><slot /><footer><slot name="actions" /></footer><button class="close" @click="$emit(\'update:open\', false)" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button class="action" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		template: '<input class="field" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	SchemaHeaderForm: {
		name: 'SchemaHeaderForm',
		props: ['value', 'slugError'],
		emits: ['input'],
		template: '<div class="schema-form">{{ slugError }}</div>',
	},
}

/**
 * Mount a dialog with the rendering stubs.
 *
 * @param {object} component The dialog component.
 * @param {object} props Its props.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function render(component, props) {
	return mount(component, { props, global: { stubs: STUBS } })
}

/**
 * The action button with the given label.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The mounted dialog.
 * @param {string} label The button text.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function button(wrapper, label) {
	const found = wrapper.findAll('button.action').find((b) => b.text() === label)
	if (!found) {
		throw new Error(`No button "${label}" in: ${wrapper.findAll('button.action').map((b) => b.text()).join(', ')}`)
	}
	return found
}

describe('DeleteFieldDialog, rendered', () => {
	it('names the property and wires delete and cancel', async () => {
		const wrapper = render(DeleteFieldDialog, { open: true, fieldName: 'title' })

		expect(wrapper.find('h2').text()).toBe('Delete property')
		expect(wrapper.find('p').text()).toContain('You are about to remove the property {name}')

		await button(wrapper, 'Delete property').trigger('click')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toBeFalsy()

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})

	it('closing the dialog cancels', async () => {
		const wrapper = render(DeleteFieldDialog, { open: true, fieldName: 'title' })

		await wrapper.find('button.close').trigger('click')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})

describe('DeleteSchemaDialog, rendered', () => {
	it('keeps delete disabled until the slug is typed exactly', async () => {
		const wrapper = render(DeleteSchemaDialog, { open: true, schemaSlug: 'orders' })
		const field = wrapper.find('input.field')

		expect(field.attributes('placeholder')).toBe('orders')
		expect(button(wrapper, 'Delete schema').attributes('disabled')).toBeDefined()

		await field.setValue('order')
		expect(button(wrapper, 'Delete schema').attributes('disabled')).toBeDefined()

		await field.setValue('orders')
		expect(button(wrapper, 'Delete schema').attributes('disabled')).toBeUndefined()

		await button(wrapper, 'Delete schema').trigger('click')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.find('input.field').element.value).toBe('')
	})

	it('cancel clears what was typed', async () => {
		const wrapper = render(DeleteSchemaDialog, { open: true, schemaSlug: 'orders' })

		await wrapper.find('input.field').setValue('orders')
		await button(wrapper, 'Cancel').trigger('click')

		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.find('input.field').element.value).toBe('')
		expect(button(wrapper, 'Delete schema').attributes('disabled')).toBeDefined()
	})

	it('closing from the parent clears what was typed', async () => {
		const wrapper = render(DeleteSchemaDialog, { open: true, schemaSlug: 'orders' })

		await wrapper.find('input.field').setValue('orders')
		await wrapper.setProps({ open: false })

		expect(wrapper.find('input.field').element.value).toBe('')
	})
})

describe('AddSchemaDialog, rendered', () => {
	it('enables add only once the form is valid, and confirms with its values', async () => {
		const wrapper = render(AddSchemaDialog, { open: true, slugError: '' })
		expect(button(wrapper, 'Add schema').attributes('disabled')).toBeDefined()

		wrapper.findComponent({ name: 'SchemaHeaderForm' }).vm.$emit('input', { slug: 'orders', title: 'Orders' })
		await wrapper.vm.$nextTick()
		expect(button(wrapper, 'Add schema').attributes('disabled')).toBeUndefined()

		await button(wrapper, 'Add schema').trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[{ slug: 'orders', title: 'Orders', description: '', version: '0.1.0' }]])
	})

	it('a slug that does not start with a letter keeps add disabled', async () => {
		const wrapper = render(AddSchemaDialog, { open: true })

		wrapper.findComponent({ name: 'SchemaHeaderForm' }).vm.$emit('input', { slug: '1orders', title: 'Orders' })
		await wrapper.vm.$nextTick()

		expect(button(wrapper, 'Add schema').attributes('disabled')).toBeDefined()
	})

	it('while submitting the button says so and is disabled', async () => {
		const wrapper = render(AddSchemaDialog, { open: true, submitting: true, slugError: 'Slug taken' })

		wrapper.findComponent({ name: 'SchemaHeaderForm' }).vm.$emit('input', { slug: 'orders', title: 'Orders' })
		await wrapper.vm.$nextTick()

		expect(button(wrapper, 'Saving…').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.schema-form').text()).toBe('Slug taken')
	})

	it('reopening resets the form', async () => {
		const wrapper = render(AddSchemaDialog, { open: false })
		wrapper.findComponent({ name: 'SchemaHeaderForm' }).vm.$emit('input', { slug: 'orders', title: 'Orders' })

		await wrapper.setProps({ open: true })

		expect(wrapper.findComponent({ name: 'SchemaHeaderForm' }).props('value')).toEqual({ slug: '', title: '', description: '', version: '0.1.0' })
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})

describe('RollbackConfirmModal, rendered', () => {
	const version = { version: '1.2.0', publishedAt: '2026-10-01T10:00:00Z' }

	it('shows the version and when it was published', () => {
		const wrapper = render(RollbackConfirmModal, { open: true, version })
		const meta = wrapper.findAll('dd').map((dd) => dd.text())

		expect(wrapper.find('h2').text()).toBe('Roll back to version {version}?')
		expect(meta).toEqual(['1.2.0', new Date('2026-10-01T10:00:00Z').toLocaleString()])
	})

	it('without a version there is no metadata list', () => {
		const wrapper = render(RollbackConfirmModal, { open: true, version: null })

		expect(wrapper.find('dl').exists()).toBe(false)
	})

	it('roll back confirms with the version and closes; cancel cancels and closes', async () => {
		const wrapper = render(RollbackConfirmModal, { open: true, version })

		await button(wrapper, 'Roll back').trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[version]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
	})
})
