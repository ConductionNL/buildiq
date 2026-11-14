/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the `PageDesigner.vue` controller helpers that the
 * dispatch / preview / undo-redo specs do not reach directly: the config
 * validator-mark helpers (`configPathPrefix` / `registerConfigField` /
 * `unregisterConfigField` / `configErrorFor`), the manifest write helpers
 * (`onPagesUpdate`, `onInsertWidgets`), navigation (`selectPageById`,
 * `reloadPreview`), `onBlockSaved`, `translateForPreview`, and the
 * `undo` / `redo` / `onKeydown` chord handling.
 *
 * `shallowMount` stubs the sub-editor tree so these methods are exercised
 * against `wrapper.vm` with a controlled history / validator, asserting real
 * return values, emitted manifests, and collaborator calls.
 */

import { shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: { results: [] } }) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

// Controllable validator: the config-field helpers call register / unregister
// / errorFor on it.
const validatorStub = vi.hoisted(() => ({
	register: vi.fn(),
	unregister: vi.fn(),
	errorFor: vi.fn(),
	errors: { value: [] },
	hasErrors: { value: false },
	isValidating: { value: false },
	validate: vi.fn(),
}))
vi.mock('../../src/composables/useManifestValidator.js', () => ({
	useManifestValidator: () => validatorStub,
}))

// Controllable history: undo / redo return the manifest the handler re-emits.
const historyStub = vi.hoisted(() => ({
	undo: vi.fn(),
	redo: vi.fn(),
	push: vi.fn(),
	reset: vi.fn(),
	canUndo: { value: false },
	canRedo: { value: false },
}))
vi.mock('../../src/composables/useSessionHistory.js', () => ({
	useSessionHistory: () => historyStub,
}))

vi.mock('../../src/composables/useLivePreview.js', () => ({
	useLivePreview: () => ({
		available: ref(false),
		previewProps: () => null,
	}),
}))

const applicationVersionRef = ref(null)
vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: () => ({
		applicationVersion: applicationVersionRef,
		loading: ref(false),
		error: ref(null),
		ready: Promise.resolve(),
	}),
}))

const PageDesigner = (await import('../../src/views/PageDesigner.vue')).default

/**
 * Shallow-mount the designer with `slug: ''` so created()/mounted() skip the
 * application + version fetches, leaving the helper methods under test free of
 * network wiring.
 *
 * @param {object} [manifest] The manifest prop.
 * @return {object} The mounted wrapper.
 */
function mountDesigner(manifest = { pages: [], menu: [] }) {
	return shallowMount(PageDesigner, {
		propsData: { manifest, slug: '' },
		mocks: { $route: { query: {} } },
	})
}

beforeEach(() => {
	validatorStub.register.mockReset()
	validatorStub.unregister.mockReset()
	validatorStub.errorFor.mockReset()
	historyStub.undo.mockReset()
	historyStub.redo.mockReset()
})

describe('PageDesigner config validator-mark helpers', () => {
	it('configPathPrefix is empty with no selection, else the full manifest path', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'a', type: 'index', config: {} }],
			menu: [],
		})
		expect(wrapper.vm.configPathPrefix('register')).toBe('')
		wrapper.vm.selectPage(0)
		expect(wrapper.vm.configPathPrefix('register')).toBe(
			'/pages/0/config/register',
		)
	})

	it('registerConfigField / unregisterConfigField forward the full path to the validator', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'a', type: 'index', config: {} }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		wrapper.vm.registerConfigField('columns')
		expect(validatorStub.register).toHaveBeenCalledWith('/pages/0/config/columns')
		wrapper.vm.unregisterConfigField('columns')
		expect(validatorStub.unregister).toHaveBeenCalledWith(
			'/pages/0/config/columns',
		)
	})

	it('registerConfigField is a no-op when no page is selected (empty prefix)', () => {
		const wrapper = mountDesigner({ pages: [{ id: 'a' }], menu: [] })
		wrapper.vm.registerConfigField('columns')
		expect(validatorStub.register).not.toHaveBeenCalled()
	})

	it('configErrorFor returns the validator bag, else the empty bag', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'a', type: 'index', config: {} }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		validatorStub.errorFor.mockReturnValue({ hasError: true, message: 'bad' })
		expect(wrapper.vm.configErrorFor('register')).toEqual({
			hasError: true,
			message: 'bad',
		})
		// A falsy validator result falls back to the empty bag.
		validatorStub.errorFor.mockReturnValue(null)
		expect(wrapper.vm.configErrorFor('register')).toEqual({
			hasError: false,
			message: '',
		})
	})

	it('configErrorFor returns the empty bag when no page is selected', () => {
		const wrapper = mountDesigner({ pages: [], menu: [] })
		expect(wrapper.vm.configErrorFor('register')).toEqual({
			hasError: false,
			message: '',
		})
		expect(validatorStub.errorFor).not.toHaveBeenCalled()
	})
})

