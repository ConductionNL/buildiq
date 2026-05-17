// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * Playwright e2e — PromoteVersionDialog destructive-confirmation gate (spec D task 5.1 / 5.2).
 *
 * Covers:
 *   5.1  (locked prompt constraint):
 *     - Admin opens the dialog, selects "empty-start", Confirm is DISABLED.
 *     - Admin types WRONG slug → Confirm still DISABLED.
 *     - Admin types EXACT slug → Confirm ENABLES.
 *     - Clicking Confirm fires the confirm event with {strategy: "empty-start"}.
 *   5.2  For start-with-source-data and migrate-existing-data, Confirm is
 *     enabled by default (no destructive gate).
 *
 * Call-site dependency:
 *   PromoteVersionDialog is a standalone modal (ADR-004 modal-isolation) that
 *   requires a call site in the Application detail page (spec B /
 *   openbuilt-app-detail-overview). Since spec B has not shipped yet, this
 *   test file is written in a describe.skip block for the e2e tests that need
 *   a live call site, so the file lands in the repo and tracks the gap.
 *
 *   The component unit tests in tests/dialogs/PromoteVersionDialog.spec.js
 *   cover the gate logic in isolation (no live NC needed).
 *
 * To activate these tests:
 *   1. Merge spec B (openbuilt-app-detail-overview) which adds a "Promote"
 *      button / action in the ApplicationVersion list.
 *   2. Remove the describe.skip wrapper and replace the TODO_PROMOTE_BUTTON
 *      selector with the actual trigger selector.
 */

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'hello-world'

async function loginAs(page: import('@playwright/test').Page, user: string, pass: string): Promise<void> {
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
}

// ---------------------------------------------------------------------------
// Tests that require a live call site — SKIPPED until spec B ships
// ---------------------------------------------------------------------------
test.describe.skip('PromoteVersionDialog — e2e with live call site (pending spec B / openbuilt-app-detail-overview)', () => {

	// TODO: Replace this selector with the actual Promote button once spec B
	// wires the dialog into the ApplicationVersion list in the detail page.
	const TODO_PROMOTE_BUTTON_SELECTOR = '[data-testid="promote-version-btn"], button:has-text("Promote")'

	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'admin', 'admin')
	})

	test('5.1 — empty-start: Confirm is disabled until exact slug is typed', async ({ page }) => {
		// Navigate to the detail page for hello-world.
		await page.goto(`${BASE}/index.php/apps/openbuilt/builder/${TEST_SLUG}`)
		await page.waitForLoadState('networkidle', { timeout: 20_000 })

		// Open the Promote dialog (call site added by spec B).
		const promoteBtn = page.locator(TODO_PROMOTE_BUTTON_SELECTOR).first()
		await expect(promoteBtn).toBeVisible({ timeout: 10_000 })
		await promoteBtn.click()

		// Verify the dialog is open.
		const dialog = page.locator('[role="dialog"]')
		await expect(dialog).toBeVisible({ timeout: 5_000 })

		// Select the "empty-start" strategy radio.
		const emptyStartRadio = dialog.locator('input[type="radio"][value="empty-start"]')
			.or(dialog.getByText(/empty start/i))
		await emptyStartRadio.click()

		// Confirm button must be DISABLED with empty input.
		const confirmBtn = dialog.getByRole('button', { name: /promote|confirm/i })
		await expect(confirmBtn, 'Confirm must be disabled when empty-start is selected and input is empty').toBeDisabled()

		// Type wrong slug.
		const slugInput = dialog.locator('input[type="text"]').last()
		await slugInput.fill('wrong-slug')
		await expect(confirmBtn, 'Confirm must still be disabled with wrong slug').toBeDisabled()

		// Clear and type exact slug.
		await slugInput.fill('')
		await slugInput.fill(TEST_SLUG)
		await expect(confirmBtn, 'Confirm must be enabled when exact app slug is typed').toBeEnabled()

		// Click Confirm — the dialog emits confirm event and closes.
		await confirmBtn.click()
		// Dialog should close or the action should be dispatched.
		await expect(dialog).not.toBeVisible({ timeout: 5_000 })
	})

	test('5.2 — start-with-source-data: Confirm is enabled by default', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/openbuilt/builder/${TEST_SLUG}`)
		await page.waitForLoadState('networkidle', { timeout: 20_000 })

		const promoteBtn = page.locator(TODO_PROMOTE_BUTTON_SELECTOR).first()
		await promoteBtn.click()

		const dialog = page.locator('[role="dialog"]')
		await expect(dialog).toBeVisible({ timeout: 5_000 })

		// start-with-source-data should be available.
		const startWithSourceRadio = dialog.locator('input[type="radio"][value="start-with-source-data"]')
		if (await startWithSourceRadio.count() > 0) {
			await startWithSourceRadio.click()
		}

		const confirmBtn = dialog.getByRole('button', { name: /promote|confirm/i })
		await expect(confirmBtn, 'Confirm must be enabled by default for start-with-source-data').toBeEnabled()
	})

	test('5.2 — migrate-existing-data: Confirm is enabled by default', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/openbuilt/builder/${TEST_SLUG}`)
		await page.waitForLoadState('networkidle', { timeout: 20_000 })

		const promoteBtn = page.locator(TODO_PROMOTE_BUTTON_SELECTOR).first()
		await promoteBtn.click()

		const dialog = page.locator('[role="dialog"]')
		await expect(dialog).toBeVisible({ timeout: 5_000 })

		const migrateRadio = dialog.locator('input[type="radio"][value="migrate-existing-data"]')
		if (await migrateRadio.count() > 0) {
			await migrateRadio.click()
		}

		const confirmBtn = dialog.getByRole('button', { name: /promote|confirm/i })
		await expect(confirmBtn, 'Confirm must be enabled by default for migrate-existing-data').toBeEnabled()
	})
})

// ---------------------------------------------------------------------------
// Component-smoke test via the router (no describe.skip — runs immediately)
// ---------------------------------------------------------------------------
test.describe('PromoteVersionDialog — component available (static assertion)', () => {
	test('PromoteVersionDialog.vue exists in src/dialogs/ (ADR-004 modal-isolation)', async ({}) => {
		// This is a file-system assertion: confirm the dialog lives in the
		// correct location per ADR-004. No browser needed.
		const fs = await import('fs/promises')
		const path = await import('path')
		const dialogPath = path.resolve(
			process.cwd(),
			'src/dialogs/PromoteVersionDialog.vue',
		)
		const exists = await fs.stat(dialogPath).then(() => true).catch(() => false)
		expect(exists, 'PromoteVersionDialog.vue must be in src/dialogs/ (ADR-004)').toBe(true)
	})
})
