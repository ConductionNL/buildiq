/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Actions menu and the Version history tab both offer Promote for a
 * version that has a next version, and both open the one promotion dialog the
 * page header mounts. Before this, Promote existed only as a pill button that
 * did nothing.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) => p.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: () => 'owner',
	getCurrentUserGroups: () => [],
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'
import VersionHistory from '../../src/views/VersionHistory.vue'
import { closePromoteDialog, promoteDialog } from '../../src/composables/usePromoteDialog.js'

const t = (app, key, vars) => Object.keys(vars || {}).reduce((out, k) => out.replace(`{${k}}`, vars[k]), key)
globalThis.t = t

const application = {
	uuid: 'app-uuid',
	slug: 'shop',
	name: 'Shop',
	productionVersion: 'prod-uuid',
	permissions: { owners: ['user:alice'], editors: [], viewers: [] },
}
const versions = [
	{ id: 'dev-uuid', slug: 'development', name: 'Development', status: 'draft', promotesTo: 'prod-uuid' },
	{ id: 'prod-uuid', slug: 'production', name: 'Production', status: 'published', promotesTo: null },
]

describe('Promote entry points', () => {
	beforeEach(() => {
		closePromoteDialog()
		axiosMock.get.mockReset()
		axiosMock.get.mockImplementation((url) =>
			Promise.resolve({ data: url.includes('/versions') ? versions : application }),
		)
	})

	it('the Actions menu offers Promote for a version with a next version only', async () => {
		const wrapper = shallowMount(ApplicationDetailActions, {
			props: { object: application, objectId: 'app-uuid' },
			global: { mocks: { t, $router: { push: vi.fn() } } },
		})
		await flushPromises()

		const promotes = wrapper.vm.actionDescriptors.filter((a) => a.id.startsWith('app-promote-'))
		expect(promotes.map((a) => a.id)).toEqual(['app-promote-development'])

		promotes[0].onSelect()
		expect(promoteDialog.open).toBe(true)
		expect(promoteDialog.sourceVersion.slug).toBe('development')
	})

	it('the Version history tab has a Promote button that opens the dialog', async () => {
		const wrapper = shallowMount(VersionHistory, {
			props: { appSlug: 'shop', applicationUuid: 'app-uuid', currentVersionUuid: 'prod-uuid', canEdit: true },
			global: { mocks: { t }, stubs: { RollbackConfirmModal: true, 'router-link': true } },
		})
		await flushPromises()

		const buttons = wrapper.findAll('button').filter((b) => b.text() === 'Promote')
		expect(buttons.length).toBe(1)
		await buttons[0].trigger('click')

		expect(promoteDialog.open).toBe(true)
		expect(promoteDialog.sourceVersion.slug).toBe('development')
	})
})
