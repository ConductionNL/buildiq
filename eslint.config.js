const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	// `@nextcloud/eslint-config`'s default entry point extends
	// `plugin:vue/recommended`, which is the **Vue 2** rule set: it forbids
	// `v-model:arg` (`vue/no-v-model-argument`) and keyed `<template v-for>`
	// (`vue/no-v-for-template-key`) — both of which are the *correct* and
	// required syntax in Vue 3. The package ships a dedicated `vue3` entry
	// point extending `plugin:vue/vue3-recommended`; now that the app runs on
	// Vue 3 that is the ruleset that actually describes this codebase.
	// TODO(vue3-preset): `@nextcloud/eslint-config/vue3` is the correct target —
	// it is the same config on `plugin:vue/vue3-recommended`. Switching it here
	// raises the count from 146 to 267 errors, because that preset also brings a
	// stricter general rule set beyond the Vue rules. Those extra findings are
	// real and worth fixing, but doing it half-way leaves the gate worse than
	// before, so the switch waits until they can be worked through in one pass.
	extends: compat.extends('@nextcloud'),

	settings: {
		// `@spec` is this project's traceability tag (hydra gate-16 /
		// gate-19 parse it to link a method to its openspec requirement) and
		// `@category` groups modules in the generated docs. Neither is a
		// standard JSDoc tag, so they must be declared or `check-tag-names`
		// reports every annotated method.
		jsdoc: {
			definedTags: ['spec', 'category'],
		},
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@floating-ui/dom-actual', './node_modules/@floating-ui/dom'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring.
		// Also allow leading-underscore vars (idiomatic "discarded destructure" —
		// `const { foo: _foo, ...rest } = x` to strip a key while keeping the rest).
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n|_)', argsIgnorePattern: '^_' }],
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off', // disable namespace checking to avoid parser requirement
		'import/default': 'off', // disable default import checking to avoid parser requirement
		'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
		'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
	},
}])
