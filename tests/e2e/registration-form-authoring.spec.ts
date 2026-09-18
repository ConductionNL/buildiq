// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e for the authoring surface of registration forms.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * RegistrationFormAuthoringServiceTest covers the refusals against an
 * in-memory store. What it cannot see is whether the route is reachable. The
 * buildiq SPA answers `/{path}` with its own shell at 200, so an unregistered
 * route comes back as `200 text/html` rather than as a 404: these assertions
 * read the CONTENT TYPE, not the status.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md
 * @e2e registration-form-builder/requirement-a-case-type-carries-several-forms-req-obrf-004/two-forms-cannot-share-a-name-on-one-type
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/buildiq/api/registration-forms'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('registration form authoring', () => {
	test('the authoring route exists rather than falling through to the SPA shell', async ({
		request,
	}) => {
		const response = await request.get(`${API}?register=dossiq&schema=Zaak`, {
			headers: HEADERS,
		})

		test.skip(response.status() === 404, 'buildiq is not installed here')

		if (response.status() === 200) {
			expect(
				response.headers()['content-type'] ?? '',
				'the registration-form route fell through to the SPA shell, so it is not registered',
			).not.toContain('text/html')
		}
	})

	test('a caller with no account cannot author a form a citizen fills in', async ({
		playwright,
	}) => {
		// The least privileged principal that should be refused. A preset on a
		// form can carry a value the citizen never sees, so an anonymous write
		// must never reach the rules.
		const anonymous = await playwright.request.newContext({
			baseURL: process.env.BASE_URL ?? process.env.NC_BASE_URL,
		})

		const response = await anonymous.put(API, {
			headers: HEADERS,
			data: {
				id: 'rf-e2e',
				name: 'Aanvraag',
				audience: 'client',
				register: 'dossiq',
				schema: 'Zaak',
				status: 'published',
			},
		})

		expect(
			response.status(),
			'an anonymous caller was allowed to write a registration form',
		).not.toBe(200)

		await anonymous.dispose()
	})
})
