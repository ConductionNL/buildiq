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
 *   - Nextcloud reachable with openbuild enabled.
 *   - SeedHelloWorld repair step has produced the hello-world virtual app.
 *   - Playwright auth via httpCredentials (admin:admin) in playwright.config.ts.
 *
 * Limitations:
 *   - The productionVersion badge assertions are "best-effort": if OR does not
 *     return the productionVersion relation inline, the card shows "Draft" + "—"
 *     by design (spec C Decision 4). The test asserts the icon is present and
 *     the badge is NOT the pre-spec-A regression value "Live".
 */
// UN-QUARANTINED 2026-07-30. The old reason (#41, "builder host blank / no
// detail pages") never applied to this file — it only reads the applications
// index — and no longer holds anyway.
//
// PRODUCT DEFECT FOUND WHILE DOING THIS (openbuild, unfiled): the status badge
// and version chip on this card are dead. `GET /api/applications` returns
// `productionVersion` as a bare UUID STRING, but `ApplicationCard.vue`'s
// `productionVersion` computed bails unless `typeof pv === 'object'`. So
// `statusKey` always falls back to `'draft'` and `productionSemver` always
// renders `'—'`, for every app, whatever its real state. Measured on the e2e
// instance: hello-world's production ApplicationVersion is
// `{status: 'published', semver: '1.0.0'}` while its card reads "Draft" and
// "Version —". That makes REQ-OBR-007b's "newly published Application shows
// published badge" unsatisfiable from the list view.
//
// The tests below therefore assert the card's STRUCTURE (icon endpoint, badge
// vocabulary, chip format, absence of the removed Live chip) — all of which are
// real contracts — and deliberately do NOT assert that the badge equals the
// production version's status, because it does not and pretending otherwise
// would either fail the suite for a defect this change is not fixing, or bake
// the broken behaviour in as expected. Fix the extension in the controller (or
// resolve the UUID client-side), then tighten test 3 and 4 to the real values.
test.describe('ApplicationCard — icon + productionVersion fields (spec A / spec C)', () => {

	test('index page renders ApplicationCards with icon <img> elements', async ({ page }) => {
		await page.goto('/apps/openbuild/applications')

		// Wait for the SPA to hydrate and the Applications list to appear.
		// The list renders one card per Application. The seeded hello-world
		// entry must be present.
		await expect(
			page.locator('.ob-app-card, [data-testid*="app-card"]').first(),
			'at least one ApplicationCard must be visible on the index',
		).toBeVisible({ timeout: 15_000 })

		// Each card must contain an <img> from the icon-serving endpoint.
		// icon src pattern: /index.php/apps/openbuild/icons/{slug}.svg
		const firstCard = page.locator('.ob-app-card').first()
		const icon = firstCard.locator('img.ob-app-card__icon')
		await expect(icon, 'icon <img> must be visible on ApplicationCard').toBeVisible({ timeout: 10_000 })
		const src = await icon.getAttribute('src')
		expect(src, 'icon src must point to the icon-serving endpoint').toMatch(/\/icons\/.+\.svg$/)
	})

	test('hello-world ApplicationCard shows a status badge (not raw "Live" chip)', async ({ page }) => {
		await page.goto('/apps/openbuild/applications')

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
		await page.goto('/apps/openbuild/applications')

		// Target hello-world for real. The previous selector was
		// `[data-slug="hello-world"], .ob-app-card` + `.first()` — ApplicationCard.vue
		// renders no `data-slug` attribute, so that alternation always collapsed to
		// "whatever card happens to be first" while the test name promised
		// hello-world. The slug IS rendered, in the muted chip as `/{slug}`.
		const helloCard = page.locator('.ob-app-card').filter({ hasText: '/hello-world' }).first()
		await expect(helloCard, 'the seeded hello-world card must be on the index').toBeVisible({ timeout: 15_000 })

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
		await page.goto('/apps/openbuild/applications')

		const helloCard = page.locator('.ob-app-card').filter({ hasText: '/hello-world' }).first()
		await expect(helloCard, 'the seeded hello-world card must be on the index').toBeVisible({ timeout: 15_000 })

		// The version chip must render the documented shape exactly: the label
		// followed by a semver, or by the em-dash placeholder spec C Decision 4
		// defines for "no resolved production version".
		//
		// The old body only checked `length > 0` and `not /undefined/`, which the
		// literal string "Version null" would have satisfied. A format assertion
		// pins every failure mode a template hole can produce.
		const versionChip = helloCard.locator('.ob-app-card__chip').first()
		const chipText = (await versionChip.textContent() || '').trim()
		expect(
			chipText,
			`version chip must read "Version <semver>" or "Version —", got "${chipText}"`,
		).toMatch(/^Version\s+(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?|—)$/)

		// And the slug chip beside it must be the real slug, not a template hole.
		await expect(
			helloCard.locator('.ob-app-card__chip--muted'),
			'the slug chip must render the application slug',
		).toHaveText('/hello-world')
	})
})
