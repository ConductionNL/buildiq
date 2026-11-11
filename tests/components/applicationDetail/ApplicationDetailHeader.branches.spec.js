// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

// Characterisation tests for the branching methods of
// ApplicationDetailHeader.vue: the caller-role badge (allowed and refused
// buckets), version selection and every outcome of a promotion.

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

const router = { push: vi.fn(), replace: vi.fn() }
const route = {
	name: 'VirtualAppDetail',
	params: { objectId: 'app-uuid' },
	query: {},
}

const application = {
	'@self': { id: 'app-uuid' },
	id: 'app-uuid',
	slug: 'shop',
	name: 'Shop',
	productionVersion: 'prod-uuid',
	permissions: {
		owners: ['user:alice'],
		editors: ['bob'],
		viewers: ['user:carol'],
	},
}
const versions = [
	{ id: 'dev-uuid', slug: 'development', name: 'Development', promotesTo: 'prod-uuid' },
	{ id: 'prod-uuid', slug: 'production', name: 'Production', promotesTo: null },
]

function mountHeader(props = { object: application, objectId: 'app-uuid' }, mocks = {}) {
	return shallowMount(ApplicationDetailHeader, {
		props,
		global: {
			mocks: { t, $router: router, $route: route, ...mocks },
			stubs: { PromoteVersionDialog: true },
		},
	})
}

beforeEach(() => {
	closePromoteDialog()
	axios.get.mockReset()
	axios.post.mockReset()
	router.replace.mockReset()
	router.replace.mockResolvedValue(undefined)
	axios.get.mockImplementation((url) =>
		url.includes('/versions')
			? Promise.resolve({ data: versions })
			: new Promise(() => {}),
	)
})

describe('callerRole', () => {
	it.each([
		['alice', 'owner'],
		['bob', 'editor'],
		['carol', 'viewer'],
		['dave', ''],
		['', ''],
	])('gives %j the role %j', (uid, role) => {
		const wrapper = mountHeader()
		wrapper.vm.callerUid = uid
		expect(wrapper.vm.callerRole).toBe(role)
	})

	it('prefers the highest bucket and ignores a non-array bucket', () => {
		const wrapper = mountHeader({
			object: {
				...application,
				permissions: { owners: 'alice', editors: ['alice'], viewers: ['alice'] },
			},
			objectId: 'app-uuid',
		})
		wrapper.vm.callerUid = 'alice'
		expect(wrapper.vm.callerRole).toBe('editor')
	})

	it('has no role without permissions', () => {
		const wrapper = mountHeader({
			object: { ...application, permissions: undefined },
			objectId: 'app-uuid',
		})
		wrapper.vm.callerUid = 'alice'
		expect(wrapper.vm.callerRole).toBe('')
	})
})

describe('selectVersion', () => {
	it.each([[null], [{ uuid: 'u' }]])('ignores %j', (version) => {
		const wrapper = mountHeader()
		wrapper.vm.selectedVersionUuid = 'before'
		wrapper.vm.selectVersion(version)
		expect(wrapper.vm.selectedVersionUuid).toBe('before')
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('selects the version and replaces the route', () => {
		const wrapper = mountHeader()
		wrapper.vm.selectVersion({ uuid: 'dev-uuid', slug: 'development' })
		expect(wrapper.vm.selectedVersionUuid).toBe('dev-uuid')
		expect(router.replace).toHaveBeenCalledTimes(1)
		expect(router.replace.mock.calls[0][0].name).toBe('VirtualAppDetail')
		expect(router.replace.mock.calls[0][0].query._version).toBe('development')
	})

	it('falls back to the detail route without a current route', () => {
		const wrapper = mountHeader(undefined, { $route: undefined })
		wrapper.vm.selectVersion({ uuid: 'dev-uuid', slug: 'development' })
		expect(router.replace.mock.calls[0][0].name).toBe('VirtualAppDetail')
	})

	it('still selects without a router', () => {
		const wrapper = mountHeader(undefined, { $router: undefined })
		wrapper.vm.selectVersion({ uuid: 'dev-uuid', slug: 'development' })
		expect(wrapper.vm.selectedVersionUuid).toBe('dev-uuid')
	})
})

describe('onPromoteConfirm', () => {
	it('does nothing without a source version', async () => {
		const wrapper = mountHeader()
		await flushPromises()
		await wrapper.vm.onPromoteConfirm({ strategy: 's' })
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.promoteNotice).toBe('')
	})

	it('does nothing while a promotion runs', async () => {
		const wrapper = mountHeader()
		await flushPromises()
		openPromoteDialog({ sourceVersion: wrapper.vm.versions[0], application })
		wrapper.vm.promoting = true
		await wrapper.vm.onPromoteConfirm({ strategy: 's' })
		expect(axios.post).not.toHaveBeenCalled()
		expect(promoteDialog.open).toBe(true)
	})

	it('does nothing for a source version without a uuid', async () => {
		const wrapper = mountHeader()
		await flushPromises()
		openPromoteDialog({ sourceVersion: { slug: 'x' }, application })
		await wrapper.vm.onPromoteConfirm({ strategy: 's' })
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('names the versions by slug and an absent target as empty', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountHeader()
		await flushPromises()
		openPromoteDialog({ sourceVersion: { id: 'x-uuid', slug: 'x' }, application })
		await wrapper.vm.onPromoteConfirm({ strategy: 's' })
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/app-uuid/versions/x-uuid/promote',
			{ strategy: 's' },
		)
		expect(wrapper.vm.promoteNotice).toBe('x is promoted to .')
		expect(wrapper.vm.promoteFailed).toBe(false)
		expect(wrapper.vm.promoting).toBe(false)
		expect(promoteDialog.open).toBe(false)
	})

	it('shows the in-flight notice and closes the dialog before the request settles', async () => {
		let settle
		axios.post.mockReturnValue(new Promise((resolve) => { settle = resolve }))
		const wrapper = mountHeader()
		await flushPromises()
		openPromoteDialog({ sourceVersion: wrapper.vm.versions[0], application })
		const run = wrapper.vm.onPromoteConfirm({ strategy: 's' })
		expect(wrapper.vm.promoting).toBe(true)
		expect(promoteDialog.open).toBe(false)
		expect(wrapper.vm.promoteNotice).toBe('Promoting Development to Production…')
		settle({ data: {} })
		await run
		expect(wrapper.vm.promoteNotice).toBe('Development is promoted to Production.')
	})

	it.each([
		[{ response: { data: { message: 'M', detail: 'D' } }, message: 'E' }, 'M'],
		[{ response: { data: { detail: 'D' } }, message: 'E' }, 'D'],
		[{ message: 'E' }, 'E'],
		[null, ''],
	])('explains the failure %j', async (err, reason) => {
		axios.post.mockRejectedValue(err)
		const wrapper = mountHeader()
		await flushPromises()
		openPromoteDialog({ sourceVersion: wrapper.vm.versions[0], application })
		await wrapper.vm.onPromoteConfirm({ strategy: 's' })
		expect(wrapper.vm.promoteNotice).toBe(`Could not promote Development: ${reason}`)
		expect(wrapper.vm.promoteFailed).toBe(true)
		expect(wrapper.vm.promoting).toBe(false)
	})
})
