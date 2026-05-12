/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest configuration for OpenBuilt Vue 2 unit tests.
 *
 * Spec files live under `tests/vitest/` and target src/composables + src/
 * modals. The Nextcloud `t()` translation helper and the Vue-2 plugin
 * registry are wired up by `tests/vitest/setup.js` (loaded automatically
 * via `test.setupFiles` below).
 *
 * Why this exists (and not earlier):
 * The openbuilt-rbac change is the first one to ship a non-trivial
 * composable + an owner-only modal that we need to unit-test in isolation.
 * Borrowed the mydash config layout (proven across 11 mydash composables)
 * so new contributors find familiar territory.
 */

const path = require('path')
const vue2 = require('@vitejs/plugin-vue2')

/**
 * Intercept `*.css` imports from @nextcloud/vue + transitive packages —
 * Vite's transform pipeline rejects them because they are produced by a
 * parallel webpack build, not present on disk during the unit-test run.
 * Substitute an empty module so component mounts never load a real
 * stylesheet.
 */
const cssNoop = {
	name: 'openbuilt-css-noop',
	enforce: 'pre',
	resolveId(id) {
		if (typeof id === 'string' && /\.css(\?.*)?$/.test(id)) {
			return '\0virtual:css-noop'
		}
		return null
	},
	load(id) {
		if (id === '\0virtual:css-noop') {
			return 'export default {}'
		}
		return null
	},
}

module.exports = {
	plugins: [
		cssNoop,
		vue2.default ? vue2.default() : vue2(),
	],
	test: {
		environment: 'jsdom',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		setupFiles: [path.resolve(__dirname, 'tests/vitest/setup.js')],
		server: {
			deps: {
				// Inline the NC + Conduction ecosystem so the cssNoop plugin
				// above intercepts their .css side-effect imports. Without
				// this, Vitest hands the raw .css path to Node's ESM loader
				// which crashes with `ERR_UNKNOWN_FILE_EXTENSION`.
				inline: [
					/@nextcloud\/vue/,
					/@nextcloud\/axios/,
					/@nextcloud\/dialogs/,
					/@nextcloud\/initial-state/,
					/@conduction\/nextcloud-vue/,
					/vue-material-design-icons/,
					/vue-select/,
					/vue-multiselect/,
				],
			},
		},
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			// `@conduction/nextcloud-vue` ships a CJS bundle whose internal
			// require('foo.vue') calls Vite's transform layer cannot serve.
			// Substitute the bundle with a stub at the alias layer; tests
			// that care about specific Nc* components use vi.mock to
			// override individually.
			{
				find: /^@conduction\/nextcloud-vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/conduction-nextcloud-vue.js'),
			},
		],
	},
}
