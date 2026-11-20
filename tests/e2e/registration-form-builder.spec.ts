// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: an admin builds a two-phase registration form and publishes it.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * RegistrationFormAuthoringServiceTest drives the rules against an in-memory
 * store. What it cannot see is whether a form the builder saves through the
 * real route survives OpenRegister's schema validation with its steps, their
 * phase labels and its rule intact, and whether the published status sticks.
 * This walks the builder's own save path: a draft first (which keeps its
 * findings), then the publish, then the list the builder reloads from.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the rendering of the form in the consuming app. That is
 * registration-form-leaf.spec.ts and dossiq's own suite. On a stack without
 * dossiq the destination check has nothing to validate against and refuses
 * the publish; the publish test then skips with that reason rather than
 * passing on a draft.
 *
 * @spec openspec/changes/registration-form-builder/specs/registration-form-builder/spec.md
 * @e2e registration-form-builder/requirement-the-form-builder-groups-questions-per-phase-with-rules-and-steps-req-obrf-002/an-admin-builds-a-two-phase-registration-form
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { anonymousContext } from './support/anonymous.ts'

const API = '/index.php/apps/buildiq/api/registration-forms'
const HEADERS = { 'OCS-APIRequest': 'true' }
const REGISTER = 'dossiq'
const SCHEMA = 'case'

// A run-unique name and id: the uniqueness rule refuses a second form with
// the same name on one type, and a rerun on the same stack must not collide.
const RUN = `${Date.now()}`
const FORM_ID = `rf-e2e-two-phase-${RUN}`

/**
 * The form the builder sends for the scenario: bound to `dossiq/case`
 * `caseType = bouwvergunning`, steps "Aanvrager" and "Bouwwerk", and one
 * `visibleWhen` rule on the company name.
 *
 * @param status The lifecycle status to save it in.
 * @return The form body.
 */
function twoPhaseForm(status: 'draft' | 'published'): Record<string, unknown> {
	return {
		id: FORM_ID,
		targetApp: 'dossiq',
		register: REGISTER,
		schema: SCHEMA,
		typeProperty: 'caseType',
		typeValue: 'bouwvergunning',
		name: `e2e-two-phase-${RUN}`,
		audience: 'internal',
		isDefault: false,
		isPublic: false,
		allowSaveForLater: true,
		status,
		sections: [
			{ name: 'aanvrager', label: 'Aanvrager', order: 1 },
			{ name: 'bouwwerk', label: 'Bouwwerk', order: 2 },
		],
		fields: [
			{
				name: 'initiatorType',
				label: 'U vraagt aan als',
				type: 'select',
				required: true,
				options: [
					{ value: 'person', label: 'Particulier' },
					{ value: 'company', label: 'Bedrijf' },
				],
				section: 'aanvrager',
				order: 1,
			},
			{
				name: 'initiatorDisplayName',
				label: 'Naam van het bedrijf',
				type: 'text',
				required: true,
				visibleWhen: { field: 'initiatorType', op: 'eq', value: 'company' },
				section: 'aanvrager',
				order: 2,
			},
			{
				name: 'title',
				label: 'Wat wilt u bouwen?',
				type: 'text',
				required: true,
				section: 'bouwwerk',
				order: 1,
			},
		],
		steps: [
			{
				id: 'aanvrager',
				title: 'Aanvrager',
				phase: 'Aanvrager',
				fields: ['initiatorType', 'initiatorDisplayName'],
			},
			{ id: 'bouwwerk', title: 'Bouwwerk', phase: 'Bouwwerk', fields: ['title'] },
		],
		formLogic: {},
	}
}

/**
 * The stored form with this run's id, read back from the list the builder
 * reloads after a save.
 *
 * @param request The admin request context.
 * @return The form, or undefined when it is not stored.
 */
async function storedForm(
	request: APIRequestContext,
): Promise<Record<string, unknown> | undefined> {
	const response = await request.get(`${API}?register=${REGISTER}&schema=${SCHEMA}`, {
		headers: HEADERS,
	})
	expect(response.status(), 'the builder could not reload the forms for the type').toBe(200)

	const body = await response.json()
	const items = (body.items ?? []) as Array<Record<string, unknown>>

	return items.find((item) => String(item.id ?? '') === FORM_ID)
}

test.describe('registration form builder', () => {
	test.describe.configure({ mode: 'serial' })

	test('an admin saves a two-phase form as a draft first', async ({ request }) => {
		const response = await request.put(API, { headers: HEADERS, data: twoPhaseForm('draft') })

		test.skip(response.status() === 404, 'buildiq is not installed here')
		test.skip(
			response.status() === 422 && (await response.text()).includes('register'),
			'the dossiq register is not installed here, so there is no type to bind the form to',
		)

		expect(response.status(), await response.text()).toBe(200)

		// A draft is saved with its findings (decision 189), so the answer
		// carries the list even when it is empty.
		const body = await response.json()
		expect(Array.isArray(body.findings)).toBeTruthy()

		const stored = await storedForm(request)
		expect(stored, 'the draft was answered 200 but is not in the list').toBeDefined()
		expect(stored?.status).toBe('draft')
	})

	test('publishing leaves one published form with both phase labels', async ({ request }) => {
		const response = await request.put(API, {
			headers: HEADERS,
			data: twoPhaseForm('published'),
		})

		test.skip(response.status() === 404, 'buildiq is not installed here')

		if (response.status() === 422) {
			const body = await response.json()
			test.skip(
				body.error === 'destination_findings' || String(body.message ?? '').includes('register'),
				`the destination refused the publish on this stack: ${String(body.message ?? '')}`,
			)
		}

		expect(response.status(), await response.text()).toBe(200)

		const reload = await request.get(`${API}?register=${REGISTER}&schema=${SCHEMA}`, {
			headers: HEADERS,
		})
		const items = ((await reload.json()).items ?? []) as Array<Record<string, unknown>>
		const mine = items.filter((item) => String(item.id ?? '') === FORM_ID)

		expect(mine, 'publishing created a second form instead of updating the draft').toHaveLength(1)
		expect(mine[0].status).toBe('published')
		expect(mine[0].typeValue).toBe('bouwvergunning')

		const steps = (mine[0].steps ?? []) as Array<Record<string, unknown>>
		expect(steps.map((step) => step.phase)).toEqual(['Aanvrager', 'Bouwwerk'])

		// The rule the admin added survives the round trip.
		const fields = (mine[0].fields ?? []) as Array<Record<string, unknown>>
		const company = fields.find((field) => field.name === 'initiatorDisplayName')
		expect(company?.visibleWhen).toEqual({ field: 'initiatorType', op: 'eq', value: 'company' })
	})

	test('a caller with no account cannot publish a form', async ({ playwright }) => {
		// The least privileged principal: an empty storage state, no credentials.
		const anonymous = await anonymousContext(playwright)

		const response = await anonymous.put(API, {
			headers: HEADERS,
			data: { ...twoPhaseForm('published'), id: `${FORM_ID}-anon`, name: `anon-${RUN}` },
		})

		expect(response.status(), 'an anonymous caller published a registration form').not.toBe(200)

		await anonymous.dispose()
	})
})
