// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, vi } from 'vitest'
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
const route = { name: 'VirtualAppDetail', params: { objectId: 'app-uuid' }, query: {} }

/**
 * Spec: openbuilt-app-detail-overview / application-detail-overview
 * REQ-OBADO-001 (six rows), REQ-OBADO-002 (pill ordering), REQ-OBADO-003 (window toggle).
 *
 * Mount-only assertions — the integration behaviour (HTTP fan-out, real
 * routing) lives in the Playwright spec.
 */
describe('ApplicationDetailHeader', () => {
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

	it('mounts and renders the hero strip + window toggle controls', () => {
		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		expect(wrapper.find('.ob-detail-header').exists()).toBe(true)
		expect(wrapper.text()).toContain('Hello World')

		// Three window toggle buttons.
		const winButtons = wrapper.findAll('.ob-detail-header__window-btn')
		expect(winButtons.length).toBe(3)
		expect(winButtons.at(0).text()).toBe('7d')
		expect(winButtons.at(1).text()).toBe('30d')
		expect(winButtons.at(2).text()).toBe('90d')

		// Default window is 7d.
		expect(winButtons.at(0).classes()).toContain('ob-detail-header__window-btn--active')
	})

	it('renders pill tabs for each version in chain order, with production starred', async () => {
		const versions = [
			{ uuid: 'dev-uuid', slug: 'development', promotesTo: 'staging-uuid', name: 'development' },
			{ uuid: 'staging-uuid', slug: 'staging', promotesTo: 'prod-uuid', name: 'staging' },
			{ uuid: 'prod-uuid', slug: 'production', promotesTo: null, name: 'production' },
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
		// Wire the OC currentUser for the viewer.
		wrapper.vm.callerUid = 'bob'
		wrapper.vm.versions = versions
		await wrapper.vm.$nextTick()

		const visible = wrapper.vm.visibleVersions
		expect(visible.length).toBe(1)
		expect(visible[0].slug).toBe('production')
	})

	it('changing the window updates the active window state', async () => {
		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		await wrapper.findAll('.ob-detail-header__window-btn').at(1).trigger('click')
		expect(wrapper.vm.selectedWindow).toBe('30d')
	})

	it('shows the empty-state activity message when activity is empty (REQ-OBADO-005)', () => {
		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		expect(wrapper.text()).toContain('No activity in the selected window')
	})

	it('renders the version-no-longer-accessible banner when 404 occurs', async () => {
		const wrapper = shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})
		wrapper.vm.versionNoLongerAccessible = true
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.ob-detail-header__banner').exists()).toBe(true)
		expect(wrapper.text()).toContain('no longer accessible')
	})
})
