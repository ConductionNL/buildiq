/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@conduction/nextcloud-vue`.
 *
 * The published package ships a CJS bundle that does `require('foo.vue')`
 * which Vite cannot transform under the unit-test pipeline (vue-loader is
 * a webpack plugin; the @vitejs/plugin-vue2 transform is gated on the
 * Vite resolver, not Node's `require`). Tests that mount components
 * which transitively depend on `@conduction/nextcloud-vue` do not exercise
 * its rendered markup — they only need the imported symbol to be a valid
 * Vue component object or a callable composable — so we substitute
 * lightweight stubs at the alias layer.
 *
 * `createObjectStore` is stubbed as a factory that returns a Pinia-style
 * composable; the schema-store tests inject their own `useSchemasStore`
 * via `vi.mock`, so this fallback only matters as a transitive import
 * guard.
 */

import { h } from 'vue'

// Vue 3 no longer passes `h` into a render function — it must be imported
// from `vue`. A `render: (h) => h('div')` stub written for Vue 2 throws
// "h is not a function" the moment anything mounts it.
//
// The stub also renders its default slot: a stub that swallowed its
// children made every assertion about content *inside* an NcModal /
// NcDialog / NcNoteCard read as empty.
function stub(name) {
	return {
		name,
		render() {
			return h(
				'div',
				{ class: `${name.toLowerCase()}-stub` },
				this.$slots?.default?.(),
			)
		},
	}
}

export const NcModal = stub('NcModal')
export const NcDialog = stub('NcDialog')
export const NcButton = stub('NcButton')
export const NcTextField = stub('NcTextField')
export const NcSelect = stub('NcSelect')
export const NcEmptyContent = stub('NcEmptyContent')
export const NcCheckboxRadioSwitch = stub('NcCheckboxRadioSwitch')
export const NcNoteCard = stub('NcNoteCard')
export const NcLoadingIcon = stub('NcLoadingIcon')

/**
 * Fallback stub for `createObjectStore`. Tests that exercise the
 * `useSchemasStore` factory should mock `@conduction/nextcloud-vue`
 * directly via `vi.mock`. This stub returns a function that yields a
 * minimal mock store shape so unrelated transitive imports still load.
 *
 * @return {Function} a factory yielding a mock store
 */
export function createObjectStore() {
	return () => ({
		objectTypeRegistry: {},
		errors: {},
		registerObjectType() {},
		fetchCollection: async () => [],
		fetchObject: async () => null,
		saveObject: async (_type, body) => body,
		deleteObject: async () => true,
	})
}

// Manifest-renderer family — stubbed so App.vue / main.js transitive
// imports load under the vitest pipeline. None of the current tests mount
// these; they only need the symbols to exist.
export const CnAppRoot = stub('CnAppRoot')
export const CnAppNav = stub('CnAppNav')
// Renders `#nav-end` as well as the default slot: the real strip carries page
// controls there, and a stub that dropped it made every assertion about them
// read as absent.
export const CnTabs = {
	name: 'CnTabs',
	render() {
		return h('div', { class: 'cntabs-stub' }, [
			this.$slots?.['nav-end']?.(),
			this.$slots?.default?.(),
		])
	},
}
// Props declared so a spec can read the title/active a consumer binds.
export const CnTab = {
	name: 'CnTab',
	props: {
		title: { type: String, default: '' },
		active: { type: Boolean, default: false },
	},
	render() {
		return h('div', { class: 'cntab-stub' }, this.$slots?.default?.())
	},
}
export const CnPageRenderer = { name: 'CnPageRenderer', render: () => h('div') }
export const CnCard = {
	name: 'CnCard',
	props: [
		'title',
		'description',
		'titleTooltip',
		'icon',
		'iconSize',
		'labels',
		'stats',
	],
	render() {
		return h('div', { class: 'cn-card-stub' }, [
			h('h3', this.title),
			h('p', this.description),
		])
	},
}
export const defaultPageTypes = {}
export function registerIcons() {}
export function registerTranslations() {}

