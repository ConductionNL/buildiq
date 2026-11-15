/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DocumentAttachmentsSection without Docudesk: the add dialog never opens,
 * whatever calls `openAdd()`; with Docudesk it opens on a fresh entry.
 *
 * Spec: docudesk-document-templates (REQ-DDT-002).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DocumentAttachmentsSection from '../../src/components/DocumentAttachmentsSection.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	DocumentTemplateAttachmentDialog: {
		name: 'DocumentTemplateAttachmentDialog',
		template: '<div class="dialog-stub" />',
	},
}

function factory(docudeskAvailable) {
	return mount(DocumentAttachmentsSection, {
		propsData: { manifest: {}, schemas: [], docudeskAvailable },
		stubs,
	})
}

describe('DocumentAttachmentsSection openAdd', () => {
	it('keeps the dialog closed when Docudesk is unavailable', () => {
		const vm = factory(false).vm
		vm.editingAttachment = { id: 'kept' }

		vm.openAdd()

		expect(vm.dialogOpen).toBe(false)
		expect(vm.editingAttachment).toEqual({ id: 'kept' })
	})

	it('opens the dialog on a fresh entry when Docudesk is available', () => {
		const vm = factory(true).vm
		vm.editingAttachment = { id: 'stale' }

		vm.openAdd()

		expect(vm.dialogOpen).toBe(true)
		expect(vm.editingAttachment).toBe(null)
	})
})
