/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `markUnresolvedRefs` over generated block fragments and
 * unresolved slug lists. The marked node of every case, and that the input is
 * left as it was, are pinned.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
import { describe, expect, it } from 'vitest'
import { markUnresolvedRefs } from '../../src/services/blockInsert.js'

const KEYS = ['schema', 'relatedSchema', 'register', 'config', 'widgets', 'title']
const VALUES = ['case', 'task', 'person', 3, null, true, '']
const SLUG_LISTS = [
	undefined,
	null,
	'case',
	[],
	['case'],
	['case', 'task'],
	['ghost'],
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
 * Build a node: a scalar, a list of nodes or an object of nodes.
 *
 * @param {function(): number} rand The PRNG.
 * @param {number} depth How many levels may still nest.
 *
 * @return {*} The node.
 */
function nodeFrom(rand, depth) {
	const roll = rand()
	if (depth === 0 || roll < 0.35) {
		return pick(rand, VALUES)
	}
	if (roll < 0.5) {
		return Array.from({ length: Math.floor(rand() * 3) }, () =>
			nodeFrom(rand, depth - 1),
		)
	}
	const node = {}
	for (let i = Math.floor(rand() * 4); i > 0; i--) {
		node[pick(rand, KEYS)] = nodeFrom(rand, depth - 1)
	}
	return node
}

describe('markUnresolvedRefs golden', () => {
	it('pins the marked node of generated fragments', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const node = nodeFrom(rand, 4)
			const slugs = pick(rand, SLUG_LISTS)
			const before = JSON.stringify(node)
			const result = markUnresolvedRefs(node, slugs)
			lines.push(
				`${before} ${JSON.stringify(slugs)} => ${result === node} ${JSON.stringify(result)} unchanged=${JSON.stringify(node) === before}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
