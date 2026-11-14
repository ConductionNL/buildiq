/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `validateDocumentAttachments` over generated
 * `runtime.documents[]` arrays and manifest schema sets. Every entry key is
 * drawn from a pool of valid, invalid and absent values, and the full error
 * list (codes, pointers and order) of every case is pinned.
 *
 * Spec: docudesk-document-templates (REQ-DDT-001).
 */
import { describe, expect, it } from 'vitest'
import { validateDocumentAttachments } from '../../src/services/manifestValidation/documentAttachments.js'

const ABSENT = Symbol('absent')
const UUID = '0b6d2c1e-3f4a-4b5c-8d9e-0f1a2b3c4d5e'

const POOLS = {
	id: [ABSENT, 'd1', 'd2', 'd1', '', ' ', 4],
	schema: [ABSENT, 'case', 'case', 'other', 'ghost', '', '  ', 9],
	templateId: [ABSENT, UUID, UUID.toUpperCase(), 'not-a-uuid', 5],
	templateName: [ABSENT, 'Letter', '', ' ', null],
	label: [ABSENT, 'Send', 'Send', 'Other', '', ' ', 3],
	format: [ABSENT, 'pdf', 'docx', 'odt', null],
	filenameTemplate: [ABSENT, '{{id}}.pdf', 7, null],
	extra: [ABSENT, ABSENT, 'foo'],
}

const SCHEMA_SETS = [
	ABSENT,
	[{ slug: 'case' }, { id: 'other' }],
	[{ title: 'case' }],
	{ case: {}, other: {} },
	{ other: {} },
	{},
	[{ properties: {} }],
]

const PAGE_SETS = [
	ABSENT,
	[],
	[{ config: { schema: 'case' } }],
	[null, { config: { schema: 'ghost' } }, { config: {} }],
]

const NON_ENTRIES = [null, [], 'doc', 0, true]

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
 * Build one document entry from the pools, or a non-object entry now and then.
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
 * Build a manifest around the documents, with a drawn schema set and pages.
 *
 * @param {function(): number} rand The PRNG.
 * @param {Array} documents The documents array.
 *
 * @return {object} The manifest.
 */
function manifestFrom(rand, documents) {
	const manifest = { runtime: { documents } }
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

describe('validateDocumentAttachments golden', () => {
	it('pins the errors of generated document arrays', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const length = 1 + Math.floor(rand() * 4)
			const documents = Array.from({ length }, () => entryFrom(rand))
			const manifest = manifestFrom(rand, documents)
			lines.push(
				`${describeInput(manifest)} => ${JSON.stringify(validateDocumentAttachments(manifest))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('keys the duplicate check on the exact (schema, label) pair', () => {
		const entry = (id, schema, label) => ({
			id,
			schema,
			label,
			templateId: UUID,
			templateName: 'Letter',
		})
		const manifests = [
			{
				runtime: {
					documents: [
						entry('a', 'case', 'x y'),
						entry('b', 'case x', 'y'),
					],
				},
			},
			{
				runtime: {
					documents: [entry('a', 'case', 'x'), entry('b', 'case', 'x')],
				},
			},
			{
				runtime: {
					documents: [entry('a', 'case', 'x'), entry('b', 'case', 'X')],
				},
			},
		]
		const lines = manifests.map(
			(m) =>
				`${describeInput(m)} => ${JSON.stringify(validateDocumentAttachments(m))}`,
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the manifest-level early returns', () => {
		const manifests = [
			undefined,
			null,
			{},
			{ runtime: null },
			{ runtime: { documents: null } },
			{ runtime: { documents: {} } },
			{ runtime: { documents: [] } },
			{ runtime: { documents: NON_ENTRIES } },
		]
		const lines = manifests.map(
			(m) =>
				`${describeInput(m)} => ${JSON.stringify(validateDocumentAttachments(m))}`,
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
