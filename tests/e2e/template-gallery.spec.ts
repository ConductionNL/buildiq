/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end test for the Buildiq template gallery
 * (REQ-OBTC-003 + the github-shop-catalogue template-catalogue-ui spec).
 *
 * UN-QUARANTINED AND REWRITTEN 2026-07-31. The quarantine blamed
 * buildiq#41, and my own earlier triage of this file blamed missing fixtures.
 * Both were wrong. What this file asserted was REMOVED ON PURPOSE:
 *
 *   - it expected four locally-seeded template cards, a "Use this template"
 *     action and a `.clone-dialog` clone flow. Commit f8e0eec57 ("keep
 *     GitHub-only") made the Templates tab a server-backed GitHub search over
 *     `topic:openbuild-app`. The two cards a run does find are GitHub repos,
 *     not the seeded templates — the count mismatch (2 vs 4) looked like a
 *     seeding gap and was not one.
 *   - the four fixtures ARE seeded and correct (permit-tracker,
 *     stakeholder-consultation, employee-onboarding, incident-reporter, with
 *     their categories) via TemplateSeedService + the SeedApplicationTemplates
 *     repair step.
 *
 * ⚠️ ORPHANED CAPABILITY, worth a product decision rather than a test:
 * `POST /api/applications/from-template/{templateSlug}` is routed and the
 * fixtures are seeded on every install, but NOTHING in src/ calls that endpoint
 * any more — the gallery's CloneTemplateDialog is bound `:github="true"` and
 * installs through `/api/shop/github/install`. So the seeded templates are
 * currently unreachable from the UI. Asserting the old flow here would just
 * re-freeze coverage of a dead path; this file now covers the surface that
 * actually ships, and the gap is recorded rather than papered over.
 *
 * What it covers instead:
 *   - the gallery shell and its Templates/Blocks tab pair render (REQ-OBTC-003)
 *   - the Templates tab is server-backed: typing a query issues the GitHub
 *     search request to Buildiq's own endpoint (never to github.com directly)
 *   - the tab resolves to one of its three legitimate states rather than
 *     hanging — cards, the empty state, or the unreachable/rate-limited note.
 *     Deliberately tolerant: this suite must not fail because GitHub is
 *     unreachable or rate-limiting anonymous browsing from CI.
 */

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl.ts'

test.describe('Buildiq template gallery', () => {
	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
	})

	test('REQ-OBTC-003: the gallery renders its Templates/Blocks tabs', async ({
		page,
	}) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/buildiq/templates`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('.template-gallery')).toBeVisible({
			timeout: 45_000,
		})
		await dismissOverlays(page)

		const tabs = page.getByRole('tab')
		await expect(tabs.filter({ hasText: /^Templates$/ })).toBeVisible()
		await expect(tabs.filter({ hasText: /^Blocks$/ })).toBeVisible()

		// Templates is the default view.
		await expect(page.locator('.template-gallery__view-btn--active')).toHaveText(
			/Templates/i,
		)
	})

	test('the Templates tab searches through Buildiq, not the browser, and settles into a real state', async ({
		page,
	}) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/buildiq/templates`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('.template-gallery')).toBeVisible({
			timeout: 45_000,
		})
		await dismissOverlays(page)

		// The search is server-backed: the browser must never call github.com
		// itself (no token in the page, and CSP would block it).
		const searchRequest = page.waitForRequest(
			(req) => /\/apps\/buildiq\/api\/shop\/github\/search/.test(req.url()),
			{ timeout: 30_000 },
		)
		await page.getByRole('textbox', { name: /search github/i }).fill('buildiq')
		const req = await searchRequest
		expect(
			req.url(),
			"the query must be forwarded to Buildiq's own search endpoint",
		).toContain('q=buildiq')

		// Whatever GitHub answers, the tab must reach a terminal state rather
		// than spin: cards, the "no matches" empty state, or the
		// unreachable/rate-limited note. All three are correct outcomes.
		const settled = page.locator(
			'.template-gallery__grid, .template-gallery__empty, .template-gallery__github-hint',
		)
		await expect(settled.first()).toBeVisible({ timeout: 45_000 })
		await expect(page.locator('.template-gallery__loading')).toHaveCount(0)
	})
})
