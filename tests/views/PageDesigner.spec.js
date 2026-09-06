/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PageDesigner (REQ-OBPD-003).
 *
 * Covers:
 *  - Mount: three panes render (left list, centre editor, right errors).
 *  - Page-type dispatcher picks the correct sub-editor for each `page.type`.
 *  - Unknown / missing type falls back to StubPageEditor.
 *  - selectedIndex switching updates the rendered sub-editor.
 *  - update:config from a sub-editor mutates the right `pages[i].config`
 *    and re-emits update:manifest.
 *  - Validator side-panel reflects errors[] from useManifestValidator.
 *  - canSaveAndPreview is false when slug is empty or errors exist.
 *  - Raw-JSON fallback (StubPageEditor) preserves edits — i.e. when a
 *    page-type is not in SUB_EDITOR_MAP, mounting still works and
 *    update:config is still wired.
 *  - Tabs / pane structure: aside.page-designer__left + section
 *    page-designer__centre + aside.page-designer__right all present.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'

// data-registers-runtime task 2.2: PageDesigner resolves the Application
// record itself (a small, dedicated fetch — see design.md Decision 2), so
// axios + generateUrl need a deterministic mock. `fetchApplicationDataRegisters`
// (created()) and `useApplicationVersion`'s internal lookups (mounted()) both
// call GET .../objects/buildiq/built-app — served from the same fixture
// below; the versions-list endpoint resolves empty (irrelevant to these specs).
const axiosGetMock = vi.fn()
let applicationFixture = null
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGetMock(...args) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

// Mock both composables to keep the spec deterministic. Real refs are
// required so Vue's template auto-unwrap sees them as ref-like.
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

const previewAvailableRef = ref(false)
vi.mock('../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({
		available: previewAvailableRef,
		previewProps: () => null,
	}),
}))

// Stub the sub-editors so the dispatcher contract is observable without
// dragging the whole picker + fields chain in.
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
				'pageId',
				'runtimeExternalForms',
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
vi.mock('../../src/components/page-editor/MapPageEditor.vue', () =>
	stub('MapPageEditor'),
)
vi.mock('../../src/components/page-editor/RoadmapPageEditor.vue', () =>
	stub('RoadmapPageEditor'),
)
vi.mock('../../src/components/page-editor/SearchPageEditor.vue', () =>
	stub('SearchPageEditor'),
)
vi.mock('../../src/components/page-editor/WikiPageEditor.vue', () =>
	stub('WikiPageEditor'),
)
// StubPageEditor keeps its real required-prop contract (title/message) so
// the dispatch-binding tests below can assert PageDesigner actually binds
// them (REQ-PEC-001) rather than stubbing that contract away.
vi.mock('../../src/components/page-editor/StubPageEditor.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'StubPageEditor',
			props: {
				title: { type: String, required: true },
				message: { type: String, required: true },
				config: { type: Object, default: () => ({}) },
			},
			render() {
				return h('div', { class: 'stubpageeditor-stub' }, [
					h('h3', this.title),
					h('p', this.message),
				])
			},
		},
	}
})

// PageListEditor + MenuTreeEditor are stubbed so we can fire their
// emitted events directly without rendering the whole tree.
vi.mock('../../src/components/page-editor/PageListEditor.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'PageListEditor',
			props: ['pages', 'selectedIndex'],
			render() {
				return h('div', { class: 'page-list-editor-stub' })
			},
		},
	}
})
vi.mock('../../src/components/page-editor/MenuTreeEditor.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'MenuTreeEditor',
			props: ['menu'],
			render() {
				return h('div', { class: 'menu-tree-editor-stub' })
			},
		},
	}
})

const PageDesigner = (await import('../../src/views/PageDesigner.vue')).default

function mountDesigner(manifest = { pages: [], menu: [] }, slug = 'hello-world') {
	return mount(PageDesigner, {
		propsData: { manifest, slug },
	})
}

