/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for OpenBuilt Vue 2.7 unit tests.
 *
 * Tests live under `tests/components/**` and `tests/views/**` and run in
 * a jsdom environment so DOM assertions (`wrapper.find`, `wrapper.text`,
 * event firing) work without a real browser.
 *
 * Notes:
 *  - `@vitejs/plugin-vue2` compiles single-file components for Vite/Vitest
 *    (separate from webpack's `vue-loader`).
 *  - `*.css` side-effect imports from `@nextcloud/vue` and related
 *    packages do not exist on disk in unit-test mode (they are produced
 *    by a parallel vite build the published packages ship). The
 *    `cssNoop` plugin intercepts those resolutions and feeds the
 *    transformer an empty module so component mounts don't crash with
 *    `ERR_UNKNOWN_FILE_EXTENSION`.
 *  - `@conduction/nextcloud-vue` is aliased to a lightweight stub under
 *    `tests/vitest/stubs/` because its CJS bundle uses `require('*.vue')`
 *    which Vite's transform pipeline cannot consume.
 */

const path = require('path')
const vue2 = require('@vitejs/plugin-vue2')

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
		include: ['tests/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'tests/Unit/**', 'tests/unit/**', 'node_modules/**'],
		setupFiles: [path.resolve(__dirname, 'tests/vitest/setup.js')],
		server: {
			deps: {
				inline: [
					/@nextcloud\/vue/,
					/@nextcloud\/axios/,
					/@nextcloud\/dialogs/,
					/@nextcloud\/initial-state/,
					/@conduction\/nextcloud-vue/,
					/vue-material-design-icons/,
					/vue-select/,
					/vue-multiselect/,
					/floating-vue/,
				],
			},
		},
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@conduction\/nextcloud-vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/conduction-nextcloud-vue.js'),
			},
		],
	},
}
