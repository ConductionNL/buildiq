// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

// Stub axios + nextcloud helpers before importing the component. The header
// fetches versions/insights on mount; the resolved promise keeps mounting
// quiet so we can assert the static appType badge/note.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: [] }),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import ApplicationDetailHeader from '../../../src/components/applicationDetail/ApplicationDetailHeader.vue'

function t(app, key, vars) {
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
 * Spec: unify-apps-with-app-type / unified-app-model.
 *
 * The detail header renders a Virtual/Hybrid badge derived from
 * `application.appType` and shows a read-only hybrid note for hybrid apps.
 */
describe('ApplicationDetailHeader — appType badge + hybrid note', () => {
	const baseApp = {
		uuid: 'app-uuid',
		slug: 'hello-world',
		name: 'Hello World',
		description: 'A demo app',
		status: 'published',
		productionVersion: 'prod-uuid',
		permissions: { owners: ['user:alice'], editors: [], viewers: [] },
	}

	const factory = (application) =>
		shallowMount(ApplicationDetailHeader, {
			propsData: { object: application, objectId: 'app-uuid' },
			mocks: { t, $router: router, $route: route },
		})

	it('renders a "Virtual" type badge when appType is absent', () => {
		const w = factory({ ...baseApp })
		const badge = w.find('.ob-detail-header__badge--type')
		expect(badge.exists()).toBe(true)
		expect(badge.text()).toBe('Virtual')
		expect(w.vm.appTypeKey).toBe('virtual')
		expect(w.vm.isHybrid).toBe(false)
	})

	it('renders a "Hybrid" type badge when appType is hybrid', () => {
		const w = factory({ ...baseApp, appType: 'hybrid' })
		const badge = w.find('.ob-detail-header__badge--type')
		expect(badge.exists()).toBe(true)
		expect(badge.text()).toBe('Hybrid')
		expect(w.vm.appTypeKey).toBe('hybrid')
		expect(w.vm.isHybrid).toBe(true)
	})

	it('renders the read-only hybrid note for a hybrid app', () => {
		const w = factory({ ...baseApp, appType: 'hybrid' })
		const note = w.find('.ob-detail-header__hybrid-note')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain('hybrid app')
	})

	it('does not render the hybrid note for a virtual app', () => {
		const w = factory({ ...baseApp, appType: 'virtual' })
		expect(w.find('.ob-detail-header__hybrid-note').exists()).toBe(false)
	})

	it('does not render the hybrid note when appType is absent', () => {
		const w = factory({ ...baseApp })
		expect(w.find('.ob-detail-header__hybrid-note').exists()).toBe(false)
	})
})
