// SPDX-License-Identifier: EUPL-1.2
/**
 * useSessionHistory — thin per-app adapter over nc-vue's
 * `useManifestEditHistory` leaf (`@conduction/nextcloud-vue`, change
 * `manifest-edit-history`), bridging the narrow surface OpenBuild's
 * designers consume (`push`, `undo`, `redo`, `reset`, reactive
 * `canUndo`/`canRedo`, `size`) onto the leaf's actual surface
 * (`push`, `undo`, `redo`, `clear`, reactive `canUndo`/`canRedo`/`size`/
 * `current`).
 *
 * Contains ZERO history logic of its own — every semantic (bounded
 * stack, branch discard, structural-identity no-op, snapshot
 * freeze/share) lives in the leaf. The only adaptation this file makes:
 *
 *  - the leaf has no constructor-time seed and no `reset(state)` — a
 *    fresh engine starts empty (`cursor === -1`); this adapter seeds it
 *    with one `push(initial)` on creation, and implements `reset(state)`
 *    as `clear()` followed by `push(state)` (D3: re-baseline to a single
 *    entry with both Undo and Redo disabled).
 *
 * See openspec/changes/builder-undo-redo/design.md D1 (task 0.1 —
 * "if the surface differs, adapt at the single integration seam").
 *
 * @module composables/useSessionHistory
 */
import { useManifestEditHistory } from '@conduction/nextcloud-vue'

/**
 * Create a bounded, per-editing-session undo/redo history seeded with an
 * initial state.
 *
 * @param {object|null} initial - the starting draft (manifest or staged
 *   editor model); recorded as the session's first (baseline) entry.
 * @param {object} [opts] - options.
 * @param {number} [opts.limit] - max stack depth (default 100, REQ-BUR-007).
 * @return {{
 *   push: (state: object) => (object|null),
 *   undo: () => (object|null),
 *   redo: () => (object|null),
 *   reset: (state: object|null) => void,
 *   canUndo: import('vue').Ref<boolean>,
 *   canRedo: import('vue').Ref<boolean>,
 *   size: import('vue').Ref<number>,
 * }}
 */
export function useSessionHistory(initial = null, opts = {}) {
	const limit = Math.max(1, opts.limit || 100)
	const engine = useManifestEditHistory({ limit })

	// Seed the session baseline: one entry, both Undo and Redo disabled.
	engine.push(initial ?? {})

	/**
	 * Re-baseline the session to `state` — a session boundary (successful
	 * save, app/version switch, publish/rollback re-entry per D3). Clears
	 * the whole stack and reseeds it with exactly one entry so Undo/Redo
	 * are both disabled immediately after.
	 *
	 * @param {object|null} state - the new baseline state.
	 * @return {void}
	 */
	function reset(state) {
		engine.clear()
		engine.push(state ?? {})
	}

	return {
		push: engine.push,
		undo: engine.undo,
		redo: engine.redo,
		reset,
		canUndo: engine.canUndo,
		canRedo: engine.canRedo,
		size: engine.size,
	}
}
