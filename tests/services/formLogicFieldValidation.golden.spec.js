/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the per-field half of `validateFormLogic`
 * (`visibleWhen` and `validation`) over generated form pages. Every field
 * key is drawn from a pool of valid, invalid and absent values, and the full
 * error list (codes, pointers and order) of every case is pinned.
 *
 * Spec: form-editor-logic (REQ-OBFEL-005).
 */
import { describe, expect, it } from 'vitest'
import { validateFormLogic } from '../../src/services/manifestValidation/formLogic.js'

const ABSENT = Symbol('absent')

const VISIBLE_WHEN = [
	ABSENT,
	null,
	[],
	'cond',
	{},
	{ field: 'a' },
	{ field: 'b', op: 'eq', value: 1 },
	{ field: 'ghost', op: 'gte' },
	{ field: '', op: 'eq' },
	{ field: '  ' },
	{ field: 3, op: 'like' },
	{ field: 'a', op: 'EQ' },
	{ field: 'a', op: null },
	{ endpoint: '/x', field: 'ghost', op: 'bad' },
	{ source: 'remote', field: '' },
	{ endpoint: '', source: 0, field: 'ghost' },
]

const VALIDATION = [
	ABSENT,
	null,
	[],
	0,
	{},
	{ required: true },
	{ required: 'yes' },
	{ min: 1, max: 5 },
	{ min: 5, max: 1 },
	{ min: 3, max: 3 },
	{ min: '1', max: 5 },
	{ min: 1, max: '5' },
	{ min: 9 },
	{ max: null },
	{ pattern: '^a+$' },
	{ pattern: '(' },
	{ pattern: 5 },
	{ message: 'Bad' },
	{ message: 1 },
	{ required: 1, min: 'a', max: 'b', pattern: '[', message: {} },
	{ min: '9', max: 1 },
	{ pattern: ['('] },
	{ min: 5, max: 1, pattern: '(' },
]

const FLAT = [{}, {}, { required: true }, { pattern: 'x' }, { required: undefined }]

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
 * Build one field from the pools.
 *
 * @param {function(): number} rand The PRNG.
 * @param {string} key The field key.
 *
 * @return {object} The field.
 */
function fieldFrom(rand, key) {
	const field = { key, ...pick(rand, FLAT) }
	const visibleWhen = pick(rand, VISIBLE_WHEN)
	if (visibleWhen !== ABSENT) {
		field.visibleWhen = visibleWhen
	}
	const validation = pick(rand, VALIDATION)
	if (validation !== ABSENT) {
		field.validation = validation
	}
	return field
}

/**
 * Describe a case input as text, keeping undefined values visible.
 *
 * @param {*} value The input.
 *
 * @return {string} The description.
 */
function describeInput(value) {
	return JSON.stringify(value, (k, v) => (v === undefined ? '<undefined>' : v))
}

describe('validateFormLogic field golden', () => {
	it('pins the errors of generated form fields', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 500; i++) {
			const fields = ['a', 'b', 'c']
				.slice(0, 1 + Math.floor(rand() * 3))
				.map((key) => fieldFrom(rand, key))
			if (rand() < 0.1) {
				fields.push(pick(rand, [null, 'field', 3, []]))
			}
			const manifest = { pages: [{ type: 'form', config: { fields } }] }
			lines.push(
				`${describeInput(fields)} => ${JSON.stringify(validateFormLogic(manifest))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins every visibleWhen and validation value on its own', () => {
		const lines = []
		for (const visibleWhen of VISIBLE_WHEN) {
			for (const validation of VALIDATION) {
				const field = { key: 'a' }
				if (visibleWhen !== ABSENT) {
					field.visibleWhen = visibleWhen
				}
				if (validation !== ABSENT) {
					field.validation = validation
				}
				const manifest = {
					pages: [
						{ type: 'form', config: { fields: [field, { key: 'b' }] } },
					],
				}
				lines.push(
					`${describeInput(field)} => ${JSON.stringify(validateFormLogic(manifest))}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
