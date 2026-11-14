/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `entryOf` over generated diagnostics reports. Every report
 * key is drawn from a pool of present, empty and absent values, and the full
 * entry of every case is pinned.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md
 */
import { describe, expect, it } from 'vitest'
import { entryOf } from '../../src/composables/useDebugLog.js'

const ABSENT = Symbol('absent')

const POOLS = {
	kind: [
		ABSENT,
		'request',
		'request',
		'render-error',
		'unknown-component',
		'binding',
		'other',
		'toString',
		['request'],
	],
	pageId: [ABSENT, 'ticket', '', 0, 7],
	status: [ABSENT, 200, 399, 400, 404, 500, '503', 0, -1, 'x'],
	path: [
		ABSENT,
		'/apps/openregister/api/objects',
		'/index.php/apps/buildiq/api/x',
		'',
	],
	method: [ABSENT, 'get', 'POST', ''],
	durationMs: [ABSENT, 80, '12', 'slow'],
	message: [ABSENT, 'Boom', ''],
	component: [ABSENT, 'CnTable', ''],
	name: [ABSENT, 'CnChart', ''],
	register: [ABSENT, 'pipelinq', ''],
	schema: [ABSENT, 'ticket', ''],
	property: [ABSENT, 'title', '', 3],
	problem: [ABSENT, 'missing-property', ''],
	where: [ABSENT, 'column', ''],
}

const NON_REPORTS = [undefined, null, 0, '', 'request', true]

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
 * Build one report from the pools.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {object} The report.
 */
function reportFrom(rand) {
	const report = {}
	for (const [key, pool] of Object.entries(POOLS)) {
		const value = pick(rand, pool)
		if (value !== ABSENT) {
			report[key] = value
		}
	}
	return report
}

describe('entryOf golden', () => {
	it('pins the entry of generated reports', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 800; i++) {
			const report = reportFrom(rand)
			lines.push(
				`${JSON.stringify(report)} => ${JSON.stringify(entryOf(report))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the non-report inputs', () => {
		const lines = NON_REPORTS.map(
			(r) => `${String(r)} => ${JSON.stringify(entryOf(r))}`,
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
