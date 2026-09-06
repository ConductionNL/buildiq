import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for WorkflowAttachmentsSection.vue.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-001, REQ-PWA-002).
 */
import { describe, expect, it } from 'vitest'
import WorkflowAttachmentsSection from '../../src/components/WorkflowAttachmentsSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const stubs = {
	NcButton: NcButtonStub,
	WorkflowAttachmentDialog: {
		name: 'WorkflowAttachmentDialog',
		template: '<div class="dialog-stub" />',
	},
}

const entry = {
	id: 'wf-1',
	schema: 'kapaanvraag',
	caseTypeUuid: 'u',
	caseTypeName: 'Kapvergunning',
	trigger: 'on-create',
	linkProperty: 'zaakUrl',
}

function factory(manifest) {
	return mount(WorkflowAttachmentsSection, {
		propsData: { manifest, schemas: [] },
		stubs,
	})
}

describe('WorkflowAttachmentsSection', () => {
	it('renders empty state with no attachments', () => {
		const wrapper = factory({})
		expect(wrapper.find('.ob-workflows-section__empty').exists()).toBe(true)
	})

	it('lists existing attachments', () => {
		const wrapper = factory({ runtime: { workflows: [entry] } })
		expect(wrapper.text()).toContain('Kapvergunning')
		// the meta line uses an interpolated i18n key; the test t() stub does not
		// interpolate, so assert the list rendered exactly one item instead.
		expect(wrapper.findAll('.ob-workflows-section__item')).toHaveLength(1)
	})

	it('adding an attachment emits an updated manifest with runtime.workflows', () => {
		const wrapper = factory({})
		wrapper.vm.onDialogSave({ entry, addStatusTab: false })
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.workflows).toHaveLength(1)
		expect(emitted.runtime.workflows[0].id).toBe('wf-1')
	})

	it('detaching asks first and emits nothing until confirmed', () => {
		const wrapper = factory({ runtime: { workflows: [entry] } })
		wrapper.vm.detach(entry)
		expect(wrapper.vm.confirmDetachOpen).toBe(true)
		expect(wrapper.emitted()['update:manifest']).toBeUndefined()
	})

	it('detaching removes the entry once confirmed and keeps zero-attachment manifests clean', () => {
		const wrapper = factory({ runtime: { workflows: [entry] } })
		wrapper.vm.detach(entry)
		wrapper.vm.onConfirmDetach()
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime).toBeUndefined() // runtime dropped when empty
	})

	it('injects a procest-case-status tab into a matching detail page when toggled', () => {
		const wrapper = factory({
			pages: [{ type: 'detail', config: { schema: 'kapaanvraag' } }],
		})
		wrapper.vm.onDialogSave({ entry, addStatusTab: true })
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		const tabs = emitted.pages[0].config.sidebarProps.tabs
		expect(tabs.some((t) => t.component === 'procest-case-status')).toBe(true)
	})
})
