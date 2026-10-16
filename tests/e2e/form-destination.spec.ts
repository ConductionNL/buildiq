// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — forms are valid against their destination (decisions 179, 189).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * The unit suite proves the mapping and the refusal against OpenRegister's
 * validator class. What it cannot see is the wiring on a running server:
 * buildiq's container handing out OpenRegister's validator, the routes, and
 * the draft-saves / publish-refuses split through the real controllers.
 *
 * The destination is buildiq's own `registrationForm` schema, so the test
 * needs no other app: a form that maps only `name` into it leaves its other
 * required properties unfilled, which is a known `required-unmapped`.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the marks on the fields in the designer (a browser run, owed in the
 * live pass, decision 139), and not a schema change unpublishing a form,
 * which needs a schema an e2e run may change.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md
 * @e2e form-destination-authoring/requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published/a-registration-form-missing-a-required-property-cannot-be-published
 * @e2e form-destination-authoring/requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published/every-finding-is-shown-when-the-publish-is-refused
 * @e2e form-destination-authoring/requirement-a-draft-form-saves-with-its-findings/a-half-built-registration-form-is-saved-as-a-draft
 * @e2e form-destination-authoring/requirement-a-draft-form-saves-with-its-findings/a-page-designer-save-keeps-its-findings-visible
 */
import { expect, test } from '@playwright/test'

const FORMS = '/index.php/apps/buildiq/api/registration-forms'
const CHECK = '/index.php/apps/buildiq/api/forms/destination-check'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/**
 * A registration form whose destination is buildiq's own `registrationForm`.
 *
 * @param status - `draft` or `published`.
 * @return The form.
 */
function form(status: string) {
	return {
		name: `e2e-destination-${status}-${Date.now()}`,
		audience: 'internal',
		status,
		targetApp: 'buildiq',
		register: 'buildiq',
		schema: 'registrationForm',
		typeProperty: 'audience',
		typeValue: 'internal',
		fields: [{ name: 'name', type: 'text' }],
	}
}

test.describe('forms are valid against their destination', () => {
	test('a draft with findings is saved and answers them; a published one is refused with every finding', async ({
		request,
	}) => {
		const draft = await request.put(FORMS, {
			headers: HEADERS,
			data: form('draft'),
		})
		test.skip(
			draft.status() === 404,
			'buildiq registration forms are not routed here',
		)
		expect(draft.status()).toBe(200)
		const saved = await draft.json()
		const codes = (saved.findings || []).map((f: { code: string }) => f.code)
		test.skip(
			codes.includes('destination-unchecked'),
			'OpenRegister without the form destination validator (openregister#4563)',
		)
		expect(codes).toContain('required-unmapped')

		const published = await request.put(FORMS, {
			headers: HEADERS,
			data: form('published'),
		})
		expect(published.status()).toBe(422)
		const refused = await published.json()
		expect(refused.error).toBe('destination_findings')
		expect(refused.findings.length).toBe(saved.findings.length)
	})

	test('the designer check names the page and field of each finding', async ({
		request,
	}) => {
		const response = await request.post(CHECK, {
			headers: HEADERS,
			data: {
				// No app carries this slug, so the check is an admin's only.
				appSlug: 'e2e-form-destination',
				manifest: {
					pages: [
						{
							id: 'new-form',
							type: 'form',
							config: {
								destination: {
									register: 'buildiq',
									schema: 'registrationForm',
								},
								fields: [{ key: 'nietBestaand', type: 'string' }],
							},
						},
					],
				},
			},
		})
		expect(response.status()).toBe(200)
		const body = await response.json()
		const codes = body.findings.map((f: { code: string }) => f.code)
		test.skip(
			codes.includes('destination-unchecked'),
			'OpenRegister without the form destination validator (openregister#4563)',
		)
		expect(body.accepted).toBe(false)
		const unknown = body.findings.find(
			(f: { code: string }) => f.code === 'property-unknown',
		)
		expect(unknown).toMatchObject({ page: 'new-form', field: 'nietBestaand' })
	})
})
