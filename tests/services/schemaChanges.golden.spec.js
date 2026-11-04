/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `describeBreakingChanges` over generated pairs of saved and
 * next schema bodies. Properties, types and required lists are drawn from
 * pools, and the full list of lines (text and order) of every case is pinned.
 *
 * @spec openspec/specs/schema-designer-ui/spec.md
 */
import { describe, expect, it } from 'vitest'
import { describeBreakingChanges } from '../../src/services/schemaChanges.js'

const ABSENT = Symbol('absent')
const NAMES = ['title', 'count', 'status', 'owner']
const TYPES = [
	ABSENT,
	'string',
	'integer',
	['string', 'null'],
	['null', 'string'],
	'',
	null,
]
const PROPERTY_SHAPES = ['typed', 'typed', 'typed', 'null', 'empty']
const REQUIRED = [
	ABSENT,
	[],
	['title'],
	['count', 'status'],
	['owner', 'ghost'],
	null,
]

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
 * Build one property from the pools.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The property.
 */
function propertyFrom(rand) {
	const shape = pick(rand, PROPERTY_SHAPES)
	if (shape === 'null') {
		return null
	}
	const type = pick(rand, TYPES)
	return shape === 'empty' || type === ABSENT ? {} : { type }
}

/**
 * Build one schema body from the pools, or a non-body now and then.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The body.
 */
function bodyFrom(rand) {
	if (rand() < 0.06) {
		return pick(rand, [undefined, null, {}])
	}
	const body = {}
	if (rand() > 0.1) {
		body.properties = {}
		for (const name of NAMES) {
			if (rand() < 0.7) {
				body.properties[name] = propertyFrom(rand)
			}
		}
	}
	const required = pick(rand, REQUIRED)
	if (required !== ABSENT) {
		body.required = required
	}
	return body
}

describe('describeBreakingChanges golden', () => {
	it('pins the lines of generated schema pairs', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const saved = bodyFrom(rand)
			const next = bodyFrom(rand)
			lines.push(
				`${JSON.stringify(saved)} -> ${JSON.stringify(next)} => ${JSON.stringify(describeBreakingChanges(saved, next))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
