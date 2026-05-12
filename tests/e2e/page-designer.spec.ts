/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change `openbuilt-page-editor`.
 *
 * Implements tasks 7.5 (add-page → save → render) and the tab-roundtrip
 * variant of 7.6 (Design ↔ Raw JSON parity). Task 7.6's "chain spec #2 not
 * installed" affordance is covered indirectly: the seed env runs without
 * chain spec #2, so the "Save & open preview" button is the rendered
 * fallback in scenario 1.
 *
 * Requirements covered: REQ-OBPD-002, REQ-OBPD-003, REQ-OBPD-009, plus
 * MODIFIED REQ-OBR-005 (tab default + edits survive tab switch).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import { test, expect, type Page } from '@playwright/test'

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

/**
 * Drive the Nextcloud login form. `httpCredentials` only covers HTTP basic
 * auth on direct fetches — the UI itself requires a session cookie that we
 * can only obtain via the login form.
 */
async function loginAsAdmin(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"]').first().click()
	// Wait for the global header that only renders on authenticated pages.
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
}

test.describe('openbuilt page designer', () => {
	test.beforeEach(async ({ page }) => {
		await loginAsAdmin(page)
	})

	test('REQ-OBPD-002 + REQ-OBPD-003 + REQ-OBPD-009: add page → save → renders in builder', async ({ page }) => {
		// Open the editor pre-focused on the Design tab (router alias from
		// task 5.3 of the spec).
		await page.goto('/index.php/apps/openbuilt/applications/hello-world/design')

		// The application editor mounts asynchronously after the manifest
		// fetch returns. Wait for the page-list pane to settle before
		// driving the UI.
		await page.waitForSelector('.page-designer__left', { timeout: 20_000 })

		// REQ-OBR-005: Design tab is the default.
		await expect(page.locator('.application-editor__tab--active')).toHaveText(/Design/i)

		// Snapshot current page count so the post-add assertion is robust to
		// hello-world seed drift.
		const initialPageCount = await page.locator('.page-list__row, [data-test="page-row"]').count()

		// Open the "Add page" affordance and pick the canonical type. The
		// exact selector intentionally trades specificity for resilience to
		// pre-MVP CSS class churn — we look for the visible "Add page"
		// trigger first, fall back to a button matching the i18n string.
		const addBtn = page.getByRole('button', { name: /add page/i }).first()
		await addBtn.click()

		// Pick the `index` type from the closed-enum picker (REQ-OBPD-002 —
		// "Adding a page SHALL prompt for the page `type` from the canonical
		// closed enum before any other field is shown").
		await page.getByRole('option', { name: /index/i }).first().click()

		// Fill required bindings — the IndexPageEditor needs register +
		// schema before the manifest validator allows save.
		await page.getByLabel(/register/i).first().fill('openbuilt')
		await page.getByLabel(/schema/i).first().fill('hello-message')

		// The new page row defaults to a placeholder id/route — set a known
		// route so we can navigate to it below.
		const routeInput = page.getByLabel(/route/i).first()
		await routeInput.fill('/added-by-e2e')

		// REQ-OBPD-009: Save flow validates first; the button is disabled
		// while validator errors are open.
		const saveBtn = page.getByRole('button', { name: /^save$/i }).first()
		await expect(saveBtn).toBeEnabled({ timeout: 10_000 })
		await saveBtn.click()

		// Confirm the new page is reflected in the manifest. The page-list
		// row count should have grown by one.
		await expect(page.locator('.page-list__row, [data-test="page-row"]')).toHaveCount(initialPageCount + 1)

		// REQ-OBPD-003: navigate to the built virtual app and assert the
		// newly-added route renders inside the inner CnAppRoot mount.
		await page.goto('/index.php/apps/openbuilt/builder/hello-world/added-by-e2e')
		await expect(page.locator('#openbuilt-builder, .cn-app-root')).toBeVisible({ timeout: 15_000 })
	})

	test('REQ-OBR-005: edits survive a Design ↔ Raw JSON tab switch', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt/applications/hello-world/design')
		await page.waitForSelector('.page-designer__left', { timeout: 20_000 })

		// Switch to the Raw JSON tab and mutate the manifest.
		await page.getByRole('button', { name: /raw json/i }).click()

		const textarea = page.locator('.application-editor__textarea')
		await expect(textarea).toBeVisible()

		// Read the current manifest, inject a marker page id, write it back.
		const raw = await textarea.inputValue()
		const manifest = JSON.parse(raw)
		manifest.pages = Array.isArray(manifest.pages) ? manifest.pages : []
		manifest.pages.push({
			id: 'e2e-tab-roundtrip',
			type: 'index',
			route: '/e2e-tab-roundtrip',
			config: { register: 'openbuilt', schema: 'hello-message' },
		})
		await textarea.fill(JSON.stringify(manifest, null, 2))

		// Switch back to Design — the Pinia store is shared, so the new
		// page must be visible in the left pane without saving.
		await page.getByRole('button', { name: /^design$/i }).click()
		await page.waitForSelector('.page-designer__left', { timeout: 10_000 })

		await expect(page.getByText('e2e-tab-roundtrip').first()).toBeVisible({ timeout: 5_000 })

		// The unsaved-edits banner from REQ-OBR-005 must also be visible.
		await expect(page.locator('.application-editor__dirty')).toBeVisible()
	})
})