// Icon-catalogue adapters (src/utils/iconCatalogues.js). The library ships no
// icon pack — Buildiq owns the data and feeds it through these. Behaviour
// mirrors the real adapters closely enough for the wizard suite: each maps a
// source pack to `{ key, value, label }` entries.
export function fromMdiJs(mdiModule) {
	return Object.keys(mdiModule || {})
		.filter((key) => key.startsWith('mdi') && typeof mdiModule[key] === 'string')
		.map((key) => ({ key, value: mdiModule[key], label: key }))
}
export function fromOpenGemeenten(list = []) {
	return (Array.isArray(list) ? list : [])
		.filter((item) => item && typeof item === 'object')
		.map((item) => ({
			key: item.key || item.name,
			value: item.value || item.path || item.svg,
			label: item.label || item.name || item.key,
		}))
		.filter((entry) => entry.value)
}
export function fromFontAwesome(packs = {}) {
	return Object.values(packs || {})
		.flatMap((pack) => Object.values(pack || {}))
		.filter((def) => def && typeof def === 'object' && 'iconName' in def)
		.map((def) => ({
			key: def.iconName,
			value: def.icon && def.icon[4],
			label: def.iconName,
		}))
		.filter((entry) => entry.value)
}
export function dedupeCatalogue(entries) {
	const seen = new Set()
	return (entries || []).filter((entry) => {
		if (
			!entry
			|| entry.value === null
			|| entry.value === undefined
			|| entry.value === ''
		)
			return false
		if (seen.has(entry.value)) return false
		seen.add(entry.value)
		return true
	})
}

import { validateManifest as _validateManifest } from '@conduction/nextcloud-vue/dist/esm/utils/validateManifest.js'
/**
 * The REAL manifest validator, reached by its deep path so the bare-specifier
 * alias that brings you here does not send you round again.
 *
 * It used to be a stand-in returning `{ valid: true, errors: [] }`, which made
 * every test of a validator gate unfailable: the copilot review screen refuses
 * to enable Confirm & create while a predicted manifest is invalid, and no
 * unit test could ever see that refusal, because the validator behind it
 * always said yes. That is how a builder tool shipped a widget shape the
 * validator rejects.
 *
 * @param {object} manifest - the manifest to validate.
 * @param {object} [options] - validator options.
 * @return {{valid: boolean, errors: Array}}
 */
export function validateManifest(manifest, options) {
	return _validateManifest(manifest, options)
}

/**
 * Legacy arity-1 stand-in for `useAppManifest`. Chain spec #2 ships an
 * arity-2 overload `(appId, bundledManifest)`; `useLivePreview.js` uses
 * the function arity as the discriminator, so an arity-1 stub here keeps
 * the default "preview unavailable" path active. Tests that need the
 * arity-2 shape swap this out via `vi.mock(...)`.
 *
 * @return {{manifest: null, loading: boolean}}
 */
export function useAppManifest(_appId) {
	return { manifest: null, loading: false }
}

import { useManifestEditHistory as _useManifestEditHistory } from '@conduction/nextcloud-vue/dist/esm/composables/useManifestEditHistory.js'
import { diffManifest as _diffManifest } from '@conduction/nextcloud-vue/dist/esm/utils/diffManifest.js'
/**
 * `manifestEditHistory` (builder-undo-redo, nc-vue change
 * `manifest-edit-history`) is a plain-JS undo/redo engine plus a thin
 * `ref()`-based Vue wrapper — neither file transitively `require()`s a
 * `.vue` SFC, so unlike the rest of this stub module it is re-exported
 * directly from the installed package's ESM output rather than faked.
 * Vitest's `@conduction/nextcloud-vue` alias only matches the bare
 * specifier (see vitest.config.js), so this subpath import resolves
 * through normal node_modules resolution and is NOT re-aliased back to
 * this file. Tests exercising undo/redo therefore run against the real,
 * published leaf logic (bounded stack, branch discard, structural-
 * identity no-op, snapshot freeze/share) — not a hand-rolled fake.
 *
 * NOTE: these are wrapped in local function declarations rather than a
 * bare `export { X } from '...'` re-export — an `export { X, Y }`
 * pass-through of these two particular imports triggers a Vite/Vitest
 * SSR-transform bug in this file (reproduced in isolation: the
 * re-exported binding resolves to `ReferenceError: X is not defined` at
 * call time, even though the same import works fine everywhere else).
 * The wrapper wins because it never puts the raw import binding on this
 * module's export list — only a Vitest quirk is being routed around
 * here, not the leaf's behaviour, which the wrappers forward unchanged.
 */
