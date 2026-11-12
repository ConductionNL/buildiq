/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PermissionHistoryModal's failure handling and row display.
 *
 * Covers:
 *  - opening loads the trail; a closed modal reads nothing.
 *  - a 401/403 shows an empty history silently; any other failure says so.
 *  - administrator-bypass rows are marked and labelled; other events keep their name.
 *  - timestamps are formatted, blank ones stay blank.
 *  - before/after are pretty-printed, and a value JSON cannot hold is still shown.
 *  - closing emits update:open false.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
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

function mountModal(open = false) {
	return shallowMount(PermissionHistoryModal, { props: { open, applicationUuid: 'app-1' } })
}

beforeEach(() => {
	axiosMock.get.mockReset()
	showErrorMock.mockReset()
})

describe('PermissionHistoryModal display', () => {
	it('reads nothing while closed and loads when opened', async () => {
		axiosMock.get.mockResolvedValue({ data: [{ id: 'e1', event: 'rbac.admin_bypass' }] })
		const wrapper = mountModal(false)
		await flushPromises()
		expect(axiosMock.get).not.toHaveBeenCalled()

		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.entries.map((e) => e.id)).toEqual(['e1'])
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([401, 403])('a %i shows an empty history without an error', async (status) => {
		axiosMock.get.mockRejectedValue({ response: { status } })
		const wrapper = mountModal(true)
		wrapper.vm.entries = [{ id: 'stale' }]
		await wrapper.vm.load()
		expect(wrapper.vm.entries).toEqual([])
		expect(showErrorMock).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
	})

	it('any other failure is reported', async () => {
		axiosMock.get.mockRejectedValue({ response: { status: 500 } })
		const wrapper = mountModal(true)
		await flushPromises()
		expect(showErrorMock).toHaveBeenCalledWith('Failed to load permission history')

		showErrorMock.mockReset()
		axiosMock.get.mockRejectedValue(new Error('offline'))
		await wrapper.vm.load()
		expect(showErrorMock).toHaveBeenCalledTimes(1)
	})

	it('marks and labels an administrator bypass', () => {
		const wrapper = mountModal()
		const bypass = { event: 'rbac.admin_bypass' }
		expect(wrapper.vm.rowClass(bypass)).toBe('buildiq-permission-history__row--bypass')
		expect(wrapper.vm.eventLabel(bypass)).toBe('Administrator bypass')
	})

	it('labels a permission change and keeps any other event name', () => {
		const wrapper = mountModal()
		expect(wrapper.vm.rowClass({ event: 'permissions.changed' })).toBe('')
		expect(wrapper.vm.eventLabel({ event: 'permissions.changed' })).toBe('Permissions changed')
		expect(wrapper.vm.eventLabel({ event: 'schema.updated' })).toBe('schema.updated')
	})

	it('formats a timestamp and leaves a blank one blank', () => {
		const wrapper = mountModal()
		expect(wrapper.vm.formatStamp('2026-01-02T03:04:05Z')).toBe(new Date('2026-01-02T03:04:05Z').toLocaleString())
		expect(wrapper.vm.formatStamp('')).toBe('')
		expect(wrapper.vm.formatStamp(null)).toBe('')
	})

	it('pretty-prints a side, and still shows one JSON cannot hold', () => {
		const wrapper = mountModal()
		expect(wrapper.vm.pretty({ read: ['staff'] })).toBe('{\n  "read": [\n    "staff"\n  ]\n}')
		const cyclic = {}
		cyclic.self = cyclic
		expect(wrapper.vm.pretty(cyclic)).toBe('[object Object]')
	})

	it('closing asks the parent to close it', () => {
		const wrapper = mountModal()
		wrapper.vm.onClose()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
