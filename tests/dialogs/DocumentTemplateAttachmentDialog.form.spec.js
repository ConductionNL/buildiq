/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The form half of DocumentTemplateAttachmentDialog: hydrating from an
 * existing attachment, the (schema, label) uniqueness rule, the saved entry's
 * shape and the refresh of a stored template snapshot.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => axiosGet(...a), post: vi.fn() },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
const fetchTemplates = vi.fn()
vi.mock('../../src/composables/useDocudeskTemplates.js', async (importOriginal) => ({
	...(await importOriginal()),
	fetchDocudeskTemplates: (...a) => fetchTemplates(...a),
}))

const Dialog = (await import('../../src/dialogs/DocumentTemplateAttachmentDialog.vue')).default

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button><slot /></button>' },
	NcSelect: { template: '<div />' },
	NcTextField: { template: '<input />' },
}

const SCHEMAS = [{ slug: 'pet', title: 'Pet' }, { slug: 'owner' }]
const EXISTING = {
	id: 'doc-pet-1',
	schema: 'pet',
	templateId: 'tpl-1',
	templateName: 'Pet card',
	label: 'Card',
	format: 'pdf',
	filenameTemplate: 'pet-{id}',
}

function mountDialog(props = {}) {
	return mount(Dialog, { props: { schemas: SCHEMAS, ...props }, global: { stubs } })
}

async function filled(props = {}, data = {}) {
	const wrapper = mountDialog(props)
	await wrapper.setData({
		templateOption: { uuid: 'tpl-2', label: 'Letter', name: 'Letter' },
		schemaOption: { slug: 'pet', label: 'Pet' },
		label: '  Letter  ',
		...data,
	})
	return wrapper
}

