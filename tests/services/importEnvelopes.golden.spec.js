/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the block and form import parsers over the same grid of
 * inputs: JSON text and objects, with the right and wrong envelope kind, with
 * and without the block or form part. Pins the parsed record or the refusal
 * code of every input.
 */
import { describe, expect, it } from 'vitest'
import { parseBlockImport } from '../../src/services/blockExport.js'
import { parseFormImport } from '../../src/services/formExport.js'

const FORM = {
	slug: 'intake-form',
	name: 'Intake',
	kind: 'form',
	category: 'other',
	form: { fields: [] },
	uuid: 'u',
	createdBy: 'alice',
}

const BLOCK = { slug: 'b', name: 'B', fragment: {}, uuid: 'u', createdBy: 'bob' }

const ENVELOPES = [
	{ kind: 'component-block', block: BLOCK },
	{ kind: 'component-block', block: [] },
	{ kind: 'component-block', block: null },
	{ kind: 'component-block' },
	{ kind: 'form-template', form: FORM },
	{ kind: 'form-template', form: [] },
	{ kind: 'form-template', form: 'text' },
	{ kind: 'form-template' },
	{ kind: 'other', block: BLOCK, form: FORM },
	{ block: BLOCK, form: FORM },
	[],
	{},
]

const INPUTS = [
	...ENVELOPES,
	...ENVELOPES.map((e) => JSON.stringify(e)),
	'',
	'not json',
	'null',
	'7',
	'"text"',
	null,
	undefined,
	0,
	true,
]

/**
 * The parsed record, or the refusal code.
 *
 * @param {function(*): object} parse The parser.
 * @param {*} input The input.
 *
 * @return {object} The outcome.
 */
function outcome(parse, input) {
	try {
		return { ok: parse(input) }
	} catch (e) {
		return { refused: e.name, code: e.code }
	}
}

describe('import envelopes golden', () => {
	it('parseBlockImport reads every input as recorded', () => {
		expect(INPUTS.map((i) => outcome(parseBlockImport, i))).toMatchSnapshot()
	})

	it('parseFormImport reads every input as recorded', () => {
		expect(INPUTS.map((i) => outcome(parseFormImport, i))).toMatchSnapshot()
	})
})
