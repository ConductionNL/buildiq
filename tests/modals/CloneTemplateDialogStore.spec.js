/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: CloneTemplateDialog in store mode (REQ-BQRS-004), with only HTTP
 * mocked: a valid submit posts to the store install endpoint and emits
 * `installed`; a template that left the catalogue shows "This template is no
 * longer in the catalogue." and emits `stale`; other failures keep their message.
 *
 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-a-maker-installs-a-catalogue-template-req-bqrs-004
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { post: vi.fn() },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import CloneTemplateDialog from '../../src/modals/CloneTemplateDialog.vue'

function mountDialog() {
	const wrapper = mount(CloneTemplateDialog, {
		props: {
			open: true,
			remote: true,
			remoteSlug: 'vergunningen-volgen',
			template: { slug: 'vergunningen-volgen', title: 'Vergunningen volgen' },
		},
		global: {
			stubs: {
				NcModal: { template: '<div><slot /></div>' },
				NcButton: { template: '<button><slot /></button>' },
				NcTextField: { template: '<input />' },
			},
		},
	})
	wrapper.vm.localName = 'Vergunningen'
	wrapper.vm.localSlug = 'vergunningen'
	return wrapper
}

describe('CloneTemplateDialog store mode', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('installs through the store endpoint', async () => {
		axiosMock.post.mockResolvedValue({ data: { slug: 'vergunningen' } })
		const wrapper = mountDialog()

		await wrapper.vm.submit()
		await flushPromises()

		expect(axiosMock.post.mock.calls[0][0]).toBe('/apps/buildiq/api/store/templates/vergunningen-volgen/install')
		expect(wrapper.emitted('installed')[0][0]).toEqual({ slug: 'vergunningen' })
		expect(wrapper.emitted('stale')).toBeUndefined()
	})

	it('says a template left the catalogue and asks for a reload', async () => {
		axiosMock.post.mockRejectedValue({ response: { status: 404, data: { error: 'template_not_found' } } })
		const wrapper = mountDialog()

		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.vm.error).toBe('This template is no longer in the catalogue.')
		expect(wrapper.emitted('stale')).toHaveLength(1)
		expect(wrapper.emitted('installed')).toBeUndefined()
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('keeps any other failure as it was', async () => {
		axiosMock.post.mockRejectedValue({ response: { status: 409, data: { error: 'slug_taken', detail: 'The slug is already in use.' } } })
		const wrapper = mountDialog()

		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.vm.error).toBe('The slug is already in use.')
		expect(wrapper.emitted('stale')).toBeUndefined()
	})
})
