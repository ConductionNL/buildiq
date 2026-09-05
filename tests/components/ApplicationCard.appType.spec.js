// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ApplicationCard from '../../src/components/ApplicationCard.vue'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['team-alpha'],
}))

const t = (app, str) => str

/**
 * Spec: unify-apps-with-app-type / unified-app-model.
 *
 * ApplicationCard now renders a Virtual/Hybrid type pill and hides itself
 * (v-if) when the active `?filter=` URL query selects a different app type.
 */
describe('ApplicationCard — appType pill + filter', () => {
	const factory = (object, route = { query: {} }, extra = {}) =>
		shallowMount(ApplicationCard, {
			propsData: { object, ...extra },
			mocks: { t, $router: { push: vi.fn() }, $route: route },
		})

	// --- type pill ---------------------------------------------------------

	it('renders a "Virtual" type pill when appType is absent', () => {
		const w = factory({ slug: 'my-app' })
		const pill = w.find('.ob-app-card__type')
		expect(pill.exists()).toBe(true)
		expect(pill.text()).toBe('Virtual')
		expect(pill.classes()).toContain('ob-app-card__type--virtual')
	})

	it('renders a "Virtual" type pill when appType is explicitly virtual', () => {
		const w = factory({ slug: 'my-app', appType: 'virtual' })
		const pill = w.find('.ob-app-card__type')
		expect(pill.text()).toBe('Virtual')
		expect(pill.classes()).toContain('ob-app-card__type--virtual')
	})

	it('renders a "Hybrid" type pill when appType is hybrid', () => {
		const w = factory({ slug: 'my-app', appType: 'hybrid' })
		const pill = w.find('.ob-app-card__type')
		expect(pill.text()).toBe('Hybrid')
		expect(pill.classes()).toContain('ob-app-card__type--hybrid')
	})

	// --- hiddenByFilter ----------------------------------------------------

	it('renders the card when no filter is set', () => {
		const w = factory({ slug: 'my-app', appType: 'virtual' }, { query: {} })
		expect(w.find('.ob-app-card').exists()).toBe(true)
	})

	it('renders the card when the filter is "all"', () => {
		const w = factory(
			{ slug: 'my-app', appType: 'hybrid' },
			{ query: { filter: 'all' } },
		)
		expect(w.find('.ob-app-card').exists()).toBe(true)
	})

	it('renders the card when the filter equals the appTypeKey', () => {
		const w = factory(
			{ slug: 'my-app', appType: 'hybrid' },
			{ query: { filter: 'hybrid' } },
		)
		expect(w.find('.ob-app-card').exists()).toBe(true)
	})

	it('hides a virtual card when the filter is "hybrid"', () => {
		const w = factory(
			{ slug: 'my-app', appType: 'virtual' },
			{ query: { filter: 'hybrid' } },
		)
		expect(w.find('.ob-app-card').exists()).toBe(false)
		expect(w.vm.hiddenByFilter).toBe(true)
	})

	it('shows a hybrid card when the filter is "hybrid"', () => {
		const w = factory(
			{ slug: 'my-app', appType: 'hybrid' },
			{ query: { filter: 'hybrid' } },
		)
		expect(w.find('.ob-app-card').exists()).toBe(true)
		expect(w.vm.hiddenByFilter).toBe(false)
	})

	it('hides a (default-virtual) card when the filter is "hybrid"', () => {
		// No explicit appType → reads as virtual → hidden under a hybrid filter.
		const w = factory({ slug: 'my-app' }, { query: { filter: 'hybrid' } })
		expect(w.find('.ob-app-card').exists()).toBe(false)
	})
})
