/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the manifest edits of DocumentAttachmentsSection and
 * WorkflowAttachmentsSection exactly: replace-in-place of an edited entry,
 * other runtime keys kept, the runtime key dropped when the list empties, the
 * detail-page tab injected once and only on the matching detail page, and a
 * confirm without a pending detach emitting nothing.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DocumentAttachmentsSection from '../../src/components/DocumentAttachmentsSection.vue'
import WorkflowAttachmentsSection from '../../src/components/WorkflowAttachmentsSection.vue'

const stubs = {
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	DocumentTemplateAttachmentDialog: { template: '<div />' },
	WorkflowAttachmentDialog: { template: '<div />' },
	ConfirmActionDialog: { template: '<div />' },
}

const CASES = [
	{
		Section: DocumentAttachmentsSection,
		key: 'documents',
		tabFlag: 'addActionsTab',
		tab: { id: 'docudesk-document-actions', label: 'Documents', component: 'docudesk-document-actions' },
	},
	{
		Section: WorkflowAttachmentsSection,
		key: 'workflows',
		tabFlag: 'addStatusTab',
		tab: { id: 'procest-case-status', label: 'Case', component: 'procest-case-status' },
	},
]

/**
 * @param {object} Section the section component.
 * @param {object} manifest the manifest prop.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function factory(Section, manifest) {
	return mount(Section, { props: { manifest, schemas: [] }, global: { stubs } })
}

describe.each(CASES)('$key attachments section', ({ Section, key, tabFlag, tab }) => {
	const a = { id: 'a', schema: 'thing', label: 'A' }
	const b = { id: 'b', schema: 'thing', label: 'B' }

	it('replaces an edited entry in place and keeps other runtime keys', () => {
		const wrapper = factory(Section, { title: 'x', runtime: { other: 1, [key]: [a, b] } })
		wrapper.vm.onDialogSave({ entry: { ...a, label: 'A2' }, [tabFlag]: false })

		expect(wrapper.emitted('update:manifest')[0][0]).toEqual({
			title: 'x',
			runtime: { other: 1, [key]: [{ ...a, label: 'A2' }, b] },
		})
	})

	it('drops only its own runtime key when the last entry is detached', () => {
		const wrapper = factory(Section, { runtime: { other: 1, [key]: [a] } })
		wrapper.vm.detach(a)
		wrapper.vm.onConfirmDetach()

		expect(wrapper.emitted('update:manifest')[0][0]).toEqual({ runtime: { other: 1 } })
		expect(wrapper.vm.pendingDetach).toBe(null)
		expect(wrapper.vm.confirmDetachOpen).toBe(false)
	})

	it('emits nothing when a confirm arrives without a pending detach', () => {
		const wrapper = factory(Section, { runtime: { [key]: [a] } })
		wrapper.vm.onConfirmDetach()

		expect(wrapper.emitted('update:manifest')).toBeUndefined()
	})

	it('injects the tab once, only into the detail page of the entry schema', () => {
		const pages = [
			{ type: 'index', config: { schema: 'thing' } },
			{ type: 'detail', config: { schema: 'other' } },
			{ type: 'detail', config: { schema: 'thing', sidebarProps: { width: 3, tabs: [{ id: 'x' }] } } },
			{ type: 'detail', config: { schema: 'thing', sidebarProps: { tabs: [tab] } } },
			{ type: 'detail' },
			null,
		]
		const wrapper = factory(Section, { pages })
		wrapper.vm.onDialogSave({ entry: a, [tabFlag]: true })

		expect(wrapper.emitted('update:manifest')[0][0]).toEqual({
			pages: [
				pages[0],
				pages[1],
				{ type: 'detail', config: { schema: 'thing', sidebarProps: { width: 3, tabs: [{ id: 'x' }, tab] } } },
				{ type: 'detail', config: { schema: 'thing', sidebarProps: { tabs: [tab] } } },
				pages[4],
				null,
			],
			runtime: { [key]: [a] },
		})
	})
})
