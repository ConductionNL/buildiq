/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `parseFormImport` over generated form exports, as text and
 * as parsed objects. Envelope and form keys are drawn from pools, and the
 * returned record or the refusal code of every case is pinned.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
import { describe, expect, it } from 'vitest'
import { parseFormImport } from '../../src/services/formExport.js'

const ABSENT = Symbol('absent')

/**
 * A small deterministic PRNG (mulberry32).
 *
 * @param {number} seed The seed.
 *
 * @return {function(): number} Returns floats in [0, 1).
 */
function mulberry32(seed) {
	let a = seed
	return () => {
		a = (a + 0x6d2b79f5) | 0
		let t = Math.imul(a ^ (a >>> 15), 1 | a)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

/**
 * Pick one element of a list.
 *
 * @param {function(): number} rand The PRNG.
 * @param {Array} list The list.
 *
 * @return {*} The element.
 */
function pick(rand, list) {
	return list[Math.floor(rand() * list.length)]
}

/**
 * Build one object from pools of values per key; ABSENT leaves a key out.
 *
 * @param {function(): number} rand The PRNG.
 * @param {object} pools The pool per key.
 *
 * @return {object} The object.
 */
function objectFrom(rand, pools) {
	const out = {}
	for (const [key, pool] of Object.entries(pools)) {
		const value = pick(rand, pool)
		if (value !== ABSENT) {
			out[key] = value
		}
	}
	return out
}

const FORM_POOLS = {
	slug: [ABSENT, 'intake-form', 'a', 'ab', 'Intake', '-intake', 'intake-', 3],
	name: [ABSENT, 'Intake', '', 5],
	kind: [ABSENT, 'form-page', 'registration-form', 'page'],
	category: [ABSENT, 'field-work', 'government-services', 'other'],
	form: [
		ABSENT,
		{ fields: [] },
		{ fields: [{ key: 'a' }] },
		{ fields: 'a' },
		{},
		null,
		'form',
	],
	schemaFragment: [ABSENT, {}, { properties: {} }, [], null, 'x'],
	uuid: [ABSENT, 'u-1'],
	id: [ABSENT, 9],
	organisation: [ABSENT, 'org'],
	'@self': [ABSENT, { id: 1 }],
}

const ENVELOPE_POOLS = {
	kind: [ABSENT, 'form-template', 'form-template', 'template'],
	schemaVersion: [ABSENT, '1.0'],
}

/**
 * Build one import input: an envelope, its text, or a non-export.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The input.
 */
function inputFrom(rand) {
	const roll = rand()
	if (roll < 0.05) {
		return pick(rand, [null, undefined, 0, '', '{ broken', 'null', '[]', true])
	}
	const envelope = objectFrom(rand, ENVELOPE_POOLS)
	if (rand() < 0.9) {
		envelope.form =
			rand() < 0.05
				? pick(rand, [null, 'form', 0])
				: objectFrom(rand, FORM_POOLS)
	}
	return roll < 0.4 ? JSON.stringify(envelope) : envelope
}

/**
 * The outcome of one import, as text.
 *
 * @param {*} input The input.
 *
 * @return {string} The record, or the refusal.
 */
function outcomeOf(input) {
	try {
		return JSON.stringify(parseFormImport(input))
	} catch (error) {
		return `throws ${error.name} ${error.code} ${error.message}`
	}
}

describe('parseFormImport golden', () => {
	it('pins the outcome of generated imports', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 800; i++) {
			const input = inputFrom(rand)
			lines.push(
				`${typeof input} ${JSON.stringify(input)} => ${outcomeOf(input)}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
	it('pins every single change away from a valid export', () => {
		const valid = {
			slug: 'intake-form',
			name: 'Intake',
			kind: 'form-page',
			category: 'field-work',
			form: { fields: [] },
			schemaFragment: { properties: {} },
		}
		const lines = []
		for (const [key, pool] of Object.entries(FORM_POOLS)) {
			for (const value of pool) {
				const form = { ...valid }
				if (value === ABSENT) {
					delete form[key]
				} else {
					form[key] = value
				}
				const input = { kind: 'form-template', form }
				lines.push(
					`${key}=${JSON.stringify(value === ABSENT ? '(absent)' : value)} => ${outcomeOf(input)}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
