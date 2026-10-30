/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQDM-006: the app detail page shows the version's performance and errors.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => axiosGet(...args) } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (p) => p }))

const ApplicationHealthSection = (await import('../../../src/components/applicationDetail/ApplicationHealthSection.vue')).default

describe('ApplicationHealthSection', () => {
	beforeEach(() => axiosGet.mockReset())

	it('reads the version health for the window and lists the slowest page first, by its title', async () => {
		axiosGet.mockResolvedValue({
			data: {
				requests: 60,
				failures: 3,
				failureShare: 0.05,
				slowRequestShare: 0.08,
				loads: 15,
				averageLoadMs: 1600,
				slowLoadShare: 0.13,
				slowestPages: [{ pageId: 'permit-detail', averageLoadMs: 2400, slowLoadShare: 0.2, loads: 10, failures: 1 }],
			},
		})
		const wrapper = mount(ApplicationHealthSection, {
			propsData: { appUuid: 'app-1', versionUuid: 'ver-1', window: '7d', pageTitles: { 'permit-detail': 'Permit' } },
		})
		await flushPromises()

		expect(axiosGet).toHaveBeenCalledWith('/apps/buildiq/api/applications/app-1/versions/ver-1/health', { params: { window: '7d' } })
		const firstRow = wrapper.find('[data-testid="app-health-pages"] tbody tr')
		expect(firstRow.text()).toContain('Permit')
		expect(wrapper.find('[data-testid="app-health-figures"]').exists()).toBe(true)
	})

	it('says so when nothing was reported, and reloads for another window', async () => {
		axiosGet.mockResolvedValue({ data: { loads: 0, slowestPages: [] } })
		const wrapper = mount(ApplicationHealthSection, { propsData: { appUuid: 'app-1', versionUuid: 'ver-1', window: '7d' } })
		await flushPromises()
		expect(wrapper.find('[data-testid="app-health-empty"]').exists()).toBe(true)

		await wrapper.setProps({ window: '30d' })
		await flushPromises()
		expect(axiosGet).toHaveBeenLastCalledWith(expect.any(String), { params: { window: '30d' } })
	})
})
