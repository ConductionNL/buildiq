/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression tests for the app detail page reporting state it does not have:
 * a Diff tab that never diffed, a 404 before every version list, export
 * polling with no job, a half-built register name while loading, app cards
 * stuck on "Draft", and "Open app" ignoring the selected version.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, routeState } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
	routeState: { query: {} },
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) =>
		p.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
	imagePath: (app, p) => `/${app}/${p}`,
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: () => 'owner',
	getCurrentUserGroups: () => [],
}))

import ApplicationDetailDashboard from '../../src/components/applicationDetail/ApplicationDetailDashboard.vue'
import RegisterWidget from '../../src/components/applicationDetail/widgets/RegisterWidget.vue'
import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'
import ApplicationDiffTab from '../../src/components/tabs/ApplicationDiffTab.vue'
import ExportJobsList from '../../src/views/ExportJobsList.vue'
import VersionHistory from '../../src/views/VersionHistory.vue'
import {
	ensureProductionVersionsLoaded,
	productionVersions,
	resetProductionVersions,
} from '../../src/store/productionVersions.js'

function t(app, key, vars) {
	return Object.keys(vars || {}).reduce(
		(out, k) => out.replace(`{${k}}`, vars[k]),
		key,
	)
}
globalThis.t = t
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', count)

const application = {
	uuid: 'app-uuid',
	slug: 'shop',
	name: 'Shop',
	productionVersion: 'prod-uuid',
	permissions: { owners: ['user:alice'], editors: [], viewers: [] },
}
const versions = [
	{
		id: 'dev-uuid',
		slug: 'development',
		name: 'Development',
		semver: '0.2.0',
		status: 'draft',
		promotesTo: 'prod-uuid',
		application: 'app-uuid',
	},
	{
		id: 'prod-uuid',
		slug: 'production',
		name: 'Production',
		semver: '0.1.0',
		status: 'published',
		promotesTo: null,
		application: 'app-uuid',
	},
]
function mocks() {
	return {
		t,
		n,
		$route: routeState,
		$router: {
			push: vi.fn().mockResolvedValue(),
			replace: vi.fn().mockResolvedValue(),
		},
	}
}

