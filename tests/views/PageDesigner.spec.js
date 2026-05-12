/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
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

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, computed } from 'vue'

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
function stub(name) {
	return {
		default: {
			name,
			props: ['config', 'pageType', 'appSlug', 'parentRoute'],
			render(h) { return h('div', { staticClass: `${name.toLowerCase()}-stub` }, name) },
		},
	}
}
vi.mock('../../src/components/page-editor/IndexPageEditor.vue', () => stub('IndexPageEditor'))
vi.mock('../../src/components/page-editor/DetailPageEditor.vue', () => stub('DetailPageEditor'))
vi.mock('../../src/components/page-editor/DashboardPageEditor.vue', () => stub('DashboardPageEditor'))
vi.mock('../../src/components/page-editor/FormPageEditor.vue', () => stub('FormPageEditor'))
vi.mock('../../src/components/page-editor/LogsPageEditor.vue', () => stub('LogsPageEditor'))
vi.mock('../../src/components/page-editor/SettingsPageEditor.vue', () => stub('SettingsPageEditor'))
vi.mock('../../src/components/page-editor/ChatPageEditor.vue', () => stub('ChatPageEditor'))
vi.mock('../../src/components/page-editor/FilesPageEditor.vue', () => stub('FilesPageEditor'))
vi.mock('../../src/components/page-editor/CustomPageEditor.vue', () => stub('CustomPageEditor'))
vi.mock('../../src/components/page-editor/StubPageEditor.vue', () => stub('StubPageEditor'))

// PageListEditor + MenuTreeEditor are stubbed so we can fire their
// emitted events directly without rendering the whole tree.
vi.mock('../../src/components/page-editor/PageListEditor.vue', () => ({
	default: {
		name: 'PageListEditor',
		props: ['pages', 'selectedIndex'],
		render(h) { return h('div', { staticClass: 'page-list-editor-stub' }) },
	},
}))
vi.mock('../../src/components/page-editor/MenuTreeEditor.vue', () => ({
	default: {
		name: 'MenuTreeEditor',
		props: ['menu'],
		render(h) { return h('div', { staticClass: 'menu-tree-editor-stub' }) },
	},
}))

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
		expect(wrapper.findComponent({ name: 'IndexPageEditor' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'FormPageEditor' }).exists()).toBe(false)
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
		expect(wrapper.findComponent({ name: 'DashboardPageEditor' }).exists()).toBe(true)
	})

	it('unknown page.type falls back to StubPageEditor (raw-JSON preserves edits)', async () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'x', type: 'unknown-future-type', config: { foo: 'bar' } }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'StubPageEditor' }).exists()).toBe(true)
		// The fallback must receive the same `config` prop so unsupported
		// fields survive a round-trip via the raw-JSON editor.
		const stubInstance = wrapper.findComponent({ name: 'StubPageEditor' })
		expect(stubInstance.props('config')).toEqual({ foo: 'bar' })
		expect(stubInstance.props('pageType')).toBe('unknown-future-type')
	})

	it('subEditorFor maps every documented page type', () => {
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
		}
		for (const [type, expected] of Object.entries(mapping)) {
			expect(wrapper.vm.subEditorFor(type)).toBe(expected)
		}
		expect(wrapper.vm.subEditorFor('mystery')).toBe('StubPageEditor')
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
		expect(wrapper.findComponent({ name: 'IndexPageEditor' }).exists()).toBe(true)
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
		wrapper.setProps({ manifest: { pages: [{ id: 'home', type: 'index' }], menu: [] } })
		await wrapper.vm.$nextTick()
		expect(validateSpy).toHaveBeenCalledTimes(2)
	})
})
