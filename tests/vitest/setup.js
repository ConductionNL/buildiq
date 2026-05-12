/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Global setup for Vitest unit tests. Stubs the Nextcloud `t()` and `n()`
 * translation helpers so component renders that call them resolve to the
 * bare key string. Loaded automatically via `test.setupFiles` in
 * `vitest.config.js`.
 *
 * Helpers are exposed BOTH ways so component templates and script-level
 * calls both work:
 *   - Vue 2's template compiler emits `_vm.t(...)` — looks on instance.
 *   - Plain script-level `t(...)` calls look on globalThis.
 *
 * Per memory rule we keep the stubs visible — beforeEach in individual
 * specs can override if they care about translation arguments.
 */

import Vue from 'vue'

const tStub = (_app, key, _vars) => key
const nStub = (_app, singular, plural, count) => (count === 1 ? singular : plural)

globalThis.t = tStub
globalThis.n = nStub

Vue.mixin({
	methods: {
		t: tStub,
		n: nStub,
	},
})
