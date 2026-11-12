/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning ApplicationVersionsTab.vue: the edit / release
 * permissions it derives from the caller's role, the rollback that PUTs a
 * snapshot's manifest onto the active version and leaves the app at draft,
 * and the reload after a release.
 */
import { shallowMount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, loadState } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), put: vi.fn() },
	loadState: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState }))

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

import ApplicationVersionsTab from '../../../src/components/tabs/ApplicationVersionsTab.vue'

const APP = {
	'@self': { id: 'uuid-1' },
	slug: 'my app',
	status: 'published',
	permissions: { owners: ['owners-group'], editors: ['editors-group'], viewers: ['viewers-group'] },
}

function mountTab(object = APP) {
	return shallowMount(ApplicationVersionsTab, { props: { object } })
}

describe('ApplicationVersionsTab permissions', () => {
	beforeEach(() => {
		loadState.mockReset()
	})

	it.each([
		['owners-group', true, true],
		['editors-group', true, false],
		['viewers-group', false, false],
		['stranger', false, false],
	])('a member of %s gets canEdit=%s and canRelease=%s', (group, canEdit, canRelease) => {
		loadState.mockReturnValue([group])
		const vm = mountTab().vm
		expect(vm.canEdit).toBe(canEdit)
		expect(vm.canRelease).toBe(canRelease)
	})
})

describe('ApplicationVersionsTab onRollback', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
		loadState.mockReturnValue(['owners-group'])
	})

	it.each([
		[null],
		[{ version: '1.0.0' }],
		[{ version: '1.0.0', manifest: null }],
	])('ignores a snapshot without a manifest: %j', async (version) => {
		const vm = mountTab().vm
		await vm.onRollback(version)
		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(vm.rollbackError).toBe('')
	})

	it('ignores the rollback while no application is loaded', async () => {
		const vm = mountTab().vm
		vm.obApp = null
		await vm.onRollback({ manifest: { pages: [] } })
		expect(axiosMock.put).not.toHaveBeenCalled()
	})

	it('PUTs the manifest wrapped to the slug route, then patches the app to draft', async () => {
		axiosMock.put
			.mockResolvedValueOnce({ data: {} })
			.mockResolvedValueOnce({ data: { results: { ...APP, status: 'draft' } } })
		const vm = mountTab().vm
		await vm.onRollback({ version: '1.0.0', manifest: { pages: ['a'] } })

		expect(axiosMock.put).toHaveBeenCalledTimes(2)
		expect(axiosMock.put.mock.calls[0]).toEqual([
			'/apps/buildiq/api/applications/my%20app/manifest',
			{ manifest: { pages: ['a'] } },
		])
		expect(axiosMock.put.mock.calls[1][0]).toBe('/apps/openregister/api/objects/buildiq/built-app/uuid-1')
		expect(axiosMock.put.mock.calls[1][1]).toMatchObject({ slug: 'my app', status: 'draft' })
		expect(vm.obApp.status).toBe('draft')
		expect(vm.rollbackError).toBe('')
	})

	it('reports a failed manifest PUT and does not patch the status', async () => {
		axiosMock.put.mockRejectedValueOnce(new Error('denied'))
		const vm = mountTab().vm
		await vm.onRollback({ manifest: { pages: [] } })
		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		expect(vm.rollbackError).toBe('Rollback failed: denied')
		expect(vm.obApp.status).toBe('published')
	})

	it('reports a thrown non-Error value as is', async () => {
		axiosMock.put.mockImplementationOnce(async () => {
			throw 'offline'
		})
		const vm = mountTab().vm
		await vm.onRollback({ manifest: { pages: [] } })
		expect(vm.rollbackError).toBe('Rollback failed: offline')
	})

	it('clears an earlier error when a later rollback starts', async () => {
		axiosMock.put.mockResolvedValue({ data: {} })
		const vm = mountTab().vm
		vm.rollbackError = 'Rollback failed: old'
		await vm.onRollback({ manifest: { pages: [] } })
		expect(vm.rollbackError).toBe('')
	})
})

describe('ApplicationVersionsTab onReleased', () => {
	it('reloads the application from the shared props', async () => {
		loadState.mockReturnValue(['owners-group'])
		const vm = mountTab().vm
		vm.obApp = null
		vm.onReleased()
		await Promise.resolve()
		expect(vm.obApp).toEqual(APP)
	})
})

describe('ApplicationVersionsTab template', () => {
	it('shows the rollback error under the history', async () => {
		loadState.mockReturnValue([])
		const wrapper = mountTab()
		wrapper.vm.rollbackError = 'Rollback failed: x'
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.ob-versions-tab__error').text()).toBe('Rollback failed: x')
	})

	it('passes the production version over the current one to the history', () => {
		loadState.mockReturnValue([])
		const wrapper = mountTab({ ...APP, productionVersion: 'v-prod', currentVersion: 'v-cur' })
		const history = wrapper.findComponent({ name: 'VersionHistory' })
		expect(history.props('currentVersionUuid')).toBe('v-prod')
		expect(history.props('applicationUuid')).toBe('uuid-1')
		expect(history.props('appSlug')).toBe('my app')
	})
})
