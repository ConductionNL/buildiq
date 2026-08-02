/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the "Save as template" action wiring in
 * `src/components/ApplicationDetailActions.vue` (REQ-SAT-001).
 *
 * Covers:
 *   - The action's visibility follows the same RBAC source of truth as the
 *     edit actions: owner/editor see it, viewer (and none) do not.
 *   - Opening the action gathers the app manifest + companion schemas +
 *     visible templates and opens the dialog with the app context.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))
const { roleMock, fetchSchemasMock } = vi.hoisted(() => ({
	roleMock: vi.fn(() => 'owner'),
	fetchSchemasMock: vi.fn(async () => [{ slug: 'my-permits-permit-application' }]),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: roleMock,
	getCurrentUserGroups: () => ['group1'],
}))
const { useRegisterPickerSpy } = vi.hoisted(() => ({
	useRegisterPickerSpy: vi.fn(),
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: (opts) => {
		useRegisterPickerSpy(opts)
		return {
			fetchSchemas: fetchSchemasMock,
			resolveAppRegister: () => 'openbuild-my-permits',
		}
	},
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

const application = {
	uuid: 'app-uuid',
	slug: 'my-permits',
	name: 'My permits',
	status: 'draft',
	manifest: { pages: [] },
	permissions: { owners: ['group1'], editors: [], viewers: [] },
	dataRegisters: [{ register: 'spectr', label: 'Spectr market intelligence data' }],
}

const t = (app, key, vars) => {
	if (!vars) return key
	let out = key
	for (const k of Object.keys(vars)) {
		out = out.replace(`{${k}}`, String(vars[k]))
	}
	return out
}

/**
 * @param {string} role mocked caller role.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountActions(role) {
	roleMock.mockReturnValue(role)
	axiosMock.get.mockResolvedValue({ data: application })
	return shallowMount(ApplicationDetailActions, {
		propsData: { object: application, objectId: 'app-uuid' },
		mocks: { t, $router: { push: vi.fn() } },
		stubs: { NcButton: true, PermissionsModal: true, PermissionHistoryModal: true, SaveAsTemplateDialog: true, ExportDialog: true },
	})
}

describe('ApplicationDetailActions — Save as template action (REQ-SAT-001)', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		fetchSchemasMock.mockClear()
		useRegisterPickerSpy.mockClear()
	})

	it('offers the action to owners', () => {
		const wrapper = mountActions('owner')
		expect(wrapper.vm.canSaveAsTemplate).toBe(true)
	})

	it('offers the action to editors', () => {
		const wrapper = mountActions('editor')
		expect(wrapper.vm.canSaveAsTemplate).toBe(true)
	})

	it('hides the action from viewers', () => {
		const wrapper = mountActions('viewer')
		expect(wrapper.vm.canSaveAsTemplate).toBe(false)
	})

	it('hides the action when the caller has no role', () => {
		const wrapper = mountActions('none')
		expect(wrapper.vm.canSaveAsTemplate).toBe(false)
	})

	it('openSaveAsTemplate gathers schemas + templates and opens the dialog', async () => {
		const wrapper = mountActions('owner')
		await wrapper.vm.$nextTick()

		// This used to queue a single `mockResolvedValueOnce(...)` and rely on
		// it landing on the templates read. `openSaveAsTemplate()` now makes
		// TWO GETs — it resolves the manifest from
		// `/api/applications/{slug}/manifest` first, then reads the templates —
		// so the one-shot response was consumed by the MANIFEST call and the
		// templates read fell through to the generic `{ data: application }`
		// default, which has no `results` array, leaving `existingTemplates`
		// empty.
		//
		// The production change that added the manifest GET is correct and
		// deliberate (see the comment in ApplicationDetailActions.vue: an
		// Application record carries neither `manifest` nor `currentVersion`,
		// so the old `obApp.manifest` read always fell through to `{}` and
		// saving ANY app as a template was impossible). It is the test that
		// was left behind — and it could only regress silently because no
		// app's JS unit suite had ever run in CI.
		//
		// Routing the mock by URL instead of by call order removes the
		// ordering dependency entirely and lets both responses be asserted.
		axiosMock.get.mockImplementation((url) => {
			if (url.includes('/manifest')) {
				return Promise.resolve({ data: { pages: [] } })
			}
			if (url.includes('application-template')) {
				return Promise.resolve({ data: { results: [{ slug: 'permit-pack', isSeeded: false }] } })
			}
			return Promise.resolve({ data: application })
		})

		await wrapper.vm.openSaveAsTemplate()

		// The manifest comes from the resolving endpoint, not from the
		// Application record.
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/openbuild/api/applications/my-permits/manifest')
		expect(fetchSchemasMock).toHaveBeenCalled()
		expect(wrapper.vm.saveTemplateSchemas).toEqual([{ slug: 'my-permits-permit-application' }])
		expect(wrapper.vm.existingTemplates).toEqual([{ slug: 'permit-pack', isSeeded: false }])
		expect(wrapper.vm.saveTemplateOpen).toBe(true)
		expect(wrapper.vm.saveTemplateManifest).toEqual({ pages: [] })
		// data-registers-runtime task 2.3: the Application's declared
		// dataRegisters are forwarded into the picker used to resolve
		// saveTemplateSchemas.
		expect(useRegisterPickerSpy).toHaveBeenCalledWith({
			appSlug: 'my-permits',
			dataRegisters: application.dataRegisters,
		})
	})
})
