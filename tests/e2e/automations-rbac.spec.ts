/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change `automation-designer`,
 * task 7.7 — REQ-AUTD-008 (pattern of `rbac-403.spec.ts`): an editor may
 * author + enable an automation on a draft (non-production) version; the
 * same editor gets 403 enabling on the production version; an owner
 * succeeds where the editor was rejected.
 *
 * Pre-conditions (mirrors rbac-403.spec.ts's Newman Setup folder
 * assumptions): test users `rbac-editor` / `rbac-owner` exist and hold
 * `editors` / `owners` roles respectively on the seeded `hello-world`
 * Application's `permissions` block.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 * CI-run only — not executed in this session (no deploy to the shared dev
 * instance per project policy).
 */

import { test, expect, type Page } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NC_BASE_URL ?? process.env.NEXTCLOUD_URL ?? 'http://localhost:8080'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'
const OWNER_USER = process.env.NC_RBAC_OWNER_USER ?? 'rbac-owner'
const OWNER_PASS = process.env.NC_RBAC_OWNER_PASS ?? 'RbacOwner-1!'
const APP_SLUG = process.env.NC_RBAC_TEST_SLUG ?? 'hello-world'

/**
 * Log a fresh browser context into Nextcloud as the supplied user (mirrors
 * rbac-403.spec.ts's `loginAs` — we need a non-admin session so the
 * PermissionResolver check actually runs, not an admin-bypass shortcut).
 *
 * @param page Playwright page object.
 * @param user The username to log in.
 * @param pass The user's password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${NEXTCLOUD_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} appears to have failed — still on ${page.url()}.`)
	}
}

test.describe('automation-designer — RBAC (REQ-AUTD-008)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test('editor authors + enables an automation on a non-production (draft) version', async ({ page }) => {
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/automations`)
		await page.waitForSelector('.automations-page', { timeout: 20_000 })

		await page.getByLabel(/application/i).click()
		await page.getByRole('option', { name: new RegExp(APP_SLUG, 'i') }).first().click()
		await page.getByLabel(/version/i).click()
		await page.getByRole('option', { name: /development|draft/i }).first().click()

		await page.getByRole('button', { name: /new automation/i }).click()
		await page.getByLabel(/^name$/i).fill('RBAC editor draft automation')
		await page.getByRole('button', { name: /add action/i }).click()
		await page.getByLabel(/subject \(english\)/i).fill('x')
		await page.getByRole('button', { name: /^save$/i }).click()
		await expect(page.locator('.automation-edit')).toHaveCount(0, { timeout: 10_000 })

		const row = page.locator('[data-testid="automation-row"]', { hasText: 'RBAC editor draft automation' })
		await expect(row).toBeVisible()
		const toggle = row.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]').first()
		await toggle.click()
		// No error note card on a non-production enable.
		await expect(page.locator('.ncnotecard-stub, [class*="note-card"][class*="error"]')).toHaveCount(0)
	})

	test('editor gets 403 enabling on the production version; owner succeeds', async ({ page, browser }) => {
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/automations`)
		await page.waitForSelector('.automations-page', { timeout: 20_000 })

		await page.getByLabel(/application/i).click()
		await page.getByRole('option', { name: new RegExp(APP_SLUG, 'i') }).first().click()
		await page.getByLabel(/version/i).click()
		await page.getByRole('option', { name: /production/i }).first().click()

		const row = page.locator('[data-testid="automation-row"]').first()
		await expect(row).toBeVisible({ timeout: 10_000 })
		const toggle = row.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]').first()

		const responsePromise = page.waitForResponse((resp) => resp.url().includes('/api/automations/') && resp.url().includes('/enable'))
		await toggle.click()
		const response = await responsePromise
		expect(response.status()).toBe(403)

		// Same automation, owner session: succeeds.
		const ownerContext = await browser.newContext()
		const ownerPage = await ownerContext.newPage()
		await loginAs(ownerPage, OWNER_USER, OWNER_PASS)
		await ownerPage.goto(`${NEXTCLOUD_URL}/apps/openbuild/automations`)
		await ownerPage.waitForSelector('.automations-page', { timeout: 20_000 })
		await ownerPage.getByLabel(/application/i).click()
		await ownerPage.getByRole('option', { name: new RegExp(APP_SLUG, 'i') }).first().click()
		await ownerPage.getByLabel(/version/i).click()
		await ownerPage.getByRole('option', { name: /production/i }).first().click()

		const ownerRow = ownerPage.locator('[data-testid="automation-row"]').first()
		await expect(ownerRow).toBeVisible({ timeout: 10_000 })
		const ownerToggle = ownerRow.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]').first()
		const ownerResponsePromise = ownerPage.waitForResponse((resp) => resp.url().includes('/api/automations/') && resp.url().includes('/enable'))
		await ownerToggle.click()
		const ownerResponse = await ownerResponsePromise
		expect(ownerResponse.status()).toBe(200)

		await ownerContext.close()
	})
})
