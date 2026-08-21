/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PageDesignerHost.vue (PR #101 designer UI).
 *
 * The route-level host for the visual page designer. Covers:
 *  - created()/load(): app resolution + manifest seeding (version → app →
 *    empty), and the load-failure path.
 *  - Soft capability checks (procest / nldesign / docudesk) flip the flags.
 *  - Computeds: routeSlug, versionSlug, appSchemas (array + map + none),
 *    versionNotFound, applicationUuid, builderUrl.
 *  - onManifestUpdate / onThemePreview / onCreateLinkProperty.
 *  - save(): guard, version PUT, application PUT, and the failure path.
 *  - onThemePreview retargets the in-flight manifest (no OpenBuild-owned
 *    applier, theme-picker-consumes-nldesign REQ-NTS-002/003) and
 *    livePreviewAvailable gates the toggle (design.md OQ-1, task 3.3).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const axiosGetMock = vi.fn()
const axiosPutMock = vi.fn()
const axiosPatchMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => axiosGetMock(...a),
		put: (...a) => axiosPutMock(...a),
		patch: (...a) => axiosPatchMock(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

let versionHolder = null
// When true, the fake behaves like the REAL composable: it starts an async fetch
// and populates `applicationVersion` LATER. The default (false) keeps the old
// synchronous shape so the pre-existing specs read unchanged.
//
// The synchronous default is exactly why this suite passed while the designer was
// broken in production: `load()` seeds the editor FROM `applicationVersion`, and a
// fake that is already resolved at mount time can never reproduce the window in
// which it is still null. See the async spec below.
let versionResolvesAsync = false
vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: () => {
		if (versionResolvesAsync === false) {
			return {
				applicationVersion: ref(versionHolder),
				loading: ref(false),
				error: ref(null),
				ready: Promise.resolve(),
			}
		}
		const applicationVersion = ref(null)
		const loading = ref(true)
		// Resolve on a MACROtask. The real composable's fetchDefaultVersion() makes
		// TWO sequential round trips (/versions, then /objects?slug=) while load()
		// makes one, so in production the version reliably lands AFTER load() has
		// already seeded. A microtask fake would resolve inside load()'s own await
		// and hide the bug — which is precisely what the synchronous fake did.
		const ready = new Promise((resolve) => {
			setTimeout(() => {
				applicationVersion.value = versionHolder
				loading.value = false
				resolve()
			}, 10)
		})
		return { applicationVersion, loading, error: ref(null), ready }
	},
}))

let statusAvailable = true
vi.mock('../../src/composables/useAppStatus.js', () => ({
	useAppStatus: () => {
		const available = ref(false)
		return {
			available,
			checked: ref(false),
			check: () =>
				Promise.resolve().then(() => {
					available.value = statusAvailable
					return statusAvailable
				}),
		}
	},
}))

// REQ-NTS-002 (design.md OQ-1, task 3.3): PageDesignerHost's own
// `livePreviewAvailable` computed reads `useLivePreview().available` —
// default true so the pre-existing onThemePreview specs (written before
// this gate existed) keep exercising the toggle unchanged; the dedicated
// task-3.3 spec below flips it to false.
let livePreviewAvailableValue = true
vi.mock('../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({
		available: {
			get value() {
				return livePreviewAvailableValue
			},
		},
		previewProps: () => null,
	}),
}))

async function childStub(name) {
	const { h } = await import('vue')
	return {
		default: {
			name,
			props: [
				'manifest',
				'slug',
				'schemas',
				'procestAvailable',
				'nldesignAvailable',
				'docudeskAvailable',
			],
			render() {
				return h('div', { class: `${name.toLowerCase()}-stub` })
			},
		},
	}
}
vi.mock('../../src/views/PageDesigner.vue', () => childStub('PageDesigner'))
vi.mock('../../src/components/WorkflowAttachmentsSection.vue', () =>
	childStub('WorkflowAttachmentsSection'),
)
vi.mock('../../src/components/ThemeSection.vue', () => childStub('ThemeSection'))
vi.mock('../../src/components/DocumentAttachmentsSection.vue', () =>
	childStub('DocumentAttachmentsSection'),
)

