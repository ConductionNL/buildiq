/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/UserDeltaEditModal.vue`: the JSON
 * validation, the owner-scoped PUT and the error messages of `save()`.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { put: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import UserDeltaEditModal from '../../src/modals/UserDeltaEditModal.vue'

const STUBS = {
	NcModal: { template: '<div><slot /></div>' },
	NcButton: { template: '<button><slot /></button>' },
	CnJsonViewer: true,
}

/**
 * Mount the modal with a slug and a raw JSON draft.
 *
 * @param {object} opts `{ appSlug, draft }`.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountModal({ appSlug = 'petstore', draft = '{}' } = {}) {
	const wrapper = mount(UserDeltaEditModal, {
		propsData: { open: false, appSlug },
		stubs: STUBS,
	})
	wrapper.vm.draft = draft
	return wrapper
}

describe('UserDeltaEditModal.vue save()', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
	})

	it('does nothing without an app slug', async () => {
		const wrapper = mountModal({ appSlug: '' })
		await wrapper.vm.save()
		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('')
	})

	it('refuses a draft that is not valid JSON', async () => {
		const wrapper = mountModal({ draft: '{nope' })
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('The delta is not valid JSON.')
		expect(axiosMock.put).not.toHaveBeenCalled()
	})

	it.each(['null', '[]', '"text"', '3'])('refuses the non-object draft %s', async (draft) => {
		const wrapper = mountModal({ draft })
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('The delta must be a JSON object.')
		expect(axiosMock.put).not.toHaveBeenCalled()
	})

	it('PUTs an empty object for a blank draft, then emits saved and closes', async () => {
		axiosMock.put.mockResolvedValueOnce({ data: {} })
		const wrapper = mountModal({ draft: '   ' })
		await wrapper.vm.save()
		expect(axiosMock.put).toHaveBeenCalledWith(
			'/apps/buildiq/api/app-overrides/petstore/user',
			{},
		)
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('PUTs the parsed draft', async () => {
		axiosMock.put.mockResolvedValueOnce({ data: {} })
		const wrapper = mountModal({ draft: '{"pages":{"a":1}}' })
		await wrapper.vm.save()
		expect(axiosMock.put.mock.calls[0][1]).toEqual({ pages: { a: 1 } })
	})

	it.each([
		[{ response: { data: { detail: 'D', error: 'E' } } }, 'Could not save your override: D'],
		[{ response: { data: { error: 'E' } } }, 'Could not save your override: E'],
		[{ response: { data: {} } }, 'Could not save your override'],
		[{ response: {} }, 'Could not save your override'],
		[null, 'Could not save your override'],
	])('reports the failure %j as %s', async (err, message) => {
		axiosMock.put.mockRejectedValueOnce(err)
		const wrapper = mountModal()
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
