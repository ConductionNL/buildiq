/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins ApplicationHealthSection's reload on another application or version,
 * and how an unknown duration or share reads.
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

describe('ApplicationHealthSection reload and figures', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosGet.mockResolvedValue({ data: { pages: [] } })
	})

	it('reads again for another application and for another version', async () => {
		const wrapper = shallowMount(ApplicationHealthSection, { props: { appUuid: 'a', versionUuid: 'v' } })
		await flushPromises()
		expect(axiosGet).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ appUuid: 'b' })
		await flushPromises()
		expect(axiosGet).toHaveBeenCalledTimes(2)
		expect(axiosGet.mock.calls[1][0]).toBe('/apps/buildiq/api/applications/b/versions/v/health')

		await wrapper.setProps({ versionUuid: 'w' })
		await flushPromises()
		expect(axiosGet).toHaveBeenCalledTimes(3)
		expect(axiosGet.mock.calls[2][0]).toBe('/apps/buildiq/api/applications/b/versions/w/health')
	})

	it('says an unknown duration or share is not known, and writes known ones as figures', async () => {
		const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
		const wrapper = shallowMount(ApplicationHealthSection, { props: {}, global: { mocks: { t } } })
		await flushPromises()
		const vm = wrapper.vm

		expect(vm.seconds(null)).toBe('Not known')
		expect(vm.seconds(undefined)).toBe('Not known')
		expect(vm.percent(null)).toBe('Not known')
		expect(vm.percent(undefined)).toBe('Not known')
		expect(vm.seconds(2400)).toMatch(/^2[.,]4 s$/)
		expect(vm.percent(0.04)).toMatch(/^4\s?%$/)
	})
})
