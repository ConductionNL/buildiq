import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ManifestLayersDetail.vue (layered-versioned-app-deltas,
 * PR #101 designer UI).
 *
 * Covers the routed Manifest-layers page:
 *  - loadAll(): application + admin version + user delta + maintainer overrides.
 *  - RBAC affordances: hasRole / canEdit / canRelease / isAdmin.
 *  - Computeds: appUuid / appSlug / appName / isHybrid / allowUserOverrides /
 *    userMeta.
 *  - User-delta lifecycle: createOverride / resetOverride / onUserDeltaSaved.
 *  - createDraft(): clone prod manifest, auto-name draft-N, POST, refresh.
 *  - Navigation: openInOpenRegister / goBack; formatDate / rowUuid helpers.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGetMock = vi.fn()
const axiosPutMock = vi.fn()
const axiosPostMock = vi.fn()
const axiosDeleteMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => axiosGetMock(...a),
		put: (...a) => axiosPutMock(...a),
		post: (...a) => axiosPostMock(...a),
		delete: (...a) => axiosDeleteMock(...a),
	},
}))
// Substituting generateUrl so asserted endpoints read like the real ones.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p, params) => {
		let out = p
		if (params)
			for (const [k, v] of Object.entries(params))
				out = out.replace(`{${k}}`, v)
		return out
	},
}))
let currentUid = 'alice'
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: currentUid }),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
}))

// Child stubs. Two Vue-2-isms had to go:
//   - `render(h)` — Vue 3 does not pass `h` to a render function; it is
//     imported from `vue` (inside the factory, since vitest hoists `vi.mock`
//     above this file's own imports).
//   - `staticClass` is Vue 2 vnode data. Vue 3 uses `class`; left as-is it
//     renders a literal `staticclass` attribute and `.find('.x-stub')`
//     matches nothing.
vi.mock('../../src/views/VersionHistory.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'VersionHistory',
			props: [
				'appSlug',
				'applicationUuid',
				'currentVersionUuid',
				'canEdit',
				'canRelease',
			],
			methods: { refresh: vi.fn().mockResolvedValue(undefined) },
			render() {
				return h('div', { class: 'version-history-stub' })
			},
		},
	}
})
vi.mock('../../src/modals/UserDeltaEditModal.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'UserDeltaEditModal',
			props: ['open', 'appSlug', 'delta'],
			render() {
				return h('div', { class: 'user-delta-edit-modal-stub' })
			},
		},
	}
})

import { showSuccess } from '@nextcloud/dialogs'
const ManifestLayersDetail = (
	await import('../../src/views/ManifestLayersDetail.vue')
).default

async function flush(wrapper) {
	await new Promise((r) => setTimeout(r, 0))
	await new Promise((r) => setTimeout(r, 0))
	if (wrapper) await wrapper.vm.$nextTick()
}

// Default happy-path router for GET across the several endpoints.
function installGet({ app, userDelta, overrides, versions } = {}) {
	axiosGetMock.mockImplementation((url) => {
		if (url.includes('/user-deltas')) {
			if (overrides === 'forbidden') return Promise.reject(new Error('403'))
			return Promise.resolve({ data: { overrides: overrides ?? [] } })
		}
		if (url.includes('/versions')) {
			return Promise.resolve({ data: versions ?? [] })
		}
		if (url.endsWith('/user')) {
			if (userDelta === 'error') return Promise.reject(new Error('boom'))
			return Promise.resolve({
				data: userDelta ?? { allowed: false, exists: false },
			})
		}
		if (url.includes('/objects/buildiq/built-app/')) {
			if (app === 'error') return Promise.reject(new Error('boom'))
			return Promise.resolve({ data: app ?? null })
		}
		return Promise.resolve({ data: null })
	})
}

function mountDetail({
	objectId = 'app-uuid',
	routeObjectId,
	push = vi.fn().mockResolvedValue(undefined),
} = {}) {
	return mount(ManifestLayersDetail, {
		propsData: { objectId },
		mocks: {
			$route: { params: { objectId: routeObjectId ?? objectId } },
			$router: { push },
		},
	})
}

const APP = {
	uuid: 'app-uuid',
	slug: 'petstore',
	name: 'Pet Store',
	appType: 'hybrid',
	allowUserOverrides: true,
	productionVersion: { uuid: 'prod-ver' },
	permissions: { owners: ['user:alice'], editors: [] },
}

