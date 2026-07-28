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
	// required syntax in Vue 3. Those two rules are switched off below.
	//
	// RESOLVED(vue3-preset): the previous TODO here proposed switching to
	// `@nextcloud/eslint-config/vue3` and described the resulting 146→267 error
	// jump as "stricter general rules ... real and worth fixing". That was
	// measured but never inspected. It is wrong.
	//
	// 128 of those 267 errors are a single rule, `vue/valid-v-for`, claiming
	// "Expected 'v-bind:key' directive to use the variables which are defined by
	// the 'v-for' directive" — on code that plainly does exactly that, e.g.
	//   <template v-for="v in openableVersions" :key="v.slug">
	//   <div v-for="(item, index) in items" :key="item._key">
	// The key references the loop variable in every sampled case. Under the
	// default entry point the very same files produce zero `valid-v-for`
	// errors, so this is the `vue3` preset mis-wiring `vue-eslint-parser`'s
	// template scope under FlatCompat, not a stricter check. Adopting it would
	// mean "fixing" 128 pieces of correct Vue 3 code.
	//
	// It also enables `vue/v-on-event-hyphenation` (169 findings), whose
	// autofix rewrites `@update:modelValue` to `@update:model-value`. Nextcloud
	// Vue 3 field components resolve their model listener through `useModel`,
	// which reads the `onUpdate:modelValue` prop directly rather than going
	// through `emit()`'s hyphenated-variant lookup — so the hyphenated form is
	// silently DEAD. Applying that autofix would re-break the 34 listeners
	// commit 441128f4 just repaired, with no error at runtime.
	//
	// So: stay on the default entry point and disable the two genuinely
	// Vue-2-only rules. That is the ruleset that actually describes this
	// codebase; the `vue3` preset is not adoptable until upstream fixes it.
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
		// `settings.jsdoc.definedTags` (above) is NOT honoured by
		// `jsdoc/check-tag-names` once the rule is configured by an extended
		// preset — the rule reads `definedTags` from its own options, and the
		// preset's options object wins over the shared setting. That is why
		// every `@spec` annotation still reported "Invalid JSDoc tag name"
		// (1106 warnings) despite the setting being declared. Passing the tags
		// as rule options is the form the rule actually reads.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec', 'category'] }],
		// Vue-2-only rules that flag *required* Vue 3 syntax. `v-model:open`
		// (an argument on v-model) replaced Vue 2's `.sync`, and Vue 3 requires
		// the `v-for` key on the `<template>` itself rather than on its
		// children. Both are errors under `plugin:vue/recommended` only because
		// that preset describes Vue 2 — see the long note above.
		'vue/no-v-model-argument': 'off',
		'vue/no-v-for-template-key': 'off',
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
