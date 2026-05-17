// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * End-to-end smoke test for the bootstrap-openbuilt change.
 *
 * Boots the seeded hello-world virtual app inside the OpenBuilt shell
 * at /index.php/apps/openbuilt/builder/hello-world and asserts the
 * canonical index page renders the three sample HelloMessage objects
 * created by the SeedHelloWorld repair step.
 *
 * Preconditions (one-time setup):
 *  - Docker stack up (`bash clean-env.sh` or `/clean-env` skill).
 *  - OpenBuilt app enabled (`docker exec nextcloud php occ app:enable openbuilt`).
 *  - Playwright browsers installed (`npx playwright install --with-deps`).
 */
test.describe('bootstrap-openbuilt hello-world', () => {
	test('renders the three seeded hello-message objects on the index page', async ({ page }) => {
		await page.goto('/apps/openbuilt/builder/hello-world')

		// The SPA needs a moment to fetch the manifest and resolve the index page.
		// The hello-world manifest's index page lists `hello-message` objects with
		// the title, body and @self.created columns.
		await expect(page).toHaveURL(/\/index\.php\/apps\/openbuilt\/builder\/hello-world/)

		// Seed bodies — anchored on the canonical strings written by
		// SeedHelloWorld::buildSampleMessages(). At minimum the page must
		// render the three known titles before the smoke test passes.
		const expectedTitles = [
			'Welcome to OpenBuilt',
			'Edit me',
			'Built from a manifest',
		]

		for (const title of expectedTitles) {
			await expect(
				page.getByText(title, { exact: false }),
				`expected the seeded hello-message titled "${title}" to render on the index page`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('returns the seeded manifest from the public endpoint', async ({ request }) => {
		const response = await request.get('/index.php/apps/openbuilt/api/applications/hello-world/manifest')
		expect(response.status(), 'manifest endpoint must return 200 for the seeded slug').toBe(200)

		const body = await response.json()
		expect(body).toHaveProperty('version')
		expect(body).toHaveProperty('menu')
		expect(body).toHaveProperty('pages')
		expect(Array.isArray(body.pages)).toBe(true)
		expect(body.pages.length).toBeGreaterThan(0)
	})
})
