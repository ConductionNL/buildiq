// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const { axiosGetMock } = vi.hoisted(() => ({
	axiosGetMock: vi.fn(async () => ({ data: { results: [] } })),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGetMock } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p, params) => {
		let out = p
		Object.entries(params || {}).forEach(([k, v]) => {
			out = out.replace(`{${k}}`, v)
		})
		return out
	},
}))

import GroupsWidget from '../../../src/components/applicationDetail/widgets/GroupsWidget.vue'
import MenuWidget from '../../../src/components/applicationDetail/widgets/MenuWidget.vue'
import PagesWidget from '../../../src/components/applicationDetail/widgets/PagesWidget.vue'
import RegisterWidget from '../../../src/components/applicationDetail/widgets/RegisterWidget.vue'
import SchemasWidget from '../../../src/components/applicationDetail/widgets/SchemasWidget.vue'

const t = (app, key) => key
const router = { push: vi.fn() }
const route = { name: 'VirtualAppDetail', params: {}, query: {} }

/**
 * Spec: buildiq-app-detail-overview / application-detail-overview
 * REQ-OBADO-006 through REQ-OBADO-010.
 *
 * Each widget is a small presentational card with a deep-link click handler.
 * These tests verify the mount contract + the routing call shape; the
 * end-to-end behaviour lives in tests/e2e/application-detail-overview.spec.ts.
 */
describe('applicationDetail widgets', () => {
	it('RegisterWidget renders the register slug + Schemas section (no object/file counts)', () => {
		const wrapper = shallowMount(RegisterWidget, {
			propsData: { appSlug: 'hello-world', versionSlug: 'production' },
			mocks: { t, n: (app, s, p, n) => (n === 1 ? s : p) },
		})
		const text = wrapper.text()
		expect(text).toContain('openbuild-hello-world-production')
		expect(text).toContain('Register')
		expect(text).toContain('Schemas')
		// Object/file counts moved to the dashboard KPI tiles — not here.
		expect(text).not.toContain('Objects')
		expect(text).not.toContain('Files')
	})

	it('RegisterWidget hides the Import data affordance for non-builders (canImport=false)', () => {
		const wrapper = shallowMount(RegisterWidget, {
			propsData: {
				appSlug: 'hello-world',
				versionSlug: 'production',
				canImport: false,
			},
			mocks: { t, n: (app, s, p, num) => (num === 1 ? s : p) },
		})
		expect(wrapper.text()).not.toContain('Import data')
	})

	it('RegisterWidget shows Import data + emits import-data when canImport=true', async () => {
		const wrapper = shallowMount(RegisterWidget, {
			propsData: {
				appSlug: 'hello-world',
				versionSlug: 'production',
				canImport: true,
			},
			mocks: { t, n: (app, s, p, num) => (num === 1 ? s : p) },
			stubs: {
				NcButton: {
					name: 'NcButton',
					props: ['type'],
					template: '<button @click="$emit(\'click\')"><slot /></button>',
				},
			},
		})
		expect(wrapper.text()).toContain('Import data')
		wrapper.findAllComponents({ name: 'NcButton' }).at(0).vm.$emit('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('import-data')).toBeTruthy()
		expect(wrapper.emitted('import-data')[0][0].registerSlug).toBe(
			'openbuild-hello-world-production',
		)
	})

	it('SchemasWidget renders schema names + emits addSchema event', async () => {
		const schemas = [
			{ id: 's1', name: 'Customer', objectCount: 5, status: 'active' },
		]
		const wrapper = shallowMount(SchemasWidget, {
			propsData: { appSlug: 'hello-world', versionSlug: 'staging', schemas },
			mocks: { t, $router: router, $route: route },
		})
		expect(wrapper.text()).toContain('Customer')

		// Trigger addSchema via the inline button. The parent routes this to
		// the schema designer; the widget only announces the intent.
		wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		// Allow Vue's reactive update to flush.
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('addSchema')).toBeTruthy()
	})

	it('SchemasWidget click on a row pushes a versioned route', async () => {
		const schemas = [{ id: 's1', name: 'Customer', objectCount: 5 }]
		router.push.mockReset()
		const wrapper = shallowMount(SchemasWidget, {
			propsData: { appSlug: 'hello-world', versionSlug: 'staging', schemas },
			mocks: { t, $router: router, $route: route },
		})
		await wrapper.find('.ob-schemas-widget__row').trigger('click')
		expect(router.push).toHaveBeenCalledWith(
			expect.objectContaining({
				name: 'SchemaDesigner',
				params: { slug: 'hello-world', schemaId: 's1' },
				query: { _version: 'staging' },
			}),
		)
	})

	it('GroupsWidget flattens permissions buckets into role-tagged rows', () => {
		const application = {
			permissions: {
				owners: ['user:alice'],
				editors: ['group:devs', 'user:bob'],
				viewers: ['group:everyone'],
			},
		}
		const wrapper = shallowMount(GroupsWidget, {
			propsData: { application },
			mocks: { t },
		})
		const rows = wrapper.findAll('.ob-groups-widget__row')
		expect(rows.length).toBe(4)
		expect(wrapper.text()).toContain('alice')
		expect(wrapper.text()).toContain('devs')
		expect(wrapper.text()).toContain('Owner')
		expect(wrapper.text()).toContain('Editor')
		expect(wrapper.text()).toContain('Viewer')
	})

	it('PagesWidget row click pushes a versioned route with pageId', async () => {
		const pages = [
			{
				id: 'customers-list',
				route: '/customers',
				type: 'index',
				title: 'Customers',
			},
		]
		router.push.mockReset()
		const wrapper = shallowMount(PagesWidget, {
			propsData: { appSlug: 'hello-world', versionSlug: 'development', pages },
			mocks: { t, $router: router, $route: route },
		})
		await wrapper.find('.ob-pages-widget__row').trigger('click')
		expect(router.push).toHaveBeenCalledWith(
			expect.objectContaining({
				name: 'PageDesigner',
				params: { slug: 'hello-world' },
				query: expect.objectContaining({
					_version: 'development',
					pageId: 'customers-list',
				}),
			}),
		)
	})

	it('MenuWidget row click pushes a versioned route with focus=menu', async () => {
		const menu = [{ id: 'home', label: 'Home', route: '/', order: 10 }]
		router.push.mockReset()
		const wrapper = shallowMount(MenuWidget, {
			propsData: { appSlug: 'hello-world', versionSlug: 'production', menu },
			mocks: { t, $router: router, $route: route },
		})
		await wrapper.find('.ob-menu-widget__row').trigger('click')
		expect(router.push).toHaveBeenCalledWith(
			expect.objectContaining({
				name: 'PageDesigner',
				params: { slug: 'hello-world' },
				query: expect.objectContaining({
					_version: 'production',
					focus: 'menu',
				}),
			}),
		)
	})
	describe('GroupsWidget rows', () => {
		it('labels users, groups and bare principals, in role then entry order', () => {
			const wrapper = shallowMount(GroupsWidget, {
				propsData: {
					application: {
						permissions: {
							viewers: ['group:everyone'],
							owners: ['user:alice', 'admins'],
							editors: ['group:devs', '', 7, null, 'user:bob'],
						},
					},
				},
				mocks: { t },
			})
			expect(wrapper.vm.rows).toEqual([
				{ role: 'owners', principal: 'user:alice', label: 'alice', isGroup: false },
				{ role: 'owners', principal: 'admins', label: 'admins', isGroup: true },
				{ role: 'editors', principal: 'group:devs', label: 'devs', isGroup: true },
				{ role: 'editors', principal: 'user:bob', label: 'bob', isGroup: false },
				{ role: 'viewers', principal: 'group:everyone', label: 'everyone', isGroup: true },
			])
		})

		it.each([
			[{ permissions: { owners: 'user:alice', editors: null } }],
			[{ permissions: null }],
			[{}],
			[null],
		])('has no rows for %j', (application) => {
			const wrapper = shallowMount(GroupsWidget, {
				propsData: { application },
				mocks: { t },
			})
			expect(wrapper.vm.rows).toEqual([])
		})
	})
})

