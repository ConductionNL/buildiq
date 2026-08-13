/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vue Test Utils v1 -> v2 mount-options adapter.
 *
 * VTU v2 moved the per-mount environment options under a `global` key:
 *
 *   v1: mount(C, { stubs, provide, mocks, directives, plugins, components })
 *   v2: mount(C, { global: { stubs, provide, mocks, directives, plugins, components } })
 *
 * v2 does not warn or throw on the v1 shape — it SILENTLY IGNORES those keys.
 * A spec that stubs a heavy child still mounts the real child, a spec that
 * provides an injection key mounts without it, and the failure surfaces far
 * away as a confusing assertion or a null-deref inside an unrelated component.
 * ~48 spec files here use the v1 shape (28 `stubs`, 20 `mocks`, 2 `provide`).
 *
 * Rewriting them by hand is a large structural edit with a real chance of
 * silently changing what a spec asserts. This adapter hoists the v1 keys into
 * `global` at call time, preserving each spec's intent exactly, and is
 * trivially reversible: `vitest.config.js` aliases `@vue/test-utils` here, so
 * deleting the alias restores stock behaviour.
 *
 * It is a migration aid, not a permanent fixture — specs should move to the
 * native `global: { … }` shape over time, and this file deleted once
 * `grep -rE '^\s+(stubs|provide|mocks): ' tests/ src/` comes back empty.
 *
 * `propsData` is deliberately NOT touched: v2 still accepts it as an alias
 * for `props`.
 */

// Import the real module by absolute path — importing '@vue/test-utils' here
// would hit the vitest alias that points at this file and recurse forever.
import * as vtu from '../../node_modules/@vue/test-utils/dist/vue-test-utils.esm-bundler.mjs'

/** Mount options v1 accepted at the top level and v2 expects under `global`. */
const HOISTED_KEYS = [
	'stubs',
	'provide',
	'mocks',
	'directives',
	'plugins',
	'components',
	'config',
	'renderStubDefaultSlot',
]

/**
 * Move any v1-style top-level environment options into `global`, leaving an
 * explicit `global` block the caller already wrote as the winner on conflict.
 *
 * @param {object} [options] Mount options as the spec wrote them.
 * @return {object} Options in the shape VTU v2 expects.
 */
function hoistGlobalOptions(options) {
	if (!options || typeof options !== 'object') {
		return options
	}
	const hoisted = {}
	let found = false
	for (const key of HOISTED_KEYS) {
		if (Object.prototype.hasOwnProperty.call(options, key)) {
			hoisted[key] = options[key]
			found = true
		}
	}
	if (!found) {
		return options
	}
	const next = { ...options }
	for (const key of HOISTED_KEYS) {
		delete next[key]
	}
	// An explicitly-written `global` block wins — it is already v2 syntax and
	// therefore states the author's current intent.
	next.global = { ...hoisted, ...(options.global || {}) }
	return next
}

export * from '../../node_modules/@vue/test-utils/dist/vue-test-utils.esm-bundler.mjs'

export const mount = (component, options) =>
	vtu.mount(component, hoistGlobalOptions(options))
export const shallowMount = (component, options) =>
	vtu.shallowMount(component, hoistGlobalOptions(options))
