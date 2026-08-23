// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * End-to-end smoke test for the bootstrap-buildiq change.
 *
 * Boots the seeded hello-world virtual app inside the Buildiq shell
 * at /index.php/apps/buildiq/builder/hello-world and asserts the
 * canonical index page renders the three sample HelloMessage objects
 * created by the SeedHelloWorld repair step.
 *
 * Preconditions (one-time setup):
 *  - Docker stack up (`bash clean-env.sh` or `/clean-env` skill).
 *  - Buildiq app enabled (`docker exec nextcloud php occ app:enable buildiq`).
 *  - Playwright browsers installed (`npx playwright install --with-deps`).
 */
test.describe('bootstrap-buildiq hello-world', () => {
	// UN-QUARANTINED 2026-08-06. The recorded reason — "#41: builder host blank
	// (BuilderHostView unresolved by nc-vue CnPageRenderer)" — is the SAME
	// sentence builder-host.spec.ts carries above its own un-quarantine note
	// saying it "no longer holds". builder-host.spec.ts's first test performs
	// this identical journey (goto /apps/buildiq/builder/hello-world, then
	// assert the same three seeded titles) and PASSES in CI — measured in run
	// 31083894467. One of the two files was simply never revisited.
	test('renders the three seeded hello-message objects on the index page', async ({
		page,
	}) => {
		await page.goto('/apps/buildiq/builder/hello-world')

		// The SPA needs a moment to fetch the manifest and resolve the index page.
		// The hello-world manifest's index page lists `hello-message` objects with
		// the title, body and @self.created columns.
		//
		// The `/index.php` prefix is OPTIONAL and is not what this test is about.
		// Nextcloud emits it only when `htaccess.IgnoreFrontController` is off;
		// CI turns it ON (tests/e2e/ci-seed.sh gates on the served page reporting
		// `modRewriteWorking:true`), so the pretty form is what the router
		// produces there and the old anchored regex could not have matched. The
		// app path is still asserted in full — only the webroot style, an
		// instance-configuration artifact, is allowed to vary.
		await expect(page).toHaveURL(
			/(\/index\.php)?\/apps\/buildiq\/builder\/hello-world/,
		)

		// Seed bodies — anchored on the canonical strings written by
		// SeedHelloWorld::buildSampleMessages(). At minimum the page must
		// render the three known titles before the smoke test passes.
		const expectedTitles = [
			'Welcome to Buildiq',
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

	// MOVED TO NEWMAN: asserts on the manifest API response, not the UI. The
	// API/contract is covered by tests/integration/*.postman_collection.json
	// ("GET hello-world manifest returns 200 with version/menu/pages").
	// Playwright is UI-only.
	test.skip('returns the seeded manifest from the public endpoint', async ({
		request,
	}) => {
		const response = await request.get(
			'/index.php/apps/buildiq/api/applications/hello-world/manifest',
		)
		expect(
			response.status(),
			'manifest endpoint must return 200 for the seeded slug',
		).toBe(200)

		const body = await response.json()
		expect(body).toHaveProperty('version')
		expect(body).toHaveProperty('menu')
		expect(body).toHaveProperty('pages')
		expect(Array.isArray(body.pages)).toBe(true)
		expect(body.pages.length).toBeGreaterThan(0)
	})
})
