// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e for the authoring surface of page layouts and screen overrides.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * PageLayoutAuthoringServiceTest covers the stamp, the refusal and the re-cut
 * against an in-memory store. What it cannot see is whether the routes are
 * reachable at all. A controller method with no entry in `appinfo/routes.php`
 * is a 404 at runtime and looks, from a unit suite, exactly like one that
 * works. The buildiq SPA answers `/{path}` with its own shell, so a route that
 * does not exist comes back as `200 text/html` rather than as a 404: these
 * assertions read the CONTENT TYPE, not the status, which is the only way to
 * tell the two apart.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the composition, and not the refusals. No layout exists on a CI stack to
 * patch, so the base fingerprint, the orphan report and the re-cut are asserted
 * in the unit suite, which can build those fixtures.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md
 * @e2e screen-override-layers/requirement-an-override-records-the-base-it-was-cut-against-req-obso-002/an-override-without-a-base-is-refused
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/buildiq/api/page-layouts'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('page layout authoring', () => {
	test('the authoring routes exist rather than falling through to the SPA shell', async ({
		request,
	}) => {
		const response = await request.get(`${API}?register=dossiq&schema=Zaak`, {
			headers: HEADERS,
		})

		test.skip(response.status() === 404, 'buildiq is not installed here')

		// The SPA catch-all answers any unrouted path with the app shell, and it
		// always answers 200. So a 200 that is HTML is the one shape that means
		// "this route does not exist"; a 401 or a 403 means the route is there
		// and the admin gate refused this session, which is also a pass.
		if (response.status() === 200) {
			expect(
				response.headers()['content-type'] ?? '',
				'the page-layout route fell through to the SPA shell, so it is not registered',
			).not.toContain('text/html')
		}
	})

	test('a caller with no account cannot author a screen for one', async ({
		playwright,
	}) => {
		// The least privileged principal that should be refused. A layout
		// decides what everyone using a case type sees, so an anonymous write
		// must never reach the rules, let alone the store.
		const anonymous = await playwright.request.newContext({
			baseURL: process.env.BASE_URL ?? process.env.NC_BASE_URL,
		})

		const response = await anonymous.put(API, {
			headers: HEADERS,
			data: {
				id: 'pl-e2e',
				register: 'dossiq',
				schema: 'Zaak',
				status: 'published',
				tabs: [{ id: 'gegevens', kind: 'fieldGroup', label: 'Gegevens' }],
			},
		})

		expect(
			response.status(),
			'an anonymous caller was allowed to write a page layout',
		).not.toBe(200)

		await anonymous.dispose()
	})

	test('a re-cut of an override that is not there is refused, not invented', async ({
		request,
	}) => {
		const response = await request.post(`${API}/pl-bestaat-niet/recut`, {
			headers: HEADERS,
		})

		test.skip(response.status() === 404, 'buildiq is not installed here')

		// A 200 here would mean the endpoint created something to re-cut, which
		// is how a layout nobody authored ends up on a page.
		expect(response.status()).not.toBe(200)
	})
})
