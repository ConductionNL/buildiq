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
	journeyFindings,
	moveStep,
	repeatFindings,
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

describe('repeating writes (forms-multi-product-request)', () => {
	const productForms = {
		'form-products': {
			fields: [
				{
					key: 'producten',
					widget: 'sub-objects',
					type: 'array',
					items: { properties: { product: { enum: ['parkeervergunning', 'afvalcontainer'] } } },
				},
				{ key: 'naam' },
			],
		},
		'form-contact': { fields: [{ key: 'email' }] },
	}

	/**
	 * A journey asking contact then products, with the given write on the given step.
	 *
	 * @param {object} write - the write.
	 * @param {string} [on] - the step that makes it.
	 * @return {object} The journey.
	 */
	function multi(write, on = 'products') {
		const steps = [
			{ id: 'contact', type: 'form', formId: 'form-contact' },
			{ id: 'products', type: 'form', formId: 'form-products' },
			{ id: 'review', type: 'review' },
		]
		steps.find((step) => step.id === on).writes = [write]
		return { steps }
	}

	const repeating = {
		as: 'aanvraag',
		forEach: 'producten',
		targetBy: 'product',
		targets: {
			parkeervergunning: { register: 'loket', schema: 'zaak', typeValue: 'PV' },
			afvalcontainer: { register: 'loket', schema: 'zaak', typeValue: 'AC' },
		},
	}

	it('accepts a write repeating over the product list with a target for every product', () => {
		expect(repeatFindings(multi(repeating), productForms)).toEqual([])
		expect(repeatFindings(multi(repeating, 'review'), productForms)).toEqual([])
	})

	it('names the product that has no target', () => {
		const write = { ...repeating, targets: { parkeervergunning: repeating.targets.parkeervergunning } }

		expect(repeatFindings(multi(write), productForms)).toEqual([
			{ step: 'products', write: 'aanvraag', code: 'write-target-missing', product: 'afvalcontainer' },
		])
	})

	it('refuses repeating over an answer asked after the write, or not a list, or unknown', () => {
		expect(repeatFindings(multi(repeating, 'contact'), productForms)).toEqual([
			{ step: 'contact', write: 'aanvraag', code: 'write-foreach-later', answer: 'producten' },
		])
		expect(repeatFindings(multi({ as: 'a', forEach: 'naam' }), productForms)).toEqual([
			{ step: 'products', write: 'a', code: 'write-foreach-not-list', answer: 'naam' },
		])
		expect(repeatFindings(multi({ as: 'a', forEach: 'kinderen' }), productForms)).toEqual([
			{ step: 'products', write: 'a', code: 'write-foreach-unknown', answer: 'kinderen' },
		])
	})

	it('refuses targets on a write that does not repeat, targets without targetBy, and a repeating bundle', () => {
		expect(repeatFindings(multi({ as: 'a', targets: {} }), productForms).map((f) => f.code)).toEqual(['write-targets-without-foreach'])
		expect(repeatFindings(multi({ ...repeating, targetBy: '' }), productForms).map((f) => f.code)).toEqual(['write-target-by-missing'])
		expect(repeatFindings(multi({ ...repeating, bundle: true }), productForms).map((f) => f.code)).toEqual(['write-bundle-repeats'])
	})

	it('reads the product values from the column options too', () => {
		const forms = {
			...productForms,
			'form-products': { fields: [{ key: 'producten', widget: 'sub-objects', columns: [{ key: 'product', options: [{ value: 'parkeervergunning' }, 'ontheffing'] }] }] },
		}

		expect(repeatFindings(multi(repeating), forms)).toEqual([
			{ step: 'products', write: 'aanvraag', code: 'write-target-missing', product: 'ontheffing' },
		])
	})

	it('is part of every finding the designer makes', () => {
		const write = { ...repeating, targets: {} }

		expect(journeyFindings(multi(write), productForms).map((f) => f.code)).toEqual(['write-target-missing', 'write-target-missing'])
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
