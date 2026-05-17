/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Playwright end-to-end test for the openbuilt-versioning publish + rollback
 * cycle. Walks an admin user through:
 *
 *   1. login → open the hello-world Application editor
 *   2. make a small manifest edit (bump version field) and Publish
 *      → a v1.1.0 ApplicationVersion appears in the version-history panel
 *      → version-history now lists 2 versions (the seed v1.0.0 + v1.1.0)
 *   3. click Rollback on v1.0.0 → confirm modal → confirm
 *      → version-history now lists 3 versions (rollback row appended;
 *        history is append-only per design.md Decision 3)
 *
 * Pre-conditions assumed by this spec:
 *   - Nextcloud reachable at NC_BASE_URL (default http://localhost:8080) with
 *     openbuilt enabled.
 *   - SeedHelloWorld repair step has produced the hello-world Application
 *     AND its initial v1.0.0 ApplicationVersion (per tests/integration/
 *     openbuilt-versioning.postman_collection.json Setup step).
 *   - admin/admin credentials (dev compose memory rule).
 *
 * Running:
 *   npx playwright test tests/e2e/version-rollback.spec.ts
 *
 * Workers are 1 — the editor mutates the seed Application's manifest, and
 * parallel runs would race on the shared seed state. The Newman teardown
 * step at the end of the versioning postman collection restores manifest
 * + version table back to a known-good baseline.
 */

import { test, expect, type Page } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NC_BASE_URL ?? process.env.NEXTCLOUD_URL ?? 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'
const TEST_SLUG = process.env.NC_VERSIONING_TEST_SLUG ?? 'hello-world'

/**
 * Log into Nextcloud with a fresh context (no storageState).
 *
 * @param page Playwright page object.
 * @param user Username.
 * @param pass Password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${NEXTCLOUD_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} failed — still on ${page.url()}.`)
	}
}

test.describe('openbuilt-versioning — publish + rollback (REQ-OBV-005 / REQ-OBR-009)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeEach(async ({ page }) => {
		await loginAs(page, ADMIN_USER, ADMIN_PASS)
	})

	test('publish a manifest edit then roll back to v1.0.0 — history grows append-only', async ({ page }) => {
		// Step 1 — open the openbuilt shell + navigate to the hello-world editor.
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuilt/applications`)
		// The list-page renders an entry per Application. Click the seeded
		// row's edit affordance — accept either a [data-slug] anchor or the
		// rendered slug text.
		const editorEntry = page.locator(`[data-slug="${TEST_SLUG}"], a:has-text("${TEST_SLUG}")`).first()
		await editorEntry.click({ timeout: 15_000 })
		await expect(page.getByText(/Hello World/i).first()).toBeVisible({ timeout: 10_000 })

		// Step 2 — bump the manifest's `version` field. We use the editor's
		// textarea (a JSON edit surface) and patch the version string in place.
		const manifestField = page.locator('textarea, [contenteditable="true"]').first()
		await expect(manifestField).toBeVisible({ timeout: 10_000 })
		const original = await manifestField.inputValue().catch(() => '')
		const patched = (original || '{}').replace(/"version"\s*:\s*"1\.0\.0"/, '"version": "1.1.0"')
		await manifestField.fill(patched)

		// Step 3 — Publish.
		const publishBtn = page.getByRole('button', { name: /publish/i }).first()
		await publishBtn.click()
		// Tolerate either an in-place success toast or an async refresh.
		await page.waitForTimeout(1_500)

		// Step 4 — version history panel now lists 2 versions (seed + new).
		// Each version row carries the version string in its visible text;
		// we count by version-history__row CSS class (component contract).
		const rows = page.locator('.version-history__row')
		await expect(rows).toHaveCount(2, { timeout: 15_000 })
		await expect(page.getByText(/1\.1\.0/).first()).toBeVisible()

		// Step 5 — click Rollback on the v1.0.0 row. The bottom row is the
		// oldest (seed v1.0.0) since the list is sorted newest-first.
		const oldRow = rows.last()
		const rollbackBtn = oldRow.locator('.version-history__btn--danger').first()
		await rollbackBtn.click()

		// Step 6 — confirm modal appears, click Roll back.
		// RollbackConfirmModal renders an NcDialog with two NcButtons. Match
		// by visible text — copy is "Roll back" (no ellipsis).
		const confirmBtn = page.getByRole('button', { name: /^roll back$/i }).first()
		await expect(confirmBtn).toBeVisible({ timeout: 10_000 })
		await confirmBtn.click()
		await page.waitForTimeout(1_500)

		// Step 7 — history now has 3 versions (append-only contract).
		await expect(rows).toHaveCount(3, { timeout: 15_000 })

		// Step 8 — the editor's manifest reflects the rollback (back to 1.0.0
		// shape). We re-read the textarea — version field should be 1.0.0
		// again (or a rollback-marker like 1.0.0-rb1 depending on the
		// implementation choice).
		const reloaded = await manifestField.inputValue().catch(() => '')
		expect(reloaded).toMatch(/"version"\s*:\s*"1\.0\.0/)
	})
})
