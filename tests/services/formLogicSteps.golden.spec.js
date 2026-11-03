/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the page and step half of the form-logic contract:
 * `validateFormLogic` over generated manifests whose pages, steps and field
 * lists are drawn from pools, and `assignUnassignedFieldsToFinalStep` over the
 * same manifests (the manifest it returns, and whether it is the input).
 *
 * @spec openspec/specs/form-editor-logic/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	assignUnassignedFieldsToFinalStep,
	validateFormLogic,
} from '../../src/services/manifestValidation/formLogic.js'

const ABSENT = Symbol('absent')

const STEP_TITLES = [ABSENT, 'Start', '', '  ', 3]
const STEP_IDS = [ABSENT, 's1', 's1', 's2', '', 7]
const STEP_FIELDS = [
	ABSENT,
	'a',
	[],
	['a'],
	['a', 'b'],
	['ghost', 'a'],
	[3, null, 'c'],
	['b', 'b'],
]
const STEP_SHAPES = ['step', 'step', 'step', 'step', null, 'text', ['x']]
const STEPS = [ABSENT, 'none', [], 'list', 'list', 'list']
const FIELD_LISTS = [
	ABSENT,
	[],
	[{ key: 'a' }, { key: 'b' }, { key: 'c' }],
	[
		{ key: 'a' },
		null,
		{ key: 7 },
		{ label: 'x' },
		{ key: 'b', visibleWhen: { field: 'ghost' } },
	],
	'fields',
]
const PAGE_TYPES = ['form', 'form', 'form', 'index', ABSENT]
const CONFIGS = ['config', 'config', 'config', null, [], ABSENT]

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
 * Set a key unless the drawn value is ABSENT.
 *
 * @param {object} target The object.
 * @param {[string, *]} entry The key and the drawn value.
 *
 * @return {void}
 */
function put(target, entry) {
	if (entry[1] !== ABSENT) {
		target[entry[0]] = entry[1]
	}
}

/**
 * Build one step.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The step.
 */
function stepFrom(rand) {
	const shape = pick(rand, STEP_SHAPES)
	if (shape !== 'step') {
		return shape
	}
	const step = {}
	put(step, ['id', pick(rand, STEP_IDS)])
	put(step, ['title', pick(rand, STEP_TITLES)])
	put(step, ['fields', pick(rand, STEP_FIELDS)])
	return step
}

/**
 * Build one page.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The page.
 */
function pageFrom(rand) {
	if (rand() < 0.08) {
		return null
	}
	const page = { id: 'p' }
	put(page, ['type', pick(rand, PAGE_TYPES)])
	const configShape = pick(rand, CONFIGS)
	if (configShape !== 'config') {
		put(page, ['config', configShape])
		return page
	}
	const config = {}
	put(config, ['fields', pick(rand, FIELD_LISTS)])
	const steps = pick(rand, STEPS)
	if (steps === 'list') {
		config.steps = Array.from({ length: 1 + Math.floor(rand() * 3) }, () =>
			stepFrom(rand),
		)
	} else if (steps === 'none') {
		config.steps = 'steps'
	} else {
		put(config, ['steps', steps])
	}
	page.config = config
	return page
}

/**
 * Build the case manifests.
 *
 * @return {Array} The manifests.
 */
function manifests() {
	const rand = mulberry32(20261102)
	const fields = [{ key: 'a' }, { key: 'b' }]
	const out = [
		undefined,
		null,
		{},
		{ pages: 'x' },
		{ pages: [] },
		{
			pages: [
				{
					type: 'form',
					config: {
						fields,
						steps: [
							{ id: 's1', title: 'A', fields: ['a'] },
							{ id: 's1', title: 'B', fields: ['b', 'a'] },
							{ id: 's1', title: 'C', fields: 'b' },
							{ id: 's2', title: 'D' },
							{ id: 's2', title: 'E', fields: [] },
						],
					},
				},
				{
					type: 'form',
					config: {
						fields,
						steps: [{ title: 'Only', fields: ['b'] }, null],
					},
				},
				{
					type: 'form',
					config: {
						fields,
						steps: [
							{ id: '', title: 'A', fields: ['a'] },
							{ id: '', title: 'B', fields: ['b'] },
						],
					},
				},
			],
		},
	]
	for (let i = 0; i < 160; i++) {
		out.push({
			pages: Array.from({ length: 1 + Math.floor(rand() * 3) }, () =>
				pageFrom(rand),
			),
		})
	}
	return out
}

describe('form logic steps golden', () => {
	it('validateFormLogic reports every manifest as recorded', () => {
		expect(manifests().map((m) => validateFormLogic(m))).toMatchSnapshot()
	})

	it('assignUnassignedFieldsToFinalStep writes every manifest as recorded', () => {
		const out = manifests().map((m) => {
			const before = JSON.stringify(m)
			const result = assignUnassignedFieldsToFinalStep(m)
			expect(JSON.stringify(m)).toBe(before)
			return { same: result === m, result }
		})
		expect(out).toMatchSnapshot()
	})
})
