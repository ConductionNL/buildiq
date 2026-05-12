/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PageDesigner's undo/redo stack (OQ-1).
 *
 * PageDesigner is a controlled component, so the test echoes every
 * `update:manifest` back into the `manifest` prop (mimicking
 * PageDesignerHost) and asserts that:
 *  - editing pushes onto the history;
 *  - undo() / redo() re-emit the historical manifest;
 *  - canUndo / canRedo gate the toolbar buttons;
 *  - Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y route through onKeydown;
 *  - re-emitting an undone state does NOT re-push it (no thrash);
 *  - the toolbar renders Undo / Redo buttons.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, computed } from 'vue'

const validatorErrorsRef = ref([])
const validatorStub = {
	errors: validatorErrorsRef,
	hasErrors: computed(() => validatorErrorsRef.value.length > 0),
	isValidating: ref(false),
	validate: vi.fn(),
	register: vi.fn(),
	unregister: vi.fn(),
	errorsByPrefix: ref(new Map()),
	errorMap: ref(new Map()),
	errorFor: () => ({ hasError: false, message: '' }),
	DEBOUNCE_MS: 300,
}
vi.mock('../../src/composables/useManifestValidator.js', () => ({
	useManifestValidator: () => validatorStub,
}))
vi.mock('../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({ available: ref(false), previewProps: () => null }),
}))

function stub(name) {
	return {
		default: {
			name,
			props: ['config', 'pageType', 'appSlug', 'parentRoute'],
			render(h) { return h('div', { staticClass: `${name.toLowerCase()}-stub` }) },
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
vi.mock('../../src/components/page-editor/PageListEditor.vue', () => ({
	default: { name: 'PageListEditor', props: ['pages', 'selectedIndex'], render(h) { return h('div') } },
}))
vi.mock('../../src/components/page-editor/MenuTreeEditor.vue', () => ({
	default: { name: 'MenuTreeEditor', props: ['menu'], render(h) { return h('div') } },
}))

const PageDesigner = (await import('../../src/views/PageDesigner.vue')).default

// Mount PageDesigner with a host-like echo: every update:manifest is
// pushed straight back into the manifest prop, the way PageDesignerHost
// does. Returns the wrapper.
function mountControlled(initial = { pages: [], menu: [] }, slug = 'hello-world') {
	const wrapper = mount(PageDesigner, { propsData: { manifest: initial, slug } })
	wrapper.vm.$on('update:manifest', (next) => {
		wrapper.setProps({ manifest: next })
	})
	return wrapper
}

describe('PageDesigner — undo/redo', () => {
	beforeEach(() => {
		validatorErrorsRef.value = []
		validatorStub.validate.mockClear()
	})

	it('renders Undo / Redo toolbar buttons', () => {
		const wrapper = mountControlled()
		const btns = wrapper.findAll('.page-designer__tool-btn').wrappers.map((w) => w.text())
		expect(btns.some((t) => t.includes('Undo'))).toBe(true)
		expect(btns.some((t) => t.includes('Redo'))).toBe(true)
	})

	it('starts with undo/redo disabled', () => {
		const wrapper = mountControlled()
		expect(wrapper.vm.canUndo).toBe(false)
		expect(wrapper.vm.canRedo).toBe(false)
	})

	it('an edit enables undo', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'inbox', label: 'inbox.label' }])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(true)
	})

	it('undo re-emits the previous manifest and enables redo', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }, { id: 'b', label: 'b' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(1)
		expect(wrapper.vm.canRedo).toBe(true)
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(0)
		expect(wrapper.vm.canUndo).toBe(false)
	})

	it('redo replays the undone manifest', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(0)
		wrapper.vm.redo()
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(1)
		expect(wrapper.vm.canRedo).toBe(false)
	})

	it('re-emitting an undone state does not re-push it (no thrash)', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }, { id: 'b', label: 'b' }])
		await wrapper.vm.$nextTick()
		const sizeBefore = wrapper.vm.history.size.value
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		wrapper.vm.redo()
		await wrapper.vm.$nextTick()
		// undo + redo only move the cursor; the stack length is unchanged.
		expect(wrapper.vm.history.size.value).toBe(sizeBefore)
	})

	it('Ctrl+Z triggers undo', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', shiftKey: false, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(0)
	})

	it('Ctrl+Shift+Z and Ctrl+Y trigger redo', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', shiftKey: false, preventDefault() {} })
		await wrapper.vm.$nextTick()
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', shiftKey: true, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(1)
		// And Ctrl+Y on a fresh undo.
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', shiftKey: false, preventDefault() {} })
		await wrapper.vm.$nextTick()
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'y', shiftKey: false, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(1)
	})

	it('a plain keystroke without ctrl/meta is ignored', () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		// Should not throw / not change anything.
		wrapper.vm.onKeydown({ ctrlKey: false, key: 'z', preventDefault() {} })
		expect(wrapper.vm.canUndo).toBe(false)
	})

	it('a new edit after an undo truncates the redo tail', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }, { id: 'b', label: 'b' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.undo() // back to 1-item menu
		await wrapper.vm.$nextTick()
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }, { id: 'c', label: 'c' }])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canRedo).toBe(false)
		expect(wrapper.props('manifest').menu.map((m) => m.id)).toEqual(['a', 'c'])
	})
})
