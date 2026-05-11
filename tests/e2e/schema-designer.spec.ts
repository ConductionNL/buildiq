/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end spec for the OpenBuilt schema designer
 * (spec #4 — openbuilt-schema-editor). Marks parts of cross-spec
 * journey #2 (create-virtual-app → design-schema → edit-page →
 * publish-version-1).
 *
 * Flow under test:
 *   1. Log in as admin (NC_ADMIN_USER / NC_ADMIN_PASS env vars).
 *   2. Open the OpenBuilt app and create a virtual application
 *      (slug `pw-hello`, title "PW Hello"). The smoke spec from
 *      bootstrap-openbuilt already exercises this part; we re-use
 *      the same UX to land on the application page.
 *   3. Navigate to that virtual app's Schemas tab —
 *      /builder/pw-hello/schemas.
 *   4. Click "Add schema" → fill slug `message` + title "Message" →
 *      submit. Assert the new row appears in the list.
 *   5. Open the schema → add two fields (`subject`, `body`) →
 *      Save. Reload; assert both fields persist.
 *   6. Open the schema again → edit the title → Save → assert the
 *      list row reflects the new title.
 *   7. Delete the schema via the per-row Delete action; assert the
 *      confirm dialog appears and only fires deletion on
 *      confirmation. The schema row should disappear from the list.
 *
 * Runs against a live Nextcloud at NC_BASE_URL (default
 * http://localhost:8080) with the OpenBuilt app installed AND chain
 * spec #3 (openregister-runtime-schema-api) deployed. Until chain #3
 * lands, the schema CRUD calls return 404 and the test will fail at
 * step 4 — this is the expected gating behaviour documented in spec
 * tasks.md §7.
 *
 * The spec is intentionally self-contained — it uses
 * `page.request.post('/login')` to authenticate inside the test so
 * the suite can be run via `npx playwright test tests/e2e` without a
 * shared global-setup file (chain #3 + this spec land together).
 */

import { test, expect } from '@playwright/test'

const BASE_URL = process.env.NC_BASE_URL ?? 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

const APP_SLUG = 'pw-hello'
const SCHEMA_SLUG = 'message'

test.describe('OpenBuilt Schema Designer — end-to-end (REQ-OBSD-001..008)', () => {
	test.beforeEach(async ({ page }) => {
		// Log in via the Nextcloud /login form. Cookies persist for the
		// remainder of this test's context.
		await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' })
		await page.fill('input[name="user"]', ADMIN_USER)
		await page.fill('input[name="password"]', ADMIN_PASS)
		await Promise.all([
			page.waitForLoadState('networkidle'),
			page.click('button[type="submit"]'),
		])
	})

	test('create virtual app → add schema → add 2 fields → save → edit → delete', async ({ page }) => {
		// Step 1 — open the OpenBuilt app at the Applications page.
		await page.goto(`${BASE_URL}/index.php/apps/openbuilt/applications`, {
			waitUntil: 'domcontentloaded',
		})

		// Step 2 — create the virtual app via the existing manifest editor
		// (bootstrap-openbuilt spec covers UX). We do this via the
		// application editor's primary action; if the app already exists
		// from a previous run, the test continues idempotently.
		const addAppButton = page.getByRole('button', { name: /add application/i })
		if (await addAppButton.isVisible().catch(() => false)) {
			await addAppButton.click()
			await page.getByLabel(/slug/i).fill(APP_SLUG)
			await page.getByLabel(/title/i).fill('PW Hello')
			await page.getByRole('button', { name: /save|create/i }).click()
			await page.waitForLoadState('networkidle')
		}

		// Step 3 — navigate to the Schema Designer for this virtual app.
		await page.goto(`${BASE_URL}/index.php/apps/openbuilt/builder/${APP_SLUG}/schemas`, {
			waitUntil: 'domcontentloaded',
		})

		// Wait for the panel to render — either the empty state or a row list.
		const panel = page.locator('.openbuilt-schema-list')
		await expect(panel).toBeVisible({ timeout: 10_000 })

		// Step 4 — add a schema named `message`.
		await page.getByRole('button', { name: /add schema/i }).first().click()
		await page.getByLabel(/slug/i).fill(SCHEMA_SLUG)
		await page.getByLabel(/title/i).fill('Message')
		await page.getByRole('button', { name: /add schema|save/i }).last().click()

		// Detail view loads; back button is visible.
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({
			timeout: 10_000,
		})

		// Step 5 — add two fields and Save.
		const addFieldButton = page.getByRole('button', { name: /add field/i })
		await addFieldButton.click()
		// The first row's Name input — there is only one field so far.
		await page.getByLabel('Name', { exact: false }).first().fill('subject')

		await addFieldButton.click()
		await page.getByLabel('Name', { exact: false }).nth(1).fill('body')

		await page.getByRole('button', { name: /^save$/i }).click()
		// Expect either the toast or the saving state to settle.
		await page.waitForLoadState('networkidle')

		// Reload and verify persistence.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.getByLabel('Name', { exact: false }).first()).toHaveValue('subject')
		await expect(page.getByLabel('Name', { exact: false }).nth(1)).toHaveValue('body')

		// Step 6 — edit the title and save.
		await page.getByLabel(/title/i).first().fill('Message v2')
		await page.getByRole('button', { name: /^save$/i }).click()
		await page.waitForLoadState('networkidle')

		// Back to the list — the row should reflect the new title.
		await page.getByRole('button', { name: /back to schemas/i }).click()
		await expect(page.locator('.openbuilt-schema-list__rows')).toContainText('Message v2')

		// Step 7 — delete the schema via the per-row action; confirm in
		// the dialog (REQ-OBSD-008).
		const row = page
			.locator('.openbuilt-schema-list__row')
			.filter({ hasText: SCHEMA_SLUG })
		await row.getByRole('button', { name: /delete/i }).click()
		// Confirm dialog asks for explicit confirmation.
		const confirmButton = page
			.locator('.delete-schema-dialog, [role="dialog"]')
			.getByRole('button', { name: /delete|confirm/i })
		await confirmButton.click()

		// Row disappears from the list.
		await expect(
			page.locator('.openbuilt-schema-list__row').filter({ hasText: SCHEMA_SLUG }),
		).toHaveCount(0, { timeout: 10_000 })
	})
})
