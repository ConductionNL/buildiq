/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PageDesigner's undo/redo stack (OQ-1 /
 * builder-undo-redo).
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
 *
 * builder-undo-redo adds (rewired to the shared nc-vue
 * `manifestEditHistory` engine via `useSessionHistory`, design.md D1):
 *  - REQ-BUR-004: a `sessionKey` prop change resets the history to the
 *    then-current manifest, disabling both Undo and Redo; history
 *    survives a `selectPage` sub-editor switch.
 *  - REQ-BUR-003: the editable-target guard ignores chords aimed at an
 *    `<input>`/`<textarea>`/contenteditable element; `metaKey` (Cmd)
 *    chords behave identically to `ctrlKey` ones outside editable
 *    fields (the Vitest side of the Cmd scenario's `@e2e exclude`).
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

async function stub(name) {
	const { h } = await import('vue')
	return {
		default: {
			name,
			props: ['config', 'pageType', 'appSlug', 'parentRoute'],
			render() { return h('div', { class: `${name.toLowerCase()}-stub` }) },
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
vi.mock('../../src/components/page-editor/PageListEditor.vue', async () => {
	const { h } = await import('vue')
	return {
		default: { name: 'PageListEditor', props: ['pages', 'selectedIndex'], render() { return h('div') } },
	}
})
vi.mock('../../src/components/page-editor/MenuTreeEditor.vue', async () => {
	const { h } = await import('vue')
	return {
		default: { name: 'MenuTreeEditor', props: ['menu'], render() { return h('div') } },
	}
})

const PageDesigner = (await import('../../src/views/PageDesigner.vue')).default

// Mount PageDesigner with a host-like echo: every update:manifest is
// pushed straight back into the manifest prop, the way PageDesignerHost
// does. Returns the wrapper.
function mountControlled(initial = { pages: [], menu: [] }, slug = 'hello-world', sessionKey = '') {
	// Vue 3 removed the `$on` instance API, so the host echo is wired as a
	// real `onUpdate:manifest` listener prop at mount time instead. The
	// `wrapper` binding is captured lazily — the listener only ever fires
	// after `mount()` has returned.
	let wrapper = null
	wrapper = mount(PageDesigner, {
		propsData: {
			manifest: initial,
			slug,
			sessionKey,
			'onUpdate:manifest': (next) => {
				wrapper.setProps({ manifest: next })
			},
		},
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
		const btns = wrapper.findAll('.page-designer__tool-btn').map((w) => w.text())
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

	// --- builder-undo-redo: session boundaries (REQ-BUR-004) ---------------

	it('history survives a selectPage sub-editor switch', async () => {
		const wrapper = mountControlled({
			pages: [{ type: 'index', config: {} }, { type: 'detail', config: {} }],
			menu: [],
		})
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(true)
		// Selecting a different page (a different SUB_EDITOR_MAP sub-editor)
		// is navigation, not an edit — it must not touch the manifest/history.
		wrapper.vm.selectPage(1)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(true)
		wrapper.vm.undo()
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(0)
	})

	it('a sessionKey change resets the history to the current manifest (both buttons disabled)', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] }, 'hello-world', 'hello-world:v1:0')
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(true)
		await wrapper.setProps({ sessionKey: 'hello-world:v1:1' })
		expect(wrapper.vm.canUndo).toBe(false)
		expect(wrapper.vm.canRedo).toBe(false)
		// The chord must not resurrect the pre-reset state.
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', shiftKey: false, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(1)
	})

	it('an unchanged sessionKey does not reset the history', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] }, 'hello-world', 'hello-world:v1:0')
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		await wrapper.setProps({ sessionKey: 'hello-world:v1:0' })
		expect(wrapper.vm.canUndo).toBe(true)
	})

	// --- builder-undo-redo: editable-target guard (REQ-BUR-003) ------------

	it('Cmd (metaKey) chords drive undo/redo identically to Ctrl chords', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		wrapper.vm.onKeydown({ metaKey: true, key: 'z', shiftKey: false, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(0)
		wrapper.vm.onKeydown({ metaKey: true, key: 'z', shiftKey: true, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.props('manifest').menu).toHaveLength(1)
	})

	it('a Ctrl+Z chord targeting an <input> is ignored (native text-field undo wins)', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		let prevented = false
		const input = document.createElement('input')
		wrapper.vm.onKeydown({
			ctrlKey: true,
			key: 'z',
			shiftKey: false,
			target: input,
			preventDefault() { prevented = true },
		})
		await wrapper.vm.$nextTick()
		expect(prevented).toBe(false)
		expect(wrapper.vm.canUndo).toBe(true)
		expect(wrapper.props('manifest').menu).toHaveLength(1)
	})

	it('a Ctrl+Z chord targeting a contenteditable element is ignored', async () => {
		const wrapper = mountControlled({ pages: [], menu: [] })
		wrapper.vm.onMenuUpdate([{ id: 'a', label: 'a' }])
		await wrapper.vm.$nextTick()
		const div = document.createElement('div')
		Object.defineProperty(div, 'isContentEditable', { value: true })
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'z', shiftKey: false, target: div, preventDefault() {} })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canUndo).toBe(true)
	})
})
