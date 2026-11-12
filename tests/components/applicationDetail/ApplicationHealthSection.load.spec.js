/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins ApplicationHealthSection::load: no read without both ids, the read
 * for the window, a non-object body as no health, and the error state.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGet(...args) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const ApplicationHealthSection = (
	await import('../../../src/components/applicationDetail/ApplicationHealthSection.vue')
).default

async function section(props) {
	const wrapper = shallowMount(ApplicationHealthSection, { props })
	await flushPromises()
	return wrapper.vm
}

describe('ApplicationHealthSection load', () => {
	beforeEach(() => axiosGet.mockReset())

	it.each([[{}], [{ appUuid: 'a' }], [{ versionUuid: 'v' }]])('reads nothing with %j', async (props) => {
		const vm = await section(props)
		await vm.load()
		expect(axiosGet).not.toHaveBeenCalled()
		expect(vm.state).toBe('idle')
	})

	it('reads the version health for the window', async () => {
		axiosGet.mockResolvedValue({ data: { pages: [] } })
		const vm = await section({ appUuid: 'a b', versionUuid: 'v/1', window: '30d' })
		expect(axiosGet).toHaveBeenCalledWith('/apps/buildiq/api/applications/a%20b/versions/v%2F1/health', {
			params: { window: '30d' },
		})
		expect(vm.health).toEqual({ pages: [] })
		expect(vm.state).toBe('done')
	})

	it.each([[null], ['text'], [7]])('treats a %j body as no health', async (data) => {
		axiosGet.mockResolvedValue({ data })
		const vm = await section({ appUuid: 'a', versionUuid: 'v' })
		expect(vm.health).toBe(null)
		expect(vm.state).toBe('done')
	})

	it('shows the error state when the read fails', async () => {
		axiosGet.mockResolvedValueOnce({ data: { pages: [] } })
		const vm = await section({ appUuid: 'a', versionUuid: 'v' })
		axiosGet.mockRejectedValueOnce(new Error('down'))
		await vm.load()
		expect(vm.health).toBe(null)
		expect(vm.state).toBe('error')
	})
})
