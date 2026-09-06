import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DashboardAppsListWidget.vue (PR #101 designer UI).
 *
 * Covers the recent-apps dashboard slot widget:
 *  - fetchApps: GraphQL query → sort by _updated desc → slice(0, 8) → total.
 *  - fetchApps failure → empty apps / total 0 and loading cleared.
 *  - Render states: loading spinner, empty-content, populated table rows.
 *  - Cell helpers: appId / appStatus / appStatusLabel / appVersion / appUpdated.
 *  - Icon fallback (onIconError) and navigation (openApp / goToApps).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosPostMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => axiosPostMock(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	imagePath: (app, file) => `/apps/${app}/img/${file}`,
}))

const DashboardAppsListWidget = (
	await import('../../../src/components/dashboard/DashboardAppsListWidget.vue')
).default

async function flush(wrapper) {
	await new Promise((r) => setTimeout(r, 0))
	if (wrapper) await wrapper.vm.$nextTick()
}

function graphqlResponse(nodes, totalCount) {
	return {
		data: {
			data: {
				application: {
					edges: nodes.map((node) => ({ node })),
					totalCount: totalCount ?? nodes.length,
				},
			},
		},
	}
}

function mountWidget(routerPush = vi.fn()) {
	return mount(DashboardAppsListWidget, {
		mocks: { $router: { push: routerPush } },
	})
}