import { createManifestEditHistory as _createManifestEditHistory } from '@conduction/nextcloud-vue/dist/esm/utils/manifestEditHistory.js'
import { mergeManifestDelta as _mergeManifestDelta } from '@conduction/nextcloud-vue/dist/esm/utils/mergeManifestDelta.js'

/**
 * @param {object} [options] Forwarded verbatim to the leaf.
 * @return {object} The leaf's history instance.
 */
export function createManifestEditHistory(options) {
	return _createManifestEditHistory(options)
}

/**
 * @param {object} [options] Forwarded verbatim to the leaf.
 * @return {object} The leaf's reactive history handle.
 */
export function useManifestEditHistory(options) {
	return _useManifestEditHistory(options)
}

/**
 * `mergeManifestDelta` / `diffManifest` (app-delta-override,
 * component-blocks' insert path) are likewise Vue-free pure functions, so
 * they follow the exact same real-leaf-via-subpath-import pattern as
 * `createManifestEditHistory` above rather than a hand-rolled fake —
 * `PageDesigner.vue`'s block-insert merge exercises the real keyed-array
 * merge semantics under test, not an approximation of them.
 *
 * @param {object} base - the base manifest.
 * @param {object} delta - the delta payload to apply.
 * @return {{manifest: object, orphanedDeltaPaths: string[]}} the merge result.
 */
export function mergeManifestDelta(base, delta) {
	return _mergeManifestDelta(base, delta)
}

/**
 * @param {object} base - the base manifest.
 * @param {object} edited - the edited manifest.
 * @return {object} the minimal delta.
 */
export function diffManifest(base, edited) {
	return _diffManifest(base, edited)
}

/**
 * `useScopedTheme` (scoped-theme-applier, consumed here by
 * theme-picker-consumes-nldesign) is likewise a Vue-free pure composable —
 * no `.vue` SFC dependency, just plain JS over `@nextcloud/axios` /
 * `@nextcloud/router` — so it follows the same real-leaf-via-subpath-import
 * pattern as `createManifestEditHistory` above rather than a hand-rolled
 * fake. `ThemePickerDialog.vue`'s vitest suite therefore exercises the
 * REAL published `apply`/`teardown`/`listTokenSets`/`evaluateContrast`
 * logic under test (with `@nextcloud/axios` mocked at the HTTP boundary),
 * not an approximation of it — proof the published beta ships and runs a
 * working `useScopedTheme`, not just that it can be imported.
 */
import { useScopedTheme as _useScopedTheme } from '@conduction/nextcloud-vue/dist/esm/composables/useScopedTheme.js'

/**
 * @param {object} [opts] - forwarded verbatim to the leaf.
 * @return {{apply: Function, teardown: Function, fetchTokenCss: Function, listTokenSets: Function, evaluateContrast: Function}}
 */
export function useScopedTheme(opts) {
	return _useScopedTheme(opts)
}

// ── v2 widget placement editor (v2-widget-placement-editor) ──────────────────
//
// Two SFCs and three pure leaves. The SFCs are stubbed, because they are the
// `require('*.vue')` shape this whole file exists to route around; the leaves
// are re-exported from the package's own ESM output by their deep subpath, the
// same real-leaf pattern `mergeManifestDelta` and `validateManifest` follow
// above. That distinction is the point: `slotGeometry.js` resolves its column
// count through `resolveSlotColumns`, and a FAKE of that function would make
// the clamp spec assert the fake's arithmetic rather than the one number the
// validator's post-schema check reads.

/**
 * Stub for `CnWidgetGrid`. Declares the props and the emit the placement panel
 * binds, so a spec can read what was bound and can fire `layout-change`
 * itself. The real component drives GridStack, which jsdom cannot run.
 */
export const CnWidgetGrid = {
	name: 'CnWidgetGrid',
	props: {
		widgets: { type: Array, default: () => [] },
		slotName: { type: String, required: true },
		editable: { type: Boolean, default: false },
		columns: { type: Number, default: null },
		registry: { type: Object, default: null },
	},
	emits: ['layout-change'],
	render() {
		return h('div', {
			class: 'cn-widget-grid-stub',
			'data-slot': this.slotName,
			'data-editable': String(this.editable),
		})
	},
}

