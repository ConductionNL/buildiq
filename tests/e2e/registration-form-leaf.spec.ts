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
 * The served form is asserted by the last test, which writes its own
 * published registrationForm rows for a host type that exists nowhere else
 * (through OpenRegister's objects API, so buildiq's destination check against
 * a real target schema does not apply), asks the leaf for one channel, and
 * removes the rows again. The hidden preset is still asserted in the unit
 * suite only.
 *
 * @spec openspec/specs/registration-form-builder/spec.md
 * @e2e registration-form-builder/requirement-the-leaf-serves-by-audience-and-by-name-req-obrf-006/dossiq-asks-for-the-desk-default
 * @e2e registration-form-builder/requirement-a-form-presets-fields-hidden-or-visible-req-obrf-005/the-citizen-never-sees-the-channel-field
 * @e2e registration-form-builder/requirement-the-form-owns-field-order-and-grouping-req-obrf-008/the-citizens-form-asks-four-fields-in-the-order-the-admin-set
 * @e2e registration-form-builder/requirement-the-leaf-serves-by-channel-and-the-channel-comes-back-with-the-form-req-obrf-009/dossiq-writes-the-channel-the-form-declared
 */
import { expect, test } from '@playwright/test'
import { anonymousContext } from './support/anonymous.ts'

const CATALOGUE = '/index.php/apps/openregister/api/integrations'
const LEAF_ID = 'buildiq-registration-form'
const HEADERS = { 'OCS-APIRequest': 'true' }
const FORMS = '/index.php/apps/openregister/api/objects/buildiq/registrationForm'

test.describe('registration-form leaf', () => {
	test("the leaf is on OpenRegister's catalogue under its own id", async ({
		request,
	}) => {
		const response = await request.get(CATALOGUE, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const entries = (body.integrations ?? body.items ?? body.results ?? body ?? []) as Array<
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
		const anonymous = await anonymousContext(playwright)

		const response = await anonymous.post(
			`/index.php/apps/openregister/api/objects/dossiq/Zaak/bouwvergunning/integrations/${LEAF_ID}`,
			{
				headers: HEADERS,
				data: { registrationFormId: 'rf-1', values: { voornaam: 'Jan' } },
			},
		)

		expect(response.status()).not.toBe(200)
		expect(response.status()).not.toBe(201)

		await anonymous.dispose()
	})

	test("asked for a channel, the leaf serves that channel's forms first, with sections and fields in the admin's order", async ({
		request,
	}) => {
		// forms-per-case-type 8.2: the channel filter (REQ-OBRF-009) and the
		// ordered sections (REQ-OBRF-008) on the served answer.
		const host = { register: 'e2e-host', schema: 'e2e-type', id: `e2e-${Date.now()}` }
		const base = {
			targetApp: 'dossiq',
			register: host.register,
			schema: host.schema,
			typeProperty: 'caseType',
			typeValue: host.id,
			audience: 'client',
			status: 'published',
		}
		const rows = [
			{
				...base,
				name: 'E2E portal form',
				channel: 'portal',
				sections: [{ name: 'tweede', order: 2 }, { name: 'eerste', order: 1 }],
				fields: [
					{ name: 'losVeld' },
					{ name: 'telefoon', section: 'tweede', order: 1 },
					{ name: 'achternaam', section: 'eerste', order: 2 },
					{ name: 'voornaam', section: 'eerste', order: 1 },
				],
			},
			{ ...base, name: 'E2E form without a channel', channel: '' },
			{ ...base, name: 'E2E desk form', channel: 'desk' },
		]

		const created: string[] = []
		try {
			for (const row of rows) {
				const response = await request.post(FORMS, { headers: HEADERS, data: row })
				test.skip(response.status() === 404, 'buildiq or OpenRegister is not installed here')
				expect(response.ok(), `could not write the fixture "${row.name}"`).toBeTruthy()
				const saved = await response.json()
				created.push(String(saved.id ?? saved.uuid ?? saved['@self']?.id ?? ''))
			}

			const response = await request.get(
				`/index.php/apps/openregister/api/objects/${host.register}/${host.schema}/${host.id}/integrations/${LEAF_ID}?channel=portal`,
				{ headers: HEADERS },
			)
			expect(response.ok()).toBeTruthy()
			const body = await response.json()
			const items = (body.items ?? []) as Array<Record<string, any>>

			// The channel's own form first, then the channel-less one; the desk form is not served.
			expect(items.map((item) => item.name)).toEqual(['E2E portal form', 'E2E form without a channel'])
			expect(items[0].channel).toBe('portal')

			// Sections in their own order, fields grouped by section and ordered inside it, section-less last.
			expect((items[0].sections as Array<{ name: string }>).map((section) => section.name)).toEqual(['eerste', 'tweede'])
			expect((items[0].fields as Array<{ name: string }>).map((field) => field.name)).toEqual(['voornaam', 'achternaam', 'telefoon', 'losVeld'])
		} finally {
			for (const id of created.filter(Boolean)) {
				await request.delete(`${FORMS}/${id}`, { headers: HEADERS })
			}
		}
	})
})

