/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end test for the OpenBuilt template gallery and
 * clone-from-template flow (REQ-OBTC-003, REQ-OBTC-004, REQ-OBTC-006,
 * REQ-OBTC-008).
 *
 * Scenario:
 *   1. Log in as admin via /login.
 *   2. Navigate to /apps/openbuilt/templates.
 *   3. Assert the four seeded template cards render.
 *   4. Click "Use this template" on a card.
 *   5. Fill the clone dialog with a new name + slug.
 *   6. Submit; assert the navigation lands on the page editor for the new app.
 *
 * NOTE: Playwright infrastructure is not yet wired into openbuilt's package
 * scripts. This file is the canonical e2e coverage for the spec and will
 * run once the cohort-wide Playwright bootstrap lands (mirroring the same
 * deferred-bootstrap pattern used by mydash).
 */

import { test, expect } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || process.env.NC_BASE_URL || 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

// Auth: globalSetup writes the storageState that every spec inherits
// (see tests/e2e/global-setup.ts + playwright.config.ts use.storageState).
// The legacy per-spec form login is gone — it was racing the NC
// brute-force throttle and is redundant against the shared session.
void ADMIN_USER
void ADMIN_PASS
void NEXTCLOUD_URL

test.describe('OpenBuilt template gallery', () => {

	test('lists the four seeded templates and clones one into a draft application', async ({ page }) => {
		// 1. Navigate to the gallery.
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuilt/templates`)

		// 2. Wait for the gallery shell to render.
		await expect(page.locator('.template-gallery')).toBeVisible({ timeout: 15_000 })

		// 3. Assert the four seeded cards are present.
		//    The card title (.template-card__title) must match each canonical slug's title.
		const cards = page.locator('.template-card')
		await expect(cards).toHaveCount(4, { timeout: 15_000 })

		const expectedTitles = [
			'Permit Tracker',
			'Stakeholder Consultation',
			'Employee Onboarding',
			'Incident Reporter',
		]
		for (const title of expectedTitles) {
			await expect(page.locator('.template-card__title', { hasText: title })).toBeVisible()
		}

		// 4. Click "Use this template" on the permit-tracker card.
		const permitCard = page
			.locator('.template-card')
			.filter({ has: page.locator('.template-card__title', { hasText: 'Permit Tracker' }) })
		await permitCard.getByRole('button', { name: /Use this template/i }).click()

		// 5. The clone dialog should open.
		const dialog = page.locator('.clone-dialog')
		await expect(dialog).toBeVisible({ timeout: 5_000 })

		// 6. Fill in name + slug.
		const newSlug = `e2e-permits-${Date.now().toString(36)}`
		await dialog.getByLabel(/Application name/i).fill('E2E permits')
		await dialog.getByLabel(/Slug/i).fill(newSlug)

		// 7. Submit — primary button labelled "Clone template".
		await dialog.getByRole('button', { name: /Clone template/i }).click()

		// 8. Assert the post-clone redirect lands on the editor surface.
		//    The page editor route (or its fallback ApplicationEditor) carries
		//    the new slug in the URL.
		await page.waitForURL((url) => url.toString().includes(newSlug), { timeout: 15_000 })
		expect(page.url()).toContain(newSlug)
	})

	test('filter by category narrows to government-services only', async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuilt/templates`)
		await expect(page.locator('.template-gallery')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.template-card')).toHaveCount(4, { timeout: 15_000 })

		// The category select is a NcSelect (vue-select) — click and pick the option.
		const filter = page.locator('.template-gallery__filters').locator('input[role="combobox"], input').last()
		await filter.click()
		await page.getByText('Government services', { exact: true }).click()

		// Only permit-tracker remains.
		await expect(page.locator('.template-card')).toHaveCount(1)
		await expect(page.locator('.template-card__title')).toHaveText(/Permit Tracker/)
	})
})