/**
 * Stub for `CnAddWidgetModal`. Declares every prop the panel binds (the spec
 * asserts `surface`, `userAddableOnly` and `editingWidget` on it) and both
 * emits, so a spec can drive a submit through the panel's persist path.
 */
export const CnAddWidgetModal = {
	name: 'CnAddWidgetModal',
	props: {
		show: { type: Boolean, default: false },
		preselectedType: { type: String, default: null },
		editingWidget: { type: Object, default: null },
		surface: { type: String, default: 'app-dashboard' },
		userAddableOnly: { type: Boolean, default: false },
		pageConfig: { type: Object, default: null },
		dataContext: { type: Object, default: null },
		uploadFn: { type: Function, default: null },
		fileUploadFn: { type: Function, default: null },
		calendarsFetcher: { type: Function, default: null },
	},
	emits: ['close', 'submit'],
	render() {
		return this.show ? h('div', { class: 'cn-add-widget-modal-stub' }) : null
	},
}

import {
	dashboardWidgetRegistry as _dashboardWidgetRegistry,
	getDefaultContent as _getDefaultContent,
	getWidgetTypeEntry as _getWidgetTypeEntry,
	listUserAddableWidgetTypes as _listUserAddableWidgetTypes,
	listWidgetTypes as _listWidgetTypes,
	registerDashboardWidget as _registerDashboardWidget,
} from '@conduction/nextcloud-vue/dist/esm/components/CnWidgetGrid/dashboardWidgetRegistry.js'
import { resolveSlotColumns as _resolveSlotColumns } from '@conduction/nextcloud-vue/dist/esm/utils/resolveSlotColumns.js'

/** The REAL shared catalog object, so a spec can register a type and see it. */
export const dashboardWidgetRegistry = _dashboardWidgetRegistry

/**
 * @param {string} type - the widget type key.
 * @param {object} entry - the registry entry.
 * @return {void}
 */
export function registerDashboardWidget(type, entry) {
	return _registerDashboardWidget(type, entry)
}

/**
 * @param {string} [surface] - the surface key to filter by.
 * @return {string[]} the offerable type keys.
 */
export function listWidgetTypes(surface) {
	return _listWidgetTypes(surface)
}

/**
 * @param {string} [surface] - the surface key to filter by.
 * @return {string[]} the type keys a user may add themselves.
 */
export function listUserAddableWidgetTypes(surface) {
	return _listUserAddableWidgetTypes(surface)
}

/**
 * @param {string} type - the widget type key.
 * @return {object|null} the registry entry, or null.
 */
export function getWidgetTypeEntry(type) {
	return _getWidgetTypeEntry(type)
}

/**
 * @param {string} type - the widget type key.
 * @return {object} a fresh copy of the type's default content.
 */
export function getDefaultContent(type) {
	return _getDefaultContent(type)
}

/**
 * The REAL column resolver — the one function `validateManifest`'s
 * `gridX + gridWidth` post-schema check calls, with the same arguments.
 *
 * @param {string} slotName - the slot key.
 * @param {object|null} [slotColumns] - the page's `config.slotColumns`.
 * @param {number|null} [propColumns] - an explicit `columns` override.
 * @return {number} the effective column count.
 */
export function resolveSlotColumns(slotName, slotColumns, propColumns) {
	return _resolveSlotColumns(slotName, slotColumns, propColumns)
}

export default {
	NcModal,
	NcDialog,
	NcButton,
	NcTextField,
	NcSelect,
	NcEmptyContent,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcLoadingIcon,
	createObjectStore,
	CnAppRoot,
	CnAppNav,
	CnTabs,
	CnTab,
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
	validateManifest,
	useAppManifest,
	createManifestEditHistory,
	useManifestEditHistory,
	mergeManifestDelta,
	diffManifest,
	useScopedTheme,
	CnWidgetGrid,
	CnAddWidgetModal,
	dashboardWidgetRegistry,
	registerDashboardWidget,
	listWidgetTypes,
	listUserAddableWidgetTypes,
	getWidgetTypeEntry,
	getDefaultContent,
	resolveSlotColumns,
}
