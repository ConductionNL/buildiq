/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/calculations.js (change
 * data-calculated-field-authoring, REQ-BQCF-001 to REQ-BQCF-004).
 *
 * The recorded responses mirror OpenRegister development: the catalogue
 * rows of OperatorCatalogue::all(), the trial shape of
 * CalculationTrialService, and the 422 body SchemasController returns on a
 * CalculationDeclarationException ({error, errors: [{code, message}]}).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
const postMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => getMock(...a), post: (...a) => postMock(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const {
	applyCalculations,
	calculationsFromProperties,
	loadOperatorCatalogue,
	referencedFields,
	refusalsByProperty,
	tryCalculation,
} = await import('../../src/services/calculations.js')

const catalogue = {
	operators: [
		{ op: 'prop', category: 'reference', arity: '1', operands: ['string'], result: 'any', description: 'Reads a property of the object.' },
		{ op: '*', category: 'arithmetic', arity: '1+', operands: ['number'], result: 'number', description: 'Multiplies its operands.' },
		{ op: '/', category: 'arithmetic', arity: '2', operands: ['number', 'number'], result: 'number', description: 'Divides.' },
	],
	categories: ['reference', 'arithmetic'],
}

const total = {
	type: 'number',
	expression: { '*': [{ prop: 'quantity' }, { prop: 'unitPrice' }] },
}

beforeEach(() => {
	getMock.mockReset()
	postMock.mockReset()
})

describe('loadOperatorCatalogue', () => {
	it('reads the published catalogue from OpenRegister', async () => {
		getMock.mockResolvedValue({ data: catalogue })
		const result = await loadOperatorCatalogue()
		expect(getMock).toHaveBeenCalledWith('/apps/openregister/api/schemas/calculation-operators')
		expect(result.operators.map((o) => o.op)).toEqual(['prop', '*', '/'])
	})

	it('answers null when the endpoint is missing, so the editor falls back to read-only', async () => {
		getMock.mockRejectedValue({ response: { status: 404 } })
		expect(await loadOperatorCatalogue()).toBeNull()
	})
})

describe('tryCalculation', () => {
	it('posts the declaration with a sample and returns the value', async () => {
		postMock.mockResolvedValue({ data: { ok: true, value: 8, dependencies: ['quantity', 'unitPrice'] } })
		const result = await tryCalculation(total, { quantity: 2, unitPrice: 4 })
		expect(postMock).toHaveBeenCalledWith('/apps/openregister/api/schemas/calculation-evaluate', {
			calculation: total,
			object: { quantity: 2, unitPrice: 4 },
		})
		expect(result).toEqual({ ok: true, value: 8, error: null })
	})

	it('returns the refusal of a 422 instead of throwing', async () => {
		postMock.mockRejectedValue({
			response: { status: 422, data: { ok: false, error: { code: 'calculation-unknown-op', message: 'Unknown operator "pow".' } } },
		})
		const result = await tryCalculation({ type: 'number', expression: { pow: [1, 2] } }, {})
		expect(result).toEqual({ ok: false, value: null, error: { code: 'calculation-unknown-op', message: 'Unknown operator "pow".' } })
	})
})

describe('refusalsByProperty', () => {
	it('maps each refusal onto the calculation names its message quotes', () => {
		const errors = [
			{ code: 'calculation-cycle', message: 'Calculation cycle: a -> b -> a' },
			{ code: 'calculation-prop-unknown', message: 'Calculation "total" reads unknown property "qty".' },
		]
		const map = refusalsByProperty(errors, ['a', 'b', 'total'])
		expect(map.a).toEqual(['Calculation cycle: a -> b -> a'])
		expect(map.b).toEqual(['Calculation cycle: a -> b -> a'])
		expect(map.total).toEqual(['Calculation "total" reads unknown property "qty".'])
	})

	it('answers an empty map for a refusal that is not a list', () => {
		expect(refusalsByProperty('boom', ['a'])).toEqual({})
	})
})

describe('calculationsFromProperties and applyCalculations', () => {
	it('reads the property-level calculation key', () => {
		const properties = { quantity: { type: 'number' }, total: { type: 'number', calculation: total } }
		expect(calculationsFromProperties(properties)).toEqual({ total })
	})

	it('writes calculations onto properties, adds a missing property, and drops removed ones', () => {
		const properties = {
			quantity: { type: 'number' },
			old: { type: 'number', calculation: { type: 'number', expression: 1 } },
		}
		const { properties: out, order } = applyCalculations(properties, ['quantity', 'old'], {
			total,
			due: { type: 'date', expression: { prop: 'start' } },
		})
		expect(out.old).toEqual({ type: 'number' })
		expect(out.total).toEqual({ type: 'number', calculation: total })
		expect(out.due).toEqual({ type: 'string', format: 'date', calculation: { type: 'date', expression: { prop: 'start' } } })
		expect(order).toEqual(['quantity', 'old', 'total', 'due'])
		expect(properties.old.calculation).toBeDefined()
	})
})

describe('referencedFields', () => {
	it('lists the fields an expression reads, once each', () => {
		expect(referencedFields({ '+': [{ prop: 'a' }, { '*': [{ prop: 'b' }, { prop: ['a'] }] }, 3] })).toEqual(['a', 'b'])
	})
})