describe('DashboardAppsListWidget', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
		axiosPostMock.mockResolvedValue(graphqlResponse([], 0))
	})

	it('shows the loading spinner before the fetch resolves', () => {
		// Never-resolving fetch keeps loading=true.
		axiosPostMock.mockReturnValue(new Promise(() => {}))
		const wrapper = mountWidget()
		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.find('.ob-apps-list-widget__loading').exists()).toBe(true)
	})

	it('renders the empty state when no apps come back', async () => {
		axiosPostMock.mockResolvedValue(graphqlResponse([], 0))
		const wrapper = mountWidget()
		await flush(wrapper)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.apps).toEqual([])
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(true)
		expect(wrapper.find('.ob-apps-list-widget__table').exists()).toBe(false)
	})

	it('fetches, sorts by _updated desc, and slices to 8', async () => {
		const nodes = []
		for (let i = 0; i < 10; i++) {
			nodes.push({
				_uuid: 'u' + i,
				name: 'App ' + i,
				slug: 'app-' + i,
				_updated: `2026-01-${String(i + 1).padStart(2, '0')}T00:00:00Z`,
			})
		}
		axiosPostMock.mockResolvedValue(graphqlResponse(nodes, 10))
		const wrapper = mountWidget()
		await flush(wrapper)
		expect(axiosPostMock).toHaveBeenCalledTimes(1)
		// Most recently updated first, capped at 8.
		expect(wrapper.vm.apps).toHaveLength(8)
		expect(wrapper.vm.apps[0]._uuid).toBe('u9')
		expect(wrapper.vm.apps[7]._uuid).toBe('u2')
		expect(wrapper.vm.total).toBe(10)
		// One row per app.
		expect(wrapper.findAll('.ob-apps-list-widget__row')).toHaveLength(8)
	})

	it('renders the "view all" footer only when total exceeds visible apps', async () => {
		const nodes = [
			{
				_uuid: 'u1',
				name: 'One',
				slug: 'one',
				_updated: '2026-01-01T00:00:00Z',
			},
		]
		axiosPostMock.mockResolvedValue(graphqlResponse(nodes, 42))
		const wrapper = mountWidget()
		await flush(wrapper)
		expect(wrapper.find('.ob-apps-list-widget__footer').exists()).toBe(true)
		expect(wrapper.find('.ob-apps-list-widget__footer').text()).toContain('42')
	})

	it('handles a fetch failure by clearing apps and total', async () => {
		axiosPostMock.mockRejectedValue(new Error('boom'))
		const wrapper = mountWidget()
		await flush(wrapper)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.apps).toEqual([])
		expect(wrapper.vm.total).toBe(0)
	})

	it('tolerates a missing application connection in the response', async () => {
		axiosPostMock.mockResolvedValue({ data: {} })
		const wrapper = mountWidget()
		await flush(wrapper)
		expect(wrapper.vm.apps).toEqual([])
		expect(wrapper.vm.total).toBe(0)
	})

	describe('cell helpers', () => {
		let vm
		beforeEach(async () => {
			const wrapper = mountWidget()
			await flush(wrapper)
			vm = wrapper.vm
		})

		it('appId resolves the first available identifier', () => {
			expect(vm.appId({ _uuid: 'a' })).toBe('a')
			expect(vm.appId({ '@self': { id: 'b' } })).toBe('b')
			expect(vm.appId({ uuid: 'c' })).toBe('c')
			expect(vm.appId({ id: 'd' })).toBe('d')
			expect(vm.appId({ slug: 'e' })).toBe('e')
		})

		it('appStatus normalizes to a known bucket (default draft)', () => {
			expect(vm.appStatus({ status: 'published' })).toBe('published')
			expect(vm.appStatus({ status: 'archived' })).toBe('archived')
			expect(vm.appStatus({ status: 'weird' })).toBe('draft')
			expect(vm.appStatus({})).toBe('draft')
			// productionVersion.status wins over top-level status.
			expect(
				vm.appStatus({
					productionVersion: { status: 'published' },
					status: 'draft',
				}),
			).toBe('published')
		})

		it('appStatusLabel maps the status bucket to its label', () => {
			expect(vm.appStatusLabel({ status: 'published' })).toBe('Published')
			expect(vm.appStatusLabel({ status: 'archived' })).toBe('Archived')
			expect(vm.appStatusLabel({})).toBe('Draft')
		})

		it('appVersion prefers the production semver, then version, then dash', () => {
			expect(vm.appVersion({ productionVersion: { semver: '2.1.0' } })).toBe(
				'2.1.0',
			)
			expect(vm.appVersion({ version: '1.0.0' })).toBe('1.0.0')
			expect(vm.appVersion({})).toBe('—')
		})

		it('appUpdated formats a timestamp and dashes when absent', () => {
			expect(vm.appUpdated({})).toBe('—')
			const out = vm.appUpdated({ _updated: '2026-03-15T00:00:00Z' })
			expect(out).not.toBe('—')
			expect(typeof out).toBe('string')
			// Also resolves from the @self envelope.
			expect(
				vm.appUpdated({ '@self': { updated: '2026-03-15T00:00:00Z' } }),
			).not.toBe('—')
		})

		// A real <img>, not a bare `{ src }` stub: onIconError reads the LITERAL
		// src attribute (`getAttribute`) rather than the resolved `.src` property,
		// which the DOM makes absolute.
		it('onIconError swaps in the fallback icon', () => {
			const target = document.createElement('img')
			target.setAttribute('src', '/index.php/apps/buildiq/icons/foo-dark.svg')
			vm.onIconError({ target })
			expect(target.getAttribute('src')).toBe('/apps/buildiq/img/app-dark.svg')
		})

		it('onIconError does not re-swap once the fallback itself is showing', () => {
			// If the fallback also 404s, the error event re-fires on the same node.
			// Without the guard the handler would re-set the same failing src forever,
			// spamming requests — so it must swap at most once.
			const target = document.createElement('img')
			target.setAttribute('src', '/apps/buildiq/img/app-dark.svg')
			vm.onIconError({ target })
			expect(target.getAttribute('src')).toBe('/apps/buildiq/img/app-dark.svg')
		})
	})

	it('openApp navigates to the app detail route with its id', async () => {
		const push = vi.fn()
		const wrapper = mountWidget(push)
		await flush(wrapper)
		wrapper.vm.openApp({ _uuid: 'abc' })
		expect(push).toHaveBeenCalledWith({
			name: 'VirtualAppDetail',
			params: { objectId: 'abc' },
		})
	})

	it('openApp is a no-op when the app has no id', async () => {
		const push = vi.fn()
		const wrapper = mountWidget(push)
		await flush(wrapper)
		wrapper.vm.openApp({})
		expect(push).not.toHaveBeenCalled()
	})

	it('goToApps navigates to the apps overview', async () => {
		const push = vi.fn()
		const wrapper = mountWidget(push)
		await flush(wrapper)
		wrapper.vm.goToApps()
		expect(push).toHaveBeenCalledWith({ name: 'VirtualApps' })
	})
})
