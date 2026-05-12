// SPDX-License-Identifier: EUPL-1.2
/**
 * useManifestHistory — a bounded undo/redo stack for the in-flight
 * manifest the PageDesigner edits (OQ-1).
 *
 * The PageDesigner is a controlled component (manifest prop in,
 * `update:manifest` out) — so the history lives here, fed by `push(next)`
 * on every accepted edit and read by `undo()` / `redo()` which return the
 * historical state for the host to re-emit. `push` is a no-op when the
 * next state is structurally identical to the current one (cheap JSON
 * compare) so a re-emit of the same manifest never pollutes the stack.
 *
 * Bounded to `limit` states (default 50): the oldest entry is dropped
 * when a new push overflows. `undo`/`redo` move a cursor over the stack
 * rather than mutating it, so a redo is still available until the next
 * push (which truncates the redo tail, classic editor semantics).
 *
 * Returns reactive `canUndo` / `canRedo` for toolbar button disabling.
 */
import { ref, computed } from 'vue'

const DEFAULT_LIMIT = 50

/**
 * @param {object|null} initial - the starting manifest (recorded as the
 *   first stack entry; may be null/empty).
 * @param {object} [opts] - options.
 * @param {number} [opts.limit] - max stack depth (default 50).
 * @return {object} { push, undo, redo, reset, canUndo, canRedo, size }
 */
export function useManifestHistory(initial = null, opts = {}) {
	const limit = Math.max(1, opts.limit || DEFAULT_LIMIT)
	const stack = ref([clone(initial)])
	const cursor = ref(0)

	const canUndo = computed(() => cursor.value > 0)
	const canRedo = computed(() => cursor.value < stack.value.length - 1)
	const size = computed(() => stack.value.length)

	/**
	 * Record a new state. No-op when it equals the current state. Drops
	 * any redo tail and trims to `limit` from the front.
	 *
	 * @param {object} next - the new manifest.
	 */
	function push(next) {
		const snap = clone(next)
		if (sameJson(snap, stack.value[cursor.value])) {
			return
		}
		const head = stack.value.slice(0, cursor.value + 1)
		head.push(snap)
		const overflow = head.length - limit
		const trimmed = overflow > 0 ? head.slice(overflow) : head
		stack.value = trimmed
		cursor.value = trimmed.length - 1
	}

	/**
	 * Step back one state. Returns a fresh clone of the previous manifest
	 * (or null when there is nothing to undo).
	 *
	 * @return {object|null}
	 */
	function undo() {
		if (!canUndo.value) {
			return null
		}
		cursor.value -= 1
		return clone(stack.value[cursor.value])
	}

	/**
	 * Step forward one state. Returns a fresh clone of the next manifest
	 * (or null when there is nothing to redo).
	 *
	 * @return {object|null}
	 */
	function redo() {
		if (!canRedo.value) {
			return null
		}
		cursor.value += 1
		return clone(stack.value[cursor.value])
	}

	/**
	 * Re-seed the stack (e.g. when the host loads a different Application).
	 *
	 * @param {object|null} manifest - the new starting manifest.
	 */
	function reset(manifest = null) {
		stack.value = [clone(manifest)]
		cursor.value = 0
	}

	return { push, undo, redo, reset, canUndo, canRedo, size }
}

/**
 * Deep clone via JSON (manifests are plain JSON). Returns `{}` for
 * nullish / non-cloneable input so callers never get undefined.
 *
 * @param {*} value - value to clone.
 * @return {object}
 */
function clone(value) {
	if (value === null || value === undefined) {
		return {}
	}
	try {
		return JSON.parse(JSON.stringify(value))
	} catch {
		return {}
	}
}

/**
 * Structural equality via JSON serialisation.
 *
 * @param {*} a - first value.
 * @param {*} b - second value.
 * @return {boolean}
 */
function sameJson(a, b) {
	try {
		return JSON.stringify(a) === JSON.stringify(b)
	} catch {
		return false
	}
}
