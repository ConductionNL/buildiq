/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Global setup for OpenBuild Vitest unit tests. Stubs the Nextcloud
 * `t()` and `n()` translation helpers so component renders that call
 * them resolve to the bare key string. Loaded automatically via
 * `test.setupFiles` in `vitest.config.js`.
 *
 * The helpers are exposed two ways because Vue 2's template compiler
 * emits `_vm.t(...)` (looking on the component instance), while plain
 * script code calls `t(...)` (looking on the global). We register them
 * as a global mixin so every mounted component has them on `this`, AND
 * on `globalThis` so direct script-level calls resolve.
 */

import { config } from '@vue/test-utils'

const tStub = (_app, key, _vars) => key
const nStub = (_app, singular, plural, count) => (count === 1 ? singular : plural)

globalThis.t = tStub
globalThis.n = nStub

// Vue 3: register the translation stubs as a global mixin for every mounted
// component (Vue 2's `Vue.mixin()` is gone — Test Utils' config.global is the
// idiomatic replacement, and covers both `_vm.t(...)` template calls and
// `this.t(...)` script calls).
config.global.mixins = [
	...(config.global.mixins || []),
	{
		methods: {
			t: tStub,
			n: nStub,
		},
	},
]
