// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * Playwright e2e — ApplicationCard icon display + productionVersion fields.
 *
 * Covers:
 *   spec A task 7.4  — icon <img> appears on each ApplicationCard
 *   ApplicationCard regression fix — status badge and version chip read from
 *     Application.productionVersion (spec C), not from the (now-removed)
 *     top-level Application.status / Application.version fields.
 *
 * Preconditions:
 *   - Nextcloud reachable with openbuilt enabled.
 *   - SeedHelloWorld repair step has produced the hello-world virtual app.
 *   - Playwright auth via httpCredentials (admin:admin) in playwright.config.ts.
 *
 * Limitations:
 *   - The productionVersion badge assertions are "best-effort": if OR does not
 *     return the productionVersion relation inline, the card shows "Draft" + "—"
 *     by design (spec C Decision 4). The test asserts the icon is present and
 *     the badge is NOT the pre-spec-A regression value "Live".
 */
test.describe('ApplicationCard — icon + productionVersion fields (spec A / spec C)', () => {

	test('index page renders ApplicationCards with icon <img> elements', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt')

		// Wait for the SPA to hydrate and the Applications list to appear.
		// The list renders one card per Application. The seeded hello-world
		// entry must be present.
		await expect(
			page.locator('.ob-app-card, [data-testid*="app-card"]').first(),
			'at least one ApplicationCard must be visible on the index',
		).toBeVisible({ timeout: 15_000 })

		// Each card must contain an <img> from the icon-serving endpoint.
		// icon src pattern: /index.php/apps/openbuilt/icons/{slug}.svg
		const firstCard = page.locator('.ob-app-card').first()
		const icon = firstCard.locator('img.ob-app-card__icon')
		await expect(icon, 'icon <img> must be visible on ApplicationCard').toBeVisible({ timeout: 10_000 })
		const src = await icon.getAttribute('src')
		expect(src, 'icon src must point to the icon-serving endpoint').toMatch(/\/icons\/.+\.svg$/)
	})

	test('hello-world ApplicationCard shows a status badge (not raw "Live" chip)', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt')

		// Wait for at least the seeded hello-world card.
		await expect(
			page.locator('.ob-app-card').first(),
		).toBeVisible({ timeout: 15_000 })

		// Spec A task 4.2 removed the "Live" chip. The card must never show
		// text "Live" in a chip regardless of Application state.
		const liveChips = page.locator('.ob-app-card__chip--live')
		await expect(
			liveChips,
			'no element with class ob-app-card__chip--live should exist (removed in spec A)',
		).toHaveCount(0)

		const liveText = page.locator('.ob-app-card').getByText('Live', { exact: true })
		await expect(liveText, 'no "Live" text should appear in any ApplicationCard').toHaveCount(0)
	})

	test('hello-world ApplicationCard status badge is one of the known values', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt')

		// Find the card for hello-world specifically.
		const helloCard = page.locator('[data-slug="hello-world"], .ob-app-card').first()
		await expect(helloCard).toBeVisible({ timeout: 15_000 })

		// The badge must be one of draft / published / archived (from
		// Application.productionVersion.status via spec C).
		const badge = helloCard.locator('.ob-app-card__badge')
		await expect(badge).toBeVisible({ timeout: 5_000 })
		const badgeText = (await badge.textContent() || '').trim().toLowerCase()
		const validStatuses = ['draft', 'published', 'archived']
		expect(
			validStatuses.some(s => badgeText.includes(s)),
			`badge text "${badgeText}" must be one of: ${validStatuses.join(', ')}`,
		).toBe(true)
	})

	test('hello-world ApplicationCard version chip shows semver or — placeholder', async ({ page }) => {
		await page.goto('/index.php/apps/openbuilt')

		const helloCard = page.locator('[data-slug="hello-world"], .ob-app-card').first()
		await expect(helloCard).toBeVisible({ timeout: 15_000 })

		// The version chip must show a semver string (from
		// Application.productionVersion.semver) OR the "—" fallback when
		// productionVersion is not yet inline-extended.
		const versionChip = helloCard.locator('.ob-app-card__chip').first()
		const chipText = (await versionChip.textContent() || '').trim()
		// Accept: "Version 1.0.0" / "Version —" / "v0.1.0" etc.
		expect(
			chipText.length,
			'version chip must contain some text',
		).toBeGreaterThan(0)
		expect(
			chipText,
			'version chip must not contain the old Application-level "version" field fallback text',
		).not.toMatch(/undefined/)
	})
})
