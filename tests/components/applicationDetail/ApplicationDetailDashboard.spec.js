// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount } from '@vue/test-utils'

// Stub axios + nextcloud helpers before importing the component.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: [] }),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import ApplicationDetailDashboard from '../../../src/components/applicationDetail/ApplicationDetailDashboard.vue'
import { useInsightsWindow } from '../../../src/composables/useInsightsWindow.js'

const t = (app, key, vars) => {
	if (!vars) return key
	let out = key
	for (const k of Object.keys(vars)) {
		out = out.replace(`{${k}}`, String(vars[k]))
	}
	return out
}

const router = { push: vi.fn(), replace: vi.fn().mockResolvedValue(undefined) }
const route = { name: 'VirtualAppDetail', params: { objectId: 'app-uuid' }, query: {} }

/**
 * Spec: openbuild-app-detail-overview / application-detail-overview.
 *
 * The grid-built body dashboard (CnDetailPage #before-body slot) owns the KPI
 * grid, activity graph, structural widgets, and the version-no-longer-accessible
 * banner — the rows that used to live in ApplicationDetailHeader above the
 * action line.
 */
describe('ApplicationDetailDashboard', () => {
	const application = {
		uuid: 'app-uuid',
		slug: 'hello-world',
		name: 'Hello World',
		productionVersion: 'prod-uuid',
		permissions: { owners: ['user:alice'], editors: [], viewers: [] },
	}

	const factory = () => shallowMount(ApplicationDetailDashboard, {
		propsData: { object: application, objectId: 'app-uuid' },
		mocks: { t, $router: router, $route: route },
	})

	beforeEach(() => {
		useInsightsWindow().selectedWindow.value = '7d'
	})

	it('renders the four KPI widgets', () => {
		const wrapper = factory()
		// CnStatsBlock is stubbed under shallowMount — assert the four KPI
		// widgets and their titles via the stub `title` prop. (Storage shows a
		// formatted byte size; before data loads it is the loading variant.)
		const kpis = wrapper.findAll('.ob-detail-dashboard__kpi')
		expect(kpis.length).toBe(4)
		// VTU v2 returns a plain array from findAll(); the v1 `.wrappers`
		// accessor no longer exists.
		const titles = kpis.map((w) => w.attributes('title'))
		expect(titles).toEqual(['Active users', 'Object count', 'Storage', 'Audit events'])
	})

	it('shows the empty-state activity message when activity is empty (REQ-OBADO-005)', () => {
		const wrapper = factory()
		expect(wrapper.text()).toContain('No activity in the selected window')
	})

	it('renders the structural widget grid', () => {
		const wrapper = factory()
		expect(wrapper.find('.ob-detail-dashboard__widgets').exists()).toBe(true)
	})

	it('renders the version-no-longer-accessible banner when 404 occurs', async () => {
		const wrapper = factory()
		wrapper.vm.versionNoLongerAccessible = true
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.ob-detail-dashboard__banner').exists()).toBe(true)
		expect(wrapper.text()).toContain('no longer accessible')
	})

	it('reads the shared insights window selected in the header', async () => {
		const wrapper = factory()
		useInsightsWindow().selectedWindow.value = '30d'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.selectedWindow).toBe('30d')
	})
})
