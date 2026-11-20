/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what the document-template and workflow attachment dialogs share:
 * their declared props and events, `editing`, Cancel closing the dialog, and
 * the id a saved entry gets (kept when editing, `<prefix>-<schema>-<time>`
 * when adding).
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: [] }), post: vi.fn() },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

import DocumentTemplateAttachmentDialog from '../../src/dialogs/DocumentTemplateAttachmentDialog.vue'
import WorkflowAttachmentDialog from '../../src/dialogs/WorkflowAttachmentDialog.vue'

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { template: '<div />' },
	NcTextField: { template: '<input />' },
}

const CASES = [
	{
		Dialog: DocumentTemplateAttachmentDialog,
		filled: { templateOption: { uuid: 't1', name: 'T' }, schemaOption: { slug: 'case' }, label: 'Letter' },
	},
	{
		Dialog: WorkflowAttachmentDialog,
		filled: { caseTypeOption: { uuid: 'c1', name: 'C' }, schemaOption: { slug: 'case' }, linkPropertyOption: { name: 'zaakUrl' } },
	},
]

/**
 * @param {object} Dialog the dialog.
 * @param {object} props extra props.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountDialog(Dialog, props = {}) {
	return mount(Dialog, {
		props,
		global: { stubs, mocks: { $route: { params: {}, query: {} } } },
	})
}

describe.each(CASES)('$Dialog.name shared plumbing', ({ Dialog, filled }) => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('declares its props and events', () => {
		const options = mountDialog(Dialog).vm.$options
		const props = Object.fromEntries(Object.entries(options.props).sort().map(([k, v]) => [
			k,
			{ type: v.type.name, default: typeof v.default === 'function' ? v.default() : v.default },
		]))
		expect({ props, emits: [...options.emits].sort() }).toMatchSnapshot()
	})

	it('tells adding from editing and closes on Cancel', async () => {
		const wrapper = mountDialog(Dialog)
		expect(wrapper.vm.editing).toBe(false)
		await wrapper.setProps({ attachment: { id: 'x' } })
		expect(wrapper.vm.editing).toBe(true)

		await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('gives a new entry a generated id and keeps the id of an edited one', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(1700000000000)
		const wrapper = mountDialog(Dialog)
		await wrapper.setData(filled)
		wrapper.vm.onSave()
		await wrapper.setProps({ attachment: { id: 'kept' } })
		await wrapper.setData(filled)
		wrapper.vm.onSave()

		expect(wrapper.emitted('save').map(([payload]) => payload.entry.id)).toMatchSnapshot()
		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
	})
})
