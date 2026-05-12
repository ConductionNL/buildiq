/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `useManifestHistory` (OQ-1):
 *  - push records new states; no-op on structurally-identical states.
 *  - undo / redo walk the stack and return fresh clones.
 *  - canUndo / canRedo reflect the cursor position.
 *  - a push after an undo truncates the redo tail.
 *  - the stack is bounded to `limit`, dropping the oldest entry.
 *  - reset re-seeds the stack.
 *  - returned states are clones (mutating them doesn't corrupt history).
 */

import { describe, it, expect } from 'vitest'
import { useManifestHistory } from '../../src/composables/useManifestHistory.js'

describe('useManifestHistory', () => {
	it('seeds with the initial state and cannot undo/redo', () => {
		const h = useManifestHistory({ pages: [] })
		expect(h.canUndo.value).toBe(false)
		expect(h.canRedo.value).toBe(false)
		expect(h.size.value).toBe(1)
	})

	it('push records a new state and enables undo', () => {
		const h = useManifestHistory({ v: 1 })
		h.push({ v: 2 })
		expect(h.canUndo.value).toBe(true)
		expect(h.size.value).toBe(2)
	})

	it('push is a no-op for a structurally-identical state', () => {
		const h = useManifestHistory({ pages: [{ id: 'a' }] })
		h.push({ pages: [{ id: 'a' }] })
		expect(h.size.value).toBe(1)
		expect(h.canUndo.value).toBe(false)
	})

	it('undo returns the previous state and redo brings it back', () => {
		const h = useManifestHistory({ v: 1 })
		h.push({ v: 2 })
		h.push({ v: 3 })
		expect(h.undo()).toEqual({ v: 2 })
		expect(h.canRedo.value).toBe(true)
		expect(h.undo()).toEqual({ v: 1 })
		expect(h.canUndo.value).toBe(false)
		expect(h.redo()).toEqual({ v: 2 })
		expect(h.redo()).toEqual({ v: 3 })
		expect(h.canRedo.value).toBe(false)
	})

	it('undo at the bottom / redo at the top return null', () => {
		const h = useManifestHistory({ v: 1 })
		expect(h.undo()).toBeNull()
		h.push({ v: 2 })
		h.redo()
		expect(h.redo()).toBeNull()
	})

	it('a push after an undo truncates the redo tail', () => {
		const h = useManifestHistory({ v: 1 })
		h.push({ v: 2 })
		h.push({ v: 3 })
		h.undo() // back to v:2
		h.push({ v: 99 })
		expect(h.canRedo.value).toBe(false)
		expect(h.undo()).toEqual({ v: 2 })
		expect(h.undo()).toEqual({ v: 1 })
	})

	it('bounds the stack to `limit`, dropping the oldest entry', () => {
		const h = useManifestHistory({ n: 0 }, { limit: 3 })
		h.push({ n: 1 })
		h.push({ n: 2 })
		h.push({ n: 3 }) // overflows — { n: 0 } dropped
		expect(h.size.value).toBe(3)
		// Walk back to the oldest surviving state.
		expect(h.undo()).toEqual({ n: 2 })
		expect(h.undo()).toEqual({ n: 1 })
		expect(h.canUndo.value).toBe(false)
	})

	it('reset re-seeds the stack', () => {
		const h = useManifestHistory({ v: 1 })
		h.push({ v: 2 })
		h.reset({ fresh: true })
		expect(h.size.value).toBe(1)
		expect(h.canUndo.value).toBe(false)
		h.push({ fresh: true, x: 1 })
		expect(h.undo()).toEqual({ fresh: true })
	})

	it('returned states are clones — mutating them does not corrupt history', () => {
		const h = useManifestHistory({ pages: [{ id: 'a' }] })
		h.push({ pages: [{ id: 'a' }, { id: 'b' }] })
		const back = h.undo()
		back.pages.push({ id: 'mutant' })
		// Redo should still yield the untouched 2-page state.
		expect(h.redo()).toEqual({ pages: [{ id: 'a' }, { id: 'b' }] })
	})

	it('handles a null / undefined initial state gracefully', () => {
		const h = useManifestHistory(null)
		expect(h.size.value).toBe(1)
		h.push({ pages: [] })
		expect(h.undo()).toEqual({})
	})
})
