// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'

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

import RegisterWidget from '../../../src/components/applicationDetail/widgets/RegisterWidget.vue'
import SchemasWidget from '../../../src/components/applicationDetail/widgets/SchemasWidget.vue'
import GroupsWidget from '../../../src/components/applicationDetail/widgets/GroupsWidget.vue'
import PagesWidget from '../../../src/components/applicationDetail/widgets/PagesWidget.vue'
import MenuWidget from '../../../src/components/applicationDetail/widgets/MenuWidget.vue'

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

	it('SchemasWidget renders schema names + emits add-schema event', async () => {
		const schemas = [
			{ id: 's1', name: 'Customer', objectCount: 5, status: 'active' },
		]
		const wrapper = shallowMount(SchemasWidget, {
			propsData: { appSlug: 'hello-world', versionSlug: 'staging', schemas },
			mocks: { t, $router: router, $route: route },
		})
		expect(wrapper.text()).toContain('Customer')

		// Trigger add-schema via the inline button — when no global dialog is
		// registered the component emits the event instead.
		wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
		// Allow Vue's reactive update to flush.
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('add-schema')).toBeTruthy()
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
})
