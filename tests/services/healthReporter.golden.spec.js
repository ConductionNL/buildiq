/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the health reporter over generated sessions: page
 * navigations, diagnostics reports and flushes, with a fake clock. Every
 * posted batch and the pending samples at the end are pinned.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
 */
import { describe, expect, it } from 'vitest'
import { createHealthReporter } from '../../src/services/healthReporter.js'

const ABSENT = Symbol('absent')

const REPORT_POOLS = {
	kind: [
		'request',
		'request',
		'request',
		'render-error',
		'unknown-component',
		'binding',
		ABSENT,
	],
	pageId: [ABSENT, ABSENT, 'home', 'list', ''],
	status: [ABSENT, 200, 399, 400, 404, 499, 500, 503, 0, -1, '404', 'x'],
	durationMs: [ABSENT, 10, 249, 250, 999, 1000, 2999, 3000, 9000, 'slow'],
}

const NON_REPORTS = [null, undefined, 'request', 0]

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
 * Build one diagnostics report from the pools.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The report.
 */
function reportFrom(rand) {
	if (rand() < 0.05) {
		return pick(rand, NON_REPORTS)
	}
	const report = {}
	for (const [key, pool] of Object.entries(REPORT_POOLS)) {
		const value = pick(rand, pool)
		if (value !== ABSENT) {
			report[key] = value
		}
	}
	return report
}

/**
 * Run one generated session and describe what it posted.
 *
 * @param {number} seed The seed.
 *
 * @return {string} The session's posts and pending samples.
 */
function session(seed) {
	const rand = mulberry32(seed)
	let clock = 1000
	const posts = []
	const reporter = createHealthReporter({
		post: (batch) => {
			posts.push(batch)
			return 'posted'
		},
		now: () => clock,
	})
	const steps = []
	for (let i = 0; i < 40; i++) {
		clock += pick(rand, [0, 5, 120, 900, 2500, 4000, 10000, 11000])
		const roll = rand()
		if (roll < 0.15) {
			const pageId = pick(rand, ['home', 'list', '', undefined])
			reporter.navigate(pageId)
			steps.push(`nav ${String(pageId)}`)
		} else if (roll < 0.25) {
			steps.push(`flush ${String(reporter.flush())}`)
		} else {
			reporter.listener(reportFrom(rand))
		}
	}
	return JSON.stringify({ steps, posts, pending: reporter.pending() })
}

describe('health reporter golden', () => {
	it('pins the posted batches of generated sessions', () => {
		const lines = []
		for (let seed = 1; seed <= 120; seed++) {
			lines.push(session(seed))
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
