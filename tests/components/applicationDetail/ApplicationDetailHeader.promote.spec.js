// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, key) => params[key] ?? `{${key}}`),
}))

import axios from '@nextcloud/axios'
import ApplicationDetailHeader from '../../../src/components/applicationDetail/ApplicationDetailHeader.vue'
import {
	closePromoteDialog,
	openPromoteDialog,
	promoteDialog,
} from '../../../src/composables/usePromoteDialog.js'

function t(app, key, vars) {
	let out = key
	for (const k of Object.keys(vars || {})) {
		out = out.replace(`{${k}}`, String(vars[k]))
	}
	return out
}
globalThis.t = t

const router = { push: vi.fn(), replace: vi.fn().mockResolvedValue(undefined) }
const route = { name: 'VirtualAppDetail', params: { objectId: 'app-uuid' }, query: {} }

const application = {
	'@self': { id: 'app-uuid' },
	id: 'app-uuid',
	slug: 'shop',
	name: 'Shop',
	productionVersion: 'prod-uuid',
	permissions: { owners: ['user:alice'], editors: [], viewers: [] },
}
const versions = [
	{ id: 'dev-uuid', slug: 'development', name: 'Development', promotesTo: 'prod-uuid', register: 'openbuild-shop-development' },
	{ id: 'prod-uuid', slug: 'production', name: 'Production', promotesTo: null, register: 'openbuild-shop-production' },
]

function mountHeader(props) {
	return shallowMount(ApplicationDetailHeader, {
		props,
		global: {
			mocks: { t, $router: router, $route: route },
			stubs: { PromoteVersionDialog: { name: 'PromoteVersionDialog', props: ['sourceVersion', 'targetVersion', 'application'], template: '<div class="promote-dialog-stub" />' } },
		},
	})
}

describe('ApplicationDetailHeader promotion and loading state', () => {
	beforeEach(() => {
		closePromoteDialog()
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockImplementation((url) => {
			if (url.includes('/versions')) {
				return Promise.resolve({ data: versions })
			}
			return new Promise(() => {})
		})
	})

	it('shows a loading line, not "Untitled application", while the app loads', async () => {
		const wrapper = mountHeader({ objectId: 'app-uuid' })
		await flushPromises()

		expect(wrapper.text()).toContain('Loading app…')
		expect(wrapper.text()).not.toContain('Untitled application')
		expect(wrapper.find('.ob-detail-header__pills').exists()).toBe(false)
	})

	it('opens the promotion dialog from the pill, aimed at the next version', async () => {
		const wrapper = mountHeader({ object: application, objectId: 'app-uuid' })
		wrapper.vm.callerUid = 'alice'
		await flushPromises()

		expect(wrapper.find('.promote-dialog-stub').exists()).toBe(false)
		await wrapper.find('.ob-detail-header__pill-promote').trigger('click')

		const dialog = wrapper.findComponent({ name: 'PromoteVersionDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('sourceVersion').slug).toBe('development')
		expect(dialog.props('targetVersion').slug).toBe('production')
	})

	it('posts the chosen strategy to the promote endpoint and reloads the versions', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountHeader({ object: application, objectId: 'app-uuid' })
		await flushPromises()
		const versionLoads = axios.get.mock.calls.length

		openPromoteDialog({ sourceVersion: wrapper.vm.versions[0], application })
		await wrapper.vm.onPromoteConfirm({ strategy: 'migrate-existing-data' })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/app-uuid/versions/dev-uuid/promote',
			{ strategy: 'migrate-existing-data' },
		)
		expect(promoteDialog.open).toBe(false)
		expect(axios.get.mock.calls.length).toBeGreaterThan(versionLoads)
		expect(wrapper.text()).toContain('Development is promoted to Production.')
	})

	it('shows why a promotion failed', async () => {
		axios.post.mockRejectedValue({ response: { data: { message: 'Target is locked' } } })
		const wrapper = mountHeader({ object: application, objectId: 'app-uuid' })
		await flushPromises()

		openPromoteDialog({ sourceVersion: wrapper.vm.versions[0], application })
		await wrapper.vm.onPromoteConfirm({ strategy: 'migrate-existing-data' })
		await flushPromises()

		expect(wrapper.text()).toContain('Could not promote Development: Target is locked')
	})
})
