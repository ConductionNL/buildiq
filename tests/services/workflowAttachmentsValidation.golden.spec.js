/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `validateWorkflowAttachments` over generated
 * `runtime.workflows[]` arrays and manifest schema sets. Every entry key is
 * drawn from a pool of valid, invalid and absent values, and the full error
 * list (codes, pointers and order) of every case is pinned.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-001).
 */
import { describe, expect, it } from 'vitest'
import { validateWorkflowAttachments } from '../../src/services/manifestValidation/workflowAttachments.js'

const ABSENT = Symbol('absent')
const UUID = '0b6d2c1e-3f4a-4b5c-8d9e-0f1a2b3c4d5e'

const POOLS = {
	id: [ABSENT, 'w1', 'w2', 'w1', '', ' ', 4],
	schema: [ABSENT, 'case', 'case', 'other', 'ghost', '', '  ', 9],
	caseTypeUuid: [ABSENT, UUID, UUID.toUpperCase(), 'not-a-uuid', 5],
	caseTypeName: [ABSENT, 'Permit', '', ' ', null],
	trigger: [ABSENT, 'on-create', 'on-update', null],
	linkProperty: [ABSENT, 'url', 'title', 'count', 'link', 'missing', '', 3],
	descriptionTemplate: [ABSENT, 'Case {{id}}', 7, null],
	extra: [ABSENT, ABSENT, 'foo'],
}

const PROPERTIES = {
	url: { type: 'string', format: 'uri' },
	title: { type: 'string' },
	count: { type: 'integer' },
	link: { format: 'url' },
}

const SCHEMA_SETS = [
	ABSENT,
	[{ slug: 'case', properties: PROPERTIES }, { id: 'other' }],
	[{ title: 'case', schema: { properties: PROPERTIES } }],
	{ case: { properties: PROPERTIES }, other: { properties: null } },
	{ other: { schema: { properties: { url: {} } } } },
	{},
]

const PAGE_SETS = [
	ABSENT,
	[],
	[{ config: { schema: 'case' } }],
	[null, { config: { schema: 'ghost' } }, { config: {} }],
]

const NON_ENTRIES = [null, [], 'wf', 0, true]

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
 * Build one workflow entry from the pools, or a non-object entry now and then.
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
 * Build a manifest around the workflows, with a drawn schema set and pages.
 *
 * @param {function(): number} rand The PRNG.
 * @param {Array} workflows The workflows array.
 *
 * @return {object} The manifest.
 */
function manifestFrom(rand, workflows) {
	const manifest = { runtime: { workflows } }
	const schemas = pick(rand, SCHEMA_SETS)
	if (schemas !== ABSENT) {
		manifest.schemas = schemas
	}
	const pages = pick(rand, PAGE_SETS)
	if (pages !== ABSENT) {
		manifest.pages = pages
	}
	return manifest
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

describe('validateWorkflowAttachments golden', () => {
	it('pins the errors of generated workflow arrays', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const length = 1 + Math.floor(rand() * 3)
			const workflows = Array.from({ length }, () => entryFrom(rand))
			const manifest = manifestFrom(rand, workflows)
			lines.push(
				`${describeInput(manifest)} => ${JSON.stringify(validateWorkflowAttachments(manifest))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the manifest-level early returns', () => {
		const manifests = [
			undefined,
			null,
			{},
			{ runtime: null },
			{ runtime: { workflows: null } },
			{ runtime: { workflows: {} } },
			{ runtime: { workflows: [] } },
			{ runtime: { workflows: NON_ENTRIES } },
		]
		const lines = manifests.map(
			(m) =>
				`${describeInput(m)} => ${JSON.stringify(validateWorkflowAttachments(m))}`,
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins every link property against every schema set', () => {
		const lines = []
		for (const schemas of SCHEMA_SETS) {
			for (const linkProperty of POOLS.linkProperty) {
				const entry = {
					id: 'w',
					schema: 'case',
					caseTypeUuid: UUID,
					caseTypeName: 'Permit',
					trigger: 'on-create',
				}
				if (linkProperty !== ABSENT) {
					entry.linkProperty = linkProperty
				}
				const manifest = { runtime: { workflows: [entry] } }
				if (schemas !== ABSENT) {
					manifest.schemas = schemas
				}
				lines.push(
					`${describeInput(manifest)} => ${JSON.stringify(validateWorkflowAttachments(manifest))}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
