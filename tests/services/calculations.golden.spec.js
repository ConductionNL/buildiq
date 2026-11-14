/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the calculated-field helpers over fixed input grids: the
 * trial result of every answer and failure shape, the refusals mapped per
 * name, the properties and order a set of calculations writes, and the
 * fields an expression reads.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

const postMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: (...a) => postMock(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const {
	applyCalculations,
	calculationsFromProperties,
	referencedFields,
	refusalsByProperty,
	tryCalculation,
} = await import('../../src/services/calculations.js')

const ANSWERS = [
	{ ok: true, value: 4 },
	{ ok: true, value: 0 },
	{ ok: true },
	{ ok: true, value: null, error: { code: 'x' } },
	{ ok: false, error: { code: 'div-zero', message: 'no' } },
	{ ok: false },
	{ ok: 'true', value: 1 },
	{ ok: 1, value: 2 },
	null,
	undefined,
]

const FAILURES = [
	{
		response: { data: { error: { code: 'bad', message: 'Bad' } } },
		message: 'Request failed',
	},
	{ response: { data: {} }, message: 'Request failed' },
	{ response: {}, message: '' },
	{ message: 'Network Error' },
	{},
	null,
]

const ERRORS = [
	undefined,
	null,
	'oops',
	[],
	[{ code: 'a', message: 'Calculation "total" refers to "price"' }],
	[{ code: 'b', message: 'Cycle: total -> price -> total' }],
	[{ code: 'c', message: 'total -> sub.total' }, { message: 'x->total' }],
	[{ message: 'price (total)' }, null, { message: 7 }, {}],
	[{ message: 'Calculation "a.b" is wrong; a.b -> c' }, { message: 'a+b -> x' }],
	[
		{ message: 'Calculation "total" is wrong' },
		{ message: 'Cycle: price -> total' },
		{ message: '"x" and "total"' },
	],
	7,
	{ message: 'Calculation "total"' },
]

const NAMES = [[], ['total'], ['total', 'price'], ['a.b', 'a+b', 'sub.total', 'x']]

const PROPERTIES = [
	undefined,
	{},
	{
		total: { type: 'number', calculation: { type: 'number', expression: 1 } },
		price: { type: 'number' },
	},
	{
		price: { type: 'integer', title: 'Price' },
		when: { type: 'string', format: 'date' },
	},
]

const ORDERS = [undefined, [], ['price', 'total'], ['price']]

const CALCULATIONS = [
	undefined,
	{},
	{ total: { type: 'number', expression: { '*': [{ prop: 'price' }, 2] } } },
	{
		'': { type: 'number', expression: 1 },
		due: { type: 'date', expression: 'x' },
	},
	{
		flag: { type: 'boolean', expression: true },
		other: { type: 'weird', expression: null },
		price: { type: 'integer', expression: 3 },
	},
	{ when: { type: 'string', expression: 'y' }, total: { type: 'string' } },
]

const EXPRESSIONS = [
	undefined,
	null,
	5,
	'price',
	{ prop: 'price' },
	{ prop: ['price', 0] },
	{ prop: '' },
	{ prop: 7 },
	{ '+': [{ prop: 'a' }, { prop: 'b' }, { prop: 'a' }, 3] },
	[{ prop: 'x' }, [{ prop: 'y' }], { if: [{ prop: 'x' }, { prop: 'z' }, null] }],
	{ max: { prop: 'q' }, extra: { prop: 'ignored' } },
	{ prop: ['a', { prop: 'b' }] },
	{ prop: { prop: 'c' } },
	{},
]

describe('calculations golden', () => {
	it('tryCalculation answers each response and failure as recorded', async () => {
		const out = []
		for (const answer of ANSWERS) {
			postMock.mockReset()
			postMock.mockResolvedValue({ data: answer })
			out.push(
				await tryCalculation(
					{ type: 'number', expression: 1 },
					answer?.ok ? { a: 1 } : undefined,
				),
			)
			out.push(postMock.mock.calls[0][1])
		}
		for (const failure of FAILURES) {
			postMock.mockReset()
			postMock.mockRejectedValue(failure)
			out.push(await tryCalculation({ type: 'number', expression: 1 }, null))
		}
		expect(out).toMatchSnapshot()
	})

	it('refusalsByProperty maps every error grid cell as recorded', () => {
		const out = []
		for (const errors of ERRORS) {
			for (const names of NAMES) {
				out.push(refusalsByProperty(errors, names))
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('applyCalculations writes every grid cell as recorded and leaves its input unchanged', () => {
		const out = []
		for (const properties of PROPERTIES) {
			for (const order of ORDERS) {
				for (const calculations of CALCULATIONS) {
					const before = JSON.stringify([properties, order, calculations])
					out.push(applyCalculations(properties, order, calculations))
					expect(JSON.stringify([properties, order, calculations])).toBe(
						before,
					)
				}
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('calculationsFromProperties reads every map as recorded', () => {
		const maps = [
			...PROPERTIES,
			null,
			{
				a: null,
				b: 'x',
				c: { calculation: null },
				d: { calculation: 'x' },
				e: { calculation: [1] },
			},
			{
				f: { calculation: { type: 'number' } },
				g: { calculation: { expression: 2, extra: 1 } },
			},
		]
		expect(maps.map((m) => calculationsFromProperties(m))).toMatchSnapshot()
	})

	it('referencedFields reads every expression as recorded', () => {
		expect(EXPRESSIONS.map((e) => referencedFields(e))).toMatchSnapshot()
	})
})
