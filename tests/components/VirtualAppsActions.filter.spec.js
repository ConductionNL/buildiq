/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning VirtualAppsActions.vue's `setFilter`: the `?filter=`
 * query it writes (cleared for 'all', other query keys kept), no navigation
 * when the filter is unchanged, and nothing at all without a router.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import VirtualAppsActions from '../../src/components/VirtualAppsActions.vue'

function mountWith(route, router) {
	return shallowMount(VirtualAppsActions, {
		global: { mocks: { $route: route, $router: router, t: (a, s) => s } },
	})
}

function routerMock() {
	return { replace: vi.fn(() => Promise.resolve()), push: vi.fn(() => Promise.resolve()) }
}

describe('VirtualAppsActions setFilter', () => {
	it.each([
		[{ query: { page: '2' } }, 'virtual', { page: '2', filter: 'virtual' }],
		[{ query: { filter: 'virtual', page: '2' } }, 'hybrid', { page: '2', filter: 'hybrid' }],
		[{ query: { filter: 'hybrid', page: '2' } }, 'all', { page: '2' }],
		[{ query: { filter: 'bogus' } }, 'virtual', { filter: 'virtual' }],
	])('from %j choosing %s replaces the query with %j', (route, value, query) => {
		const router = routerMock()
		mountWith(route, router).vm.setFilter(value)
		expect(router.replace).toHaveBeenCalledWith({ query })
		expect(router.replace.mock.calls[0][0].query).not.toBe(route.query)
	})

	it.each([
		[{ query: { filter: 'virtual' } }, 'virtual'],
		[{ query: {} }, 'all'],
		[{ query: { filter: 'bogus' } }, 'all'],
	])('does not navigate from %j to the same filter %s', (route, value) => {
		const router = routerMock()
		mountWith(route, router).vm.setFilter(value)
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('works from no route at all', () => {
		const router = routerMock()
		mountWith(undefined, router).vm.setFilter('hybrid')
		expect(router.replace).toHaveBeenCalledWith({ query: { filter: 'hybrid' } })
	})

	it('does nothing without a router', () => {
		const route = { query: { filter: 'virtual' } }
		expect(() => mountWith(route, undefined).vm.setFilter('all')).not.toThrow()
		expect(route.query).toEqual({ filter: 'virtual' })
	})
})
