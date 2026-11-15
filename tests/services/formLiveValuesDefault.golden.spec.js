/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `resolveDefault` over every stored default in a pool and
 * every context in a pool, with the clock fixed. The value (or the error) of
 * every pair is pinned.
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
 */
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { resolveDefault } from '../../src/services/formLiveValues.js'

const VALUES = [
	undefined,
	null,
	7,
	'',
	'plain',
	'me',
	'@',
	'@me',
	'@me.displayName',
	'@me.email',
	'@me.other',
	'@today',
	'@object.',
	'@object.title',
	'@object.owner.name',
	'@object.list.0',
	'@objects.title',
	'@toString',
	'@hasOwnProperty',
]

const CONTEXTS = [
	'(none)',
	null,
	{},
	{ user: { uid: 'ann', displayName: 'Ann', email: 'ann@example.org' } },
	{ user: { uid: 'bob', displayName: '', email: '' } },
	{ user: null, now: new Date('2026-03-04T23:30:00Z') },
	{ now: 'not a date' },
	{ object: { title: 'Case', owner: { name: 'Cee' }, list: ['first'] } },
	{ object: { owner: 'flat' } },
	{ object: null },
]

/**
 * The outcome of one call, as text.
 *
 * @param {*} value The stored default.
 * @param {*} context The context, or '(none)' to leave it out.
 *
 * @return {string} The value or the error.
 */
function outcomeOf(value, context) {
	try {
		const result =
			context === '(none)'
				? resolveDefault(value)
				: resolveDefault(value, context)
		return result === undefined ? '<undefined>' : JSON.stringify(result)
	} catch (error) {
		return `throws ${error.constructor.name}: ${error.message}`
	}
}

describe('resolveDefault golden', () => {
	beforeAll(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-02T10:00:00Z'))
	})

	afterAll(() => {
		vi.useRealTimers()
	})

	it('pins the value of every default in every context', () => {
		const lines = []
		for (const context of CONTEXTS) {
			for (const value of VALUES) {
				lines.push(
					`${JSON.stringify(context)} ${String(value)} => ${outcomeOf(value, context)}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
