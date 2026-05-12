// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * E2E — the public manifest endpoint contract (REQ-OBR-001).
 *
 * Authenticates as admin (httpCredentials in playwright.config.ts), GETs the
 * manifest endpoint for the seeded hello-world slug, and asserts the response
 * shape matches the canonical app-manifest schema:
 *  - top-level `version` (semver string)
 *  - top-level `menu` (array)
 *  - top-level `pages` (non-empty array; each item has id/route/type)
 *  - response is UNWRAPPED (no `data` / `error` envelope)
 *
 * Preconditions: see builder-host.spec.ts.
 */
test.describe('Manifest endpoint — canonical shape', () => {
	test('GET /api/applications/hello-world/manifest returns the seeded manifest', async ({ request }) => {
		const response = await request.get('/index.php/apps/openbuilt/api/applications/hello-world/manifest')

		expect(response.status(), 'manifest endpoint must return 200 for the seeded slug').toBe(200)

		const body = await response.json()

		// Envelope check — the manifest is returned UNWRAPPED so useAppManifest
		// in @conduction/nextcloud-vue can consume it directly.
		expect(body, 'response must not be wrapped in a `data` envelope').not.toHaveProperty('data')
		expect(body, 'response must not carry an `error` envelope on 200').not.toHaveProperty('error')

		// Canonical shape — version, menu, pages all present.
		expect(body).toHaveProperty('version')
		expect(typeof body.version, 'version must be a string').toBe('string')
		expect(body.version, 'version must look like semver').toMatch(/^\d+\.\d+\.\d+/)

		expect(body).toHaveProperty('menu')
		expect(Array.isArray(body.menu), 'menu must be an array').toBe(true)

		expect(body).toHaveProperty('pages')
		expect(Array.isArray(body.pages), 'pages must be an array').toBe(true)
		expect(body.pages.length, 'pages must be non-empty (hello-world seeds 3 pages)').toBeGreaterThan(0)

		// Each page must declare id/route/type — the minimal contract the
		// CnAppRoot router needs.
		for (const page of body.pages) {
			expect(page).toHaveProperty('id')
			expect(page).toHaveProperty('route')
			expect(page).toHaveProperty('type')
			expect(['index', 'detail', 'form', 'custom'], `unknown page type "${page.type}"`).toContain(page.type)
		}
	})

	test('GET /api/applications/no-such-slug/manifest returns 404 with not_found', async ({ request }) => {
		const response = await request.get('/index.php/apps/openbuilt/api/applications/no-such-slug/manifest')

		expect(response.status(), 'unknown slug must 404').toBe(404)

		const body = await response.json()
		expect(body, 'error envelope must carry not_found code').toEqual(
			expect.objectContaining({ error: 'not_found' }),
		)
	})
})
