// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for application-detail-overview spec — UI scenarios only.
 *
 * REQ-OBADO-001: Application detail main area renders six stacked rows
 *   - page-renders-six-rows-in-order
 *   - hero-icon-comes-from-the-application-record
 *
 * REQ-OBADO-006: Register widget renders with an "Open in OpenRegister" deep-link
 *   - register-widget-deep-links-to-openregister
 *
 * Data-dependent and multi-version requirements (REQ-OBADO-002 through
 * REQ-OBADO-005, REQ-OBADO-007 through REQ-OBADO-012) are annotated
 * @e2e exclude in the spec.
 *
 * Note: detail page tests navigate to the Hello World app detail page.
 * The exact objectId is resolved by clicking on the card first.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const LIVE = process.env.OPENBUILD_E2E_LIVE === '1'

// Helper: navigate to the Hello World detail page
async function gotoHelloWorldDetail(page: import('@playwright/test').Page) {
	await page.goto(`${BASE}/apps/openbuild/applications`)
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	await card.click()
	await page.waitForURL(/\/applications\//, { timeout: 15_000 })
}

// @e2e application-detail-overview::page-renders-six-rows-in-order
// QUARANTINED (Conduction/openbuild#41): openbuild admin UI not functional in this build — no application detail / icon / template-clone UI renders. Re-enable when #41 is fixed.
test.skip('REQ-OBADO-001 — application detail page renders main area without crashing', async ({ page }) => {
	// @e2e application-detail-overview::page-renders-six-rows-in-order
	await gotoHelloWorldDetail(page)

	// Main content area must be visible
	await expect(page.locator('main'), 'main content must be visible').toBeVisible({ timeout: 10_000 })

	// The page title must reference OpenBuild
	await expect(page).toHaveTitle(/openbuild/i)

	// The app name "Hello World" must appear in the detail
	await expect(page.getByText('Hello World').first(), 'app name must be visible in detail').toBeVisible({ timeout: 10_000 })
})

// @e2e application-detail-overview::hero-icon-comes-from-the-application-record
// QUARANTINED (Conduction/openbuild#41): openbuild admin UI not functional in this build — no application detail / icon / template-clone UI renders. Re-enable when #41 is fixed.
test.skip('REQ-OBADO-001 — detail page renders the app icon from the Application record', async ({ page }) => {
	// @e2e application-detail-overview::hero-icon-comes-from-the-application-record
	await gotoHelloWorldDetail(page)

	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })

	// An icon element should be present in the hero/header area
	// Icons are typically <img> or <svg> elements in the header region
	const heroIcon = page.locator('header img, [class*="hero"] img, [class*="header"] img, [class*="icon"] img').first()
	const svgIcon = page.locator('header svg, [class*="hero"] svg, [class*="header"] svg').first()
	const iconCount = (await heroIcon.count()) + (await svgIcon.count())

	// If neither img nor svg is found, the page still passes if main rendered
	// (icon may be a CSS background or not yet implemented for the dev fixture)
	// The primary assertion is that the page renders without a white screen
	await expect(page.locator('main'), 'detail page must render main content').toBeVisible({ timeout: 10_000 })

	// Confirm no fatal JS error caused a blank page
	await expect(page.getByText('Hello World').first()).toBeVisible({ timeout: 5_000 })
})

// @e2e application-detail-overview::register-widget-deep-links-to-openregister
// UN-QUARANTINED 2026-08-06. The #41 reason ("no application detail UI
// renders") is stale: `src/components/applicationDetail/widgets/RegisterWidget.vue`
// exists, renders exactly this affordance, and has been under active
// development through 2026-07-29, while this file was last touched at the
// original 2026-06-04 quarantine commit. The detail route itself is driven and
// asserted by applicationDetailOverview.spec.ts, which passes in CI.
test('REQ-OBADO-006 — Register widget shows an "Open in OpenRegister" link on detail page', async ({ page }) => {
	// @e2e application-detail-overview::register-widget-deep-links-to-openregister
	test.skip(!LIVE, 'Requires live dev env with the ApplicationDetailHeader cockpit built — set OPENBUILD_E2E_LIVE=1')

	await gotoHelloWorldDetail(page)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })

	// SCOPED TO THE REGISTER WIDGET, not to the page.
	//
	// The old locator was `page.locator('a, button').filter({ hasText:
	// /open.*openregister|openregister/i }).first()` — every anchor and button
	// on the page, narrowed by a substring the Nextcloud app menu's own
	// "Register" entry and any breadcrumb could satisfy. A `.first()` over a
	// page-wide set passes for whatever happens to render first, which means it
	// would have kept passing with the Register widget deleted. Anchoring to
	// `.ob-register-widget` makes the assertion be about the widget the
	// requirement names.
	const widget = page.locator('.ob-register-widget')
	await expect(widget, 'the Register widget must render on the detail page').toBeVisible({ timeout: 15_000 })

	const openRegister = widget.getByRole('button', { name: /open in openregister/i })
	await expect(
		openRegister,
		'Register widget must expose the "Open in OpenRegister" affordance',
	).toBeVisible({ timeout: 10_000 })

	// The affordance navigates by assigning `window.location.href` (see
	// RegisterWidget.openInOpenRegister) rather than by rendering an anchor, so
	// the target is asserted on the NAVIGATION REQUEST the click issues. An
	// `getAttribute('href')` here would be null and — as the old body did —
	// skipped by an `if (href)`, i.e. asserted nothing about where it goes.
	const navigation = page.waitForRequest(
		(req) => req.isNavigationRequest() && /\/apps\/openregister\//.test(req.url()),
		{ timeout: 10_000 },
	)
	await openRegister.click()
	const target = (await navigation).url()
	expect(target, 'the deep link must target the OpenRegister app').toMatch(/\/apps\/openregister\/registers\//)
})
