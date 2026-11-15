/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DashboardIndex beyond the KPI tiles: how it loads the counts and the recent
 * apps, the recent-apps table (empty state, columns, type labels, opening an
 * app by row or Edit action), the create-app wizard and what happens after it
 * creates an app.
 *
 * @spec openspec/specs/unified-app-model/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, vars = {}) => p.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m),
}))

import axios from '@nextcloud/axios'
import DashboardIndex from '../../src/views/DashboardIndex.vue'

const stubs = {
	CnDashboardPage: {
		props: ['loading'],
		template: '<div class="page" :data-loading="String(loading)"><slot name="header-actions" /><slot name="widget-recent-apps" /></div>',
	},
	CnDataTable: {
		name: 'CnDataTable',
		props: ['rows', 'columns'],
		emits: ['rowClick'],
		template: '<div class="table"><div v-for="row in rows" :key="row.slug" class="row"><slot name="row-actions" :row="row" /></div></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
	CreateApplicationWizard: {
		name: 'CreateApplicationWizard',
		props: ['show'],
		emits: ['update:show', 'created'],
		template: '<div class="wizard" />',
	},
	KpiLinkTile: true,
}

const APPS = [
	{ slug: 'a', name: 'Alpha', appType: 'hybrid', '@self': { id: 'uuid-a' } },
	{ slug: 'b', name: 'Beta', appType: 'virtual', uuid: 'uuid-b' },
]

/**
 * Answer each dashboard request by schema and filter.
 *
 * @param {Array<object>} recent The recent apps.
 * @return {void}
 */
function respond(recent) {
	axios.get.mockImplementation((url, { params }) => {
		if (params._limit === 8) return Promise.resolve({ data: { results: recent } })
		if (url.endsWith('/applicationVersion')) return Promise.resolve({ data: { total: 9 } })
		if (params.appType === 'hybrid') return Promise.resolve({ data: { total: 2 } })
		return Promise.resolve({ data: { total: 5 } })
	})
}

/**
 * Mount the dashboard with a router mock and wait for the load.
 *
 * @param {object} [router] The `$router` mock.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountDashboard(router = { push: vi.fn().mockResolvedValue(undefined) }) {
	const wrapper = mount(DashboardIndex, { global: { stubs, mocks: { $router: router } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('DashboardIndex recent apps', () => {
	it('loads the counts and the eight most recent apps with type labels', async () => {
		respond(APPS)
		const wrapper = await mountDashboard()

		expect(wrapper.vm.counts).toEqual({ apps: 5, hybrid: 2, versions: 9 })
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/built-app', {
			params: { _limit: 8, _order: { '@self.updated': 'DESC' } },
		})
		const table = wrapper.findComponent({ name: 'CnDataTable' })
		expect(table.props('rows').map((r) => r.typeLabel)).toEqual(['Hybrid', 'Virtual'])
		expect(table.props('columns')).toEqual([
			{ key: 'name', label: 'Name' },
			{ key: 'typeLabel', label: 'Type' },
			{ key: 'slug', label: 'Slug' },
		])
		expect(wrapper.find('.page').attributes('data-loading')).toBe('false')
		expect(wrapper.find('.empty').exists()).toBe(false)
	})

	it('shows the empty state when there are no apps or the results are missing', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountDashboard()

		expect(wrapper.vm.counts).toEqual({ apps: 0, hybrid: 0, versions: 0 })
		expect(wrapper.find('.empty').text()).toBe('No apps yet')
	})

	it('keeps zeros and an empty list when the load fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = await mountDashboard()

		expect(wrapper.vm.counts).toEqual({ apps: 0, hybrid: 0, versions: 0 })
		expect(wrapper.vm.recentApps).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
		expect(console.error).toHaveBeenCalled()
	})

	it('opens an app by its @self id, uuid or id from a row or its Edit action', async () => {
		respond(APPS)
		const push = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mountDashboard({ push })

		await wrapper.findAll('.row button')[0].trigger('click')
		wrapper.findComponent({ name: 'CnDataTable' }).vm.$emit('rowClick', APPS[1])
		wrapper.vm.goToApp({ id: 7 })

		expect(push.mock.calls).toEqual([
			[{ name: 'VirtualAppDetail', params: { objectId: 'uuid-a' } }],
			[{ name: 'VirtualAppDetail', params: { objectId: 'uuid-b' } }],
			[{ name: 'VirtualAppDetail', params: { objectId: 7 } }],
		])
	})

	it('does not navigate for a row without any id', async () => {
		respond([])
		const push = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mountDashboard({ push })

		wrapper.vm.goToApp({ slug: 'x' })
		wrapper.vm.goToApp(null)

		expect(push).not.toHaveBeenCalled()
	})

	it('swallows a rejected navigation', async () => {
		respond([])
		const push = vi.fn().mockRejectedValue(new Error('dup'))
		const wrapper = await mountDashboard({ push })

		wrapper.vm.goToApp({ id: 1 })
		wrapper.vm.onAppCreated('uuid-new')
		await flushPromises()

		expect(push).toHaveBeenCalledTimes(2)
	})

	it('opens the wizard from the header action', async () => {
		respond([])
		const wrapper = await mountDashboard()

		await wrapper.find('.page > button').trigger('click')

		expect(wrapper.findComponent({ name: 'CreateApplicationWizard' }).props('show')).toBe(true)
	})

	it('closes the wizard and opens the created app', async () => {
		respond([])
		const push = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mountDashboard({ push })
		await wrapper.setData({ showWizard: true })

		wrapper.findComponent({ name: 'CreateApplicationWizard' }).vm.$emit('created', 'uuid-new')
		await flushPromises()

		expect(wrapper.vm.showWizard).toBe(false)
		expect(push).toHaveBeenCalledWith({ name: 'VirtualAppDetail', params: { objectId: 'uuid-new' } })
		expect(axios.get).toHaveBeenCalledTimes(4)
	})

	it('reloads the dashboard when the created app has no id', async () => {
		respond([])
		const push = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mountDashboard({ push })

		wrapper.vm.onAppCreated('')
		await flushPromises()

		expect(push).not.toHaveBeenCalled()
		expect(axios.get).toHaveBeenCalledTimes(8)
	})
})
