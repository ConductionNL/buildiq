/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation tests for the settings and navigation methods of
 * `src/components/ApplicationDetailActions.vue`: the owner-only patches
 * (overrides, flows, data registers, permissions), the support-note editor
 * load, the version URL, the designer/automations routes and the toast after
 * a template save. Each pins both the allowed and the refused outcome.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
const { roleMock } = vi.hoisted(() => ({ roleMock: vi.fn(() => 'owner') }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: roleMock,
	getCurrentUserGroups: () => ['group1'],
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({ fetchSchemas: vi.fn(async () => []) }),
}))
vi.mock('../../src/services/appRegister.js', () => ({
	fetchProductionRegister: vi.fn(async () => 'reg-prod'),
}))
vi.mock('../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord: vi.fn(async () => null),
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

const OBJECTS_URL = '/apps/openregister/api/objects/buildiq/built-app/app-uuid'

const application = {
	'@self': { id: 'app-uuid' },
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
 * @param {object} extraMocks Extra instance mocks.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountActions(role = 'owner', extraMocks = {}) {
	roleMock.mockReturnValue(role)
	const push = vi.fn(() => Promise.resolve())
	return shallowMount(ApplicationDetailActions, {
		propsData: { object: { ...application }, objectId: 'app-uuid' },
		mocks: { t, $router: { push }, ...extraMocks },
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
	axiosMock.put.mockImplementation(async (url, body) => ({ data: { results: body } }))
})

describe('owner-only settings patches', () => {
	it.each([
		['setAllowOverrides', true, { allowUserOverrides: true }],
		['setFlows', [{ flow: 'f-1' }], { flows: [{ flow: 'f-1' }] }],
		['setDataRegisters', ['permits-db'], { dataRegisters: ['permits-db'] }],
		['onPermissionsSave', { owners: ['g2'] }, { permissions: { owners: ['g2'] } }],
	])('%s PUTs the merged application for an owner', async (method, arg, patch) => {
		const wrapper = mountActions('owner')
		await flushPromises()
		wrapper.vm.error = 'stale'
		await wrapper.vm[method](arg)
		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.put.mock.calls[0]
		expect(url).toBe(OBJECTS_URL)
		expect(body).toEqual({ ...application, ...patch })
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.obApp).toEqual({ ...application, ...patch })
	})

	it.each(['setAllowOverrides', 'setFlows', 'setDataRegisters', 'onPermissionsSave'])(
		'%s writes nothing for an editor',
		async (method) => {
			const wrapper = mountActions('editor')
			await flushPromises()
			await wrapper.vm[method]({})
			expect(axiosMock.put).not.toHaveBeenCalled()
		},
	)

	it.each([
		['setAllowOverrides', 'Failed to save settings: boom'],
		['setFlows', 'Failed to save settings: boom'],
		['setDataRegisters', 'Failed to save settings: boom'],
		['onPermissionsSave', 'Failed to save permissions: boom'],
	])('%s reports a failed save', async (method, message) => {
		axiosMock.put.mockRejectedValue(new Error('boom'))
		const wrapper = mountActions('owner')
		await flushPromises()
		await wrapper.vm[method]({})
		expect(wrapper.vm.error).toBe(message)
	})

	it('closes the permissions modal only after a successful save', async () => {
		const wrapper = mountActions('owner')
		await flushPromises()
		wrapper.vm.permissionsOpen = true
		axiosMock.put.mockRejectedValueOnce(new Error('nope'))
		await wrapper.vm.onPermissionsSave({ owners: [] })
		expect(wrapper.vm.permissionsOpen).toBe(true)
		await wrapper.vm.onPermissionsSave({ owners: [] })
		expect(wrapper.vm.permissionsOpen).toBe(false)
	})
})

describe('support-note editor', () => {
	it('loads a deep copy of the manifest and opens the editor', async () => {
		const manifest = { menu: [{ id: 'home' }] }
		axiosMock.get.mockResolvedValue({ data: manifest })
		const wrapper = mountActions()
		await flushPromises()
		axiosMock.get.mockClear()
		await wrapper.vm.openSupportEditor()
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/my-permits/manifest')
		expect(wrapper.vm.supportOpen).toBe(true)
		expect(wrapper.vm.supportWorkingManifest).toEqual(manifest)
		expect(wrapper.vm.supportWorkingManifest).not.toBe(manifest)

		wrapper.vm.onSupportClose()
		expect(wrapper.vm.supportOpen).toBe(false)
		expect(wrapper.vm.supportWorkingManifest).toBe(null)
	})

	it('reports a failed load and stays closed', async () => {
		const wrapper = mountActions()
		await flushPromises()
		axiosMock.get.mockRejectedValue(new Error('500'))
		await wrapper.vm.openSupportEditor()
		expect(wrapper.vm.supportOpen).toBe(false)
		expect(wrapper.vm.error).toBe('Failed to load settings: 500')
	})

	it('does nothing without an app slug', async () => {
		const wrapper = mountActions()
		await flushPromises()
		wrapper.vm.obApp = { ...application, slug: '' }
		axiosMock.get.mockClear()
		await wrapper.vm.openSupportEditor()
		expect(axiosMock.get).not.toHaveBeenCalled()
		expect(wrapper.vm.supportOpen).toBe(false)
	})
})

// experience-multilingual-apps (REQ-BQML-001): the languages live in the
// active version's manifest, read on open and written back through PUT.
describe('app languages', () => {
	const MANIFEST_URL = '/apps/buildiq/api/applications/my-permits/manifest'
	const stored = {
		version: '1.0.0',
		pages: [{ id: 'home' }],
		i18n: { sourceLanguage: 'nl', languages: ['en', 'de'], labels: { de: { Thuis: 'Startseite' } } },
	}

	it('reads the languages when the settings modal opens', async () => {
		const wrapper = mountActions('owner')
		await flushPromises()
		axiosMock.get.mockImplementation(async (url) => ({ data: url === MANIFEST_URL ? stored : [] }))
		await wrapper.vm.loadLanguages()
		expect(wrapper.vm.appLanguages).toEqual({ sourceLanguage: 'nl', languages: ['en', 'de'] })
	})

	it.each(['owner', 'editor'])('%s saves them into the manifest, dropping a removed language\'s translations', async (role) => {
		const wrapper = mountActions(role)
		await flushPromises()
		axiosMock.get.mockImplementation(async (url) => ({ data: url === MANIFEST_URL ? stored : [] }))
		axiosMock.put.mockClear()
		await wrapper.vm.setLanguages({ sourceLanguage: 'nl', languages: ['en'] })
		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.put.mock.calls[0]
		expect(url).toBe(MANIFEST_URL)
		expect(body).toEqual({
			manifest: { version: '1.0.0', pages: [{ id: 'home' }], i18n: { sourceLanguage: 'nl', languages: ['en'], labels: {} } },
		})
		expect(wrapper.vm.appLanguages).toEqual({ sourceLanguage: 'nl', languages: ['en'] })
		expect(wrapper.vm.savingLanguages).toBe(false)
	})

	it('writes nothing for a viewer', async () => {
		const wrapper = mountActions('viewer')
		await flushPromises()
		axiosMock.put.mockClear()
		await wrapper.vm.setLanguages({ sourceLanguage: 'nl', languages: ['en'] })
		expect(axiosMock.put).not.toHaveBeenCalled()
	})

	it('reports a failed save', async () => {
		const wrapper = mountActions('owner')
		await flushPromises()
		axiosMock.get.mockResolvedValue({ data: stored })
		axiosMock.put.mockRejectedValue(new Error('boom'))
		await wrapper.vm.setLanguages({ sourceLanguage: 'nl', languages: [] })
		expect(wrapper.vm.error).toBe('Failed to save settings: boom')
		expect(wrapper.vm.savingLanguages).toBe(false)
	})
})

describe('version URL', () => {
	it('points production at the builder and other versions at ?_version=', async () => {
		const wrapper = mountActions()
		await flushPromises()
		expect(wrapper.vm.versionUrl({ id: 'ver-prod', slug: 'v1' })).toBe('/apps/buildiq/builder/my-permits')
		expect(wrapper.vm.versionUrl({ id: 'ver-2', slug: 'v 2' })).toBe(
			'/apps/buildiq/builder/my-permits?_version=v%202',
		)
	})

	it('is null without an app slug', async () => {
		const wrapper = mountActions()
		await flushPromises()
		wrapper.vm.obApp = null
		expect(wrapper.vm.versionUrl({ id: 'ver-2', slug: 'v2' })).toBe(null)
	})
})

describe('navigation', () => {
	it.each([
		['setup', { mode: 'setup' }],
		['walkthrough', {}],
	])('opens the walkthrough designer on the %s tab', async (mode, query) => {
		const wrapper = mountActions()
		await flushPromises()
		wrapper.vm.openWalkthroughDesigner(mode)
		expect(wrapper.vm.$router.push).toHaveBeenCalledWith({
			name: 'WalkthroughDesigner',
			params: { slug: 'my-permits' },
			query,
		})
	})

	it('routes nowhere without an app slug', async () => {
		const wrapper = mountActions()
		await flushPromises()
		wrapper.vm.obApp = { ...application, slug: '' }
		wrapper.vm.openWalkthroughDesigner('setup')
		wrapper.vm.openAutomations()
		wrapper.vm.editVersion({ id: 'ver-2', slug: 'v2' })
		expect(wrapper.vm.$router.push).not.toHaveBeenCalled()
	})

	it('leaves a deleted app through the router', async () => {
		const wrapper = mountActions()
		await flushPromises()
		wrapper.vm.leaveDeletedApp()
		expect(wrapper.vm.$router.push).toHaveBeenCalledWith({ name: 'VirtualApps' })
	})
})

describe('onTemplateSaved', () => {
	it.each([
		[{ slug: 'tpl', mode: 'update' }, 'Template "{slug}" updated'],
		[{ slug: 'tpl', mode: 'create' }, 'Saved as template "{slug}"'],
	])('closes the dialog and toasts for %j', async (payload, toast) => {
		const wrapper = mountActions()
		await flushPromises()
		wrapper.vm.saveTemplateOpen = true
		wrapper.vm.onTemplateSaved(payload)
		expect(wrapper.vm.saveTemplateOpen).toBe(false)
		expect(wrapper.vm.toast).toBe(toast)
	})
})
