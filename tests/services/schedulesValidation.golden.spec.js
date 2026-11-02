/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `validateScheduleEntry` and `validateSchedules` over
 * generated schedule entries. Every entry key is drawn from a pool of valid,
 * invalid and absent values, and the full error list of every case is pinned.
 *
 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-006
 */
import { describe, expect, it } from 'vitest'
import {
	validateScheduleEntry,
	validateSchedules,
} from '../../src/services/manifestValidation/schedules.js'

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

const POOLS = {
	id: [ABSENT, 'nightly-sync', 'nightly-sync', 'Nightly', 'a--b', '', ' ', 4],
	enabled: [ABSENT, true, false, 'yes', null],
	interval: [ABSENT, ABSENT, 60, 0, -5, 1.5, '60', null],
	cron: [
		ABSENT,
		ABSENT,
		'0 3 * * *',
		'*/5 * * * *',
		'0 3 * *',
		'1-5/2,7 * * * 1',
		'x * * * *',
		5,
		null,
	],
	action: [
		ABSENT,
		'openconnector:synchronization',
		'openconnector:synchronization',
		'other',
	],
	arguments: [
		ABSENT,
		{ synchronizationId: 'sync-1' },
		{ synchronizationId: ' ' },
		{ synchronizationId: 3 },
		{},
		null,
		'args',
	],
	extra: [ABSENT, ABSENT, 'foo'],
}

const NON_ENTRIES = [null, [], 'entry', 0, true]

/**
 * Build one schedule entry from the pools, or a non-object entry now and then.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The entry.
 */
function entryFrom(rand) {
	if (rand() < 0.08) {
		return pick(rand, NON_ENTRIES)
	}
	const entry = objectFrom(rand, POOLS)
	if (entry.extra !== undefined) {
		entry[entry.extra] = 'x'
		delete entry.extra
	}
	return entry
}

describe('schedules golden', () => {
	it('pins the errors of generated entries', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 800; i++) {
			const entry = entryFrom(rand)
			const idx = pick(rand, [ABSENT, 0, 3, 'new'])
			const errors =
				idx === ABSENT
					? validateScheduleEntry(entry)
					: validateScheduleEntry(entry, idx)
			lines.push(
				`${JSON.stringify(entry)} @${String(idx)} => ${JSON.stringify(errors)}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the errors of generated schedule arrays', () => {
		const rand = mulberry32(7)
		const lines = []
		for (let i = 0; i < 200; i++) {
			const schedules = Array.from(
				{ length: 1 + Math.floor(rand() * 3) },
				() => entryFrom(rand),
			)
			const manifest = { schedules }
			lines.push(
				`${JSON.stringify(manifest)} => ${JSON.stringify(validateSchedules(manifest))}`,
			)
		}
		for (const manifest of [
			undefined,
			null,
			{},
			{ schedules: null },
			{ schedules: {} },
			{ schedules: [] },
		]) {
			lines.push(
				`${JSON.stringify(manifest)} => ${JSON.stringify(validateSchedules(manifest))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
