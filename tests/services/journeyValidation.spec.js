/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/journeyValidation.js (journey-designer):
 * step references, branch rules from the canonical operator set, the order
 * of write references, and the call to the server's write check.
 */
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { describe, expect, it, vi } from 'vitest'
import {
	BRANCH_OPERATORS,
	branchFindings,
	checkJourneyWrites,
	fieldsAskedBy,
	journeyFindings,
	moveStep,
	stepFindings,
	storedFormStep,
	writeOrderFindings,
} from '../../src/services/journeyValidation.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const forms = {
	'form-applicant': { fields: [{ key: 'kind' }, { key: 'name' }] },
	'form-company': { steps: [{ fields: [{ key: 'kvk' }] }] },
}

/**
 * A journey of an applicant form, a company form and a review.
 *
 * @param {object} [extra] - fields merged into the first step.
 * @return {object} The journey.
 */
function journey(extra = {}) {
	return {
		id: 'j1',
		steps: [
			{ id: 'applicant', type: 'form', formId: 'form-applicant', ...extra },
			{ id: 'company', type: 'form', formId: 'form-company' },
			{ id: 'review', type: 'review' },
		],
	}
}

describe('BRANCH_OPERATORS', () => {
	it('is exactly the canonical visibleWhen operator set', () => {
		const require = createRequire(import.meta.url)
		const path = require.resolve('@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json')
		const schema = JSON.parse(readFileSync(path, 'utf8'))

		expect([...BRANCH_OPERATORS]).toEqual(schema.$defs.visibleWhen.properties.op.enum)
		expect(Object.isFrozen(BRANCH_OPERATORS)).toBe(true)
	})
})

describe('steps', () => {
	it('stores a form step as a reference, never with field definitions', () => {
		const stored = storedFormStep({ id: 's', title: 'You', formId: 'form-applicant', form: { fields: [{ key: 'name' }] }, fields: [{ key: 'x' }] })

		expect(stored).toEqual({ id: 's', title: 'You', type: 'form', formId: 'form-applicant' })
	})

	it('moves the second step first and leaves every step as it was', () => {
		const steps = journey().steps

		const moved = moveStep(steps, 1, 0)

		expect(moved.map((s) => s.id)).toEqual(['company', 'applicant', 'review'])
		expect(moved[0]).toBe(steps[1])
		expect(steps.map((s) => s.id)).toEqual(['applicant', 'company', 'review'])
		expect(moveStep(steps, 5, 0)).toEqual(steps)
	})

	it('reports a referenced form that no longer exists', () => {
		const findings = stepFindings(journey({ formId: 'form-deleted' }), forms)

		expect(findings).toEqual([{ step: 'applicant', code: 'step-form-gone', form: 'form-deleted' }])
	})

	it('reports copied fields, a missing form, an unknown type and duplicate ids', () => {
		const findings = stepFindings({
			steps: [
				{ id: 'a', type: 'form', formId: 'form-applicant', form: { fields: [] } },
				{ id: 'a', type: 'form' },
				{ id: '', type: 'page' },
			],
		}, forms)

		expect(findings).toEqual([
			{ step: 'a', code: 'step-copies-fields' },
			{ step: 'a', code: 'step-id-duplicate' },
			{ step: 'a', code: 'step-form-missing' },
			{ step: '', code: 'step-id-missing' },
			{ step: '', code: 'step-type-unknown', type: 'page' },
		])
	})

	it('finds nothing wrong with a well-formed journey', () => {
		expect(journeyFindings(journey(), forms)).toEqual([])
	})
})

