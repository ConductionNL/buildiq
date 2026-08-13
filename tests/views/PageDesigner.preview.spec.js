/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the PageDesigner live-preview pane (REQ-OBPD-008,
 * change page-designer-live-preview-pane).
 *
 * Covers:
 *  - available branch: when useLivePreview reports available:true and
 *    previewProps() returns a prop bag, the sandboxed CnAppRoot mounts
 *    with the expected appId / manifest / key props and the fallback
 *    ("Save & open preview") is NOT shown.
 *  - unavailable branch: when available:false, the fallback panel + button
 *    render and no CnAppRoot is mounted (regression pin for the pre-chain-
 *    spec-2 degraded path).
 *  - no-write invariant: the sandbox mount never issues a manifest PUT/save
 *    (the preview surface has no save wiring; asserting no update:manifest
 *    is emitted by merely rendering the preview).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, computed } from 'vue'

const axiosGetMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGetMock(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))
// Partial mock: only `translate` is pinned to the raw key. `@nextcloud/vue`
// (Vue 3) additionally imports `getLanguage`/`register` from this module at
// import time, so a total mock makes the whole suite fail to collect.
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, key) => key,
}))

// Keep the registry import light — the preview only needs a resolvable
// map; the real registry drags the whole component graph in.
vi.mock('../../src/registry.js', async () => {
	const { h } = await import('vue')
	return {
		default: {
			SomePage: {
				kind: 'page',
				component: { name: 'SomePage', render: () => h('div') },
			},
		},
	}
})

const validatorErrorsRef = ref([])
const validatorStub = {
	errors: validatorErrorsRef,
	hasErrors: computed(() => validatorErrorsRef.value.length > 0),
	isValidating: ref(false),
	validate: vi.fn(),
	register: vi.fn(),
	unregister: vi.fn(),
	errorsByPrefix: ref(new Map()),
	DEBOUNCE_MS: 300,
}
vi.mock('../../src/composables/useManifestValidator.js', () => ({
	useManifestValidator: () => validatorStub,
}))

// Drive the available/unavailable branch from the test.
const previewAvailableRef = ref(false)
const previewPropsMock = vi.fn(() => null)
vi.mock('../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({
		available: previewAvailableRef,
		previewProps: (...args) => previewPropsMock(...args),
	}),
}))

// Sub-editors + tree editors stubbed so only the right-hand pane matters.
async function stub(name) {
	const { h } = await import('vue')
	return {
		default: {
			name,
			props: [
				'config',
				'pageType',
				'appSlug',
				'dataRegisters',
				'parentRoute',
				'pages',
				'selectedIndex',
				'menu',
			],
			render() {
				return h('div', { class: `${name.toLowerCase()}-stub` }, name)
			},
		},
	}
}
vi.mock('../../src/components/page-editor/IndexPageEditor.vue', () =>
	stub('IndexPageEditor'),
)
vi.mock('../../src/components/page-editor/DetailPageEditor.vue', () =>
	stub('DetailPageEditor'),
)
vi.mock('../../src/components/page-editor/DashboardPageEditor.vue', () =>
	stub('DashboardPageEditor'),
)
vi.mock('../../src/components/page-editor/FormPageEditor.vue', () =>
	stub('FormPageEditor'),
)
vi.mock('../../src/components/page-editor/LogsPageEditor.vue', () =>
	stub('LogsPageEditor'),
)
vi.mock('../../src/components/page-editor/SettingsPageEditor.vue', () =>
	stub('SettingsPageEditor'),
)
vi.mock('../../src/components/page-editor/ChatPageEditor.vue', () =>
	stub('ChatPageEditor'),
)
vi.mock('../../src/components/page-editor/FilesPageEditor.vue', () =>
	stub('FilesPageEditor'),
)
vi.mock('../../src/components/page-editor/CustomPageEditor.vue', () =>
	stub('CustomPageEditor'),
)
vi.mock('../../src/components/page-editor/StubPageEditor.vue', () =>
	stub('StubPageEditor'),
)
vi.mock('../../src/components/page-editor/PageListEditor.vue', () =>
	stub('PageListEditor'),
)
vi.mock('../../src/components/page-editor/MenuTreeEditor.vue', () =>
	stub('MenuTreeEditor'),
)

