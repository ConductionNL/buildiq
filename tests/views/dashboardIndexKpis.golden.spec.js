/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the three KPI tiles of the Buildiq dashboard (Apps, Hybrid apps,
 * Published versions): their markup and stats-block props, and that a click
 * or Enter on each opens the Apps index. Scoped-style `data-v-*` attributes
 * are left out.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: { results: [], total: 0 } }) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import DashboardIndex from '../../src/views/DashboardIndex.vue'

const stubs = {
	CnDashboardPage: {
		template: '<div class="page"><slot name="widget-apps" /><slot name="widget-hybrid" /><slot name="widget-versions" /></div>',
	},
	CnStatsBlock: true,
	CnDataTable: true,
	NcButton: true,
	NcEmptyContent: true,
	CreateApplicationWizard: true,
}

describe('DashboardIndex KPI tiles', () => {
	it('renders the three tiles and opens the Apps index from each', async () => {
		const push = vi.fn().mockResolvedValue(undefined)
		const wrapper = mount(DashboardIndex, { global: { stubs, mocks: { $router: { push } } } })
		await flushPromises()
		await wrapper.setData({ counts: { apps: 5, hybrid: 2, versions: 9 } })

		const tiles = wrapper.findAll('.ob-kpi-link')
		const html = tiles.map((tile) => tile.html().replace(/ data-v-[0-9a-f]+=""/g, ''))
		for (const tile of tiles) {
			await tile.trigger('click')
			await tile.trigger('keyup.enter')
		}

		expect(html).toMatchSnapshot()
		expect(push.mock.calls).toEqual(Array(6).fill([{ name: 'VirtualApps' }]))
	})
})
