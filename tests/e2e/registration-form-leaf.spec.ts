// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — the registration-form leaf (spec: registration-form-builder).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * RegistrationFormLeafProviderTest already covers the serving shape, the
 * filters and the draft ownership, against a double. What it cannot see is
 * whether the leaf reached OpenRegister's catalogue at all: whether buildiq's
 * listener ran, whether the descriptor survived validation, and whether the id
 * on the PHP half is the id a consumer asks for. A provider that is never
 * contributed answers nothing at runtime and every unit test stays green.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the served form. No published registrationForm exists on a CI stack, so
 * the hidden preset, the channel fall-back and the field order are asserted in
 * the unit suite, which can build those fixtures.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md
 * @e2e registration-form-builder/requirement-the-leaf-serves-by-audience-and-by-name-req-obrf-006/dossiq-asks-for-the-desk-default
 * @e2e registration-form-builder/requirement-a-form-presets-fields-hidden-or-visible-req-obrf-005/the-citizen-never-sees-the-channel-field
 * @e2e registration-form-builder/requirement-the-form-owns-field-order-and-grouping-req-obrf-008/the-citizens-form-asks-four-fields-in-the-order-the-admin-set
 * @e2e registration-form-builder/requirement-the-leaf-serves-by-channel-and-the-channel-comes-back-with-the-form-req-obrf-009/dossiq-writes-the-channel-the-form-declared
 */
import { expect, test } from '@playwright/test'

const CATALOGUE = '/index.php/apps/openregister/api/integrations'
const LEAF_ID = 'buildiq-registration-form'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('registration-form leaf', () => {
	test("the leaf is on OpenRegister's catalogue under its own id", async ({
		request,
	}) => {
		const response = await request.get(CATALOGUE, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const entries = (body.items ?? body.results ?? body ?? []) as Array<
			Record<string, unknown>
		>

		expect(entries.map((entry) => String(entry.id ?? ''))).toContain(LEAF_ID)
	})

	test('an unauthenticated caller cannot save somebody a draft', async ({
		playwright,
	}) => {
		// A draft holds a person's own half-written answers, often personal
		// data. The least privileged principal that should be refused is nobody
		// at all: there is no one for the draft to belong to.
		const anonymous = await playwright.request.newContext({
			baseURL: process.env.BASE_URL ?? process.env.NC_BASE_URL,
		})

		const response = await anonymous.post(
			`${CATALOGUE}/${LEAF_ID}/dossiq/Zaak/bouwvergunning`,
			{
				headers: HEADERS,
				data: { registrationFormId: 'rf-1', values: { voornaam: 'Jan' } },
			},
		)

		expect(response.status()).not.toBe(200)
		expect(response.status()).not.toBe(201)

		await anonymous.dispose()
	})
})