describe('branch rules', () => {
	it('accepts a rule on a field the step itself asks', () => {
		const findings = branchFindings(journey({ branch: [{ when: { field: 'kind', op: 'eq', value: 'company' }, goto: 'company' }] }), forms)

		expect(findings).toEqual([])
	})

	it('refuses a rule on a field first asked at a later step, naming field and step', () => {
		const findings = branchFindings(journey({ branch: [{ when: { field: 'kvk', op: 'notEmpty' }, goto: 'review' }] }), forms)

		expect(findings).toEqual([{ step: 'applicant', code: 'branch-field-later', field: 'kvk', askedAt: 'company' }])
	})

	it('refuses an operator outside the canonical set, inside a composition too', () => {
		const findings = branchFindings(journey({
			branch: [{ when: { all: [{ field: 'kind', op: 'matches', value: '^c' }, { field: 'name', op: 'notEmpty' }] }, goto: 'review' }],
		}), forms)

		expect(findings).toEqual([{ step: 'applicant', code: 'branch-operator-unknown', operator: 'matches' }])
	})

	it('reports an unknown target and a field no step asks', () => {
		const findings = branchFindings(journey({ branch: [{ when: { field: 'age.years', op: 'gt', value: 17 }, goto: 'nowhere' }] }), forms)

		expect(findings).toEqual([
			{ step: 'applicant', code: 'branch-target-unknown', goto: 'nowhere' },
			{ step: 'applicant', code: 'branch-field-unknown', field: 'age' },
		])
	})
})

describe('write order', () => {
	it('accepts a contact that references the organisation written before it', () => {
		const findings = writeOrderFindings(journey({
			writes: [
				{ as: 'org', register: 'crm', schema: 'organisation', mapping: { fields: [{ field: 'name', property: 'name' }] } },
				{ as: 'contact', register: 'crm', schema: 'contact', mapping: { fixed: { organisation: { $write: 'org' } } } },
			],
		}))

		expect(findings).toEqual([])
	})

	it('refuses a reference to a write made later, and a repeated name', () => {
		const findings = writeOrderFindings({
			steps: [
				{ id: 'one', type: 'form', writes: [{ as: 'contact', mapping: { fixed: { organisation: { $write: 'org' } } } }] },
				{ id: 'two', type: 'form', writes: [{ as: 'org' }, { as: 'org' }] },
			],
		})

		expect(findings).toEqual([
			{ step: 'one', code: 'write-unknown', write: 'org', property: 'organisation' },
			{ step: 'two', code: 'write-as-duplicate', write: 'org' },
		])
	})
})

describe('checkJourneyWrites', () => {
	it('posts the journey and reports the server findings as not accepted', async () => {
		const finding = { step: 'applicant', write: 'org', property: 'naam', code: 'property-unknown', message: 'x' }
		const client = { post: vi.fn().mockResolvedValue({ data: { accepted: false, findings: [finding] } }) }
		const j = journey()

		const answer = await checkJourneyWrites(j, client)

		expect(client.post).toHaveBeenCalledWith('/index.php/apps/buildiq/api/journeys/check', { journey: j })
		expect(answer).toEqual({ accepted: false, findings: [finding] })
	})

	it('reads a body without findings as accepted', async () => {
		const client = { post: vi.fn().mockResolvedValue({ data: {} }) }

		expect(await checkJourneyWrites(journey(), client)).toEqual({ accepted: true, findings: [] })
	})
})

describe('fieldsAskedBy', () => {
	const forms = { f1: { fields: [{ key: 'name' }], steps: [{ fields: [{ name: 'age' }] }] }, f2: { fields: [{ key: 'email' }, { key: 'name' }] } }
	const journey = { steps: [{ id: 'a', type: 'form', formId: 'f1' }, { id: 'r', type: 'review' }, { id: 'b', type: 'form', formId: 'f2' }] }

	it('gives the fields of the step and every form step before it, each once', () => {
		expect(fieldsAskedBy(journey, forms, 0)).toEqual(['name', 'age'])
		expect(fieldsAskedBy(journey, forms, 1)).toEqual(['name', 'age'])
		expect(fieldsAskedBy(journey, forms, 2)).toEqual(['name', 'age', 'email'])
	})

	it('gives nothing for a missing form or journey', () => {
		expect(fieldsAskedBy({ steps: [{ id: 'a', type: 'form', formId: 'gone' }] }, forms, 0)).toEqual([])
		expect(fieldsAskedBy(null, forms, 3)).toEqual([])
	})
})
