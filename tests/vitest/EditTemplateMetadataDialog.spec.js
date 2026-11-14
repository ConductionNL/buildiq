/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/EditTemplateMetadataDialog.vue`.
 *
 * Pins the metadata-only save: the PUT url (record uuid from `@self.id`,
 * `uuid` or `id`), the patch carrying the existing record over, the
 * sourceUrl keep/drop branch, the category options and the error message
 * fallback chain.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { put: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import EditTemplateMetadataDialog from '../../src/dialogs/EditTemplateMetadataDialog.vue'

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcTextField: { name: 'NcTextField', template: '<input />' },
	NcTextArea: { name: 'NcTextArea', template: '<textarea />' },
	NcSelect: { name: 'NcSelect', template: '<select />' },
}

const TEMPLATE = {
	slug: 'my-permits',
	title: 'My permits',
	useCase: 'Permits',
	description: 'Permits app',
	category: 'field-work',
	sourceUrl: 'https://example.org',
	manifest: { pages: [] },
	'@self': { id: 'tpl-1' },
}

/**
 * @param {object} template the template prop.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function openDialog(template = TEMPLATE) {
	const wrapper = mount(EditTemplateMetadataDialog, {
		props: { open: false, template },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('EditTemplateMetadataDialog.vue', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
	})

	it('offers the four template categories with their labels', async () => {
		const wrapper = await openDialog()

		expect(wrapper.vm.categoryOptions).toEqual([
			{ id: 'government-services', label: 'Government services' },
			{ id: 'internal-operations', label: 'Internal operations' },
			{ id: 'citizen-engagement', label: 'Citizen engagement' },
			{ id: 'field-work', label: 'Field work' },
		])
		expect(wrapper.vm.categoryOption).toEqual({ id: 'field-work', label: 'Field work' })
	})

	it('PUTs the patched record to the @self id and keeps the sourceUrl', async () => {
		const wrapper = await openDialog()
		wrapper.vm.form.title = '  Renamed  '
		axiosMock.put.mockResolvedValueOnce({ data: {} })

		await wrapper.vm.save()

		const [url, patch] = axiosMock.put.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/buildiq/application-template/tpl-1')
		expect(patch).toMatchObject({ title: 'Renamed', manifest: { pages: [] }, sourceUrl: 'https://example.org' })
		expect(wrapper.emitted('saved')[0][0]).toEqual({ slug: 'my-permits' })
		expect(wrapper.emitted('update:open').at(-1)).toEqual([false])
	})

	it('falls back to uuid, then id, and drops an emptied sourceUrl', async () => {
		const wrapper = await openDialog({ slug: 's', title: 'T', category: 'field-work', sourceUrl: 'x', uuid: 'u 1' })
		wrapper.vm.form.sourceUrl = ''
		axiosMock.put.mockResolvedValue({ data: {} })

		await wrapper.vm.save()
		await wrapper.setProps({ template: { slug: 's', title: 'T', category: 'field-work', id: 'id-2' } })
		await wrapper.vm.save()

		expect(axiosMock.put.mock.calls[0][0]).toBe('/apps/openregister/api/objects/buildiq/application-template/u%201')
		expect(axiosMock.put.mock.calls[0][1]).not.toHaveProperty('sourceUrl')
		expect(axiosMock.put.mock.calls[1][0]).toBe('/apps/openregister/api/objects/buildiq/application-template/id-2')
	})

	it.each([
		[{ response: { data: { detail: 'D', error: 'E' } }, message: 'M' }, 'D'],
		[{ response: { data: { error: 'E' } }, message: 'M' }, 'E'],
		[{ message: 'M' }, 'M'],
		[{}, 'Saving the template failed.'],
	])('reports a failed save as %j -> %s', async (error, expected) => {
		const wrapper = await openDialog()
		axiosMock.put.mockRejectedValueOnce(error)

		await wrapper.vm.save()

		expect(wrapper.vm.saveError).toBe(expected)
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
