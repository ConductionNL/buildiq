/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DocumentAttachmentsSection.vue.
 *
 * Spec: docudesk-document-templates (REQ-DDT-001, REQ-DDT-002).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DocumentAttachmentsSection from '../../src/components/DocumentAttachmentsSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled', 'title'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const stubs = {
	NcButton: NcButtonStub,
	DocumentTemplateAttachmentDialog: {
		name: 'DocumentTemplateAttachmentDialog',
		template: '<div class="dialog-stub" />',
	},
}

const entry = {
	id: 'doc-1',
	schema: 'kapaanvraag',
	templateId: 'u',
	templateName: 'Bevestigingsbrief',
	label: 'Generate confirmation letter',
}

const factory = (manifest, props = {}) =>
	mount(DocumentAttachmentsSection, {
		propsData: { manifest, schemas: [], ...props },
		stubs,
	})

describe('DocumentAttachmentsSection', () => {
	it('renders the empty state with no attachments', () => {
		const wrapper = factory({})
		expect(wrapper.find('.ob-documents-section__empty').exists()).toBe(true)
	})

	it('lists existing attachments', () => {
		const wrapper = factory({ runtime: { documents: [entry] } })
		expect(wrapper.text()).toContain('Generate confirmation letter')
		expect(wrapper.findAll('.ob-documents-section__item')).toHaveLength(1)
	})

	it('adding an attachment emits an updated manifest with runtime.documents', () => {
		const wrapper = factory({})
		wrapper.vm.onDialogSave({ entry, addActionsTab: false })
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.documents).toHaveLength(1)
		expect(emitted.runtime.documents[0].id).toBe('doc-1')
	})

	it('allows two attachments on the same schema with distinct labels', () => {
		const wrapper = factory({ runtime: { documents: [entry] } })
		wrapper.vm.onDialogSave({
			entry: { ...entry, id: 'doc-2', label: 'Generate besluit' },
			addActionsTab: false,
		})
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.documents).toHaveLength(2)
	})

	it('detaching asks first and emits nothing until confirmed', () => {
		const wrapper = factory({ runtime: { documents: [entry] } })
		wrapper.vm.detach(entry)
		expect(wrapper.vm.confirmDetachOpen).toBe(true)
		expect(wrapper.emitted()['update:manifest']).toBeUndefined()
	})

	it('detaching removes the entry once confirmed and keeps zero-attachment manifests clean', () => {
		const wrapper = factory({ runtime: { documents: [entry] } })
		wrapper.vm.detach(entry)
		wrapper.vm.onConfirmDetach()
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime).toBeUndefined()
	})

	it('injects a docudesk-document-actions tab into a matching detail page when toggled', () => {
		const wrapper = factory({
			pages: [{ type: 'detail', config: { schema: 'kapaanvraag' } }],
		})
		wrapper.vm.onDialogSave({ entry, addActionsTab: true })
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		const tabs = emitted.pages[0].config.sidebarProps.tabs
		expect(tabs.some((t) => t.component === 'docudesk-document-actions')).toBe(
			true,
		)
	})

	it('disables Add when Docudesk is absent but keeps rows removable', () => {
		const wrapper = factory(
			{ runtime: { documents: [entry] } },
			{ docudeskAvailable: false },
		)
		const addBtn = wrapper.findAll('button').at(0)
		expect(addBtn.attributes('disabled')).toBeDefined()
		expect(wrapper.find('.ob-documents-section__hint').exists()).toBe(true)
		// Detach buttons still present.
		expect(wrapper.text()).toContain('Detach')
	})
})