beforeEach(() => {
	routeState.query = {}
	axiosMock.get.mockReset()
	axiosMock.get.mockImplementation((url) =>
		Promise.resolve({
			data: url.includes('/versions') ? versions : application,
		}),
	)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('Diff tab', () => {
	it('compares the version that promotes into production with production', async () => {
		const wrapper = shallowMount(ApplicationDiffTab, {
			props: { object: application, objectId: 'app-uuid' },
			global: { mocks: mocks() },
		})
		await flushPromises()

		const diff = wrapper.findComponent({ name: 'ManifestDiff' })
		expect(diff.exists()).toBe(true)
		expect(diff.props('from')).toBe('dev-uuid')
		expect(diff.props('to')).toBe('prod-uuid')
		expect(diff.props('fromLabelText')).toBe('Development (0.2.0)')
		expect(wrapper.text()).not.toContain('publish the app first')
	})

	it('says so when the app has one version', async () => {
		axiosMock.get.mockImplementation(() =>
			Promise.resolve({ data: [versions[1]] }),
		)
		const wrapper = shallowMount(ApplicationDiffTab, {
			props: { object: application, objectId: 'app-uuid' },
			global: { mocks: mocks() },
		})
		await flushPromises()

		expect(wrapper.findComponent({ name: 'ManifestDiff' }).exists()).toBe(false)
		expect(wrapper.text()).toContain('This app has one version')
	})
})

describe('Version history', () => {
	it('never asks the non-existent applicationversions endpoint', async () => {
		shallowMount(VersionHistory, {
			props: { applicationUuid: 'app-uuid' },
			global: {
				mocks: mocks(),
				stubs: { RollbackConfirmModal: true, 'router-link': true },
			},
		})
		await flushPromises()

		const urls = axiosMock.get.mock.calls.map((c) => c[0])
		expect(urls.some((u) => u.includes('applicationversions'))).toBe(false)
	})
})

describe('Export jobs', () => {
	function mountWithJobs(sequence) {
		const fetchMock = vi.fn(async () => ({
			ok: true,
			json: async () => ({
				results: sequence.length > 1 ? sequence.shift() : sequence[0],
			}),
		}))
		vi.stubGlobal('fetch', fetchMock)
		const wrapper = shallowMount(ExportJobsList, {
			props: { applicationSlug: 'shop', applicationUuid: 'app-uuid' },
			global: { mocks: mocks() },
		})
		return { wrapper, fetchMock }
	}

	it('does not poll when no job is running', async () => {
		vi.useFakeTimers()
		const { wrapper, fetchMock } = mountWithJobs([[{ status: 'succeeded' }]])
		await flushPromises()
		await vi.advanceTimersByTimeAsync(30000)

		expect(fetchMock).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})

	it('polls every five seconds while a job runs, then stops', async () => {
		vi.useFakeTimers()
		const { wrapper, fetchMock } = mountWithJobs([
			[{ status: 'running' }],
			[{ status: 'running' }],
			[{ status: 'succeeded' }],
		])
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledTimes(1)

		await vi.advanceTimersByTimeAsync(4000)
		expect(fetchMock).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1000)
		expect(fetchMock).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(5000)
		expect(fetchMock).toHaveBeenCalledTimes(3)
		await vi.advanceTimersByTimeAsync(30000)
		expect(fetchMock).toHaveBeenCalledTimes(3)
		wrapper.unmount()
	})
})

describe('Register widget', () => {
	it('names no register until the version is known', () => {
		const wrapper = shallowMount(RegisterWidget, {
			props: { appSlug: 'shop', versionSlug: '' },
			global: { mocks: mocks() },
		})

		expect(wrapper.text()).not.toContain('openbuild-shop-')
		expect(wrapper.vm.openRegisterUrl).toBeUndefined()
	})
})

describe('App card status index', () => {
	beforeEach(() => {
		resetProductionVersions()
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	it('tries again after a failed load instead of pinning it', async () => {
		axiosMock.get.mockRejectedValueOnce(new Error('timeout'))
		await ensureProductionVersionsLoaded()
		expect(productionVersions['prod-uuid']).toBeUndefined()

		axiosMock.get.mockResolvedValueOnce({
			data: [
				{
					productionVersion: 'prod-uuid',
					productionVersionDetail: {
						status: 'published',
						semver: '0.1.0',
					},
				},
			],
		})
		await ensureProductionVersionsLoaded('prod-uuid')
		expect(productionVersions['prod-uuid'].status).toBe('published')
	})

	it('reloads once for a production version the index does not know', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: [] })
		await ensureProductionVersionsLoaded('new-uuid')

		axiosMock.get.mockResolvedValueOnce({
			data: [
				{
					productionVersion: 'new-uuid',
					productionVersionDetail: { status: 'published' },
				},
			],
		})
		await ensureProductionVersionsLoaded('new-uuid')
		await ensureProductionVersionsLoaded('new-uuid')

		expect(axiosMock.get).toHaveBeenCalledTimes(2)
		expect(productionVersions['new-uuid'].status).toBe('published')
	})
})

describe('Open app', () => {
	async function mountActions() {
		const wrapper = shallowMount(ApplicationDetailActions, {
			props: { object: application, objectId: 'app-uuid' },
			global: { mocks: mocks() },
		})
		await flushPromises()
		return wrapper
	}

	it('opens the version selected in the header', async () => {
		routeState.query = { _version: 'development' }
		const wrapper = await mountActions()

		const open = wrapper.vm.actionDescriptors.find((a) => a.id === 'open-app')
		expect(open.href).toBe('/apps/buildiq/builder/shop?_version=development')
	})

	it('opens production at its canonical URL', async () => {
		routeState.query = { _version: 'production' }
		const wrapper = await mountActions()

		const open = wrapper.vm.actionDescriptors.find((a) => a.id === 'open-app')
		expect(open.href).toBe('/apps/buildiq/builder/shop')
	})
})

describe('Schemas widget counts', () => {
	it('shows the per-schema object count from the insights payload', async () => {
		axiosMock.get.mockImplementation((url) => {
			if (url.includes('/insights')) {
				return Promise.resolve({
					data: {
						kpis: { objectCount: 3 },
						activity: [],
						schemaCounts: { 'shop-production-order': 3 },
					},
				})
			}
			return Promise.resolve({
				data: url.includes('/versions')
					? [
							{
								...versions[1],
								manifest: {
									pages: [
										{
											config: {
												register:
													'openbuild-shop-production',
												schema: 'shop-production-order',
											},
										},
									],
								},
							},
						]
					: {},
			})
		})
		vi.useFakeTimers()
		const wrapper = shallowMount(ApplicationDetailDashboard, {
			props: { object: application, objectId: 'app-uuid' },
			global: { mocks: mocks() },
		})
		await flushPromises()
		await vi.advanceTimersByTimeAsync(250)
		await flushPromises()

		expect(wrapper.vm.activeSchemas).toEqual([
			{
				id: 'shop-production-order',
				name: 'shop-production-order',
				objectCount: 3,
				status: 'active',
			},
		])
	})
})
