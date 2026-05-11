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
}
