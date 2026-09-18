/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain-language lines the schema designer shows before a breaking save.
 */
import { describe, expect, it } from 'vitest'
import { describeBreakingChanges } from '../../src/services/schemaChanges.js'

const saved = {
	properties: {
		title: { type: 'string' },
		count: { type: 'integer' },
		note: { type: 'string' },
	},
	required: ['title'],
}

describe('describeBreakingChanges', () => {
	it('names a field that becomes required', () => {
		const lines = describeBreakingChanges(saved, {
			properties: saved.properties,
			required: ['title', 'note'],
		})
		expect(lines).toEqual([
			'The field "note" becomes required. Records without a value fail validation until someone fills it in.',
		])
	})

	it('names a removed field and a changed type', () => {
		const lines = describeBreakingChanges(saved, {
			properties: { title: { type: 'string' }, count: { type: 'string' } },
			required: ['title'],
		})
		expect(lines).toEqual([
			'The field "note" is removed. Existing records lose its values.',
			'The field "count" changes from integer to string. Existing values may no longer fit.',
		])
	})

	it('falls back to a general line when it cannot tell what broke', () => {
		expect(
			describeBreakingChanges(saved, {
				properties: saved.properties,
				required: [],
			}),
		).toEqual(['This change can make existing records invalid.'])
	})
})