describe('PageDesigner manifest write helpers', () => {
	it('onPagesUpdate re-emits the manifest with the replacement pages array', () => {
		const wrapper = mountDesigner({ pages: [], menu: [{ id: 'm' }] })
		const pages = [{ id: 'x', type: 'index', config: {} }]
		wrapper.vm.onPagesUpdate(pages)
		const emitted = wrapper.emitted('update:manifest')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0].pages).toBe(pages)
		// The rest of the manifest is carried through.
		expect(emitted[0][0].menu).toEqual([{ id: 'm' }])
	})

	it('onInsertWidgets merges the widgets onto the selected page', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'home', type: 'index', config: {}, widgets: [] }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		wrapper.vm.onInsertWidgets([{ id: 'w1', type: 'text' }])
		const emitted = wrapper.emitted('update:manifest')
		expect(emitted).toHaveLength(1)
		const page = emitted[0][0].pages.find((p) => p.id === 'home')
		expect(page.widgets.some((w) => w.id === 'w1')).toBe(true)
	})

	it('onInsertWidgets is a no-op without a selection or with an empty list', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'home', type: 'index', config: {} }],
			menu: [],
		})
		// No page selected.
		wrapper.vm.onInsertWidgets([{ id: 'w1' }])
		// Selected, but nothing to insert.
		wrapper.vm.selectPage(0)
		wrapper.vm.onInsertWidgets([])
		expect(wrapper.emitted('update:manifest')).toBeUndefined()
	})
})

describe('PageDesigner navigation + preview helpers', () => {
	it('selectPageById opens the page the id names and ignores an unknown id', () => {
		const wrapper = mountDesigner({
			pages: [
				{ id: 'a', type: 'index', config: {} },
				{ id: 'b', type: 'detail', config: {} },
			],
			menu: [],
		})
		wrapper.vm.selectPageById('b')
		expect(wrapper.vm.selectedIndex).toBe(1)
		wrapper.vm.selectPageById('does-not-exist')
		expect(wrapper.vm.selectedIndex).toBe(1)
	})

	it('reloadPreview rebuilds the sandbox when the ref is present, no-ops otherwise', () => {
		// A mounted instance's `$refs` is sealed, so drive the method with a
		// stand-in `this` carrying the sandbox ref.
		const createSandbox = vi.fn()
		PageDesigner.methods.reloadPreview.call({
			$refs: { previewSandbox: { createSandbox } },
		})
		expect(createSandbox).toHaveBeenCalledTimes(1)
		// Absent ref (preview pane not rendered) is a silent no-op.
		const wrapper = mountDesigner()
		expect(wrapper.vm.reloadPreview()).toBeUndefined()
	})

	it('onBlockSaved refreshes the block-capture context', () => {
		const wrapper = mountDesigner()
		const fetchCtx = vi
			.spyOn(wrapper.vm, 'fetchBlockCaptureContext')
			.mockResolvedValue()
		wrapper.vm.onBlockSaved()
		expect(fetchCtx).toHaveBeenCalledTimes(1)
	})

	it('translateForPreview resolves the key through the l10n helper', () => {
		const wrapper = mountDesigner()
		expect(wrapper.vm.translateForPreview('Some key')).toBe('Some key')
	})
})

