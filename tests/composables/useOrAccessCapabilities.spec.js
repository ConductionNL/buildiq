/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `useOrAccessCapabilities.js` (REQ-OBDSA-003).
 *
 * Covers:
 *  - Absent `openregister.authorization.scopes` key → baseline `['group']`.
 *  - Advertised `['group', 'creator', 'condition']` passes through verbatim.
 *  - A non-array value at the capabilities key → baseline (malformed guard).
 *  - `getCapabilities()` throwing → baseline (defensive, never crashes the
 *    Schema Designer).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const capabilitiesMocks = vi.hoisted(() => {
	return { getCapabilities: vi.fn() }
})

vi.mock('@nextcloud/capabilities', () => {
	return { getCapabilities: capabilitiesMocks.getCapabilities }
})

const { useOrAccessCapabilities, BASELINE_SCOPES } =
	await import('../../src/composables/useOrAccessCapabilities.js')

beforeEach(() => {
	capabilitiesMocks.getCapabilities.mockReset()
})

describe('useOrAccessCapabilities', () => {
	it("REQ-OBDSA-003: absent capabilities key falls back to baseline ['group']", () => {
		capabilitiesMocks.getCapabilities.mockReturnValue({})
		expect(useOrAccessCapabilities()).toEqual({ scopes: ['group'] })
	})

	it('REQ-OBDSA-003: missing openregister key entirely falls back to baseline', () => {
		capabilitiesMocks.getCapabilities.mockReturnValue(null)
		expect(useOrAccessCapabilities().scopes).toEqual([...BASELINE_SCOPES])
	})

	it("REQ-OBDSA-003: advertised ['group','creator','condition'] passes through", () => {
		capabilitiesMocks.getCapabilities.mockReturnValue({
			openregister: {
				authorization: { scopes: ['group', 'creator', 'condition'] },
			},
		})
		expect(useOrAccessCapabilities()).toEqual({
			scopes: ['group', 'creator', 'condition'],
		})
	})

	it('REQ-OBDSA-003: a non-array scopes value falls back to baseline', () => {
		capabilitiesMocks.getCapabilities.mockReturnValue({
			openregister: { authorization: { scopes: 'group' } },
		})
		expect(useOrAccessCapabilities().scopes).toEqual(['group'])
	})

	it('REQ-OBDSA-003: an empty array falls back to baseline (never offer zero scope kinds)', () => {
		capabilitiesMocks.getCapabilities.mockReturnValue({
			openregister: { authorization: { scopes: [] } },
		})
		expect(useOrAccessCapabilities().scopes).toEqual(['group'])
	})

	it('REQ-OBDSA-003: getCapabilities() throwing falls back to baseline', () => {
		capabilitiesMocks.getCapabilities.mockImplementation(() => {
			throw new Error('capabilities not loaded')
		})
		expect(useOrAccessCapabilities().scopes).toEqual(['group'])
	})
})
