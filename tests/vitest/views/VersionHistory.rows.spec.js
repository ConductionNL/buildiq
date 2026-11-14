/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest unit tests pinning `src/views/VersionHistory.vue`'s list read
 * (`refresh`: envelopes, parent filter, archived rows, production-first then
 * newest-first order), its row id fallbacks (`rowUuid`) and the release flow
 * (`release`: guards, success and every failure message).
 */

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, showErrorMock, showSuccessMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
	showErrorMock: vi.fn(),
	showSuccessMock: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: showErrorMock, showSuccess: showSuccessMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))

import VersionHistory from '../../../src/views/VersionHistory.vue'

/**
 * Mount the panel without a slug (no read on mount).
 *
 * @param {object} props Props.
 * @return {object} The wrapper.
 */
function mountPanel(props = {}) {
	return shallowMount(VersionHistory, { propsData: props })
}

describe('VersionHistory refresh', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('clears the list and reads nothing without a slug', async () => {
		const wrapper = mountPanel()
		wrapper.vm.versions = [{ id: 'x' }]
		await wrapper.vm.refresh()
		expect(wrapper.vm.versions).toEqual([])
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it.each([
		['a bare array', [{ id: 'a' }], ['a']],
		['a results envelope', { results: [{ id: 'b' }] }, ['b']],
		['an envelope without results', { total: 0 }, []],
		['null', null, []],
	])('reads %s', async (label, data, ids) => {
		const wrapper = mountPanel()
		axiosMock.get.mockResolvedValue({ data })
		await wrapper.setProps({ appSlug: 'my-app' })
		await wrapper.vm.refresh()
		expect(axiosMock.get).toHaveBeenLastCalledWith('/apps/buildiq/api/applications/my-app/versions')
		expect(wrapper.vm.versions.map((r) => r.id)).toEqual(ids)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('drops other parents and archived rows, then puts production first and the newest next', async () => {
		const wrapper = mountPanel({ applicationUuid: 'app-1', currentVersionUuid: 'prod' })
		axiosMock.get.mockResolvedValue({
			data: [
				{ id: 'old', publishedAt: '2026-01-01' },
				{ id: 'other', application: 'app-2', publishedAt: '2026-09-01' },
				{ id: 'mine', application: 'app-1', publishedAt: '2026-05-01' },
				null,
				{ id: 'gone', status: 'archived', publishedAt: '2026-08-01' },
				{ id: 'undated' },
				{ id: 'prod', publishedAt: '2025-01-01' },
				{ id: 'same', publishedAt: '2026-05-01' },
			],
		})
		await wrapper.setProps({ appSlug: 'my-app' })
		await wrapper.vm.refresh()
		expect(wrapper.vm.versions.map((r) => r.id)).toEqual(['prod', 'mine', 'same', 'old', 'undated'])
	})

	it('keeps every row, null included, without a parent uuid', async () => {
		const wrapper = mountPanel()
		axiosMock.get.mockResolvedValue({ data: [{ id: 'a', application: 'x' }, { id: 'b' }] })
		await wrapper.setProps({ appSlug: 'my-app' })
		await wrapper.vm.refresh()
		expect(wrapper.vm.versions.map((r) => r.id).sort()).toEqual(['a', 'b'])
	})

	it('empties the list when the read fails', async () => {
		const wrapper = mountPanel()
		axiosMock.get.mockResolvedValueOnce({ data: [{ id: 'a' }] })
		await wrapper.setProps({ appSlug: 'my-app' })
		await wrapper.vm.refresh()
		axiosMock.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.refresh()
		expect(wrapper.vm.versions).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('VersionHistory rowUuid', () => {
	it.each([
		[null, ''],
		[{}, ''],
		[{ id: 'i', '@self': { id: 's' }, uuid: 'u' }, 'i'],
		[{ '@self': { id: 's', uuid: 'su' }, uuid: 'u' }, 's'],
		[{ '@self': { uuid: 'su' }, uuid: 'u' }, 'su'],
		[{ '@self': null, uuid: 'u' }, 'u'],
	])('of %j is %j', (row, expected) => {
		expect(mountPanel().vm.rowUuid(row)).toBe(expected)
	})
})

describe('VersionHistory release', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		showErrorMock.mockReset()
		showSuccessMock.mockReset()
		axiosMock.get.mockResolvedValue({ data: [] })
	})

	it.each([
		['no app slug', {}, { slug: 'v1' }, ''],
		['no version slug', { appSlug: 'my-app' }, { id: 'x' }, ''],
		['another release running', { appSlug: 'my-app' }, { slug: 'v1' }, 'busy'],
	])('does nothing with %s', async (label, props, row, releasing) => {
		const wrapper = mountPanel(props)
		wrapper.vm.releasing = releasing
		await wrapper.vm.release(row)
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.releasing).toBe(releasing)
	})

	it('posts the release, tells the maker, emits released and reloads', async () => {
		const wrapper = mountPanel({ appSlug: 'my-app' })
		axiosMock.get.mockClear()
		let releasingDuringPost = null
		axiosMock.post.mockImplementation(async () => {
			releasingDuringPost = wrapper.vm.releasing
			return {}
		})
		await wrapper.vm.release({ id: 'uuid-1', slug: 'v1', name: 'One' })
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/my-app/versions/v1/release', {})
		expect(releasingDuringPost).toBe('uuid-1')
		expect(showSuccessMock).toHaveBeenCalledWith('“{name}” is now the production version.')
		expect(wrapper.emitted('released')).toHaveLength(1)
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.releasing).toBe('')
	})

	it.each([
		['the server detail', { response: { data: { detail: 'Not owner' } }, message: 'M' }, 'Release failed: Not owner'],
		['the request message', { response: { data: {} }, message: 'Network Error' }, 'Release failed: Network Error'],
		['no detail', {}, 'Release failed'],
		['a thrown null', null, 'Release failed'],
	])('shows %s on failure', async (label, thrown, expected) => {
		const wrapper = mountPanel({ appSlug: 'my-app' })
		axiosMock.post.mockImplementation(async () => {
			throw thrown
		})
		await wrapper.vm.release({ slug: 'v1' })
		expect(showErrorMock).toHaveBeenCalledWith(expected)
		expect(wrapper.emitted('released')).toBeUndefined()
		expect(wrapper.vm.releasing).toBe('')
	})
})
