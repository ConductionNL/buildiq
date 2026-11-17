/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WorkflowAttachmentDialog driven through its template: the case type,
 * schema and link property pickers, the description template and the status
 * tab switch write the attachment being made, and closing the dialog asks
 * the parent to close it.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: { results: [{ uuid: 'ct-1', omschrijving: 'Licence', identificatie: 'LIC' }] } })),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const Dialog = (await import('../../src/dialogs/WorkflowAttachmentDialog.vue')).default

const SCHEMAS = [{ slug: 'pet', title: 'Pet', properties: { name: { type: 'string' }, age: { type: 'integer' } } }]

const stubs = {
	NcDialog: {
		emits: ['closing', 'update:open'],
		template: '<div><button class="dialog-close" @click="$emit(\'closing\')" /><button class="dialog-dismiss" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: { template: '<button><slot /></button>' },
	NcSelect: {
		props: ['modelValue', 'inputLabel', 'options'],
		emits: ['update:modelValue'],
		template: '<select :data-label="inputLabel" @change="$emit(\'update:modelValue\', options[$event.target.selectedIndex])"><option v-for="(o, i) in options" :key="i" :value="i">{{ o.label }}</option></select>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

async function openDialog() {
	const wrapper = mount(Dialog, {
		props: { schemas: SCHEMAS },
		global: { stubs, mocks: { $route: { params: { slug: 'petstore' }, query: {} } } },
	})
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

describe('WorkflowAttachmentDialog — fields', () => {
	it('writes the case type, schema, link property, description template and status tab', async () => {
		const wrapper = await openDialog()

		await wrapper.find('[data-label="Case type"]').setValue('0')
		await wrapper.find('[data-label="Schema"]').setValue('0')
		await wrapper.find('[data-label="Link property"]').setValue('0')
		await wrapper.find('[data-label="Description template (optional)"]').setValue('Pet {name}')
		const statusTab = wrapper.find('input[type="checkbox"]')
		await statusTab.setValue(!statusTab.element.checked)

		expect(wrapper.vm.caseTypeOption).toMatchObject({ uuid: 'ct-1', name: 'Licence' })
		expect(wrapper.vm.schemaOption).toMatchObject({ slug: 'pet' })
		expect(wrapper.vm.linkPropertyOption).toEqual(wrapper.vm.linkPropertyOptions[0])
		expect(wrapper.vm.descriptionTemplate).toBe('Pet {name}')
		expect(wrapper.vm.addStatusTab).toBe(statusTab.element.checked)
	})

	it('asks the parent to close when the dialog closes or is dismissed', async () => {
		const wrapper = await openDialog()
		await wrapper.find('.dialog-close').trigger('click')
		await wrapper.find('.dialog-dismiss').trigger('click')
		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
	})
})
