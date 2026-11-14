/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `validateConnectorDataSource` over generated dataSource
 * shapes. Every key of the connector block is drawn from a pool of valid,
 * invalid and absent values, and the full error list (codes and order) of
 * every case is pinned.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-001).
 */
import { describe, expect, it } from 'vitest'
import { validateConnectorDataSource } from '../../src/services/manifestValidation/connectorDataSource.js'

const P = '/pages/0/config/dataSource'
const ABSENT = Symbol('absent')

const POOLS = {
	endpointPath: [
		ABSENT,
		'kvk/companies',
		'',
		'   ',
		'https://example.org/x',
		'/apps/openregister/api',
		'/api/x',
		7,
		null,
	],
	method: [ABSENT, 'GET', 'POST', 'get', null],
	query: [
		ABSENT,
		{},
		{ city: 'Utrecht', n: 3, b: true, z: null },
		{ nested: { a: 1 } },
		{ list: [1] },
		[],
		null,
		'q',
	],
	itemsPath: [ABSENT, '', 'resultaten', 'a.b.0', 'a..b', 'a b', 5, null],
	fields: [
		ABSENT,
		{},
		{ name: 'naam' },
		{ name: 'naam', bad: 'x y', num: 3 },
		{ deep: 'a.b.$c_1' },
		[],
		null,
		'naam',
	],
	cacheTtl: [ABSENT, 0, 3600, 3601, -1, 1.5, '60', null],
	extra: [ABSENT, 'token', 'headers', 'auth', 'foo', 'secret'],
}

const DATA_SOURCES = [
	{},
	{ register: 'r' },
	{ schema: 's' },
	{ graphql: { query: '{ a }' } },
	{ register: undefined, schema: undefined, graphql: undefined },
]

const NON_CONNECTORS = [null, [], 'connector', 3, true]
const NON_DATA_SOURCES = [null, undefined, [], 'ds', 0, () => ({})]

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
 * Build one connector block from the pools.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {object} The connector.
 */
function connectorFrom(rand) {
	const connector = {}
	for (const [key, pool] of Object.entries(POOLS)) {
		const value = pool[Math.floor(rand() * pool.length)]
		if (value === ABSENT) {
			continue
		}
		if (key === 'extra') {
			connector[value] = 'x'
		} else {
			connector[key] = value
		}
	}
	return connector
}

/**
 * Describe a case input as text, keeping undefined values and functions visible.
 *
 * @param {*} value The input.
 *
 * @return {string} The description.
 */
function describeInput(value) {
	if (typeof value === 'function') {
		return '<function>'
	}
	if (value === undefined) {
		return '<undefined>'
	}
	return JSON.stringify(value, (k, v) => (v === undefined ? '<undefined>' : v))
}

describe('validateConnectorDataSource golden', () => {
	it('pins the errors of generated connector blocks', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const base = DATA_SOURCES[i % DATA_SOURCES.length]
			const dataSource = { ...base, connector: connectorFrom(rand) }
			const errors = validateConnectorDataSource(dataSource, P)
			lines.push(`${describeInput(dataSource)} => ${JSON.stringify(errors)}`)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the early returns', () => {
		const lines = []
		for (const ds of NON_DATA_SOURCES) {
			lines.push(
				`${describeInput(ds)} => ${JSON.stringify(validateConnectorDataSource(ds, P))}`,
			)
		}
		for (const base of DATA_SOURCES) {
			lines.push(
				`${describeInput(base)} => ${JSON.stringify(validateConnectorDataSource(base, P))}`,
			)
			for (const connector of NON_CONNECTORS) {
				const ds = { ...base, connector }
				lines.push(
					`${describeInput(ds)} => ${JSON.stringify(validateConnectorDataSource(ds, P))}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins every value of every pool on its own', () => {
		const lines = []
		for (const [key, pool] of Object.entries(POOLS)) {
			for (const value of pool) {
				const connector = { endpointPath: 'a/b', fields: { n: 'x' } }
				if (value !== ABSENT) {
					if (key === 'extra') {
						connector[value] = 'x'
					} else {
						connector[key] = value
					}
				}
				const errors = validateConnectorDataSource({ connector }, P)
				lines.push(
					`${key}=${describeInput(value === ABSENT ? undefined : value)} => ${JSON.stringify(errors)}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
