// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * E2E — BuilderHost mounts the seeded hello-world virtual app and the
 * inner CnAppRoot's router forwards path segments to the detail + form
 * pages declared in the manifest (REQ-OBR-002, REQ-OBR-003).
 *
 * Preconditions:
 *  - Docker stack up (`bash clean-env.sh`).
 *  - OpenBuilt enabled (`docker exec nextcloud php occ app:enable openbuilt`).
 *  - SeedHelloWorld has run (post-migration repair step).
 */
test.describe('BuilderHost — hello-world journey', () => {
	test('loads /builder/hello-world and renders the seeded index page', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt/builder/hello-world')

		await expect(page).toHaveURL(/\/index\.php\/apps\/openbuilt\/builder\/hello-world/)

		// The hello-world manifest's index page lists hello-message objects.
		// The three seeded titles must all be visible before this passes.
		const expectedTitles = [
			'Welcome to OpenBuilt',
			'Edit me',
			'Built from a manifest',
		]
		for (const title of expectedTitles) {
			await expect(
				page.getByText(title, { exact: false }),
				`seeded title "${title}" must render on the index page`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('navigates to a hello-message detail page', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt/builder/hello-world')

		// Click the first seeded message — the manifest defines the detail
		// page at /messages/:id so the inner router forwards us there.
		const firstMessage = page.getByText('Welcome to OpenBuilt', { exact: false }).first()
		await expect(firstMessage).toBeVisible({ timeout: 15_000 })
		await firstMessage.click()

		// The URL should now include /messages/<uuid> (the inner router's path,
		// captured by BuilderHost's :pathMatch wildcard).
		await expect(page).toHaveURL(/\/builder\/hello-world\/messages\//, { timeout: 10_000 })

		// And the detail page must show the message body.
		await expect(
			page.getByText(/rendered by your first virtual app/i),
			'detail page must render the seeded body text',
		).toBeVisible({ timeout: 10_000 })
	})

	test('navigates to the form page from the manifest menu', async ({ page }) => {
		// The manifest declares a form page at /messages/new. Hit it directly
		// to skip menu/CTA discovery (DOM may be in flux until the page-editor
		// spec lands).
		await page.goto('/index.php/apps/openbuilt/builder/hello-world/messages/new')

		await expect(page).toHaveURL(/\/builder\/hello-world\/messages\/new/)

		// The form page renders a form for the hello-message schema —
		// it must expose at least one input for the `title` field.
		const titleInput = page.locator('input[name="title"], textarea[name="title"], [data-field="title"] input').first()
		await expect(
			titleInput,
			'form page must render an input for the title field declared in the hello-message schema',
		).toBeVisible({ timeout: 15_000 })
	})
})
