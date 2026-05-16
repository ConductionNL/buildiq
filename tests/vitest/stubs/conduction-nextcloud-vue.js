/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuilt Contributors
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
}
