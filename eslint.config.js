const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

// Shared Vue 3 rule layer, published inside @conduction/nextcloud-vue.
//
// It is an ARRAY of configs, not one object, and it registers no plugins —
// which is why it layers cleanly on top of the @nextcloud v8 base and must be
// spread LAST. Do NOT adopt `@nextcloud/eslint-config/vue3` directly: it sets
// `parserOptions.parser` to a bare string, which routes template expressions
// through @typescript-eslint/parser, drops `v-for` scope, and manufactures
// hundreds of bogus `vue/valid-v-for` errors. (That is exactly what the long
// note this comment replaces had measured — the conclusion was right, the
// remedy was to keep hand-rolling, and the fleet has since solved it properly
// in nc-vue.)
const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	{
		// `@nextcloud/eslint-config`'s default entry point extends
		// `plugin:vue/recommended`, which is the **Vue 2** rule set. It is kept as
		// the base for its general JS/import/jsdoc rules; the Vue-3 corrections are
		// applied by `conductionVue3Fixes`, spread last at the bottom of this file.
		//
		// Before that layer was adopted, `npx eslint --print-config` on this
		// Vue 3 app showed EVERY `vue/no-deprecated-*` rule as `undefined` (zero
		// armed) and `vue/no-multiple-template-root` armed at `[2]` — i.e. the
		// gate that catches surviving Vue-2 idioms was entirely absent, while a
		// rule forbidding syntax Vue 3 explicitly permits was on. That is the same
		// configuration that let four `beforeDestroy` hooks (silent memory leaks,
		// no console output) survive openconnector's Vue 3 migration.
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
						[
							'@floating-ui/dom-actual',
							'./node_modules/@floating-ui/dom',
						],
					],
					extensions: ['.js', '.ts', '.vue', '.json', '.css'],
				},
			},
		},

		rules: {
			// `settings.jsdoc.definedTags` (above) is NOT honoured by
			// `jsdoc/check-tag-names` once the rule is configured by an extended
			// preset — the rule reads `definedTags` from its own options, and the
			// preset's options object wins over the shared setting. That is why
			// every `@spec` annotation still reported "Invalid JSDoc tag name"
			// (1106 warnings) despite the setting being declared. Passing the tags
			// as rule options is the form the rule actually reads.
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec', 'category'] }],
			// NOTE: `vue/no-v-model-argument` and `vue/no-v-for-template-key` used
			// to be disabled here by hand. They are two of the three inverted
			// Vue-2 rules `conductionVue3Fixes` turns off fleet-wide (along with
			// `vue/no-multiple-template-root`), so the local disables are gone.
			// Allow unused i18n functions (t, n) — imported for future translation wiring.
			// Also allow leading-underscore vars (idiomatic "discarded destructure" —
			// `const { foo: _foo, ...rest } = x` to strip a key while keeping the rest).
			'no-unused-vars': [
				'error',
				{ varsIgnorePattern: '^(t|n|_)', argsIgnorePattern: '^_' },
			],
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'import/namespace': 'off', // disable namespace checking to avoid parser requirement
			'import/default': 'off', // disable default import checking to avoid parser requirement
			'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
			'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
		},
	},
	// Spread LAST so the Vue 3 rules win over the Vue-2-era @nextcloud base.
	// Without this layer ZERO `vue/no-deprecated-*` rules are active, so Vue-2
	// survivals (beforeDestroy, .sync, filters, $listeners) lint clean while
	// being silently ignored at runtime.
	...conductionVue3Fixes,
	// eslint-config-prettier LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything spread after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule: the whole `vue/no-deprecated-*` family
	// stays present and ON, because prettier has no opinion about them.
	// `indent` is now off HERE and enforced by prettier's `useTabs: true`
	// instead — the same tab, from the tool that also covers CSS and SCSS,
	// which @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
