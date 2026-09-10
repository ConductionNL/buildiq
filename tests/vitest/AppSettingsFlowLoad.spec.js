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

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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
function mountActions(extraProps = {}) {
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
		propsData: { object: application, objectId: 'app-uuid', ...extraProps },
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
		// Asserted on the descriptor rather than on rendered markup: the bar is
		// one CnActionButtons fed by `actionDescriptors`, so the descriptor's
		// `onSelect` IS the click wiring. (It used to be an NcActionButton
		// carrying data-test="app-settings-action".)
		const wrapper = mountActions()
		await wrapper.vm.$nextTick()

		const action = wrapper.vm.actionDescriptors
			.find((a) => a.id === 'app-settings-action')
		expect(action, 'the owner-only Settings action must be declared').toBeTruthy()

		action.onSelect()
		await wrapper.vm.$nextTick()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.vm.settingsOpen).toBe(true)
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/openregister/api/flows')
	})

	it('declares one cluster: Open app primary with version children, Edit last', async () => {
		const wrapper = mountActions()
		await wrapper.vm.$nextTick()
		const ids = wrapper.vm.actionDescriptors.map((a) => a.id)

		// Open app leads and stays primary — CnActionButtons never collapses a
		// primary action and it costs none of the inline slots.
		expect(ids[0]).toBe('open-app')
		expect(wrapper.vm.actionDescriptors[0].variant).toBe('primary')

		// Settings sits ahead of the chrome editors ("actions before edit").
		expect(ids.indexOf('app-settings-action')).toBeLessThan(ids.indexOf('app-edit-setup'))

		// Every entry either does something or goes somewhere, so none of them
		// renders inert. An entry that ends in a URL declares `href` and is
		// rendered as a real link instead of a dispatched button.
		for (const action of wrapper.vm.actionDescriptors) {
			expect(
				typeof action.onSelect === 'function' || typeof action.href === 'string',
				`action "${action.id}" has neither onSelect nor href`,
			).toBe(true)
		}
	})

	it('declares the URL-bound actions as links, not click handlers', async () => {
		const wrapper = mountActions()
		await wrapper.vm.$nextTick()
		const byId = (id) =>
			wrapper.vm.actionDescriptors.find((a) => a.id === id)

		// Open app and Documentation leave the SPA, so they must be anchors:
		// a click handler cannot offer middle-click or "open in new tab".
		expect(byId('open-app').href).toContain('/apps/buildiq/builder/my-permits')
		expect(byId('open-app').target).toBe('_blank')
		expect(byId('open-app').onSelect).toBeUndefined()
		expect(byId('app-documentation').href).toBe('https://openbuild.conduction.nl')
	})

	it('offers the record Edit only when CnDetailPage hands down openEditForm', async () => {
		const withoutEdit = mountActions()
		await withoutEdit.vm.$nextTick()
		expect(withoutEdit.vm.actionDescriptors.map((a) => a.id))
			.not.toContain('app-edit-record')
	})

	it('inlines exactly the intended two, Edit last', async () => {
		// CnActionButtons promotes the first N collapsible entries in
		// declaration order, so the first two ARE the header's buttons (beside
		// the never-collapsed "Open app") and the rest are the menu. Pinned
		// because the split is invisible in the descriptor list itself —
		// reordering the computed silently moves a control between the bar and
		// the menu.
		const wrapper = mountActions({ openEditForm: () => {} })
		await wrapper.vm.$nextTick()
		const collapsible = wrapper.vm.actionDescriptors
			.filter((a) => a.variant !== 'primary' && !a.children)
			.map((a) => a.id)

		// Declaration order is left-to-right, so Edit sits nearest the `···`.
		expect(collapsible.slice(0, 2)).toEqual([
			'app-settings-action',
			'app-edit-record',
		])
		// Everything else belongs in the menu, not the bar.
		expect(collapsible.slice(2)).toEqual(
			expect.arrayContaining([
				'app-edit-setup',
				'app-edit-walkthrough',
				'app-permissions',
				'app-save-as-template',
				'app-github',
				'app-permission-history',
			]),
		)
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
