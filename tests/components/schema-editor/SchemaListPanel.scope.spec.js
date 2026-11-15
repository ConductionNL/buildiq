/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests pinning SchemaListPanel's `scopeSummary` badge across the
 * authorization shapes the main spec leaves open: a non-object block, empty
 * group lists falling back to a condition, a condition without a field,
 * operations outside the summary, and the operation order.
 *
 * @spec openspec/specs/data-scopes-authoring/spec.md#req-obdsa-005
 */
import { describe, expect, it } from 'vitest'
import { scopeSummary } from '../../../src/components/schema-editor/SchemaListPanel.vue'

describe('scopeSummary edges', () => {
	it.each([
		[null, null],
		[{}, null],
		[{ authorization: 'admin' }, null],
		[{ authorization: null }, null],
		[{ authorization: [] }, null],
	])('gives no badge for %j', (schema, expected) => {
		expect(scopeSummary(schema)).toBe(expected)
	})

	it.each([
		[{ read: [] , conditions: { read: { field: 'owner' } } }, 'read: condition(owner)'],
		[{ conditions: { update: {} } }, 'update: condition(?)'],
		[{ conditions: { read: null } }, 'Custom authorization metadata'],
		[{ conditions: null, read: ['a'] }, 'read: a'],
		[{ somethingElse: true }, 'Custom authorization metadata'],
		[{ delete: ['admin'], read: ['vets', '@creator'] }, 'read: vets, @creator; delete: admin'],
		[{ read: 'vets' }, 'Custom authorization metadata'],
	])('summarises %j as %j', (authorization, title) => {
		expect(scopeSummary({ authorization })).toEqual({ label: 'Restricted', title })
	})
})