describe('ManifestLayersDetail', () => {
	beforeEach(() => {
		currentUid = 'alice'
		axiosGetMock.mockReset()
		axiosPutMock.mockReset().mockResolvedValue({ data: {} })
		axiosPostMock.mockReset().mockResolvedValue({ data: {} })
		axiosDeleteMock.mockReset().mockResolvedValue({ data: {} })
		showSuccess.mockClear()
	})

	it('loads the app, admin version, user delta, and maintainer overrides', async () => {
		installGet({
			app: APP,
			userDelta: {
				allowed: true,
				exists: true,
				versionUuid: 'user-ver',
				manifestDelta: { x: 1 },
			},
			overrides: [
				{
					owner: 'bob',
					versionUuid: 'v-bob',
					updatedAt: '2026-01-01T00:00:00Z',
				},
			],
		})
		const wrapper = mountDetail()
		await flush(wrapper)
		expect(wrapper.vm.application.name).toBe('Pet Store')
		expect(wrapper.vm.adminVersionUuid).toBe('prod-ver')
		expect(wrapper.vm.userDelta).toEqual({
			allowed: true,
			exists: true,
			versionUuid: 'user-ver',
			manifestDelta: { x: 1 },
		})
		expect(wrapper.vm.canViewUserOverrides).toBe(true)
		expect(wrapper.vm.userOverrides).toHaveLength(1)
		// Title + overrides list render.
		expect(wrapper.find('.ob-manifest-detail__title').text()).toContain('{name}')
		expect(wrapper.findAll('.ob-manifest-detail__override')).toHaveLength(1)
	})

	it('sets an error when the app fails to load', async () => {
		installGet({ app: 'error' })
		const wrapper = mountDetail()
		await flush(wrapper)
		expect(wrapper.vm.error).toBe('Could not load the app.')
		expect(wrapper.find('.ob-manifest-detail__error').exists()).toBe(true)
	})

	it('hides the maintainer section on a 403 from the overrides endpoint', async () => {
		installGet({ app: APP, overrides: 'forbidden' })
		const wrapper = mountDetail()
		await flush(wrapper)
		expect(wrapper.vm.canViewUserOverrides).toBe(false)
		expect(wrapper.vm.userOverrides).toEqual([])
		expect(wrapper.find('.ob-manifest-detail__overrides').exists()).toBe(false)
	})

	describe('computeds & RBAC', () => {
		it('resolves appUuid from prop, record, then route', async () => {
			installGet({ app: APP })
			// prop empty; the route param seeds the initial fetch, and once the
			// record loads the computed resolves from its uuid.
			const wrapper = mountDetail({ objectId: '', routeObjectId: 'app-uuid' })
			await flush(wrapper)
			expect(wrapper.vm.appUuid).toBe('app-uuid')
		})

		it('derives appSlug / appName / isHybrid / allowUserOverrides', async () => {
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.appSlug).toBe('petstore')
			expect(wrapper.vm.appName).toBe('Pet Store')
			expect(wrapper.vm.isHybrid).toBe(true)
			expect(wrapper.vm.allowUserOverrides).toBe(true)
		})

		it('appName falls back to the slug when no name is set', async () => {
			installGet({ app: { uuid: 'app-uuid', slug: 'petstore' } })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.appName).toBe('petstore')
		})

		it('hasRole matches a direct user grant; canEdit / canRelease follow', async () => {
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.hasRole('owners')).toBe(true)
			expect(wrapper.vm.hasRole('editors')).toBe(false)
			expect(wrapper.vm.canEdit).toBe(true)
			expect(wrapper.vm.canRelease).toBe(true)
		})

		it('non-owner without admin cannot edit or release', async () => {
			currentUid = 'mallory'
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.hasRole('owners')).toBe(false)
			expect(wrapper.vm.canRelease).toBe(false)
			expect(wrapper.vm.canEdit).toBe(false)
		})

		it('isAdmin reads OC.isUserAdmin and lets an admin edit', async () => {
			currentUid = 'mallory'
			globalThis.OC = { isUserAdmin: () => true }
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.isAdmin).toBe(true)
			expect(wrapper.vm.canEdit).toBe(true)
			delete globalThis.OC
		})

		it('userMeta reflects the three delta states', async () => {
			installGet({ app: { ...APP, allowUserOverrides: false } })
			const off = mountDetail()
			await flush(off)
			expect(off.vm.userMeta).toBe(
				'Per-user overrides are turned off for this app.',
			)

			installGet({ app: APP, userDelta: { allowed: true, exists: true } })
			const has = mountDetail()
			await flush(has)
			expect(has.vm.userMeta).toBe(
				'Your personal delta, layered over the admin delta.',
			)

			installGet({ app: APP, userDelta: { allowed: true, exists: false } })
			const none = mountDetail()
			await flush(none)
			expect(none.vm.userMeta).toBe('You have no personal override yet.')
		})
	})

	describe('helpers', () => {
		it('formatDate renders a string and handles empties', async () => {
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.formatDate('')).toBe('')
			expect(typeof wrapper.vm.formatDate('2026-01-01T00:00:00Z')).toBe(
				'string',
			)
		})

		it('rowUuid resolves id / @self envelope / uuid', async () => {
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.rowUuid({ id: 'r1' })).toBe('r1')
			expect(wrapper.vm.rowUuid({ '@self': { id: 'r2' } })).toBe('r2')
			expect(wrapper.vm.rowUuid({ uuid: 'r3' })).toBe('r3')
			expect(wrapper.vm.rowUuid(null)).toBe('')
		})

		it('loadAdminVersion accepts a string production version', async () => {
			installGet({ app: { ...APP, productionVersion: 'string-ver' } })
			const wrapper = mountDetail()
			await flush(wrapper)
			expect(wrapper.vm.adminVersionUuid).toBe('string-ver')
		})
	})

	describe('user-delta lifecycle', () => {
		it('createOverride PUTs an empty delta then reloads', async () => {
			installGet({ app: APP, userDelta: { allowed: true, exists: false } })
			const wrapper = mountDetail()
			await flush(wrapper)
			await wrapper.vm.createOverride()
			expect(axiosPutMock).toHaveBeenCalledWith(
				'/apps/buildiq/api/app-overrides/petstore/user',
				{},
			)
		})

		it('createOverride surfaces an error on failure', async () => {
			installGet({ app: APP, userDelta: { allowed: true, exists: false } })
			const wrapper = mountDetail()
			await flush(wrapper)
			axiosPutMock.mockRejectedValueOnce(new Error('nope'))
			await wrapper.vm.createOverride()
			expect(wrapper.vm.error).toBe('Could not create your override.')
		})

		it('resetOverride DELETEs the delta then reloads', async () => {
			installGet({ app: APP, userDelta: { allowed: true, exists: true } })
			const wrapper = mountDetail()
			await flush(wrapper)
			await wrapper.vm.resetOverride()
			expect(axiosDeleteMock).toHaveBeenCalledWith(
				'/apps/buildiq/api/app-overrides/petstore/user',
			)
		})

		it('resetOverride surfaces an error on failure', async () => {
			installGet({ app: APP, userDelta: { allowed: true, exists: true } })
			const wrapper = mountDetail()
			await flush(wrapper)
			axiosDeleteMock.mockRejectedValueOnce(new Error('nope'))
			await wrapper.vm.resetOverride()
			expect(wrapper.vm.error).toBe('Could not reset your override.')
		})

		it('onUserDeltaSaved re-loads the user delta', async () => {
			installGet({
				app: APP,
				userDelta: { allowed: true, exists: true, versionUuid: 'uv' },
			})
			const wrapper = mountDetail()
			await flush(wrapper)
			const spy = vi.spyOn(wrapper.vm, 'loadUserDelta')
			wrapper.vm.onUserDeltaSaved()
			expect(spy).toHaveBeenCalled()
		})

		it('onRollback re-loads all layers', async () => {
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			const spy = vi.spyOn(wrapper.vm, 'loadAll')
			wrapper.vm.onRollback()
			expect(spy).toHaveBeenCalled()
		})
	})

	describe('createDraft', () => {
		it('clones the prod manifest, auto-names draft-N, POSTs and toasts', async () => {
			installGet({
				app: APP,
				versions: [
					{
						id: 'prod-ver',
						slug: 'production',
						manifest: { version: '1.0.0', pages: [{ id: 'x' }] },
					},
					{ id: 'd1', slug: 'draft-1' },
				],
			})
			const wrapper = mountDetail()
			await flush(wrapper)
			await wrapper.vm.createDraft()
			expect(axiosPostMock).toHaveBeenCalledTimes(1)
			const [url, body] = axiosPostMock.mock.calls[0]
			expect(url).toBe('/apps/buildiq/api/applications/petstore/versions')
			expect(body.name).toBe('Draft 2')
			expect(body.slug).toBe('draft-2')
			expect(body.status).toBe('draft')
			expect(body.manifest).toEqual({ version: '1.0.0', pages: [{ id: 'x' }] })
			expect(body.application).toBe('app-uuid')
			expect(showSuccess).toHaveBeenCalled()
		})

		it('reports a detailed error when the draft POST fails', async () => {
			installGet({ app: APP, versions: [] })
			const wrapper = mountDetail()
			await flush(wrapper)
			axiosPostMock.mockRejectedValueOnce({
				response: { data: { detail: 'slug taken' } },
			})
			await wrapper.vm.createDraft()
			expect(wrapper.vm.error).toContain('Could not create a draft.')
			expect(wrapper.vm.error).toContain('slug taken')
		})
	})

	describe('navigation', () => {
		it('openInOpenRegister deep-links to the OR object page (no-op on empty)', async () => {
			installGet({ app: APP })
			const wrapper = mountDetail()
			await flush(wrapper)
			const original = window.location
			Object.defineProperty(window, 'location', {
				configurable: true,
				writable: true,
				value: { href: 'about:blank' },
			})
			try {
				wrapper.vm.openInOpenRegister('')
				expect(window.location.href).toBe('about:blank')
				wrapper.vm.openInOpenRegister('ver-9')
				expect(window.location.href).toBe(
					'/apps/openregister/objects/buildiq/applicationVersion/ver-9',
				)
			} finally {
				Object.defineProperty(window, 'location', {
					configurable: true,
					writable: true,
					value: original,
				})
			}
		})

		it('goBack pushes to the app detail route', async () => {
			const push = vi.fn().mockResolvedValue(undefined)
			installGet({ app: APP })
			const wrapper = mountDetail({ push })
			await flush(wrapper)
			wrapper.vm.goBack()
			expect(push).toHaveBeenCalledWith({
				name: 'VirtualAppDetail',
				params: { objectId: 'app-uuid' },
			})
		})
	})
})
