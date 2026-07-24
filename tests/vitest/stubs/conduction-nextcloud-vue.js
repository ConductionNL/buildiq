/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
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

const stub = (name) => ({ name, render: (h) => h('div') })

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
export const CnPageRenderer = { name: 'CnPageRenderer', render: (h) => h('div') }
export const CnCard = {
	name: 'CnCard',
	props: ['title', 'description', 'titleTooltip', 'icon', 'iconSize', 'labels', 'stats'],
	render(h) {
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
// icon pack — OpenBuild owns the data and feeds it through these. Behaviour
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
		if (!entry || entry.value == null || entry.value === '') return false
		if (seen.has(entry.value)) return false
		seen.add(entry.value)
		return true
	})
}

/**
 * Lightweight stand-in for the lib's manifest validator. The unit suite
 * only needs it to be callable; the structural manifest checks live in
 * tests/vitest/manifest.spec.js. Returns `{ valid: true, errors: [] }`.
 *
 * @return {{valid: boolean, errors: Array}}
 */
export function validateManifest() {
	return { valid: true, errors: [] }
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
import { useManifestEditHistory as _useManifestEditHistory } from '@conduction/nextcloud-vue/dist/esm/composables/useManifestEditHistory.js'
import { mergeManifestDelta as _mergeManifestDelta } from '@conduction/nextcloud-vue/dist/esm/utils/mergeManifestDelta.js'
import { diffManifest as _diffManifest } from '@conduction/nextcloud-vue/dist/esm/utils/diffManifest.js'

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
}