describe('PageDesigner undo / redo / keydown', () => {
	it('undo / redo re-emit the manifest the history returns, and no-op on null', () => {
		const wrapper = mountDesigner()
		historyStub.undo.mockReturnValue({ pages: [{ id: 'u' }], menu: [] })
		wrapper.vm.undo()
		historyStub.redo.mockReturnValue({ pages: [{ id: 'r' }], menu: [] })
		wrapper.vm.redo()
		const emitted = wrapper.emitted('update:manifest')
		expect(emitted).toHaveLength(2)
		expect(emitted[0][0].pages[0].id).toBe('u')
		expect(emitted[1][0].pages[0].id).toBe('r')

		// At the ends of the stack the history returns null — nothing re-emits.
		historyStub.undo.mockReturnValue(null)
		historyStub.redo.mockReturnValue(null)
		wrapper.vm.undo()
		wrapper.vm.redo()
		expect(wrapper.emitted('update:manifest')).toHaveLength(2)
	})

	it('undo / redo are silent no-ops without a history', () => {
		const wrapper = mountDesigner()
		wrapper.vm.history = null
		expect(() => {
			wrapper.vm.undo()
			wrapper.vm.redo()
		}).not.toThrow()
		expect(wrapper.emitted('update:manifest')).toBeUndefined()
	})

	it('onKeydown routes ctrl+z to undo and ctrl+shift+z / ctrl+y to redo', () => {
		const wrapper = mountDesigner()
		const undo = vi.spyOn(wrapper.vm, 'undo').mockImplementation(() => {})
		const redo = vi.spyOn(wrapper.vm, 'redo').mockImplementation(() => {})
		const ev = (over) => ({ ctrlKey: true, preventDefault: vi.fn(), ...over })

		wrapper.vm.onKeydown(ev({ key: 'z' }))
		expect(undo).toHaveBeenCalledTimes(1)
		wrapper.vm.onKeydown(ev({ key: 'Z', shiftKey: true }))
		expect(redo).toHaveBeenCalledTimes(1)
		wrapper.vm.onKeydown(ev({ key: 'y' }))
		expect(redo).toHaveBeenCalledTimes(2)
	})

	it('onKeydown ignores events without a modifier or key', () => {
		const wrapper = mountDesigner()
		const undo = vi.spyOn(wrapper.vm, 'undo').mockImplementation(() => {})
		const redo = vi.spyOn(wrapper.vm, 'redo').mockImplementation(() => {})

		wrapper.vm.onKeydown(null)
		wrapper.vm.onKeydown({ key: 'z', preventDefault: vi.fn() })
		wrapper.vm.onKeydown({ ctrlKey: true, key: 'a', preventDefault: vi.fn() })
		expect(undo).not.toHaveBeenCalled()
		expect(redo).not.toHaveBeenCalled()
	})
})

describe('PageDesigner — drag ordering on an index page (REQ-PEC-009)', () => {
	function ctx(page) {
		const emitted = []
		const pages = [page, { id: 'other', type: 'detail' }]
		return {
			emitted,
			selectedIndex: 0,
			selectedPage: page,
			pages,
			manifest: { version: '1.0.0', pages },
			emitManifest: (next) => emitted.push(next),
			subEditorFor: PageDesigner.methods.subEditorFor,
		}
	}

	it('writes manualOrder: true on the page, outside its config', () => {
		const c = ctx({ id: 'taken', type: 'index', config: { columns: ['titel'] } })
		PageDesigner.methods.onManualOrderUpdate.call(c, true)
		expect(c.emitted[0].pages[0]).toEqual({ id: 'taken', type: 'index', config: { columns: ['titel'] }, manualOrder: true })
		expect(c.emitted[0].pages[1]).toEqual({ id: 'other', type: 'detail' })
	})

	it('removes the key when switched off', () => {
		const c = ctx({ id: 'taken', type: 'index', manualOrder: true })
		PageDesigner.methods.onManualOrderUpdate.call(c, false)
		expect(c.emitted[0].pages[0]).toEqual({ id: 'taken', type: 'index' })
	})

	it('hands the index editor the current switch and its handler only', () => {
		const c = ctx({ id: 'taken', type: 'index', manualOrder: true })
		c.onManualOrderUpdate = () => {}
		const props = PageDesigner.methods.stubEditorProps.call(c, 'index')
		expect(props.manualOrder).toBe(true)
		expect(props['onUpdate:manualOrder']).toBe(c.onManualOrderUpdate)
		expect(PageDesigner.methods.stubEditorProps.call(c, 'detail')).toEqual({})
	})
})

