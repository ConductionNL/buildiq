// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Pins the KPI row of ApplicationDetailDashboard: the four link cards (href,
 * title, aria-label, clickable class, arrow icon) and the props each stats
 * block gets, while loading and once loaded, with and without a register,
 * and with an error. Scoped-style `data-v-*` attributes and template
 * comments (dropped from production builds) are left out, and so is the
 * whitespace between tags (the row is a grid, which ignores it); `v-if`
 * placeholders are kept.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: [] }) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import ApplicationDetailDashboard from '../../../src/components/applicationDetail/ApplicationDetailDashboard.vue'

const route = { name: 'VirtualAppDetail', params: { objectId: 'app-uuid' }, query: {} }

/**
 * @param {object} application the application record.
 * @param {object} state data to set after mounting.
 * @return {Promise<string>} the KPI row markup.
 */
async function kpiRow(application, state) {
	const wrapper = shallowMount(ApplicationDetailDashboard, {
		props: { object: application, objectId: 'app-uuid' },
		global: {
			mocks: { $router: { push: vi.fn(), replace: vi.fn().mockResolvedValue(undefined) }, $route: route },
		},
	})
	await wrapper.setData(state)
	return wrapper.find('.ob-detail-dashboard__kpis').html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/<!--(?!v-if-->)[\s\S]*?-->/g, '')
		.replace(/>\s+</g, '><')
}

const PLAIN = { uuid: 'app-uuid', slug: 'hello-world', name: 'Hello World' }
const HYBRID = { ...PLAIN, appType: 'hybrid' }
const KPIS = { activeUsers: 3, objectCount: 12, filesCount: 2048, auditEventCount: 7 }

describe('ApplicationDetailDashboard KPI row', () => {
	it('renders the loading cards without a register', async () => {
		expect(await kpiRow(PLAIN, { loaded: false })).toMatchSnapshot()
	})

	it('renders the loaded cards as register links', async () => {
		expect(await kpiRow(HYBRID, { loaded: true, kpis: KPIS })).toMatchSnapshot()
	})

	it('renders the cards with an error', async () => {
		expect(await kpiRow(HYBRID, { loaded: true, error: 'boom', kpis: KPIS })).toMatchSnapshot()
	})
})