const PageDesignerHost = (await import('../../src/views/PageDesignerHost.vue'))
	.default

const flush = async (wrapper) => {
	await new Promise((r) => setTimeout(r, 0))
	if (wrapper) await wrapper.vm.$nextTick()
}

function mountHost({
	slug = 'petstore',
	query = {},
	version = null,
	appList = [],
	available = true,
} = {}) {
	versionHolder = version
	statusAvailable = available
	axiosGetMock.mockReset()
	axiosPutMock.mockReset()
	axiosPatchMock.mockReset()
	axiosGetMock.mockResolvedValue({ data: { results: appList } })
	axiosPutMock.mockResolvedValue({ data: {} })
	axiosPatchMock.mockResolvedValue({ data: {} })
	return mount(PageDesignerHost, {
		mocks: { $route: { params: { slug }, query } },
		stubs: { 'router-link': true },
	})
}

describe('PageDesignerHost', () => {
	beforeEach(() => {
		versionHolder = null
		versionResolvesAsync = false
		statusAvailable = true
		livePreviewAvailableValue = true
	})

	it('seeds the manifest from a version that resolves ASYNCHRONOUSLY', async () => {
		// Regression (#174). The real useApplicationVersion kicks off an HTTP fetch
		// and fills `applicationVersion` only when it returns — so at the moment
		// created() runs, it is null. load() seeded EMPTY_MANIFEST from that null and
		// nothing re-seeded when the version arrived, so the designer rendered "No
		// pages yet" for an app whose manifest carried pages, and offered to Save
		// that emptiness over the real thing.
		versionResolvesAsync = true
		const version = {
			manifest: {
				version: '2.0.0',
				menu: [{ label: 'Dashboard' }],
				pages: [{ id: 'Dashboard' }, { id: 'MessagesIndex' }],
			},
		}
		const wrapper = mountHost({
			version,
			// The versioned model keeps NO manifest on the Application itself, so
			// there is nothing to fall back to — the version is the only source.
			appList: [
				{ slug: 'petstore', name: 'Pet Store', '@self': { id: 'app-1' } },
			],
		})
		// Long enough for the version's (macrotask) resolution to land.
		await new Promise((resolve) => setTimeout(resolve, 40))
		await flush(wrapper)

		expect(wrapper.vm.applicationVersion).not.toBe(null)
		expect(wrapper.vm.manifest.pages).toHaveLength(2)
		expect(wrapper.vm.manifest.menu).toHaveLength(1)
		expect(wrapper.vm.manifest.version).toBe('2.0.0')
		// A deep copy, never the caller's object.
		expect(wrapper.vm.manifest).not.toBe(version.manifest)
	})

	it('loads the app and seeds the manifest from the version manifest', async () => {
		const version = { manifest: { version: '2.0.0', pages: [{ id: 'a' }] } }
		const wrapper = mountHost({
			version,
			appList: [
				{
					slug: 'petstore',
					name: 'Pet Store',
					'@self': { id: 'app-1' },
					manifest: { version: '1.0.0' },
				},
			],
		})
		await flush(wrapper)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.application.name).toBe('Pet Store')
		expect(wrapper.vm.manifest.version).toBe('2.0.0')
		expect(wrapper.vm.manifest).not.toBe(version.manifest)
		expect(wrapper.find('.pagedesigner-stub').exists()).toBe(true)
	})

	it('falls back to the app manifest, then to the empty manifest', async () => {
		const withApp = mountHost({
			appList: [{ slug: 'petstore', manifest: { version: '7.0.0' } }],
		})
		await flush(withApp)
		expect(withApp.vm.manifest.version).toBe('7.0.0')

		const bare = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(bare)
		expect(bare.vm.manifest).toEqual({ version: '1.0.0', menu: [], pages: [] })
	})

	it('renders the no-app empty state when the slug does not resolve', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'other' }] })
		await flush(wrapper)
		expect(wrapper.vm.application).toBe(null)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(true)
	})

	it('surfaces a load error and clears the application', async () => {
		const wrapper = mountHost({ appList: [] })
		// Let the MOUNT-TIME load settle before arming the rejection. mountHost()
		// mounts the component, whose own mounted hook calls load(); a
		// `mockRejectedValueOnce` queued before that settles is consumed by the
		// mount's request instead of the explicit one below, so the assertion
		// reads a component that loaded fine. vitest 1 happened to order these
		// the other way round.
		await flush(wrapper)
		axiosGetMock.mockRejectedValueOnce(new Error('load boom'))
		wrapper.vm.load()
		await flush(wrapper)
		expect(wrapper.vm.application).toBe(null)
		// This host relies on the global `t` stub (setup.js), which returns the
		// bare key without interpolating {error}.
		expect(wrapper.vm.error).toContain('Failed to load the app')
	})

	it('flips the soft capability flags once the probes resolve', async () => {
		const wrapper = mountHost({
			appList: [{ slug: 'petstore' }],
			available: false,
		})
		await flush(wrapper)
		expect(wrapper.vm.procestAvailable).toBe(false)
		expect(wrapper.vm.nldesignAvailable).toBe(false)
		expect(wrapper.vm.docudeskAvailable).toBe(false)
	})

	describe('appSchemas', () => {
		it('normalizes an array of schemas', async () => {
			const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
			await flush(wrapper)
			wrapper.vm.manifest = {
				schemas: [{ slug: 'pet', title: 'Pet', properties: { a: {} } }],
			}
			expect(wrapper.vm.appSchemas).toEqual([
				{ slug: 'pet', title: 'Pet', properties: { a: {} } },
			])
		})
		it('normalizes a schema map', async () => {
			const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
			await flush(wrapper)
			wrapper.vm.manifest = {
				schemas: { pet: { title: 'Pet', properties: { a: {} } } },
			}
			expect(wrapper.vm.appSchemas).toEqual([
				{ slug: 'pet', title: 'Pet', properties: { a: {} } },
			])
		})
		it('returns [] when the manifest has no schemas', async () => {
			const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
			await flush(wrapper)
			wrapper.vm.manifest = { pages: [] }
			expect(wrapper.vm.appSchemas).toEqual([])
		})
	})

	it('exposes routeSlug and versionSlug from the route', async () => {
		const wrapper = mountHost({
			slug: 'shop',
			query: { _version: 'draft-3' },
			appList: [{ slug: 'shop' }],
		})
		await flush(wrapper)
		expect(wrapper.vm.routeSlug).toBe('shop')
		expect(wrapper.vm.versionSlug).toBe('draft-3')
	})

	it('versionNotFound reflects the version-fetch error state', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		expect(wrapper.vm.versionNotFound).toBe(false)
		wrapper.vm.versionLoading = false
		wrapper.vm.versionError = new Error('404')
		wrapper.vm.applicationVersion = null
		expect(wrapper.vm.versionNotFound).toBe(true)
	})

	it('applicationUuid reads the @self id then the uuid field', async () => {
		const wrapper = mountHost({
			appList: [{ slug: 'petstore', '@self': { id: 'self-id' } }],
		})
		await flush(wrapper)
		expect(wrapper.vm.applicationUuid).toBe('self-id')
		wrapper.vm.application = { uuid: 'plain-uuid' }
		expect(wrapper.vm.applicationUuid).toBe('plain-uuid')
	})

	it('builderUrl links only when the app is published', async () => {
		const wrapper = mountHost({
			appList: [{ slug: 'petstore', status: 'draft' }],
		})
		await flush(wrapper)
		expect(wrapper.vm.builderUrl).toBe('')
		wrapper.vm.application = { slug: 'petstore', status: 'published' }
		expect(wrapper.vm.builderUrl).toBe('/apps/openbuild/builder/petstore')
		wrapper.vm.application = { slug: 'petstore', currentVersion: 'v1' }
		expect(wrapper.vm.builderUrl).toBe('/apps/openbuild/builder/petstore')
		wrapper.vm.application = null
		expect(wrapper.vm.builderUrl).toBe('')
	})

	it('onManifestUpdate replaces the controlled manifest', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		wrapper.vm.onManifestUpdate({ version: '4.0.0' })
		expect(wrapper.vm.manifest.version).toBe('4.0.0')
	})

	it('onThemePreview mutates the in-flight manifest and reverts to the pre-preview baseline on null (design.md Decision 3)', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		wrapper.vm.manifest = {
			version: '1.0.0',
			runtime: { theme: { source: 'nldesign', tokenSet: 'saved-theme' } },
		}
		wrapper.vm.onThemePreview({ primaryColor: '#123' })
		// Mutates THIS SAME manifest object (the one bound to PageDesigner's
		// prop, and therefore the live-preview-pane's CnAppRoot) — no
		// OpenBuild-owned applier call, per REQ-STA-3.
		expect(wrapper.vm.manifest.runtime.theme).toEqual({ primaryColor: '#123' })
		wrapper.vm.onThemePreview(null)
		// Reverts to the theme that was persisted BEFORE the first preview
		// mutation this session — not simply "whatever was there a moment ago".
		expect(wrapper.vm.manifest.runtime.theme).toEqual({
			source: 'nldesign',
			tokenSet: 'saved-theme',
		})
	})

	it('onThemePreview(null) with no active preview is a no-op', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		wrapper.vm.manifest = { version: '1.0.0' }
		wrapper.vm.onThemePreview(null)
		expect(wrapper.vm.manifest).toEqual({ version: '1.0.0' })
	})

	it('livePreviewAvailable reflects useLivePreview().available and is forwarded to ThemeSection (design.md OQ-1, task 3.3)', async () => {
		livePreviewAvailableValue = false
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		expect(wrapper.vm.livePreviewAvailable).toBe(false)
	})

	it('onCreateLinkProperty deep-links to the schema designer (and no-ops on empty)', async () => {
		const wrapper = mountHost({
			slug: 'petstore',
			appList: [{ slug: 'petstore' }],
		})
		await flush(wrapper)
		// jsdom blocks real navigation, so swap window.location for a writable stub.
		const original = window.location
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: { href: 'about:blank' },
		})
		try {
			wrapper.vm.onCreateLinkProperty('')
			expect(window.location.href).toBe('about:blank')
			wrapper.vm.onCreateLinkProperty('pet')
			expect(window.location.href).toBe(
				'/apps/openbuild/builder/petstore/schemas?schema=pet&addProperty=zaakUrl',
			)
		} finally {
			Object.defineProperty(window, 'location', {
				configurable: true,
				writable: true,
				value: original,
			})
		}
	})

	it('save() is a no-op without an application or uuid', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		wrapper.vm.application = null
		await wrapper.vm.save()
		expect(axiosPutMock).not.toHaveBeenCalled()
	})

	it('save() persists onto the ApplicationVersion when one is resolved', async () => {
		const version = { '@self': { id: 'ver-uuid' }, manifest: {} }
		const wrapper = mountHost({
			version,
			appList: [{ slug: 'petstore', '@self': { id: 'app-1' } }],
		})
		await flush(wrapper)
		axiosPatchMock.mockResolvedValueOnce({
			data: { '@self': { id: 'ver-uuid' }, saved: true },
		})
		await wrapper.vm.save()
		// PATCH the manifest only — a full-object PUT trips the reserved `register`
		// property collision on the ApplicationVersion schema.
		expect(axiosPatchMock).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/openbuild/applicationVersion/ver-uuid',
			{ manifest: expect.any(Object) },
		)
		expect(axiosPutMock).not.toHaveBeenCalled()
		expect(wrapper.vm.toast).toBe('Pages saved.')
	})

	it('save() falls back to the Application object PUT when there is no version', async () => {
		const wrapper = mountHost({
			version: null,
			appList: [{ slug: 'petstore', '@self': { id: 'app-1' } }],
		})
		await flush(wrapper)
		axiosPutMock.mockResolvedValueOnce({
			data: { '@self': { id: 'app-1' }, saved: true },
		})
		await wrapper.vm.save()
		expect(axiosPutMock).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/openbuild/application/app-1',
			expect.objectContaining({ manifest: expect.any(Object) }),
		)
		expect(wrapper.vm.toast).toBe('Pages saved.')
	})

	it('save() surfaces a persistence failure', async () => {
		const wrapper = mountHost({
			version: null,
			appList: [{ slug: 'petstore', '@self': { id: 'app-1' } }],
		})
		await flush(wrapper)
		axiosPutMock.mockRejectedValueOnce(new Error('put failed'))
		await wrapper.vm.save()
		expect(wrapper.vm.error).toContain('Failed to save')
	})

	it('unmounts cleanly with no OpenBuild-owned theme teardown call (REQ-NTS-003 — CnAppRoot tears itself down)', async () => {
		const wrapper = mountHost({ appList: [{ slug: 'petstore' }] })
		await flush(wrapper)
		expect(wrapper.vm.appTheme).toBeUndefined()
		expect(() => wrapper.unmount()).not.toThrow()
	})

	// --- builder-undo-redo: REQ-BUR-004 session boundaries ------------------

	describe('sessionKey / saveCounter (REQ-BUR-004)', () => {
		it('composes slug:versionSlug:saveCounter and changes on a successful save', async () => {
			const version = { '@self': { id: 'ver-uuid' }, manifest: {} }
			const wrapper = mountHost({
				slug: 'petstore',
				query: { _version: 'staging' },
				version,
				appList: [{ slug: 'petstore', '@self': { id: 'app-1' } }],
			})
			await flush(wrapper)
			expect(wrapper.vm.sessionKey).toBe('petstore:staging:0')
			axiosPatchMock.mockResolvedValueOnce({
				data: { '@self': { id: 'ver-uuid' } },
			})
			await wrapper.vm.save()
			expect(wrapper.vm.saveCounter).toBe(1)
			expect(wrapper.vm.sessionKey).toBe('petstore:staging:1')
		})

		it('increments saveCounter on the Application PUT fallback branch too', async () => {
			const wrapper = mountHost({
				version: null,
				appList: [{ slug: 'petstore', '@self': { id: 'app-1' } }],
			})
			await flush(wrapper)
			axiosPutMock.mockResolvedValueOnce({
				data: { '@self': { id: 'app-1' } },
			})
			await wrapper.vm.save()
			expect(wrapper.vm.saveCounter).toBe(1)
		})

		it('does NOT increment saveCounter on a failed save', async () => {
			const wrapper = mountHost({
				version: null,
				appList: [{ slug: 'petstore', '@self': { id: 'app-1' } }],
			})
			await flush(wrapper)
			axiosPutMock.mockRejectedValueOnce(new Error('put failed'))
			await wrapper.vm.save()
			expect(wrapper.vm.saveCounter).toBe(0)
		})

		it('a versionSlug change reloads the manifest — fixes the HEAD gap where only resolveVersion() ran', async () => {
			const wrapper = mountHost({
				slug: 'petstore',
				query: {},
				version: { manifest: { version: '1.0.0', pages: [] } },
				appList: [
					{
						slug: 'petstore',
						'@self': { id: 'app-1' },
						manifest: { version: '0.0.1' },
					},
				],
			})
			await flush(wrapper)
			expect(wrapper.vm.manifest.version).toBe('1.0.0')
			// Simulate the resolved version changing for the new ?_version=.
			versionHolder = {
				manifest: { version: '2.0.0', pages: [{ id: 'staged' }] },
			}
			wrapper.vm.$route.query._version = 'staging'
			// Invoke the versionSlug watcher directly — the static `mocks.$route`
			// harness isn't reactive, so this proves the watcher body itself
			// (resolveVersion() + load()) reloads the manifest, not just that
			// some other reactive path happens to.
			wrapper.vm.$options.watch.versionSlug.call(wrapper.vm)
			await flush(wrapper)
			expect(wrapper.vm.manifest.version).toBe('2.0.0')
		})
	})
})
