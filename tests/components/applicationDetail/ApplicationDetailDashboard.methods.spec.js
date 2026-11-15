// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Vitest spec for ApplicationDetailDashboard's methods and derived state.
 *
 * Covers:
 *  - storage sizes formatted in binary units.
 *  - the register slug for hybrid, stored, derived and missing registers, and
 *    the OpenRegister link built from it.
 *  - schemas collected once each from the active pages, with their counts;
 *    activity totals and the sparkline.
 *  - navigation to the schema list, page designer and manifest detail.
 *  - the insights read: guards, success, a 404 and other failures; the
 *    debounce collapsing several triggers into one read.
 *  - the user-delta read, the switch back to production and the import
 *    wizard's schema list.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, fetchSchemas } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn() },
	fetchSchemas: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars) => (vars ? path.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : path),
}))
vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({ fetchSchemas }),
}))

import ApplicationDetailDashboard from '../../../src/components/applicationDetail/ApplicationDetailDashboard.vue'
import { useInsightsWindow } from '../../../src/composables/useInsightsWindow.js'

const PROD = { uuid: 'prod-uuid', slug: 'production', semver: '1.2.0', status: 'published', manifest: {} }
const STAGING = {
	uuid: 'stage-uuid',
	slug: 'staging',
	promotesTo: 'prod-uuid',
	manifest: {
		pages: [
			{ id: 'p1', config: { schema: 'case' } },
			{ id: 'p2', config: { schema: 'task' } },
			{ id: 'p3', config: { schema: 'case' } },
			{ id: 'p4' },
			null,
		],
	},
}

let router
let responses

function stopDebounce(wrapper) {
	clearTimeout(wrapper.vm.insightsDebounce)
	wrapper.vm.insightsDebounce = null
}

async function mountDashboard(application = {}, versions = [STAGING, PROD]) {
	router = { push: vi.fn().mockResolvedValue(undefined), replace: vi.fn().mockResolvedValue(undefined) }
	responses['/applications/hello/versions'] = { data: versions }
	const app = { uuid: 'app-uuid', slug: 'hello', productionVersion: 'prod-uuid', ...application }
	const wrapper = shallowMount(ApplicationDetailDashboard, {
		props: { object: app, objectId: 'app-uuid' },
		global: { mocks: { $router: router, $route: { name: 'VirtualAppDetail', params: { objectId: 'app-uuid' }, query: {} } } },
	})
	await flushPromises()
	stopDebounce(wrapper)
	return wrapper
}

async function select(wrapper, uuid) {
	wrapper.vm.selectedVersionUuid = uuid
	await wrapper.vm.$nextTick()
	stopDebounce(wrapper)
}

