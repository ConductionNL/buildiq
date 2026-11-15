/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the click paths of DocumentAttachmentsSection.vue and
 * WorkflowAttachmentsSection.vue: Add opens an empty dialog, Edit opens it on
 * the clicked entry, Detach confirms before it writes, and the dialogs'
 * v-model and save/confirm events reach the section.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DocumentAttachmentsSection from '../../src/components/DocumentAttachmentsSection.vue'
import WorkflowAttachmentsSection from '../../src/components/WorkflowAttachmentsSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['variant', 'disabled', 'title'],
	emits: ['click'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

/**
 * A dialog stub exposing its props and emitting what the real one emits.
 *
 * @param {string} name The component name.
 * @param {Array<string>} props The props it declares.
 * @param {Array<string>} emits The events it emits.
 * @return {object}
 */
function dialogStub(name, props, emits) {
	return { name, props, emits, template: '<div />' }
}

const documentStubs = {
	NcButton: NcButtonStub,
	DocumentTemplateAttachmentDialog: dialogStub(
		'DocumentTemplateAttachmentDialog',
		['open', 'schemas', 'attachments', 'attachment', 'docudeskAvailable'],
		['update:open', 'save'],
	),
	ConfirmActionDialog: dialogStub('ConfirmActionDialog', ['open', 'name', 'message', 'confirmLabel', 'destructive'], ['update:open', 'confirm']),
}

const workflowStubs = {
	NcButton: NcButtonStub,
	WorkflowAttachmentDialog: dialogStub(
		'WorkflowAttachmentDialog',
		['open', 'schemas', 'attachments', 'attachment', 'procestAvailable'],
		['update:open', 'save'],
	),
	ConfirmActionDialog: documentStubs.ConfirmActionDialog,
}

const cases = [
	{
		title: 'DocumentAttachmentsSection',
		component: DocumentAttachmentsSection,
		stubs: documentStubs,
		dialog: 'DocumentTemplateAttachmentDialog',
		listKey: 'documents',
		availability: { docudeskAvailable: true },
		entries: [
			{ id: 'doc-1', schema: 'a', templateName: 'Letter', label: 'Letter A' },
			{ id: 'doc-2', schema: 'b', templateName: 'Memo', label: 'Memo B' },
		],
		addLabel: 'Attach template',
	},
	{
		title: 'WorkflowAttachmentsSection',
		component: WorkflowAttachmentsSection,
		stubs: workflowStubs,
		dialog: 'WorkflowAttachmentDialog',
		listKey: 'workflows',
		availability: { procestAvailable: true },
		entries: [
			{ id: 'wf-1', schema: 'a', label: 'Intake' },
			{ id: 'wf-2', schema: 'b', label: 'Review' },
		],
		addLabel: null,
	},
]

describe.each(cases)('$title clicks', (c) => {
	/**
	 * Mount the section over the case's entries.
	 *
	 * @return {import('@vue/test-utils').Wrapper}
	 */
	function factory() {
		return mount(c.component, {
			propsData: { manifest: { runtime: { [c.listKey]: c.entries } }, schemas: [], ...c.availability },
			stubs: c.stubs,
		})
	}

	/**
	 * The row buttons with a given text, in row order.
	 *
	 * @param {import('@vue/test-utils').Wrapper} wrapper The section.
	 * @param {string} text The button text.
	 * @return {Array<import('@vue/test-utils').DOMWrapper>}
	 */
	function buttons(wrapper, text) {
		return wrapper.findAll('button').filter((b) => b.text() === text)
	}

	it('opens the dialog empty from the header button', async () => {
		const wrapper = factory()
		wrapper.vm.editingAttachment = c.entries[0]
		await wrapper.find('header button').trigger('click')
		const dialog = wrapper.findComponent({ name: c.dialog })
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('attachment')).toBe(null)
		if (c.addLabel) {
			expect(wrapper.find('header button').text()).toBe(c.addLabel)
		}
	})

	it('opens the dialog on the clicked entry', async () => {
		const wrapper = factory()
		await buttons(wrapper, 'Edit')[1].trigger('click')
		const dialog = wrapper.findComponent({ name: c.dialog })
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('attachment')).toEqual(c.entries[1])
	})

	it('closes the dialog through its v-model and writes what it saves', async () => {
		const wrapper = factory()
		await buttons(wrapper, 'Edit')[0].trigger('click')
		const dialog = wrapper.findComponent({ name: c.dialog })
		const edited = { ...c.entries[0], label: 'Renamed' }
		await dialog.vm.$emit('save', { entry: edited })
		await dialog.vm.$emit('update:open', false)
		expect(wrapper.findComponent({ name: c.dialog }).props('open')).toBe(false)
		const manifest = wrapper.emitted('update:manifest')[0][0]
		expect(manifest.runtime[c.listKey]).toEqual([edited, c.entries[1]])
	})

	it('detaches the clicked entry only after the confirm dialog confirms', async () => {
		const wrapper = factory()
		await buttons(wrapper, 'Detach')[1].trigger('click')
		const confirm = wrapper.findComponent({ name: 'ConfirmActionDialog' })
		expect(confirm.props('open')).toBe(true)
		expect(wrapper.emitted('update:manifest')).toBeUndefined()

		await confirm.vm.$emit('confirm')
		expect(wrapper.findComponent({ name: 'ConfirmActionDialog' }).props('open')).toBe(false)
		expect(wrapper.emitted('update:manifest')[0][0].runtime[c.listKey]).toEqual([c.entries[0]])
	})

	it('writes nothing when the confirm dialog is dismissed', async () => {
		const wrapper = factory()
		await buttons(wrapper, 'Detach')[0].trigger('click')
		const confirm = wrapper.findComponent({ name: 'ConfirmActionDialog' })
		await confirm.vm.$emit('update:open', false)
		expect(wrapper.findComponent({ name: 'ConfirmActionDialog' }).props('open')).toBe(false)
		expect(wrapper.emitted('update:manifest')).toBeUndefined()
	})
})