const PageDesigner = (await import('../../src/views/PageDesigner.vue')).default

function mountDesigner(manifest = { pages: [], menu: [] }, slug = 'hello-world') {
	return mount(PageDesigner, { propsData: { manifest, slug } })
}

describe('PageDesigner live-preview pane (REQ-OBPD-008)', () => {
	beforeEach(() => {
		validatorErrorsRef.value = []
		previewAvailableRef.value = false
		previewPropsMock.mockReset()
		previewPropsMock.mockReturnValue(null)
		axiosGetMock.mockReset()
		axiosGetMock.mockResolvedValue({
			data: { results: [{ slug: 'hello-world' }] },
		})
	})

	it('mounts the sandboxed CnAppRoot when preview is available', async () => {
		previewAvailableRef.value = true
		previewPropsMock.mockImplementation((slug, manifest) => ({
			appId: `openbuild-preview-${slug}`,
			manifest,
			key: 'hash-123',
		}))
		const manifest = { pages: [{ id: 'home', type: 'index' }], menu: [] }
		const wrapper = mountDesigner(manifest, 'hello-world')
		await wrapper.vm.$nextTick()

		// The preview surface renders and the fallback does NOT.
		expect(wrapper.find('.page-designer__preview').exists()).toBe(true)
		expect(wrapper.find('.page-designer__preview-fallback').exists()).toBe(false)

		const appRoot = wrapper.findComponent({ name: 'CnAppRoot' })
		expect(appRoot.exists()).toBe(true)

		// The prop bag the sandbox mounts from (the lib stub declares no
		// props, so assert the resolved computed the template binds to).
		expect(wrapper.vm.livePreviewProps.appId).toBe(
			'openbuild-preview-hello-world',
		)
		expect(wrapper.vm.livePreviewProps.manifest).toEqual(manifest)

		// previewProps was called with the in-flight (slug, manifest).
		expect(previewPropsMock).toHaveBeenCalledWith('hello-world', manifest)
	})

	it('keys the sandbox mount by the manifest content hash for clean re-mounts', async () => {
		previewAvailableRef.value = true
		previewPropsMock.mockImplementation((slug, manifest) => ({
			appId: `openbuild-preview-${slug}`,
			manifest,
			key: 'stable-hash',
		}))
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.livePreviewProps.key).toBe('stable-hash')
	})

	it('renders the degraded fallback when preview is unavailable', async () => {
		previewAvailableRef.value = false
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.page-designer__preview-fallback').exists()).toBe(true)
		expect(wrapper.find('.page-designer__preview').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'CnAppRoot' }).exists()).toBe(false)
		// The "Save & open preview" button is the degraded escape hatch.
		expect(wrapper.find('.page-designer__preview-btn').exists()).toBe(true)
	})

	it('falls back when available but previewProps returns null', async () => {
		previewAvailableRef.value = true
		previewPropsMock.mockReturnValue(null)
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.page-designer__preview-fallback').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'CnAppRoot' }).exists()).toBe(false)
	})

	it('rendering the preview never emits a manifest write (no PUT/save path)', async () => {
		previewAvailableRef.value = true
		previewPropsMock.mockImplementation((slug, manifest) => ({
			appId: `openbuild-preview-${slug}`,
			manifest,
			key: 'k',
		}))
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		await wrapper.vm.$nextTick()
		// The deep manifest watcher may echo the controlled prop, but merely
		// rendering the sandbox must not push a NEW manifest state upward.
		const emissions = wrapper.emitted('update:manifest') || []
		expect(emissions).toHaveLength(0)
	})
})
