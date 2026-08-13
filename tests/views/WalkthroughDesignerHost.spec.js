/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for WalkthroughDesignerHost.vue (ADR-043, PR #101 designer UI).
 *
 * Covers the route-level host that resolves the app + ApplicationVersion,
 * seeds the walkthrough editor manifest, and persists edits back:
 *  - created()/load(): seed from the version manifest, then the app manifest,
 *    then EMPTY_MANIFEST; surface load errors.
 *  - computed routeSlug / versionSlug / applicationUuid.
 *  - onManifestUpdate mutates the controlled manifest.
 *  - save(): version path (openbuild versions PUT), app fallback path
 *    (openregister objects PUT), the "no app to save to" guard, the
 *    re-entrancy guard, and the failure path.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const axiosGetMock = vi.fn()
const axiosPutMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => axiosGetMock(...a),
		put: (...a) => axiosPutMock(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

// The composable is exercised by its own spec; here we drive its resolved
// value through a mutable holder so each mount picks up the version under test.
let versionHolder = null
vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: () => ({
		applicationVersion: ref(versionHolder),
		loading: ref(false),
		error: ref(null),
	}),
}))

vi.mock(
	'../../src/components/walkthrough-editor/WalkthroughDesigner.vue',
	async () => {
		const { h } = await import('vue')
		return {
			default: {
				name: 'WalkthroughDesigner',
				props: ['manifest', 'appSlug', 'versionSlug'],
				render() {
					return h('div', { class: 'walkthrough-designer-stub' })
				},
			},
		}
	},
)

const WalkthroughDesignerHost = (
	await import('../../src/views/WalkthroughDesignerHost.vue')
).default

const flush = async (wrapper) => {
	await new Promise((r) => setTimeout(r, 0))
	if (wrapper) await wrapper.vm.$nextTick()
}

function mountHost({ slug = 'petstore', version, appList } = {}) {
	versionHolder = version ?? null
	axiosGetMock.mockReset()
	axiosPutMock.mockReset()
	axiosGetMock.mockResolvedValue({ data: { results: appList ?? [] } })
	axiosPutMock.mockResolvedValue({ data: {} })
	return mount(WalkthroughDesignerHost, {
		mocks: {
			$route: { params: { slug }, query: {} },
		},
	})
}

describe('WalkthroughDesignerHost', () => {
	beforeEach(() => {
		versionHolder = null
	})

	it('seeds the manifest from the resolved version manifest', async () => {
		const version = {
			slug: 'v1',
			manifest: {
				version: '2.0.0',
				pages: [{ id: 'p' }],
				walkthrough: { enabled: true, tours: [1] },
			},
		}
		const wrapper = mountHost({
			version,
			appList: [
				{
					slug: 'petstore',
					name: 'Pet Store',
					manifest: { version: '9.9.9' },
				},
			],
		})
		await flush(wrapper)
		expect(wrapper.vm.application.name).toBe('Pet Store')
		// Version manifest wins over the app manifest.
		expect(wrapper.vm.manifest.version).toBe('2.0.0')
		// Deep clone — not the same reference.
		expect(wrapper.vm.manifest).not.toBe(version.manifest)
		expect(wrapper.find('.walkthrough-designer-stub').exists()).toBe(true)
	})

	it('falls back to the application manifest when the version has none', async () => {
		const wrapper = mountHost({
			version: { slug: 'v1' },
			appList: [{ slug: 'petstore', manifest: { version: '5.5.5' } }],
		})
		await flush(wrapper)
		expect(wrapper.vm.manifest.version).toBe('5.5.5')
	})

	it('uses the empty manifest when neither version nor app carry one', async () => {
		const wrapper = mountHost({
			version: null,
			appList: [{ slug: 'petstore' }],
		})
		await flush(wrapper)
		expect(wrapper.vm.manifest.walkthrough).toEqual({ enabled: true, tours: [] })
		expect(wrapper.vm.manifest.pages).toEqual([])
	})

	it('surfaces a load error when the fetch rejects', async () => {
		const wrapper = mountHost({ appList: [] })
		axiosGetMock.mockRejectedValueOnce(new Error('network down'))
		wrapper.vm.load()
		await flush(wrapper)
		expect(wrapper.vm.application).toBe(null)
		expect(wrapper.vm.error).toContain('network down')
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(true)
	})

	it('exposes routeSlug, versionSlug and applicationUuid computeds', async () => {
		const wrapper = mount(WalkthroughDesignerHost, {
			mocks: {
				$route: { params: { slug: 'shop' }, query: { _version: 'draft-2' } },
			},
		})
		await flush(wrapper)
		expect(wrapper.vm.routeSlug).toBe('shop')
		expect(wrapper.vm.versionSlug).toBe('draft-2')
		wrapper.vm.application = { '@self': { id: 'uuid-1' } }
		expect(wrapper.vm.applicationUuid).toBe('uuid-1')
		wrapper.vm.application = { uuid: 'uuid-2' }
		expect(wrapper.vm.applicationUuid).toBe('uuid-2')
	})

	it('onManifestUpdate replaces the controlled manifest', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		wrapper.vm.onManifestUpdate({ version: '3.3.3', pages: [] })
		expect(wrapper.vm.manifest.version).toBe('3.3.3')
	})

	it('save() persists to the versions endpoint when a version is resolved', async () => {
		const version = { slug: 'draft-1', manifest: { version: '1.0.0' } }
		const wrapper = mountHost({
			version,
			appList: [{ slug: 'petstore', name: 'Pet Store' }],
		})
		await flush(wrapper)
		axiosPutMock.mockResolvedValueOnce({
			data: { version: { slug: 'draft-1', saved: true } },
		})
		await wrapper.vm.save()
		expect(axiosPutMock).toHaveBeenCalledWith(
			'/apps/openbuild/api/applications/petstore/versions/draft-1',
			{ manifest: wrapper.vm.manifest },
		)
		expect(wrapper.vm.applicationVersion).toEqual({
			slug: 'draft-1',
			saved: true,
		})
		expect(wrapper.vm.toast).toBe('Walkthrough saved.')
	})

	it('save() falls back to the application object PUT for un-versioned apps', async () => {
		const wrapper = mountHost({
			version: null,
			appList: [
				{ slug: 'petstore', '@self': { id: 'app-uuid' }, manifest: {} },
			],
		})
		await flush(wrapper)
		axiosPutMock.mockResolvedValueOnce({
			data: { '@self': { id: 'app-uuid' }, updated: true },
		})
		await wrapper.vm.save()
		expect(axiosPutMock).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/openbuild/application/app-uuid',
			expect.objectContaining({ manifest: wrapper.vm.manifest }),
		)
		expect(wrapper.vm.toast).toBe('Walkthrough saved.')
	})

	it('save() reports "no app to save to" when there is no version and no uuid', async () => {
		const wrapper = mountHost({ version: null, appList: [] })
		await flush(wrapper)
		wrapper.vm.application = null
		await wrapper.vm.save()
		expect(axiosPutMock).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('No app to save to.')
	})

	it('save() is guarded against re-entrancy while saving', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		wrapper.vm.saving = true
		await wrapper.vm.save()
		expect(axiosPutMock).not.toHaveBeenCalled()
	})

	it('save() surfaces a persistence failure', async () => {
		const version = { slug: 'draft-1', manifest: {} }
		const wrapper = mountHost({ version, appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		axiosPutMock.mockRejectedValueOnce(new Error('save failed'))
		await wrapper.vm.save()
		expect(wrapper.vm.error).toContain('save failed')
	})
})
