/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The form half of WorkflowAttachmentDialog: case-type options, which schemas
 * and link properties are offered, hydration and the saved entry.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => axiosGet(...a) } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const Dialog = (await import('../../src/dialogs/WorkflowAttachmentDialog.vue')).default

const SCHEMAS = [
	{
		slug: 'pet',
		title: 'Pet',
		properties: {
			name: { type: 'string' },
			age: { type: 'integer' },
			homepage: { format: 'uri' },
			link: { type: 'object', format: 'url' },
			tags: { type: 'array' },
			empty: null,
		},
	},
	{ slug: 'owner' },
]
const EXISTING = {
	id: 'wf-pet-1',
	schema: 'pet',
	caseTypeUuid: 'ct-1',
	caseTypeName: 'Licence',
	linkProperty: 'name',
	descriptionTemplate: 'Pet {name}',
}

function mountDialog(props = {}) {
	return mount(Dialog, {
		props: { schemas: SCHEMAS, ...props },
		global: {
			mocks: { $route: { params: { slug: 'petstore' }, query: {} } },
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: { template: '<button><slot /></button>' },
				NcSelect: { template: '<div />' },
				NcTextField: { template: '<input />' },
			},
		},
	})
}

describe('WorkflowAttachmentDialog — form', () => {
	beforeEach(() => {
		axiosGet.mockReset()
	})

	it('loads the case types from dossiq when opened and labels them with their identifier', async () => {
		axiosGet.mockResolvedValue({
			data: {
				results: [
					{ uuid: 'u1', omschrijving: 'Licence', identificatie: 'LIC' },
					{ url: 'https://x/2', omschrijving: 'Permit' },
					{ omschrijving: 'Report', identificatie: 'REP' },
				],
			},
		})
		const wrapper = mountDialog()
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(axiosGet).toHaveBeenCalledTimes(1)
		expect(axiosGet.mock.calls[0][0]).toMatch(/^\/apps\/[^/]+\/api\/zgw\/catalogi\/v1\/zaaktypen$/)
		expect(wrapper.vm.caseTypeOptions).toEqual([
			{ label: 'Licence (LIC)', uuid: 'u1', name: 'Licence' },
			{ label: 'Permit', uuid: 'https://x/2', name: 'Permit' },
			{ label: 'Report (REP)', uuid: 'REP', name: 'Report' },
		])
		expect(wrapper.vm.loadingCaseTypes).toBe(false)
	})

	it('accepts a bare array and falls back to no case types on a non-array or a failure', async () => {
		axiosGet.mockResolvedValueOnce({ data: [{ uuid: 'u1', omschrijving: 'A' }] })
		const bare = mountDialog()
		await bare.setProps({ open: true })
		await flushPromises()
		expect(bare.vm.caseTypes).toHaveLength(1)

		axiosGet.mockResolvedValueOnce({ data: { results: 'nope' } })
		const odd = mountDialog()
		await odd.setProps({ open: true })
		await flushPromises()
		expect(odd.vm.caseTypes).toEqual([])

		axiosGet.mockRejectedValueOnce(new Error('down'))
		const failed = mountDialog()
		await failed.setProps({ open: true })
		await flushPromises()
		expect(failed.vm.caseTypes).toEqual([])
		expect(failed.vm.loadingCaseTypes).toBe(false)
	})

	it('does not call dossiq when Procest is unavailable', async () => {
		const wrapper = mountDialog({ procestAvailable: false })
		await wrapper.setProps({ open: true })
		expect(axiosGet).not.toHaveBeenCalled()
	})

	it('hides already attached schemas when adding, but not when editing', () => {
		expect(mountDialog({ attachedSchemas: ['pet'] }).vm.schemaOptions).toEqual([{ label: 'owner', slug: 'owner' }])
		expect(mountDialog({ attachedSchemas: ['pet'], attachment: EXISTING }).vm.schemaOptions).toEqual([
			{ label: 'Pet', slug: 'pet' },
			{ label: 'owner', slug: 'owner' },
		])
	})

	it('offers only string or uri/url properties of the picked schema as link property', async () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.linkPropertyOptions).toEqual([])

		await wrapper.setData({ schemaOption: { slug: 'pet', label: 'Pet' } })
		expect(wrapper.vm.linkPropertyOptions.map((o) => o.name)).toEqual(['name', 'homepage', 'link'])

		await wrapper.setData({ schemaOption: { slug: 'owner', label: 'owner' } })
		expect(wrapper.vm.linkPropertyOptions).toEqual([])
	})

	it('has no link route without an app slug', async () => {
		const wrapper = mount(Dialog, {
			props: { schemas: SCHEMAS },
			global: { mocks: { $route: { params: {} } }, stubs: { NcDialog: true, NcButton: true, NcSelect: true, NcTextField: true } },
		})
		await wrapper.setData({ schemaOption: { slug: 'pet' } })
		expect(wrapper.vm.createLinkPropertyRoute).toBeNull()
	})

	it('hydrates from the attachment being edited', async () => {
		axiosGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountDialog({ attachment: EXISTING })
		await wrapper.setData({ error: 'old' })
		await wrapper.setProps({ open: true })

		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.caseTypeOption).toEqual({ label: 'Licence', uuid: 'ct-1', name: 'Licence' })
		expect(wrapper.vm.schemaOption).toEqual({ label: 'pet', slug: 'pet' })
		expect(wrapper.vm.linkPropertyOption).toEqual({ label: 'name', name: 'name' })
		expect(wrapper.vm.descriptionTemplate).toBe('Pet {name}')
		expect(wrapper.vm.canSave).toBe(true)
	})

	it('clears the form when opened to add', async () => {
		axiosGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountDialog()
		await wrapper.setData({ schemaOption: { slug: 'pet' }, descriptionTemplate: 'x', addStatusTab: true })
		await wrapper.setProps({ open: true })

		expect(wrapper.vm.schemaOption).toBeNull()
		expect(wrapper.vm.descriptionTemplate).toBe('')
		expect(wrapper.vm.addStatusTab).toBe(false)
		expect(wrapper.vm.canSave).toBe(false)
	})

	it('does not save until a case type, schema and link property are picked', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ caseTypeOption: { uuid: 'u' }, schemaOption: { slug: 'pet' } })
		expect(wrapper.vm.canSave).toBe(false)
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('saves a new on-create entry and closes', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({
			caseTypeOption: { uuid: 'u1', name: 'Licence' },
			schemaOption: { slug: 'pet' },
			linkPropertyOption: { name: 'homepage' },
			addStatusTab: true,
		})
		vi.spyOn(Date, 'now').mockReturnValue(99)
		wrapper.vm.onSave()
		vi.restoreAllMocks()

		expect(wrapper.emitted('save')).toEqual([[{
			entry: { id: 'wf-pet-99', schema: 'pet', caseTypeUuid: 'u1', caseTypeName: 'Licence', trigger: 'on-create', linkProperty: 'homepage' },
			addStatusTab: true,
		}]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('keeps the edited id and carries the description template', async () => {
		axiosGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountDialog({ attachment: EXISTING })
		await wrapper.setProps({ open: true })
		wrapper.vm.onSave()

		expect(wrapper.emitted('save')[0][0]).toEqual({
			entry: { id: 'wf-pet-1', schema: 'pet', caseTypeUuid: 'ct-1', caseTypeName: 'Licence', trigger: 'on-create', linkProperty: 'name', descriptionTemplate: 'Pet {name}' },
			addStatusTab: false,
		})
	})

	it('only closes on cancel', () => {
		const wrapper = mountDialog()
		wrapper.vm.onClose()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})