beforeEach(() => {
	responses = {}
	axiosMock.get.mockReset()
	axiosMock.get.mockImplementation((url) => {
		for (const [part, answer] of Object.entries(responses)) {
			if (url.includes(part)) {
				return typeof answer === 'function' ? answer() : Promise.resolve(answer)
			}
		}
		return Promise.resolve({ data: [] })
	})
	fetchSchemas.mockReset()
	useInsightsWindow().selectedWindow.value = '7d'
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ApplicationDetailDashboard methods', () => {
	it('formats storage in binary units, dropping a trailing .0', async () => {
		const wrapper = await mountDashboard()
		expect(wrapper.vm.formatBytes(0)).toBe('{n} B')
		expect(wrapper.vm.formatBytes('bad')).toBe('{n} B')
		expect(wrapper.vm.formatBytes(1024)).toBe('1 KB')
		expect(wrapper.vm.formatBytes(1536)).toBe('1.5 KB')
		expect(wrapper.vm.formatBytes(5 * 1024 * 1024)).toBe('5 MB')
		expect(wrapper.vm.formatBytes(1024 ** 6)).toBe('1024 PB')
	})

	it('derives the register slug from the version, a stored register or the hybrid app', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		expect(wrapper.vm.registerSlug).toBe('openbuild-hello-staging')
		expect(wrapper.vm.registerUrl()).toBe('/apps/openregister/registers/openbuild-hello-staging')
		expect(wrapper.vm.registerUrl('schemas')).toBe('/apps/openregister/registers/openbuild-hello-staging?tab=schemas')

		wrapper.vm.versions = [{ ...STAGING, register: 'real-register' }, PROD]
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.registerSlug).toBe('real-register')

		const hybrid = await mountDashboard({ appType: 'hybrid' })
		expect(hybrid.vm.registerSlug).toBe('hello')
		expect(hybrid.vm.isHybrid).toBe(true)
	})

	it('has no register without an app slug or a version', async () => {
		const noSlug = await mountDashboard({ slug: '' })
		expect(noSlug.vm.registerSlug).toBe('')
		expect(noSlug.vm.registerUrl()).toBeNull()

		const noVersion = await mountDashboard({}, [])
		expect(noVersion.vm.registerSlug).toBe('')
	})

	it('collects each schema once from the active pages with its count', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		wrapper.vm.schemaCounts = { case: '12' }
		expect(wrapper.vm.activeSchemas).toEqual([
			{ id: 'case', name: 'case', objectCount: 12, status: 'active' },
			{ id: 'task', name: 'task', objectCount: 0, status: 'active' },
		])
		expect(wrapper.vm.activePages).toHaveLength(5)
		expect(wrapper.vm.activeMenu).toEqual([])
	})

	it('labels the admin version from production first', async () => {
		const wrapper = await mountDashboard({}, [])
		expect(wrapper.vm.adminVersionLabel).toBe('current')
		expect(wrapper.vm.adminVersionStatus).toBe('')
		wrapper.vm.versions = [STAGING, PROD]
		await select(wrapper, 'stage-uuid')
		expect(wrapper.vm.adminVersionLabel).toBe('1.2.0')
		expect(wrapper.vm.adminVersionStatus).toBe('published')
	})

	it('totals the activity and draws the sparkline', async () => {
		const wrapper = await mountDashboard()
		expect(wrapper.vm.sparklinePoints).toBe('0,30 100,30')
		wrapper.vm.activity = [{ eventCount: 2 }, { eventCount: '4' }, { eventCount: null }]
		expect(wrapper.vm.totalActivityEvents).toBe(6)
		expect(wrapper.vm.sparklinePoints).toBe('0.00,16.00 50.00,2.00 100.00,30.00')

		wrapper.vm.activity = [{ eventCount: 3 }]
		expect(wrapper.vm.sparklinePoints).toBe('50.00,2.00')
	})

	it('opens the schema list and the page designer for the app', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		wrapper.vm.onAddSchema()
		wrapper.vm.onAddPage()
		expect(router.push).toHaveBeenNthCalledWith(1, { name: 'SchemaDesignerList', params: { slug: 'hello' } })
		expect(router.push.mock.calls[1][0]).toMatchObject({ name: 'PageDesigner', params: { slug: 'hello' }, query: { _version: 'staging' } })
	})

	it('does not navigate without an app slug', async () => {
		const wrapper = await mountDashboard({ slug: '' })
		wrapper.vm.onAddSchema()
		wrapper.vm.onAddPage()
		expect(router.push).not.toHaveBeenCalled()
	})

	it('opens the manifest detail of the app', async () => {
		const wrapper = await mountDashboard()
		wrapper.vm.openManifestDetail()
		expect(router.push).toHaveBeenCalledWith({ name: 'ApplicationManifestDetail', params: { objectId: 'app-uuid' } })
	})

	it('forwards a permissions request', async () => {
		const wrapper = await mountDashboard()
		wrapper.vm.onOpenPermissions({ uuid: 'x' })
		expect(wrapper.emitted('open-permissions')).toEqual([[{ uuid: 'x' }]])
	})

	it('reads the insights for the active version and window', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		responses['/insights'] = {
			data: { kpis: { objectCount: 9 }, activity: [{ eventCount: 1 }], schemaCounts: { case: 3 } },
		}
		await wrapper.vm.fetchInsights()

		expect(axiosMock.get).toHaveBeenLastCalledWith(
			'/apps/buildiq/api/applications/app-uuid/versions/stage-uuid/insights',
			{ params: { window: '7d' } },
		)
		expect(wrapper.vm.kpis).toEqual({ activeUsers: 0, objectCount: 9, filesCount: 0, auditEventCount: 0 })
		expect(wrapper.vm.activity).toEqual([{ eventCount: 1 }])
		expect(wrapper.vm.schemaCounts).toEqual({ case: 3 })
		expect(wrapper.vm.loading).toBe(false)
	})

	it('keeps lists empty when the insights are malformed', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		responses['/insights'] = { data: { activity: 'x', schemaCounts: 'y' } }
		await wrapper.vm.fetchInsights()
		expect(wrapper.vm.activity).toEqual([])
		expect(wrapper.vm.schemaCounts).toEqual({})
	})

	it('reads nothing without an active version', async () => {
		const wrapper = await mountDashboard({}, [])
		axiosMock.get.mockClear()
		await wrapper.vm.fetchInsights()
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it('a 404 marks the version inaccessible, resets the figures and offers production', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		wrapper.vm.activity = [{ eventCount: 5 }]
		responses['/insights'] = () => Promise.reject({ response: { status: 404 } })
		await wrapper.vm.fetchInsights()

		expect(wrapper.vm.versionNoLongerAccessible).toBe(true)
		expect(wrapper.vm.activity).toEqual([])
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.banner.actionLabel).toBe('Switch to production')

		wrapper.vm.banner.action()
		expect(wrapper.vm.selectedVersionUuid).toBe('prod-uuid')
		expect(wrapper.vm.versionNoLongerAccessible).toBe(false)
		expect(router.replace).toHaveBeenCalledWith(expect.objectContaining({ name: 'VirtualAppDetail', query: { _version: 'production' } }))
	})

	it('any other failure is kept as an error', async () => {
		const wrapper = await mountDashboard()
		await select(wrapper, 'stage-uuid')
		responses['/insights'] = () => Promise.reject('boom')
		await wrapper.vm.fetchInsights()
		expect(wrapper.vm.error).toBeInstanceOf(Error)
		expect(wrapper.vm.error.message).toBe('boom')
		expect(wrapper.vm.versionNoLongerAccessible).toBe(false)
	})

	it('a hybrid app never shows the banner', async () => {
		const wrapper = await mountDashboard({ appType: 'hybrid' })
		wrapper.vm.versionNoLongerAccessible = true
		expect(wrapper.vm.banner).toBeNull()
	})

	it('does not switch without a production version', async () => {
		const wrapper = await mountDashboard({ productionVersion: null })
		wrapper.vm.switchToProduction()
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('collapses several triggers into one insights read', async () => {
		vi.useFakeTimers()
		const wrapper = await mountDashboard()
		const spy = vi.spyOn(wrapper.vm, 'fetchInsights').mockResolvedValue(undefined)
		wrapper.vm.scheduleInsightsFetch()
		wrapper.vm.scheduleInsightsFetch()
		vi.advanceTimersByTime(199)
		expect(spy).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(spy).toHaveBeenCalledTimes(1)
	})

	it('reads the user delta only when overrides are allowed', async () => {
		const closed = await mountDashboard()
		closed.vm.userDeltaContent = { stale: true }
		await closed.vm.loadUserDeltaContent()
		expect(closed.vm.userDeltaContent).toEqual({})

		const open = await mountDashboard({ allowUserOverrides: true })
		responses['/app-overrides'] = { data: { manifestDelta: { theme: 'dark' } } }
		await open.vm.onUserDeltaChanged()
		expect(axiosMock.get).toHaveBeenLastCalledWith('/apps/buildiq/api/app-overrides/hello/user')
		expect(open.vm.userDeltaContent).toEqual({ theme: 'dark' })

		responses['/app-overrides'] = () => Promise.reject(new Error('403'))
		await open.vm.loadUserDeltaContent()
		expect(open.vm.userDeltaContent).toEqual({})
	})

	it('opens the import wizard with the register\'s schemas', async () => {
		const wrapper = await mountDashboard({ appType: 'hybrid' })
		fetchSchemas.mockResolvedValueOnce([{ slug: 'case' }])
		await wrapper.vm.onImportData()
		expect(fetchSchemas).toHaveBeenCalledWith('hello')
		expect(wrapper.vm.importSchemas).toEqual([{ slug: 'case' }])
		expect(wrapper.vm.showImportWizard).toBe(true)

		fetchSchemas.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.onImportData()
		expect(wrapper.vm.importSchemas).toEqual([])
	})

	it('does not open the import wizard without a register', async () => {
		const wrapper = await mountDashboard({}, [])
		await wrapper.vm.onImportData()
		expect(fetchSchemas).not.toHaveBeenCalled()
		expect(wrapper.vm.showImportWizard).toBe(false)
	})

	it('re-reads the insights after an import', async () => {
		const wrapper = await mountDashboard()
		const spy = vi.spyOn(wrapper.vm, 'fetchInsights').mockResolvedValue(undefined)
		wrapper.vm.onImported()
		expect(spy).toHaveBeenCalledTimes(1)
	})
})