describe('PageDesigner provided validator, preview surface and teardown', () => {
	it('the provided pageEditorValidator forwards to the selected page path', () => {
		const wrapper = mountDesigner({
			pages: [{ id: 'a', type: 'index', config: {} }],
			menu: [],
		})
		wrapper.vm.selectPage(0)
		const { pageEditorValidator } = PageDesigner.provide.call(wrapper.vm)
		validatorStub.errorFor.mockReturnValue({ hasError: true, message: 'Required' })

		pageEditorValidator.register('schema')
		pageEditorValidator.unregister('schema')
		const bag = pageEditorValidator.errorFor('schema')

		expect(validatorStub.register).toHaveBeenCalledWith('/pages/0/config/schema')
		expect(validatorStub.unregister).toHaveBeenCalledWith('/pages/0/config/schema')
		expect(validatorStub.errorFor).toHaveBeenCalledWith('/pages/0/config/schema')
		expect(bag).toEqual({ hasError: true, message: 'Required' })
	})

	it('configErrorFor returns the empty bag when the validator has no errorFor', () => {
		const prefix = vi.fn(() => '/pages/0/config/x')
		const bag = PageDesigner.methods.configErrorFor.call(
			{ validator: { errors: { value: [] } }, configPathPrefix: prefix },
			'x',
		)
		expect(bag).toEqual({ hasError: false, message: '' })
		expect(prefix).not.toHaveBeenCalled()
	})

	it('livePreviewProps is null while preview is unavailable, else the preview props of the slug and manifest', () => {
		const manifest = { pages: [{ id: 'a' }], menu: [] }
		const previewProps = vi.fn(() => ({ manifest, slug: 'demo' }))
		const off = PageDesigner.computed.livePreviewProps.call({
			previewAvailable: false,
			previewProps,
			slug: 'demo',
			manifest,
		})
		const on = PageDesigner.computed.livePreviewProps.call({
			previewAvailable: true,
			previewProps,
			slug: 'demo',
			manifest,
		})
		expect(off).toBeNull()
		expect(on).toEqual({ manifest, slug: 'demo' })
		expect(previewProps).toHaveBeenCalledTimes(1)
		expect(previewProps).toHaveBeenCalledWith('demo', manifest)
	})

	describe('observePreviewSurface', () => {
		let observers
		let original

		beforeEach(() => {
			observers = []
			original = globalThis.ResizeObserver
			globalThis.ResizeObserver = class {
				constructor(callback) {
					this.callback = callback
					this.observe = vi.fn()
					this.disconnect = vi.fn()
					observers.push(this)
				}
			}
		})

		afterEach(() => {
			globalThis.ResizeObserver = original
		})

		it('does nothing without ResizeObserver', () => {
			globalThis.ResizeObserver = undefined
			const self = { $refs: { previewSurface: {} }, _previewSurfaceEl: undefined }
			PageDesigner.methods.observePreviewSurface.call(self)
			expect(self._previewSurfaceEl).toBeUndefined()
		})

		it('observes a new surface once and scales the preview to its width', () => {
			const el = { id: 'surface' }
			const self = { $refs: { previewSurface: el }, _previewSurfaceEl: null, _previewResizeObs: null, previewScale: 1 }

			PageDesigner.methods.observePreviewSurface.call(self)
			expect(observers).toHaveLength(1)
			expect(observers[0].disconnect).toHaveBeenCalledTimes(1)
			expect(observers[0].observe).toHaveBeenCalledWith(el)

			observers[0].callback([{ contentRect: { width: 512 } }])
			expect(self.previewScale).toBe(0.5)
			observers[0].callback([{ contentRect: { width: 4096 } }])
			expect(self.previewScale).toBe(1)
			observers[0].callback([{ contentRect: { width: 0 } }])
			observers[0].callback([{}])
			expect(self.previewScale).toBe(1)

			// The same element again is not re-observed.
			PageDesigner.methods.observePreviewSurface.call(self)
			expect(observers).toHaveLength(1)
			expect(observers[0].observe).toHaveBeenCalledTimes(1)
		})

		it('reuses the observer and stops observing when the surface goes away', () => {
			const el = { id: 'surface' }
			const self = { $refs: { previewSurface: el }, _previewSurfaceEl: null, _previewResizeObs: null, previewScale: 1 }
			PageDesigner.methods.observePreviewSurface.call(self)

			self.$refs = {}
			PageDesigner.methods.observePreviewSurface.call(self)

			expect(observers).toHaveLength(1)
			expect(observers[0].disconnect).toHaveBeenCalledTimes(2)
			expect(observers[0].observe).toHaveBeenCalledTimes(1)
			expect(self._previewSurfaceEl).toBeNull()
		})
	})

	it('unmounting removes the shortcut listener and disconnects the preview observer', () => {
		const wrapper = mountDesigner()
		const disconnect = vi.fn()
		wrapper.vm._previewResizeObs = { disconnect }
		const remove = vi.spyOn(document, 'removeEventListener')

		wrapper.unmount()

		expect(remove).toHaveBeenCalledWith('keydown', expect.any(Function))
		expect(disconnect).toHaveBeenCalledTimes(1)
		remove.mockRestore()
	})
})
