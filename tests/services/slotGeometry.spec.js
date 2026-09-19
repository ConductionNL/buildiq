/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/slotGeometry.js`
 * (v2-widget-placement-editor task 1.1).
 *
 * Covers the two scenarios the delta spec marks `@e2e exclude` because they
 * are pure-function contracts a browser run covers worse:
 *   - "A placement cannot be pushed past the slot's last column"
 *   - "A widened slot allows a wider placement"
 *
 * The column count under test is the library's own `resolveSlotColumns`,
 * reached through the vitest alias stub which re-exports the REAL leaf. That
 * matters: `validateManifest.js` calls that same function with those same
 * arguments for its `gridX + gridWidth` post-schema check, so a clamp asserted
 * against a fake would prove nothing about the save the validator accepts.
 */

import { describe, expect, it } from 'vitest'
import {
	applySlotRules,
	columnsForSlot,
	defaultGeometryFor,
	isValidSlot,
	slotOffersRow,
	slotOffersSpan,
} from '../../src/services/slotGeometry.js'

const widenedPage = { type: 'dashboard', config: { slotColumns: { body: 24 } } }

describe('slotGeometry', () => {
	describe('columnsForSlot', () => {
		it('resolves the ADR-036 defaults when a page declares no override', () => {
			expect(columnsForSlot('body', { config: {} })).toBe(12)
			expect(columnsForSlot('sidebar', { config: {} })).toBe(1)
			expect(columnsForSlot('header-actions', { config: {} })).toBe(12)
			expect(columnsForSlot('footer', { config: {} })).toBe(12)
			expect(columnsForSlot('modal', { config: {} })).toBe(12)
			expect(columnsForSlot('tab:general', { config: {} })).toBe(12)
			expect(columnsForSlot('section:intro', { config: {} })).toBe(12)
		})

		it('reads a page-declared config.slotColumns override', () => {
			expect(columnsForSlot('body', widenedPage)).toBe(24)
			// A slot the override does not name still falls to its default.
			expect(columnsForSlot('footer', widenedPage)).toBe(12)
		})

		it('survives a page with no config at all', () => {
			expect(columnsForSlot('body', null)).toBe(12)
			expect(columnsForSlot('body', {})).toBe(12)
		})
	})

	describe('applySlotRules', () => {
		it('clamps gridX so gridX + gridWidth never exceeds the slot columns', () => {
			const out = applySlotRules(
				{ slot: 'body', gridX: 10, gridY: 0, gridWidth: 6, gridHeight: 3 },
				{ config: {} },
			)
			expect(out.gridX + out.gridWidth).toBeLessThanOrEqual(12)
			expect(out.gridX).toBe(6)
			expect(out.gridWidth).toBe(6)
		})

		it('clamps an over-wide span down to the slot columns', () => {
			const out = applySlotRules(
				{ slot: 'body', gridX: 0, gridY: 0, gridWidth: 40, gridHeight: 3 },
				{ config: {} },
			)
			expect(out.gridWidth).toBe(12)
			expect(out.gridX).toBe(0)
		})

		it('allows a wider placement on a page that widens the slot', () => {
			const out = applySlotRules(
				{ slot: 'body', gridX: 0, gridY: 0, gridWidth: 20, gridHeight: 3 },
				widenedPage,
			)
			expect(out.gridWidth).toBe(20)
			expect(out.gridX + out.gridWidth).toBeLessThanOrEqual(24)
		})

		it('pins gridWidth to 1 in the sidebar, which resolves to one column', () => {
			const out = applySlotRules(
				{ slot: 'sidebar', gridX: 5, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ config: {} },
			)
			expect(out.gridWidth).toBe(1)
			expect(out.gridX).toBe(0)
			// The row is the author's in the sidebar; only the span is pinned.
			expect(out.gridY).toBe(2)
		})

		it('pins gridY to 0 in header-actions', () => {
			const out = applySlotRules(
				{
					slot: 'header-actions',
					gridX: 1,
					gridY: 7,
					gridWidth: 2,
					gridHeight: 1,
				},
				{ config: {} },
			)
			expect(out.gridY).toBe(0)
		})

		it('floors gridHeight at 1 and gridWidth at 1', () => {
			const out = applySlotRules(
				{ slot: 'body', gridX: -4, gridY: -2, gridWidth: 0, gridHeight: 0 },
				{ config: {} },
			)
			expect(out).toMatchObject({
				gridX: 0,
				gridY: 0,
				gridWidth: 1,
				gridHeight: 1,
			})
		})

		it("parses a number input's string and falls back on a cleared field", () => {
			const out = applySlotRules(
				{
					slot: 'body',
					gridX: '3',
					gridY: '',
					gridWidth: '4',
					gridHeight: 'x',
				},
				{ config: {} },
			)
			expect(out).toMatchObject({
				gridX: 3,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 1,
			})
		})

		it('falls back to the body slot when the slot is missing or unspellable', () => {
			expect(applySlotRules({}, { config: {} }).slot).toBe('body')
			expect(applySlotRules({ slot: 'nowhere' }, { config: {} }).slot).toBe(
				'body',
			)
		})

		it('keeps every key it does not own, including unknown ones', () => {
			const entry = {
				id: 'kept',
				widgetKey: 'stat',
				slot: 'body',
				gridX: 0,
				gridY: 0,
				gridWidth: 6,
				gridHeight: 3,
				tabGroup: 'general',
				roles: ['beheerders'],
				visibleWhen: { field: 'status', value: 'open' },
				_note: 'why',
				somethingTheLibraryAddsLater: { deep: true },
			}
			const out = applySlotRules(entry, { config: {} })
			expect(out.tabGroup).toBe('general')
			expect(out.roles).toEqual(['beheerders'])
			expect(out.visibleWhen).toEqual({ field: 'status', value: 'open' })
			expect(out._note).toBe('why')
			expect(out.somethingTheLibraryAddsLater).toEqual({ deep: true })
			// And it is a new object, never a mutation of the caller's entry.
			expect(out).not.toBe(entry)
		})
	})

	describe('slotOffersRow / slotOffersSpan', () => {
		it('offers a row everywhere except header-actions', () => {
			expect(slotOffersRow('body')).toBe(true)
			expect(slotOffersRow('sidebar')).toBe(true)
			expect(slotOffersRow('header-actions')).toBe(false)
		})

		it('offers a span only where the slot has more than one column', () => {
			expect(slotOffersSpan('body', { config: {} })).toBe(true)
			expect(slotOffersSpan('sidebar', { config: {} })).toBe(false)
			// A page that widens the sidebar gets the span control back.
			expect(
				slotOffersSpan('sidebar', {
					config: { slotColumns: { sidebar: 2 } },
				}),
			).toBe(true)
		})
	})

	describe('defaultGeometryFor', () => {
		it('stacks a new placement below what the slot already holds', () => {
			const existing = [
				{ slot: 'body', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 3 },
				{ slot: 'sidebar', gridX: 0, gridY: 0, gridWidth: 1, gridHeight: 9 },
			]
			const out = defaultGeometryFor('body', { config: {} }, existing)
			expect(out.gridY).toBe(3)
			expect(out.gridX).toBe(0)
		})

		it('does not fill a 12-column body, so the first widget on an empty dashboard stays valid', () => {
			const out = defaultGeometryFor(
				'body',
				{ type: 'dashboard', config: {} },
				[],
			)
			expect(out.gridWidth).toBeLessThan(12)
			expect(out).toMatchObject({ gridX: 0, gridY: 0, gridHeight: 3 })
		})

		it('gives the sidebar the one column it has', () => {
			const out = defaultGeometryFor('sidebar', { config: {} }, [])
			expect(out).toMatchObject({ slot: 'sidebar', gridX: 0, gridWidth: 1 })
		})

		it('puts a header action on row zero even when the slot is occupied', () => {
			const existing = [
				{
					slot: 'header-actions',
					gridX: 0,
					gridY: 0,
					gridWidth: 2,
					gridHeight: 1,
				},
			]
			const out = defaultGeometryFor(
				'header-actions',
				{ config: {} },
				existing,
			)
			expect(out.gridY).toBe(0)
		})
	})

	describe('isValidSlot', () => {
		it('accepts the five literals and the two patterns', () => {
			for (const slot of [
				'body',
				'sidebar',
				'header-actions',
				'footer',
				'modal',
			]) {
				expect(isValidSlot(slot)).toBe(true)
			}
			expect(isValidSlot('tab:general')).toBe(true)
			expect(isValidSlot('section:intro')).toBe(true)
		})

		it('rejects a pattern with no id and anything unspellable', () => {
			expect(isValidSlot('tab:')).toBe(false)
			expect(isValidSlot('section:')).toBe(false)
			expect(isValidSlot('aside')).toBe(false)
			expect(isValidSlot('')).toBe(false)
			expect(isValidSlot(null)).toBe(false)
		})
	})
})
