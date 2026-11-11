/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/PermissionHistoryModal.vue`.
 *
 * Covers:
 *   - `load()` reads the audit trail once with the permissions filter and
 *     unwraps both response envelopes.
 *   - A refused read (401 / 403) renders the empty state without a toast;
 *     any other failure shows the error toast.
 *   - `shape()` collapses every accepted field spelling to one row shape.
 */

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, showErrorMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn() },
	showErrorMock: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: showErrorMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import PermissionHistoryModal from '../../src/modals/PermissionHistoryModal.vue'

/**
 * Mount the modal closed, so the immediate watcher does not read.
 *
 * @return {object} The wrapper.
 */
function mountModal() {
	return shallowMount(PermissionHistoryModal, {
		propsData: { open: false, applicationUuid: 'app-1' },
		mocks: { t: (app, s) => s },
	})
}

/**
 * Build an axios-style failure with the given HTTP status.
 *
 * @param {number|undefined} status The response status.
 * @return {Error}
 */
function httpError(status) {
	const e = new Error('fail')
	e.response = status === undefined ? undefined : { status }
	return e
}

describe('PermissionHistoryModal', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		showErrorMock.mockReset()
	})

	it('reads the audit trail with the permissions filter and unwraps data.data', async () => {
		axiosMock.get.mockResolvedValue({ data: { data: [{ id: 'a', event: 'rbac.admin_bypass' }] } })
		const wrapper = mountModal()
		await wrapper.vm.load()
		expect(axiosMock.get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/built-app/app-1/audit',
			{ params: { filter: 'permissions,rbac.admin_bypass', limit: 50 } },
		)
		expect(wrapper.vm.entries).toEqual([
			{ id: 'a', actor: '', timestamp: '', event: 'rbac.admin_bypass', before: null, after: null },
		])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('unwraps a bare array body and treats an empty body as no rows', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: [{ uuid: 'u' }] })
		const wrapper = mountModal()
		await wrapper.vm.load()
		expect(wrapper.vm.entries.map((e) => e.id)).toEqual(['u'])
		axiosMock.get.mockResolvedValueOnce({ data: null })
		await wrapper.vm.load()
		expect(wrapper.vm.entries).toEqual([])
	})

	it.each([401, 403])('renders the empty state without a toast when the read is refused (%i)', async (status) => {
		axiosMock.get.mockRejectedValue(httpError(status))
		const wrapper = mountModal()
		wrapper.vm.entries = [{ id: 'stale' }]
		await wrapper.vm.load()
		expect(wrapper.vm.entries).toEqual([])
		expect(showErrorMock).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([500, 404, undefined])('shows the error toast and keeps the rows on any other failure (%s)', async (status) => {
		axiosMock.get.mockRejectedValue(httpError(status))
		const wrapper = mountModal()
		wrapper.vm.entries = [{ id: 'stale' }]
		await wrapper.vm.load()
		expect(wrapper.vm.entries).toEqual([{ id: 'stale' }])
		expect(showErrorMock).toHaveBeenCalledWith('Failed to load permission history')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shape() prefers the first present spelling of every field', () => {
		const wrapper = mountModal()
		const rows = wrapper.vm.shape([
			{
				id: 'i',
				uuid: 'u',
				actor: 'a',
				userId: 'b',
				timestamp: 't',
				createdAt: 'c',
				event: 'e',
				action: 'x',
				changes: { before: { b: 1 }, after: { a: 1 } },
				before: { b: 2 },
				after: { a: 2 },
			},
			{
				uuid: 'u2',
				userId: 'b2',
				createdAt: 'c2',
				action: 'x2',
				before: { b: 3 },
				after: { a: 3 },
			},
			{ id: null, user: 'c3', created: 'd3', changes: {}, after: false },
			{ actor: null, userId: undefined, user: 0, timestamp: null, createdAt: '' },
		])
		expect(rows).toEqual([
			{ id: 'i', actor: 'a', timestamp: 't', event: 'e', before: { b: 1 }, after: { a: 1 } },
			{ id: 'u2', actor: 'b2', timestamp: 'c2', event: 'x2', before: { b: 3 }, after: { a: 3 } },
			{ id: 'idx-2', actor: 'c3', timestamp: 'd3', event: 'permissions.changed', before: null, after: false },
			{ id: 'idx-3', actor: 0, timestamp: '', event: 'permissions.changed', before: null, after: null },
		])
	})
})