describe('PageDesigner', () => {
	beforeEach(() => {
		validatorErrorsRef.value = []
		previewAvailableRef.value = false
		validatorStub.validate.mockClear()

		applicationFixture = null
		axiosGetMock.mockReset()
		axiosGetMock.mockImplementation((url, config) => {
			if (typeof url === 'string' && url.includes('/versions')) {
				return Promise.resolve({ data: { results: [] } })
			}
			const slug =
				(config && config.params && config.params.slug) || 'hello-world'
			const app = applicationFixture || { slug }
			return Promise.resolve({ data: { results: [app] } })
		})
	})

	it('renders the three-pane layout', () => {
		const wrapper = mountDesigner()
		expect(wrapper.find('.page-designer__left').exists()).toBe(true)
		expect(wrapper.find('.page-designer__centre').exists()).toBe(true)
		expect(wrapper.find('.page-designer__right').exists()).toBe(true)
	})

	it('shows the empty state when no page is selected', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'a', type: 'index' }],
			menu: [],
		})
		// selectedIndex starts at -1 — no sub-editor rendered.
		expect(wrapper.find('.page-designer__empty').exists()).toBe(true)
	})

	it('dispatcher picks IndexPageEditor for type=index', async () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'home', type: 'index', config: { register: 'r' } }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'IndexPageEditor' }).exists()).toBe(
			true,
		)
		expect(wrapper.findComponent({ name: 'FormPageEditor' }).exists()).toBe(
			false,
		)
	})

	it('dispatcher picks FormPageEditor for type=form', async () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'submit', type: 'form', config: {} }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'FormPageEditor' }).exists()).toBe(true)
	})

	it('dispatcher picks DashboardPageEditor for type=dashboard', async () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'd', type: 'dashboard', config: {} }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'DashboardPageEditor' }).exists()).toBe(
			true,
		)
	})

	it('unknown page.type falls back to StubPageEditor (raw-JSON preserves edits)', async () => {
		const wrapper = mountDesigner({
			pages: [
				{ id: 'x', type: 'unknown-future-type', config: { foo: 'bar' } },
			],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'StubPageEditor' }).exists()).toBe(true)
		// The fallback must receive the same `config` prop so unsupported
		// fields survive a round-trip via the raw-JSON editor.
		const stubInstance = wrapper.findComponent({ name: 'StubPageEditor' })
		expect(stubInstance.props('config')).toEqual({ foo: 'bar' })
	})

	// REQ-PEC-001: StubPageEditor is the fallback ONLY for types outside
	// SUB_EDITOR_MAP, and PageDesigner must bind its required title/message
	// props whenever it mounts (the mocked StubPageEditor above keeps the
	// REAL required-prop contract, unlike the generic sub-editor stubs, so
	// this test would fail with a Vue required-prop warning / empty heading
	// if the dispatch binding regressed).
	describe('REQ-PEC-001 — stub fallback narrowed to unknown types only', () => {
		it('subEditorFor maps every canonical v2 page type to its dedicated editor', () => {
			const wrapper = mountDesigner()
			const mapping = {
				index: 'IndexPageEditor',
				detail: 'DetailPageEditor',
				dashboard: 'DashboardPageEditor',
				form: 'FormPageEditor',
				logs: 'LogsPageEditor',
				settings: 'SettingsPageEditor',
				chat: 'ChatPageEditor',
				files: 'FilesPageEditor',
				custom: 'CustomPageEditor',
				map: 'MapPageEditor',
				roadmap: 'RoadmapPageEditor',
				search: 'SearchPageEditor',
				wiki: 'WikiPageEditor',
			}
			for (const [type, expected] of Object.entries(mapping)) {
				expect(wrapper.vm.subEditorFor(type)).toBe(expected)
			}
			expect(wrapper.vm.subEditorFor('timeline')).toBe('StubPageEditor')
		})

		it('unknown page type still falls back to the stub with its title/message props bound', async () => {
			const wrapper = mountDesigner({
				pages: [{ id: 'x', type: 'timeline', config: {} }],
				menu: [],
			})
			wrapper.vm.selectPage(0)
			await wrapper.vm.$nextTick()
			const stub = wrapper.findComponent({ name: 'StubPageEditor' })
			expect(stub.exists()).toBe(true)
			expect(stub.props('title')).toBeTruthy()
			// The stubbed t() in tests/vitest/setup.js returns the raw i18n key
			// without interpolating `{type}` — assert the key names the type.
			expect(stub.props('title')).toContain('Unsupported page type')
			expect(stub.props('message')).toBeTruthy()
			// The real component renders both as non-empty heading + message.
			expect(stub.find('h3').text()).not.toBe('')
			expect(stub.find('p').text()).not.toBe('')
		})

		it('selecting a wiki page mounts the wiki sub-editor, not the stub', async () => {
			const wrapper = mountDesigner({
				pages: [
					{
						id: 'w',
						type: 'wiki',
						config: { register: 'r', schema: 's' },
					},
				],
				menu: [],
			})
			wrapper.vm.selectPage(0)
			await wrapper.vm.$nextTick()
			expect(wrapper.findComponent({ name: 'WikiPageEditor' }).exists()).toBe(
				true,
			)
			expect(wrapper.findComponent({ name: 'StubPageEditor' }).exists()).toBe(
				false,
			)
		})
	})

	it('switching selectedIndex updates the rendered sub-editor', async () => {
		const wrapper = mountDesigner({
			pages: [
				{ id: 'a', type: 'index', config: {} },
				{ id: 'b', type: 'form', config: {} },
			],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'IndexPageEditor' }).exists()).toBe(
			true,
		)
		wrapper.vm.selectPage(1)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'FormPageEditor' }).exists()).toBe(true)
	})

	it('onConfigUpdate mutates the correct pages[i].config and re-emits manifest', async () => {
		const wrapper = mountDesigner({
			pages: [
				{ id: 'a', type: 'index', config: { register: 'r1' } },
				{ id: 'b', type: 'form', config: {} },
			],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		wrapper.vm.onConfigUpdate({ register: 'r2', schema: 's2' })
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:manifest')
		expect(emitted).toBeTruthy()
		const next = emitted[emitted.length - 1][0]
		expect(next.pages[0].config).toEqual({ register: 'r2', schema: 's2' })
		expect(next.pages[1].config).toEqual({})
	})

	// external-form-provisioning REQ-EFP-001/002: FormPageEditor is handed the
	// selected page's id + the manifest's runtime.externalForms[] so it can
	// filter to the entry it owns, and its update:runtimeExternalForms is
	// merged back onto manifest.runtime.externalForms — never onto pages[].config.
	it('binds page-id + runtime-external-forms to the dispatched sub-editor', async () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'form-page-1', type: 'form', config: {} }],
			menu: [],
			runtime: {
				externalForms: [
					{
						id: 'ef-1',
						pageId: 'form-page-1',
						register: 'intake',
						schema: 'report',
						status: 'enabled',
					},
				],
			},
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		const sub = wrapper.findComponent({ name: 'FormPageEditor' })
		expect(sub.props('pageId')).toBe('form-page-1')
		expect(sub.props('runtimeExternalForms')).toEqual([
			{
				id: 'ef-1',
				pageId: 'form-page-1',
				register: 'intake',
				schema: 'report',
				status: 'enabled',
			},
		])
	})

	it('onExternalFormsUpdate merges the array onto manifest.runtime.externalForms', async () => {
		const wrapper = mountDesigner({ pages: [], menu: [] })
		const list = [
			{
				id: 'ef-1',
				pageId: 'p1',
				register: 'intake',
				schema: 'report',
				status: 'enabled',
			},
		]
		wrapper.vm.onExternalFormsUpdate(list)
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:manifest')
		const next = emitted[emitted.length - 1][0]
		expect(next.runtime.externalForms).toEqual(list)
	})

	it('onExternalFormsUpdate([]) deletes runtime.externalForms — byte-identical when never used', async () => {
		const wrapper = mountDesigner({
			pages: [],
			menu: [],
			runtime: { externalForms: [{ id: 'ef-1' }] },
		})
		wrapper.vm.onExternalFormsUpdate([])
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:manifest')
		const next = emitted[emitted.length - 1][0]
		expect(next.runtime).toBeUndefined()
	})

	it('onConfigUpdate is a no-op when nothing is selected', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'a', type: 'index' }],
			menu: [],
		})
		// selectedIndex defaults to -1.
		wrapper.vm.onConfigUpdate({ register: 'r' })
		// The deep manifest watcher fires once on mount; no NEW update:manifest
		// emit should occur from the no-op onConfigUpdate call.
		const emissions = wrapper.emitted('update:manifest') || []
		expect(emissions).toHaveLength(0)
	})

	it('onMenuUpdate re-emits manifest with the new menu and clears depthError', async () => {
		const wrapper = mountDesigner({ pages: [], menu: [] })
		wrapper.vm.depthError = true
		wrapper.vm.onMenuUpdate([{ id: 'inbox', label: 'inbox.label' }])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.depthError).toBe(false)
		const next = wrapper.emitted('update:manifest').pop()[0]
		expect(next.menu).toHaveLength(1)
	})

	it('onDepthViolation surfaces the warning paragraph', async () => {
		const wrapper = mountDesigner({ pages: [], menu: [] })
		wrapper.vm.onDepthViolation()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.depthError).toBe(true)
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
	})

	it('renders validator errors in the right side panel', async () => {
		validatorErrorsRef.value = ['/pages/0/id is required']
		const wrapper = mountDesigner()
		await wrapper.vm.$nextTick()
		const list = wrapper.find('.page-designer__error-list')
		expect(list.exists()).toBe(true)
		expect(list.text()).toContain('/pages/0/id is required')
	})

	it('canSaveAndPreview is false when slug is missing', () => {
		const wrapper = mountDesigner({ pages: [], menu: [] }, '')
		expect(wrapper.vm.canSaveAndPreview).toBe(false)
	})

	it('canSaveAndPreview is false when validator reports errors', () => {
		validatorErrorsRef.value = ['something is wrong']
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		expect(wrapper.vm.canSaveAndPreview).toBe(false)
	})

	it('canSaveAndPreview is true when slug set and no errors', () => {
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		expect(wrapper.vm.canSaveAndPreview).toBe(true)
	})

	it('Save & preview button emits save-and-preview', async () => {
		const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
		await wrapper.find('.page-designer__preview-btn').trigger('click')
		expect(wrapper.emitted('save-and-preview')).toBeTruthy()
	})

	it('preview fallback panel renders when chain spec #2 is unavailable', () => {
		const wrapper = mountDesigner()
		expect(wrapper.find('.page-designer__preview-fallback').exists()).toBe(true)
	})

	it('triggers validator.validate on manifest changes (deep + immediate)', async () => {
		const validateSpy = validatorStub.validate
		validateSpy.mockClear()
		const wrapper = mountDesigner({ pages: [], menu: [] })
		expect(validateSpy).toHaveBeenCalledTimes(1)
		wrapper.setProps({
			manifest: { pages: [{ id: 'home', type: 'index' }], menu: [] },
		})
		await wrapper.vm.$nextTick()
		expect(validateSpy).toHaveBeenCalledTimes(2)
	})

	// data-registers-runtime task 2.2: applicationDataRegisters fetch + pass-through.
	describe('applicationDataRegisters (data-registers-runtime task 2.2)', () => {
		it('resolves the Application record in created() and stores its dataRegisters', async () => {
			applicationFixture = {
				slug: 'hello-world',
				dataRegisters: [
					{ register: 'spectr', label: 'Spectr market intelligence data' },
				],
			}
			const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
			await new Promise((r) => setTimeout(r, 0))
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.applicationDataRegisters).toEqual([
				{ register: 'spectr', label: 'Spectr market intelligence data' },
			])
		})

		it('passes applicationDataRegisters as the data-registers prop to the mounted sub-editor', async () => {
			applicationFixture = {
				slug: 'hello-world',
				dataRegisters: [
					{ register: 'spectr', label: 'Spectr market intelligence data' },
				],
			}
			const wrapper = mountDesigner(
				{
					pages: [{ id: 'home', type: 'index', config: {} }],
					menu: [],
				},
				'hello-world',
			)
			await new Promise((r) => setTimeout(r, 0))
			await wrapper.vm.$nextTick()
			wrapper.vm.selectPage(0)
			await wrapper.vm.$nextTick()
			const indexEditor = wrapper.findComponent({ name: 'IndexPageEditor' })
			expect(indexEditor.props('dataRegisters')).toEqual([
				{ register: 'spectr', label: 'Spectr market intelligence data' },
			])
		})

		it('defaults to [] when the Application record has no dataRegisters', async () => {
			applicationFixture = { slug: 'hello-world' }
			const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
			await new Promise((r) => setTimeout(r, 0))
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.applicationDataRegisters).toEqual([])
		})

		it('does not fetch and stays [] when slug is empty', async () => {
			const wrapper = mountDesigner({ pages: [], menu: [] }, '')
			await new Promise((r) => setTimeout(r, 0))
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.applicationDataRegisters).toEqual([])
			// Only the (skipped) dataRegisters fetch would have used a plain
			// application lookup; assert none of the recorded calls targeted it.
			const appLookupCalls = axiosGetMock.mock.calls.filter(
				([url]) =>
					typeof url === 'string'
					&& url.includes('objects/buildiq/built-app'),
			)
			expect(appLookupCalls).toHaveLength(0)
		})

		it('degrades to [] when the fetch fails', async () => {
			axiosGetMock.mockImplementation((url) => {
				if (typeof url === 'string' && url.includes('/versions')) {
					return Promise.resolve({ data: { results: [] } })
				}
				return Promise.reject(new Error('network error'))
			})
			const wrapper = mountDesigner({ pages: [], menu: [] }, 'hello-world')
			await new Promise((r) => setTimeout(r, 0))
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.applicationDataRegisters).toEqual([])
		})
	})
})
