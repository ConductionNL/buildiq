/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation tests for the branching methods of
 * `src/components/ApplicationDetailActions.vue`: version-row and production
 * UUID resolution, the versions load, the lazy flow load, publish/unpublish,
 * delete and the save-as-template capture. Each pins both the allowed and the
 * refused outcome of the owner/editor guards it passes through.
 */

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
const { roleMock, fetchSchemasMock, registerMock, recordMock } = vi.hoisted(() => ({
	roleMock: vi.fn(() => 'owner'),
	fetchSchemasMock: vi.fn(async () => [{ slug: 'schema-a' }]),
	registerMock: vi.fn(async () => 'reg-prod'),
	recordMock: vi.fn(async () => null),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: roleMock,
	getCurrentUserGroups: () => ['group1'],
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({ fetchSchemas: fetchSchemasMock }),
}))
vi.mock('../../src/services/appRegister.js', () => ({
	fetchProductionRegister: registerMock,
}))
vi.mock('../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord: recordMock,
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

const application = {
	uuid: 'app-uuid',
	slug: 'my-permits',
	name: 'My permits',
	status: 'draft',
	productionVersion: 'ver-prod',
	manifest: { pages: [] },
	permissions: { owners: ['group1'], editors: [], viewers: [] },
	dataRegisters: [],
}

const t = (app, key) => key

/**
 * Mount the actions bar with a mocked caller role.
 *
 * @param {string} role The caller role the guard sees.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountActions(role = 'owner') {
	roleMock.mockReturnValue(role)
	const push = vi.fn(() => Promise.resolve())
	return shallowMount(ApplicationDetailActions, {
		propsData: { object: { ...application }, objectId: 'app-uuid' },
		mocks: { t, $router: { push } },
		stubs: {
			NcButton: true,
			PermissionsModal: true,
			PermissionHistoryModal: true,
			SaveAsTemplateDialog: true,
			ExportDialog: true,
			AppSettingsModal: true,
		},
	})
}

beforeEach(() => {
	for (const fn of Object.values(axiosMock)) {
		fn.mockReset()
	}
	axiosMock.get.mockResolvedValue({ data: [] })
	axiosMock.post.mockResolvedValue({ data: {} })
	axiosMock.delete.mockResolvedValue({ data: {} })
	fetchSchemasMock.mockClear()
	registerMock.mockReset()
	registerMock.mockResolvedValue('reg-prod')
	recordMock.mockReset()
	recordMock.mockResolvedValue({ ...application, status: 'published' })
})

describe('versionRowUuid', () => {
	it.each([
		[null, ''],
		[{}, ''],
		[{ id: 'a', uuid: 'd', '@self': { id: 'b', uuid: 'c' } }, 'a'],
		[{ uuid: 'd', '@self': { id: 'b', uuid: 'c' } }, 'b'],
		[{ uuid: 'd', '@self': { uuid: 'c' } }, 'c'],
		[{ uuid: 'd' }, 'd'],
	])('resolves %j to %j', (row, expected) => {
		expect(mountActions().vm.versionRowUuid(row)).toBe(expected)
	})
})

describe('productionUuid', () => {
	it.each([
		[undefined, ''],
		['ver-1', 'ver-1'],
		[{ uuid: 'u', id: 'i' }, 'u'],
		[{ id: 'i' }, 'i'],
		[{}, ''],
	])('resolves productionVersion %j to %j', async (pv, expected) => {
		const wrapper = mountActions()
		wrapper.vm.obApp = { ...application, productionVersion: pv }
		expect(wrapper.vm.productionUuid).toBe(expected)
	})

	it('is empty without an app', () => {
		const wrapper = mountActions()
		wrapper.vm.obApp = null
		expect(wrapper.vm.productionUuid).toBe('')
	})
})

describe('loadVersions', () => {
	it('clears the list without an app slug', async () => {
		const wrapper = mountActions()
		wrapper.vm.obApp = { ...application, slug: '' }
		wrapper.vm.versions = [{ slug: 'x' }]
		axiosMock.get.mockClear()
		await wrapper.vm.loadVersions()
		expect(wrapper.vm.versions).toEqual([])
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it.each([
		[[{ slug: 'a' }], [{ slug: 'a' }]],
		[{ results: [{ slug: 'b' }] }, [{ slug: 'b' }]],
		[{}, []],
		[null, []],
	])('reads the response %j as %j', async (data, expected) => {
		const wrapper = mountActions()
		axiosMock.get.mockResolvedValueOnce({ data })
		await wrapper.vm.loadVersions()
		expect(axiosMock.get).toHaveBeenLastCalledWith(
			'/apps/buildiq/api/applications/my-permits/versions',
		)
		expect(wrapper.vm.versions).toEqual(expected)
	})

	it('clears the list when the request fails', async () => {
		const wrapper = mountActions()
		wrapper.vm.versions = [{ slug: 'x' }]
		axiosMock.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.loadVersions()
		expect(wrapper.vm.versions).toEqual([])
	})
})

describe('onSettingsOpen', () => {
	it('only tracks the state when closing', async () => {
		const wrapper = mountActions()
		axiosMock.get.mockClear()
		await wrapper.vm.onSettingsOpen(false)
		expect(wrapper.vm.settingsOpen).toBe(false)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it('does not refetch a filled list or while loading', async () => {
		const wrapper = mountActions()
		axiosMock.get.mockClear()
		wrapper.vm.availableFlows = [{ label: 'a', value: 'a' }]
		await wrapper.vm.onSettingsOpen(true)
		wrapper.vm.availableFlows = []
		wrapper.vm.loadingFlows = true
		await wrapper.vm.onSettingsOpen(true)
		expect(wrapper.vm.settingsOpen).toBe(true)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it.each([
		[{ results: [{ uuid: 'f1', name: 'One' }] }],
		[{ items: [{ uuid: 'f1', name: 'One' }] }],
		[[{ uuid: 'f1', name: 'One' }]],
	])('maps the flows of %j to options', async (data) => {
		const wrapper = mountActions()
		axiosMock.get.mockResolvedValueOnce({ data })
		await wrapper.vm.onSettingsOpen(true)
		expect(axiosMock.get).toHaveBeenLastCalledWith('/apps/openregister/api/flows')
		expect(wrapper.vm.availableFlows).toEqual([{ label: 'One', value: 'f1' }])
		expect(wrapper.vm.loadingFlows).toBe(false)
	})

	it('labels by uuid, takes the @self id and drops flows without one', async () => {
		const wrapper = mountActions()
		axiosMock.get.mockResolvedValueOnce({
			data: [{ uuid: 'f1' }, { name: 'Self', '@self': { id: 's1' } }, { name: 'None' }],
		})
		await wrapper.vm.onSettingsOpen(true)
		expect(wrapper.vm.availableFlows).toEqual([
			{ label: 'f1', value: 'f1' },
			{ label: 'Self', value: 's1' },
		])
	})

	it('reports a failed load', async () => {
		const wrapper = mountActions()
		axiosMock.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.onSettingsOpen(true)
		expect(wrapper.vm.error).toBe('Failed to load flows: down')
		expect(wrapper.vm.loadingFlows).toBe(false)
	})
})

describe('setPublished', () => {
	it.each(['editor', 'viewer', 'none'])('refuses a %s', async (role) => {
		const wrapper = mountActions(role)
		await wrapper.vm.setPublished(true)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('does nothing while a publish is running', async () => {
		const wrapper = mountActions()
		wrapper.vm.publishing = true
		await wrapper.vm.setPublished(true)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it.each([
		[true, 'publish', 'App published — it now appears in the app menu.'],
		[false, 'unpublish', 'App unpublished — removed from the app menu.'],
	])('lets an owner set published=%s', async (flag, action, toast) => {
		const wrapper = mountActions()
		await wrapper.vm.setPublished(flag)
		expect(axiosMock.post).toHaveBeenCalledWith(
			`/apps/buildiq/api/applications/app-uuid/${action}`,
			{},
		)
		expect(recordMock).toHaveBeenCalled()
		expect(wrapper.vm.toast).toBe(toast)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.publishing).toBe(false)
	})

	it.each([
		[true, { response: { data: { detail: 'D' } }, message: 'M' }, 'Publish failed: D'],
		[false, { message: 'M' }, 'Unpublish failed: M'],
		[true, 'raw', 'Publish failed: raw'],
	])('reports a failure (published=%s, %j)', async (flag, err, message) => {
		const wrapper = mountActions()
		axiosMock.post.mockRejectedValueOnce(err)
		await wrapper.vm.setPublished(flag)
		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.toast).toBe('')
		expect(wrapper.vm.publishing).toBe(false)
	})
})

describe('deleteApp', () => {
	it.each(['editor', 'viewer', 'none'])('refuses a %s', async (role) => {
		const wrapper = mountActions(role)
		await wrapper.vm.deleteApp(true)
		expect(axiosMock.delete).not.toHaveBeenCalled()
	})

	it('does nothing while a delete is running', async () => {
		const wrapper = mountActions()
		wrapper.vm.deleting = true
		await wrapper.vm.deleteApp()
		expect(axiosMock.delete).not.toHaveBeenCalled()
	})

	it.each([
		[true, 1],
		[false, 0],
		[undefined, 0],
	])('lets an owner delete (deleteData=%s)', async (flag, param) => {
		const wrapper = mountActions()
		wrapper.vm.deleteOpen = true
		await wrapper.vm.deleteApp(flag)
		expect(axiosMock.delete).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/app-uuid',
			{ params: { deleteData: param } },
		)
		expect(wrapper.vm.deleteOpen).toBe(false)
		expect(wrapper.vm.$router.push).toHaveBeenCalledWith({ name: 'VirtualApps' })
		expect(wrapper.vm.deleting).toBe(false)
	})

	it.each([
		[{ response: { data: { detail: 'D' } }, message: 'M' }, 'Delete failed: D'],
		[{ response: {}, message: 'M' }, 'Delete failed: M'],
		['raw', 'Delete failed: raw'],
	])('reports a failure %j', async (err, message) => {
		const wrapper = mountActions()
		wrapper.vm.deleteOpen = true
		axiosMock.delete.mockRejectedValueOnce(err)
		await wrapper.vm.deleteApp(false)
		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.deleteOpen).toBe(true)
		expect(wrapper.vm.deleting).toBe(false)
	})
})

describe('openSaveAsTemplate', () => {
	/**
	 * Route the GETs: the manifest endpoint answers `manifest`, the
	 * templates read answers one template.
	 *
	 * @param {object|null} manifest The resolved manifest body.
	 * @return {void}
	 */
	function routeGets(manifest) {
		axiosMock.get.mockImplementation((url) => {
			if (url.includes('/manifest')) {
				return Promise.resolve({ data: manifest })
			}
			if (url.includes('application-template')) {
				return Promise.resolve({ data: { results: [{ slug: 'tpl' }] } })
			}
			return Promise.resolve({ data: [] })
		})
	}

	it.each(['viewer', 'none'])('refuses a %s', async (role) => {
		const wrapper = mountActions(role)
		routeGets({ pages: [1] })
		await wrapper.vm.openSaveAsTemplate()
		expect(axiosMock.get).not.toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/my-permits/manifest',
		)
		expect(wrapper.vm.saveTemplateOpen).toBe(false)
	})

	it('lets an editor open the dialog with the resolved manifest', async () => {
		const wrapper = mountActions('editor')
		routeGets({ pages: [1] })
		await wrapper.vm.openSaveAsTemplate()
		expect(wrapper.vm.saveTemplateManifest).toEqual({ pages: [1] })
		expect(registerMock).toHaveBeenCalledWith('my-permits', 'ver-prod')
		expect(fetchSchemasMock).toHaveBeenCalledWith('reg-prod')
		expect(wrapper.vm.saveTemplateSchemas).toEqual([{ slug: 'schema-a' }])
		expect(wrapper.vm.existingTemplates).toEqual([{ slug: 'tpl' }])
		expect(wrapper.vm.saveTemplateOpen).toBe(true)
		expect(wrapper.vm.saveTemplateLoading).toBe(false)
	})

	it('does nothing while a capture is running', async () => {
		const wrapper = mountActions()
		wrapper.vm.saveTemplateLoading = true
		routeGets({ pages: [1] })
		await wrapper.vm.openSaveAsTemplate()
		expect(wrapper.vm.saveTemplateOpen).toBe(false)
	})

	it.each([
		[{ manifest: { a: 1 } }, { a: 1 }],
		[{ manifest: undefined, currentVersion: { manifest: { b: 2 } } }, { b: 2 }],
		[{ manifest: undefined, currentVersion: {} }, {}],
		[{ manifest: undefined }, {}],
	])('falls back from an empty resolved manifest (%j)', async (record, expected) => {
		const wrapper = mountActions()
		wrapper.vm.obApp = { ...application, ...record }
		routeGets(null)
		await wrapper.vm.openSaveAsTemplate()
		expect(wrapper.vm.saveTemplateManifest).toEqual(expected)
	})

	it('offers no schemas when there is no production register', async () => {
		const wrapper = mountActions()
		registerMock.mockResolvedValueOnce('')
		routeGets({ pages: [] })
		await wrapper.vm.openSaveAsTemplate()
		expect(fetchSchemasMock).not.toHaveBeenCalled()
		expect(wrapper.vm.saveTemplateSchemas).toEqual([])
		expect(wrapper.vm.saveTemplateOpen).toBe(true)
	})

	it('reports a failed capture', async () => {
		const wrapper = mountActions()
		axiosMock.get.mockRejectedValue(new Error('down'))
		await wrapper.vm.openSaveAsTemplate()
		expect(wrapper.vm.error).toBe('Could not prepare template capture: down')
		expect(wrapper.vm.saveTemplateOpen).toBe(false)
		expect(wrapper.vm.saveTemplateLoading).toBe(false)
	})
})
