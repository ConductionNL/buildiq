/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `captureTemplate` over generated applications, schema sets,
 * manifests and metadata drawn from pools. The full record and summary (or the
 * collision error) of every case is pinned.
 *
 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
 */
import { describe, expect, it } from 'vitest'
import { captureTemplate } from '../../src/services/templateCapture.js'

const APPLICATIONS = [
	{ slug: 'demo', version: '1.2.0' },
	{ slug: 'demo' },
	{ slug: '', version: '2.0.0' },
	{},
	null,
]

const SCHEMAS = [
	{ slug: 'demo-case', properties: { title: { type: 'string' } } },
	{ slug: 'demo-task', rows: ['never read'] },
	{ slug: 'case' },
	{ slug: 'shared-person' },
	{ slug: 7 },
	{ title: 'no slug' },
	null,
	{ slug: '' },
]

const MANIFESTS = [
	{
		pages: [
			{ config: { schema: 'demo-case', columns: ['title'] } },
			{ config: { schema: 'demo-task', relatedSchema: 'demo-case' } },
			{ config: { schema: 'unknown', items: [{ schema: 'case' }] } },
		],
	},
	{ menu: [{ schema: 'shared-person' }, 'text', 3, null] },
	{},
	null,
]

const METADATA = [
	{
		title: 'Demo',
		slug: 'demo-template',
		description: 'A demo',
		useCase: 'Testing',
		category: 'other',
		sourceUrl: 'https://example.org',
	},
	{ title: 'Bare', slug: 'bare', category: 'x' },
	{ title: 'Empty', slug: 'empty', description: '', sourceUrl: '' },
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
 * The outcome of one capture, as text.
 *
 * @param {Array} args The four arguments.
 *
 * @return {string} The result or the error.
 */
function outcomeOf(args) {
	try {
		return JSON.stringify(captureTemplate(...args))
	} catch (error) {
		return `throws ${error.constructor.name}: ${error.message} ${JSON.stringify(error)}`
	}
}

describe('captureTemplate golden', () => {
	it('pins the record and summary of generated captures', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 500; i++) {
			const schemas =
				rand() < 0.1
					? pick(rand, [null, 'schemas', {}])
					: Array.from({ length: Math.floor(rand() * 4) }, () =>
							pick(rand, SCHEMAS),
						)
			const args = [
				pick(rand, APPLICATIONS),
				schemas,
				pick(rand, MANIFESTS),
				pick(rand, METADATA),
			]
			lines.push(`${JSON.stringify(args)} => ${outcomeOf(args)}`)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the outcome without metadata', () => {
		expect(
			outcomeOf([APPLICATIONS[0], [SCHEMAS[0]], MANIFESTS[0], null]),
		).toMatchSnapshot()
	})
})
