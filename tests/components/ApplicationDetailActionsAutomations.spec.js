/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the Automations entry point on the app detail page.
 *
 * The automations page carries no menu entry by design: automations belong to
 * an app. Nothing linked to it either, so it could only be reached by typing
 * its URL, and an app's automations were invisible from the app itself.
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['admin'],
}))

import axios from '@nextcloud/axios'
import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

function t (app, text, vars) {
	let out = String(text)
	Object.entries(vars || {}).forEach(([k, v]) => {
		out = out.replace(`{${k}}`, v)
	})
	return out
}
globalThis.t = t

const router = { push: vi.fn().mockResolvedValue(undefined) }
const route = { name: 'VirtualAppDetail', params: {}, query: { _version: 'production' } }

const application = {
	'@self': { id: 'app-uuid' },
	id: 'app-uuid',
	slug: 'permit-tracker',
	name: 'Permit tracker',
	permissions: { owners: ['admin'], editors: [], viewers: [] },
}

const versions = [
	{ id: 'version-1', slug: 'production', name: 'Production', register: 'openbuild-permit-tracker-production' },
]

function mountActions() {
	return shallowMount(ApplicationDetailActions, {
		props: { object: application, objectId: 'app-uuid' },
		global: {
			mocks: { t, $router: router, $route: route },
			stubs: {
				CnActionButtons: { name: 'CnActionButtons', props: ['actions', 'inline', 'overflowLabel'], template: '<div class="cn-action-buttons-stub" />' },
			},
		},
	})
}

describe('ApplicationDetailActions automations entry', () => {
	beforeEach(() => {
		router.push.mockClear()
		axios.get.mockReset()
		axios.get.mockImplementation((url) => {
			if (String(url).includes('/versions')) {
				return Promise.resolve({ data: versions })
			}
			return Promise.resolve({ data: { results: [] } })
		})
	})

	it('offers Automations in the action cluster', async () => {
		const wrapper = mountActions()
		await wrapper.vm.$nextTick()

		const ids = wrapper.vm.actionDescriptors.map((a) => a.id)
		expect(ids).toContain('app-automations')
	})

	it('opens the automations page scoped to this app and version', async () => {
		const wrapper = mountActions()
		wrapper.vm.versions = versions
		await wrapper.vm.$nextTick()

		wrapper.vm.openAutomations()

		expect(router.push).toHaveBeenCalledWith({
			name: 'Automations',
			query: { app: 'permit-tracker', version: 'production' },
		})
	})
})
