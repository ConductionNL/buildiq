// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

import { useInsightsWindow } from '../../../src/composables/useInsightsWindow.js'

// Stub axios + nextcloud helpers before importing the component.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: [] }),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import ApplicationDetailHeader from '../../../src/components/applicationDetail/ApplicationDetailHeader.vue'

const t = (app, key, vars) => {
	if (!vars) return key
	let out = key
	for (const k of Object.keys(vars)) {
		out = out.replace(`{${k}}`, String(vars[k]))
	}
	return out
}

const router = { push: vi.fn(), replace: vi.fn().mockResolvedValue(undefined) }
const route = {
	name: 'VirtualAppDetail',
	params: { objectId: 'app-uuid' },
	query: {},
}

/**
 * Spec: buildiq-app-detail-overview / application-detail-overview
 * REQ-OBADO-001 (identity + controls header), REQ-OBADO-002 (pill ordering),
 * REQ-OBADO-003 (window toggle). The KPI / activity / structural / banner rows
 * moved to ApplicationDetailDashboard (grid-built body) — see its spec.
 *
 * Mount-only assertions — the integration behaviour (HTTP fan-out, real
 * routing) lives in the Playwright spec.
 */
describe('ApplicationDetailHeader', () => {
	// The window selection is a process-wide singleton shared with the body
	// dashboard; reset it so test order doesn't leak the 30d selection.
	beforeEach(() => {
		useInsightsWindow().selectedWindow.value = '7d'
	})

	const application = {
		uuid: 'app-uuid',
		slug: 'hello-world',
		name: 'Hello World',
		description: 'A demo app',
		status: 'published',
		productionVersion: 'prod-uuid',
		permissions: {
			owners: ['user:alice'],
			editors: [],
			viewers: [],
		},
	}

	it('mounts and renders the hero strip', () => {
		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		expect(wrapper.find('.ob-detail-header').exists()).toBe(true)
		expect(wrapper.text()).toContain('Hello World')

		// The insights time-range toggle moved out of the header into the body
		// dashboard's KPI strip — the header no longer renders it.
		expect(wrapper.findAll('.ob-detail-header__window-btn').length).toBe(0)
	})

	it('renders pill tabs for each version in chain order, with production starred', async () => {
		const versions = [
			{
				uuid: 'dev-uuid',
				slug: 'development',
				promotesTo: 'staging-uuid',
				name: 'development',
			},
			{
				uuid: 'staging-uuid',
				slug: 'staging',
				promotesTo: 'prod-uuid',
				name: 'staging',
			},
			{
				uuid: 'prod-uuid',
				slug: 'production',
				promotesTo: null,
				name: 'production',
			},
		]

		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		// alice is an owner — non-production pills should be visible.
		wrapper.vm.callerUid = 'alice'
		wrapper.vm.versions = versions
		await wrapper.vm.$nextTick()

		const pills = wrapper.findAll('.ob-detail-header__pill')
		expect(pills.length).toBe(3)
		// Chain order — development first, production last.
		expect(pills.at(0).text()).toContain('development')
		expect(pills.at(2).text()).toContain('production')
		// Production carries the asterisk marker.
		expect(pills.at(2).text()).toContain('*')

		// Promote affordances render on non-terminal pills only.
		const promotes = wrapper.findAll('.ob-detail-header__pill-promote')
		expect(promotes.length).toBe(2)
	})

	it('hides non-production pills from a viewer (REQ-OBADO-002 hidden)', async () => {
		const viewerApp = {
			...application,
			permissions: { owners: [], editors: [], viewers: ['user:bob'] },
		}
		const versions = [
			{ uuid: 'dev-uuid', slug: 'development', promotesTo: 'prod-uuid' },
			{ uuid: 'prod-uuid', slug: 'production', promotesTo: null },
		]
		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: viewerApp, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		// Let the mounted hook's version fetch settle FIRST. It assigns
		// `this.versions` itself, so seeding the field while that request is
		// still in flight just gets overwritten — `visibleVersions` then filters
		// an empty list and the assertion reads 0. vitest 1 happened to resolve
		// these in the opposite order.
		await flushPromises()
		// Wire the OC currentUser for the viewer.
		wrapper.vm.callerUid = 'bob'
		wrapper.vm.versions = versions
		await wrapper.vm.$nextTick()

		const visible = wrapper.vm.visibleVersions
		expect(visible.length).toBe(1)
		expect(visible[0].slug).toBe('production')
	})
})
