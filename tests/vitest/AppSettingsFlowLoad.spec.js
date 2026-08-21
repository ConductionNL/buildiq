/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest regression test for the App settings action in
 * `src/components/ApplicationDetailActions.vue`.
 *
 * The flow picker inside the settings modal is fed by `availableFlows`, which
 * `onSettingsOpen()` fetches lazily. The Actions-menu item used to open the
 * modal by assigning `settingsOpen = true` directly, which skipped that
 * handler entirely — and since the menu item is the ONLY way to open the
 * modal, the picker was empty for every real user. It did not look broken: an
 * empty list renders the honest-sounding "No flows exist on this instance yet"
 * message, so a populated instance reported the same thing an empty one does.
 *
 * The e2e caught it only as a 240 s timeout on an option that never appeared.
 * This test pins the wiring at the cheap layer: opening settings MUST load the
 * flow list.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))
const { roleMock, fetchSchemasMock } = vi.hoisted(() => ({
	roleMock: vi.fn(() => 'owner'),
	fetchSchemasMock: vi.fn(async () => []),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: roleMock,
	getCurrentUserGroups: () => ['group1'],
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchSchemas: fetchSchemasMock,
		resolveAppRegister: () => 'openbuild-my-permits',
	}),
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

const application = {
	uuid: 'app-uuid',
	slug: 'my-permits',
	name: 'My permits',
	status: 'draft',
	manifest: { pages: [] },
	permissions: { owners: ['group1'], editors: [], viewers: [] },
	dataRegisters: [],
}

const t = (app, key) => key

/**
 * Mount the actions bar as an owner, with axios routed by URL.
 *
 * @return {import('@vue/test-utils').Wrapper} the mounted wrapper.
 */
function mountActions() {
	roleMock.mockReturnValue('owner')
	axiosMock.get.mockImplementation((url) => {
		if (url.includes('/apps/openregister/api/flows')) {
			return Promise.resolve({
				data: {
					results: [
						{ uuid: 'flow-uuid-1', name: 'Nightly reconcile' },
						{ uuid: 'flow-uuid-2', name: 'Intake triage' },
					],
				},
			})
		}
		return Promise.resolve({ data: application })
	})
	return shallowMount(ApplicationDetailActions, {
		propsData: { object: application, objectId: 'app-uuid' },
		mocks: { t, $router: { push: vi.fn() } },
		stubs: {
			NcButton: true,
			PermissionsModal: true,
			PermissionHistoryModal: true,
			SaveAsTemplateDialog: true,
			ExportDialog: true,
			AppSettingsModal: true,
			// NcActions must RENDER its slot: the Settings item lives inside
			// the overflow menu, and a plain stub swallows it, which would
			// make this test pass on an absent button rather than on a wired
			// one.
			NcActions: { template: '<div class="nc-actions-stub"><slot /></div>' },
		},
	})
}

describe('ApplicationDetailActions — App settings loads the flow list', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		axiosMock.put.mockReset()
	})

	it('the Settings action goes through onSettingsOpen, not a bare assignment', async () => {
		const wrapper = mountActions()
		await wrapper.vm.$nextTick()

		const action = wrapper.findComponent('[data-test="app-settings-action"]')
		expect(
			action.exists(),
			'the owner-only Settings action must be rendered',
		).toBe(true)

		action.vm.$emit('click')
		await wrapper.vm.$nextTick()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.vm.settingsOpen).toBe(true)
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/openregister/api/flows')
	})

	it('the fetched flows become picker options labelled by name, valued by uuid', async () => {
		const wrapper = mountActions()
		await wrapper.vm.$nextTick()

		await wrapper.vm.onSettingsOpen(true)

		expect(wrapper.vm.availableFlows).toEqual([
			{ label: 'Nightly reconcile', value: 'flow-uuid-1' },
			{ label: 'Intake triage', value: 'flow-uuid-2' },
		])
		expect(wrapper.vm.loadingFlows).toBe(false)
	})

	it('a second open does not refetch — the list is cached', async () => {
		const wrapper = mountActions()
		await wrapper.vm.onSettingsOpen(true)
		const callsAfterFirst = axiosMock.get.mock.calls.filter((call) =>
			String(call[0]).includes('/apps/openregister/api/flows'),
		).length

		await wrapper.vm.onSettingsOpen(false)
		await wrapper.vm.onSettingsOpen(true)

		const callsAfterSecond = axiosMock.get.mock.calls.filter((call) =>
			String(call[0]).includes('/apps/openregister/api/flows'),
		).length
		expect(callsAfterFirst).toBe(1)
		expect(callsAfterSecond).toBe(1)
	})
})
