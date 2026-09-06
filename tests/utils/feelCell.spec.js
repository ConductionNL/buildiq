/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/utils/feelCell.js (spec business-rules-engine
 * REQ-BRE-002 / REQ-BRE-012 — decision-table cell validation in the editor).
 */

import { describe, expect, it } from 'vitest'
import { isCellConditionValid } from '../../src/utils/feelCell.js'

describe('isCellConditionValid', () => {
	it("accepts don't-care tokens", () => {
		expect(isCellConditionValid('')).toBe(true)
		expect(isCellConditionValid('-')).toBe(true)
		expect(isCellConditionValid('*')).toBe(true)
		expect(isCellConditionValid(null)).toBe(true)
	})

	it('accepts comparison operators', () => {
		expect(isCellConditionValid('>=18')).toBe(true)
		expect(isCellConditionValid("== 'open'")).toBe(true)
		expect(isCellConditionValid('< 100')).toBe(true)
	})

	it('accepts inclusive ranges', () => {
		expect(isCellConditionValid('18..65')).toBe(true)
	})

	it('accepts list membership', () => {
		expect(isCellConditionValid('in (1, 2, 3)')).toBe(true)
	})

	it('accepts a bare literal', () => {
		expect(isCellConditionValid('open')).toBe(true)
		expect(isCellConditionValid('42')).toBe(true)
	})

	it('rejects a lone equals sign', () => {
		expect(isCellConditionValid('=18')).toBe(false)
		expect(isCellConditionValid('=')).toBe(false)
	})
})
