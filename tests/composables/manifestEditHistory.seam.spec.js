/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the Buildiq ↔ nc-vue integration seam
 * (`src/composables/useSessionHistory.js`), migrated from the deleted
 * local `useManifestHistory` composable's spec (builder-undo-redo,
 * design.md D1 / task 4.1).
 *
 * Exercises the seam exactly as Buildiq's designers consume it:
 *  - push/undo/redo round-trip.
 *  - push is a no-op on a structurally-identical state.
 *  - a push after an undo truncates the redo tail (REQ-BUR-001).
 *  - reset() re-baselines the session to a single entry, both Undo and
 *    Redo disabled (REQ-BUR-004 / design.md D3).
 *  - never issues a network request — no axios import anywhere in the
 *    seam or the leaf it wraps (REQ-BUR-001).
 *  - depth-100 bound: overflow drops the oldest entry; trimming never
 *    breaks redo (REQ-BUR-007).
 *  - a whole-state replacement (the shape a raw-JSON surface produces)
 *    lands as exactly one entry; one undo restores the complete pre-edit
 *    state (REQ-BUR-006).
 */

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { useSessionHistory } from '../../src/composables/useSessionHistory.js'

describe('useSessionHistory (manifestEditHistory integration seam)', () => {
	it('seeds with the initial state and cannot undo/redo', () => {
		const h = useSessionHistory({ pages: [] })
		expect(h.canUndo.value).toBe(false)
		expect(h.canRedo.value).toBe(false)
		expect(h.size.value).toBe(1)
	})

	it('push records a new state and enables undo', () => {
		const h = useSessionHistory({ v: 1 })
		h.push({ v: 2 })
		expect(h.canUndo.value).toBe(true)
		expect(h.size.value).toBe(2)
	})

	it('push is a no-op for a structurally-identical state', () => {
		const h = useSessionHistory({ pages: [{ id: 'a' }] })
		h.push({ pages: [{ id: 'a' }] })
		expect(h.size.value).toBe(1)
		expect(h.canUndo.value).toBe(false)
	})

	it('undo returns the previous state and redo brings it back', () => {
		const h = useSessionHistory({ v: 1 })
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
		const h = useSessionHistory({ v: 1 })
		expect(h.undo()).toBeNull()
		h.push({ v: 2 })
		h.redo()
		expect(h.redo()).toBeNull()
	})

	it('a push after an undo truncates the redo tail (REQ-BUR-001)', () => {
		const h = useSessionHistory({ v: 1 })
		h.push({ v: 2 })
		h.push({ v: 3 })
		h.undo() // back to v:2
		h.push({ v: 99 })
		expect(h.canRedo.value).toBe(false)
		expect(h.undo()).toEqual({ v: 2 })
		expect(h.undo()).toEqual({ v: 1 })
	})

	it('reset() re-baselines the session — both Undo and Redo disabled (REQ-BUR-004)', () => {
		const h = useSessionHistory({ v: 1 })
		h.push({ v: 2 })
		h.reset({ fresh: true })
		expect(h.size.value).toBe(1)
		expect(h.canUndo.value).toBe(false)
		expect(h.canRedo.value).toBe(false)
		h.push({ fresh: true, x: 1 })
		expect(h.undo()).toEqual({ fresh: true })
	})

	it('reset() clears any redo tail from before the boundary', () => {
		const h = useSessionHistory({ v: 1 })
		h.push({ v: 2 })
		h.undo()
		expect(h.canRedo.value).toBe(true)
		h.reset({ v: 'baseline' })
		expect(h.canRedo.value).toBe(false)
		expect(h.canUndo.value).toBe(false)
	})

	it('bounds the stack to `limit` (100 by default), dropping the oldest entry (REQ-BUR-007)', () => {
		const h = useSessionHistory({ n: 0 }, { limit: 3 })
		h.push({ n: 1 })
		h.push({ n: 2 })
		h.push({ n: 3 }) // overflows — { n: 0 } dropped
		expect(h.size.value).toBe(3)
		// Walk back to the oldest surviving state.
		expect(h.undo()).toEqual({ n: 2 })
		expect(h.undo()).toEqual({ n: 1 })
		expect(h.canUndo.value).toBe(false)
	})

	it('defaults to a depth-100 bound when no limit is given', () => {
		const h = useSessionHistory({ n: 0 })
		for (let i = 1; i <= 101; i += 1) {
			h.push({ n: i })
		}
		// 101 pushes on top of the seed (n:0) = 102 distinct states,
		// bounded to the most recent 100 (n:2 .. n:101).
		expect(h.size.value).toBe(100)
		for (let i = 0; i < 99; i += 1) {
			expect(h.canUndo.value).toBe(true)
			h.undo()
		}
		expect(h.canUndo.value).toBe(false)
	})

	it('trimming never breaks redo (REQ-BUR-007)', () => {
		const h = useSessionHistory({ n: 0 }, { limit: 3 })
		h.push({ n: 1 })
		h.push({ n: 2 })
		h.push({ n: 3 })
		h.undo()
		h.undo()
		expect(h.redo()).toEqual({ n: 2 })
		expect(h.redo()).toEqual({ n: 3 })
		expect(h.canRedo.value).toBe(false)
	})

	it('a whole-state replacement lands as exactly one entry (REQ-BUR-006)', () => {
		const h = useSessionHistory({ pages: [{ id: 'a' }], menu: [{ id: 'x' }] })
		const wholeReplacement = {
			pages: [{ id: 'a' }, { id: 'b' }, { id: 'c' }],
			menu: [],
		}
		h.push(wholeReplacement)
		expect(h.size.value).toBe(2)
		expect(h.undo()).toEqual({ pages: [{ id: 'a' }], menu: [{ id: 'x' }] })
	})

	it('handles a null / undefined initial state gracefully', () => {
		const h = useSessionHistory(null)
		expect(h.size.value).toBe(1)
		h.push({ pages: [] })
		expect(h.undo()).toEqual({})
	})

	it('never issues a network request — the seam file imports no HTTP client', () => {
		const seamPath = resolve(
			process.cwd(),
			'src/composables/useSessionHistory.js',
		)
		const source = readFileSync(seamPath, 'utf8')
		expect(source).not.toMatch(/axios|fetch\(/)
		// Exercise push/undo/redo with no axios mock installed in this spec —
		// an accidental network call would throw (unmocked import), not
		// silently pass.
		const h = useSessionHistory({ v: 1 })
		h.push({ v: 2 })
		h.undo()
		h.redo()
		expect(h.size.value).toBe(2)
	})
})
