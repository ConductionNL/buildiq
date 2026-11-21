/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `validateExternalForms` over generated
 * `runtime.externalForms[]` arrays. Every entry key is drawn from a pool of
 * valid, invalid and absent values, and the full error list (codes, pointers
 * and order) of every case is pinned.
 *
 * Spec: external-form-provisioning (REQ-EFP-001).
 */
import { describe, expect, it } from 'vitest'
import { validateExternalForms } from '../../src/services/manifestValidation/externalForms.js'

const ABSENT = Symbol('absent')

const POOLS = {
	id: [ABSENT, 'a', 'b', 'a', '', '  ', 3, null],
	pageId: [ABSENT, 'page-1', '', ' ', 1],
	register: [ABSENT, 'r', '', 0],
	schema: [ABSENT, 's', ' ', false],
	status: [ABSENT, 'enabled', 'disabled', 'Enabled', null],
	publicRead: [ABSENT, true, false, 'true', null, 0],
	organisationScope: [ABSENT, null, 'org-1', '', 7, {}],
	portalPage: [
		ABSENT,
		null,
		{ objectId: 'o', portalPath: '/p' },
		{ objectId: 'o' },
		{ objectId: '', portalPath: 3 },
		{ objectId: ' ', extra: 1, other: 2 },
		{ portalPath: '/p' },
		{},
		[],
		'page',
		0,
	],
	trackLinkAction: [
		ABSENT,
		null,
		{ enabled: true },
		{ enabled: 'yes' },
		{},
		{ enabled: false, more: 1 },
		[],
		0,
		'on',
		true,
	],
	extra: [ABSENT, ABSENT, 'foo', 'Id'],
}

const NON_ENTRIES = [null, undefined, [], 'entry', 0, 5, true]

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
 * Build one entry from the pools, or a non-object entry now and then.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The entry.
 */
function entryFrom(rand) {
	if (rand() < 0.08) {
		return pick(rand, NON_ENTRIES)
	}
	const entry = {}
	for (const [key, pool] of Object.entries(POOLS)) {
		const value = pick(rand, pool)
		if (value === ABSENT) {
			continue
		}
		if (key === 'extra') {
			entry[value] = 'x'
		} else {
			entry[key] = value
		}
	}
	return entry
}

/**
 * Describe a case input as text, keeping undefined values visible.
 *
 * @param {*} value The input.
 *
 * @return {string} The description.
 */
function describeInput(value) {
	if (value === undefined) {
		return '<undefined>'
	}
	return JSON.stringify(value, (k, v) => (v === undefined ? '<undefined>' : v))
}

describe('validateExternalForms golden', () => {
	it('pins the errors of generated externalForms arrays', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 500; i++) {
			const length = 1 + Math.floor(rand() * 4)
			const forms = Array.from({ length }, () => entryFrom(rand))
			const errors = validateExternalForms({
				runtime: { externalForms: forms },
			})
			lines.push(`${describeInput(forms)} => ${JSON.stringify(errors)}`)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the manifest-level early returns', () => {
		const manifests = [
			undefined,
			null,
			{},
			{ runtime: null },
			{ runtime: {} },
			{ runtime: { externalForms: null } },
			{ runtime: { externalForms: {} } },
			{ runtime: { externalForms: 'forms' } },
			{ runtime: { externalForms: [] } },
			{ runtime: { externalForms: NON_ENTRIES } },
		]
		const lines = manifests.map(
			(m) =>
				`${describeInput(m)} => ${JSON.stringify(validateExternalForms(m))}`,
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins every value of every pool on its own', () => {
		const lines = []
		for (const [key, pool] of Object.entries(POOLS)) {
			for (const value of pool) {
				const entry = {
					id: 'one',
					pageId: 'p',
					register: 'r',
					schema: 's',
					status: 'enabled',
				}
				if (value !== ABSENT) {
					if (key === 'extra') {
						entry[value] = 'x'
					} else {
						entry[key] = value
					}
				}
				const errors = validateExternalForms({
					runtime: { externalForms: [entry] },
				})
				lines.push(
					`${key}=${describeInput(entry)} => ${JSON.stringify(errors)}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