describe('FlowsWidget run on and last run (REQ-BQSF-005)', async () => {
	const { default: FlowsWidget } = await import('../../../src/components/applicationDetail/widgets/FlowsWidget.vue')

	/**
	 * Mount the widget and let its run reads settle.
	 *
	 * @param {Array<object>} flows The bindings.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountFlows(flows) {
		const wrapper = shallowMount(FlowsWidget, { props: { flows } })
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it('lists where a bound flow runs, one line per entry, and leaves out what it cannot word', async () => {
		axiosGetMock.mockImplementation(async () => ({ data: { results: [] } }))
		const wrapper = await mountFlows([{
			flow: 'f-1',
			label: 'Notify the team',
			runOn: [
				{ schema: 'order', event: 'object.created' },
				{ schema: 'order', event: 'object.deleted' },
				{ schema: 'ticket', mode: 'from-record' },
				{ schema: '', event: 'object.updated' },
				{ schema: 'order', event: 'object.renamed' },
			],
		}])

		expect(wrapper.find('.ob-flows-widget__row-name').text()).toBe('Notify the team')
		expect(wrapper.findAll('.ob-flows-widget__run-on li').map((li) => li.text())).toEqual([
			'when a order is created',
			'when a order is deleted',
			'from a ticket record',
		])
	})

	it('reads the last run of each bound flow and shows its status, or that it has not run', async () => {
		axiosGetMock.mockReset()
		axiosGetMock.mockImplementation(async (url, { params }) => ({
			data: { results: params.flowId === 'f-1' ? [{ status: 'failed', created: '2026-11-14T10:00:00+00:00' }] : [] },
		}))
		const wrapper = await mountFlows([{ flow: 'f-1', label: 'One' }, { flow: 'f-2', label: 'Two' }, { flow: 'f-1', label: 'Again' }])

		expect(axiosGetMock).toHaveBeenCalledTimes(2)
		expect(axiosGetMock).toHaveBeenCalledWith('/apps/openregister/api/flow-runs', { params: { flowId: 'f-1', limit: 1 } })
		const lines = wrapper.findAll('.ob-flows-widget__last-run').map((p) => p.text())
		expect(lines[0]).toMatch(/^Last run: failed \S/)
		expect(lines[1]).toBe('Not run yet')
		expect(wrapper.findAll('.ob-flows-widget__run-on')).toHaveLength(0)
	})

	it('shows no last run for a flow whose runs cannot be read', async () => {
		axiosGetMock.mockReset()
		axiosGetMock.mockRejectedValue(new Error('403'))
		const wrapper = await mountFlows([{ flow: 'f-1', label: 'One' }])

		expect(wrapper.find('.ob-flows-widget__last-run').exists()).toBe(false)
		expect(wrapper.find('.ob-flows-widget__row').attributes('href')).toBe('/apps/openregister/flows/f-1')
	})
})