describe('DocumentTemplateAttachmentDialog — form', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		fetchTemplates.mockReset()
		fetchTemplates.mockResolvedValue([{ id: 'tpl-9', name: 'Invoice' }])
	})

	it('offers schema titles, falling back to the slug, and a template-default format first', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.schemaOptions).toEqual([
			{ label: 'Pet', slug: 'pet' },
			{ label: 'owner', slug: 'owner' },
		])
		expect(wrapper.vm.formatOptions[0]).toEqual({ label: 'Template default', value: '' })
		expect(wrapper.vm.formatOptions.slice(1).map((f) => f.value)).toEqual(['pdf', 'docx', 'html', 'email'])
		expect(wrapper.vm.formatOptions[1].label).toBe('PDF')
	})

	it('loads the templates when opened and maps them to options', async () => {
		const wrapper = mountDialog()
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(fetchTemplates).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.templateOptions).toEqual([{ label: 'Invoice', uuid: 'tpl-9', name: 'Invoice' }])
		expect(wrapper.vm.loadingTemplates).toBe(false)
		expect(axiosGet).not.toHaveBeenCalled()
	})

	it('loads nothing when Docudesk is unavailable', async () => {
		const wrapper = mountDialog({ docudeskAvailable: false })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(fetchTemplates).not.toHaveBeenCalled()
		expect(wrapper.vm.canPreview).toBe(false)
	})

	it('hydrates every field from the attachment being edited', async () => {
		axiosGet.mockResolvedValue({ data: {} })
		const wrapper = mountDialog({ attachment: EXISTING })
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(wrapper.vm.editing).toBe(true)
		expect(wrapper.vm.templateOption).toEqual({ label: 'Pet card', uuid: 'tpl-1', name: 'Pet card' })
		expect(wrapper.vm.schemaOption).toEqual({ label: 'pet', slug: 'pet' })
		expect(wrapper.vm.label).toBe('Card')
		expect(wrapper.vm.formatOption).toEqual({ label: 'PDF', value: 'pdf' })
		expect(wrapper.vm.filenameTemplate).toBe('pet-{id}')
	})

	it('hydrates an attachment without a format as the template default', async () => {
		axiosGet.mockResolvedValue({ data: {} })
		const { format, filenameTemplate, label, ...rest } = EXISTING
		const wrapper = mountDialog({ attachment: rest })
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(wrapper.vm.formatOption).toEqual({ label: 'Template default', value: '' })
		expect(wrapper.vm.label).toBe('')
		expect(wrapper.vm.filenameTemplate).toBe('')
	})

	it('clears the form when opened to add', async () => {
		const wrapper = await filled()
		await wrapper.setProps({ open: true })

		expect(wrapper.vm.templateOption).toBeNull()
		expect(wrapper.vm.schemaOption).toBeNull()
		expect(wrapper.vm.label).toBe('')
	})

	it('renames the template from its current record when editing', async () => {
		axiosGet.mockResolvedValue({ data: { title: 'Pet card v2' } })
		const wrapper = mountDialog({ attachment: EXISTING })
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(axiosGet).toHaveBeenCalledWith('/apps/filinq/api/templates/tpl-1')
		expect(wrapper.vm.templateOption).toEqual({ label: 'Pet card v2', uuid: 'tpl-1', name: 'Pet card v2' })
		expect(wrapper.vm.templateMissing).toBe(false)
	})

	it('flags a deleted template only on a 404', async () => {
		axiosGet.mockRejectedValueOnce({ response: { status: 404 } })
		const wrapper = mountDialog({ attachment: EXISTING })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(wrapper.vm.templateMissing).toBe(true)

		axiosGet.mockRejectedValueOnce({ response: { status: 500 } })
		const other = mountDialog({ attachment: EXISTING })
		await other.setProps({ open: true })
		await flushPromises()
		expect(other.vm.templateMissing).toBe(false)
		expect(other.vm.templateOption.name).toBe('Pet card')
	})

	it('refuses a label already used for the same schema by another attachment', async () => {
		const wrapper = await filled({ attachments: [{ id: 'x', schema: 'pet', label: 'Letter' }] })
		expect(wrapper.vm.duplicateLabel).toBe(true)
		expect(wrapper.vm.canSave).toBe(false)

		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('allows the same label on another schema, or on the attachment being edited', async () => {
		const otherSchema = await filled({ attachments: [{ id: 'x', schema: 'owner', label: 'Letter' }] })
		expect(otherSchema.vm.duplicateLabel).toBe(false)

		const self = await filled({ attachment: { ...EXISTING, id: 'x' }, attachments: [{ id: 'x', schema: 'pet', label: 'Letter' }] })
		expect(self.vm.duplicateLabel).toBe(false)
		expect(self.vm.canSave).toBe(true)
	})

	it('cannot save without a template, a schema or a non-blank label', async () => {
		expect((await filled({}, { templateOption: null })).vm.canSave).toBe(false)
		expect((await filled({}, { schemaOption: null })).vm.canSave).toBe(false)
		expect((await filled({}, { label: '   ' })).vm.canSave).toBe(false)
	})

	it('saves a trimmed entry with format and filename, and closes', async () => {
		const wrapper = await filled({}, {
			formatOption: { label: 'DOCX', value: 'docx' },
			filenameTemplate: '  out-{id}  ',
			addActionsTab: true,
		})
		vi.spyOn(Date, 'now').mockReturnValue(1234)

		wrapper.vm.onSave()
		vi.restoreAllMocks()

		expect(wrapper.emitted('save')).toEqual([[{
			entry: {
				id: 'doc-pet-1234',
				schema: 'pet',
				templateId: 'tpl-2',
				templateName: 'Letter',
				label: 'Letter',
				format: 'docx',
				filenameTemplate: 'out-{id}',
			},
			addActionsTab: true,
		}]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('keeps the edited id and leaves out a default format and blank filename', async () => {
		const wrapper = await filled({ attachment: EXISTING }, {
			templateOption: { uuid: 'tpl-2', label: 'Letter label' },
			formatOption: { label: 'Template default', value: '' },
			filenameTemplate: '   ',
		})

		wrapper.vm.onSave()

		expect(wrapper.emitted('save')[0][0].entry).toEqual({
			id: 'doc-pet-1',
			schema: 'pet',
			templateId: 'tpl-2',
			templateName: 'Letter label',
			label: 'Letter',
		})
	})

	it('only closes on cancel', () => {
		const wrapper = mountDialog()
		wrapper.vm.onClose()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})
