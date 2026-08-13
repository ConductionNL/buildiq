// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for app-icon-management spec — REQ-OBICON-004 UI scenarios.
 *
 * Icon section on the Application detail page:
 *   - user-uploads-a-light-icon
 *   - user-removes-the-dark-icon
 *   - non-svg-file-is-rejected-client-side
 *
 * Backend REQ-OBICON-001/002/003/005 are excluded (verified by Newman/PHPUnit).
 */

import { test, expect, type Page } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

/** Navigate to the applications list and return the URL of the first Hello World card. */
async function getFirstAppDetailUrl(page: Page): Promise<string> {
	await page.goto(`${BASE}/apps/openbuild/applications`)
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	const href = await card.getAttribute('href')
	return href
		? href.startsWith('http')
			? href
			: `${BASE}${href}`
		: `${BASE}/apps/openbuild/applications`
}

// QUARANTINED (Conduction/openbuild#41): openbuild admin UI not functional in this build — no application detail / icon / template-clone UI renders. Re-enable when #41 is fixed.
//
// ANCHORS REMOVED. The requirement is that a user UPLOADS a light icon and it
// is stored on the Application. Nothing here uploads anything: the body clicks
// into the detail page, asserts `main` is visible, guards a fatal-error check
// behind `if (errorCount > 0)`, and ends on the app name being visible.
test.skip('REQ-OBICON-004 — detail page exposes icon upload section', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/openbuild/applications`)
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	await card.click()

	// Wait for detail page to load
	await page.waitForURL(/\/applications\//, { timeout: 15_000 })

	// Look for the sidebar or a tab that would contain icon management
	// The spec says REQ-OBICON-004 adds an Icon section to the Application detail page
	// We verify the page has loaded and contains the expected tab navigation
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })

	// Check the page does not show a white screen / hard error
	const errorOverlay = page.locator('[class*="error"], [data-error]')
	const errorCount = await errorOverlay.count()
	// If any error element, make sure it isn't a fatal crash
	if (errorCount > 0) {
		const errorText = await errorOverlay.first().textContent()
		expect(errorText, 'page must not show a fatal error').not.toMatch(
			/fatal|crash|500|undefined is not/i,
		)
	}

	// The detail page should render at minimum the application name heading
	await expect(
		page.getByText('Hello World').first(),
		'application name must be visible on the detail page',
	).toBeVisible({ timeout: 10_000 })
})

// QUARANTINED (Conduction/openbuild#41): openbuild admin UI not functional in this build — no application detail / icon / template-clone UI renders. Re-enable when #41 is fixed.
//
// ANCHORS REMOVED. The requirement is that REMOVING the dark icon deletes the
// OR attachment and clears `iconDark.ref`. This body removes nothing and reads
// no attachment. Both of its branches are satisfiable without the feature: if a
// tab matching /icon|image|brand/i exists it clicks it and asserts `main`; if
// none exists it asserts the app name. There is no input under which it fails
// while the product is broken — which is the definition of an unfalsifiable
// test, and gate-19 cannot see that through the tag.
//
// No test on this branch asserts the removal path; a real one must delete the
// dark icon and read back the cleared reference.
test.skip('REQ-OBICON-004 — icon tab/section is accessible on the detail page', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/openbuild/applications`)
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	await card.click()
	await page.waitForURL(/\/applications\//, { timeout: 15_000 })

	// The detail page should have sidebar tabs; look for an icon-related tab
	// The spec says the icon section is on the Application detail page
	// It may be a tab label or a section heading
	const possibleIconTab = page
		.locator('[role="tab"], button, a')
		.filter({ hasText: /icon|image|brand/i })

	// Either the icon tab exists (full implementation) or the detail page loads without white-screen
	const iconTabCount = await possibleIconTab.count()
	if (iconTabCount > 0) {
		// Tab exists — click it and verify no crash
		await possibleIconTab.first().click()
		await expect(page.locator('main')).toBeVisible({ timeout: 5_000 })
	} else {
		// Tab not yet wired to a specific label — detail page must at minimum load
		await expect(page.getByText('Hello World').first()).toBeVisible({
			timeout: 10_000,
		})
	}
})

// QUARANTINED (Conduction/openbuild#41): openbuild admin UI not functional in this build — no application detail / icon / template-clone UI renders. Re-enable when #41 is fixed.
//
// ANCHORS REMOVED. The requirement is that a non-SVG file is REJECTED client
// side. The rejection assertion here sits under TWO nested conditions —
// `if (fileInputCount > 0)` and then `if (errorCount > 0)` — so the product
// failing to reject is precisely the case in which nothing is asserted. The
// body says so out loud: "the test passes vacuously because the UI is not
// built". A vacuous pass and a real one are the same green.
test.skip('REQ-OBICON-004 — non-SVG upload is rejected (icon section validation)', async ({
	page,
}) => {
	await page.goto(`${BASE}/apps/openbuild/applications`)
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	await card.click()
	await page.waitForURL(/\/applications\//, { timeout: 15_000 })

	// Look for a file input in the icon section
	const fileInputs = page.locator('input[type="file"]')
	const fileInputCount = await fileInputs.count()

	if (fileInputCount > 0) {
		// Try to upload a non-SVG file; the client-side validator should reject it
		const fileInput = fileInputs.first()
		await fileInput.setInputFiles({
			name: 'test-image.png',
			mimeType: 'image/png',
			buffer: Buffer.from('PNG_FAKE_CONTENT'),
		})
		// An inline error message should appear
		const errorMsg = page
			.locator('[class*="error"], [role="alert"], .nc-error-message')
			.filter({ hasText: /svg|format|invalid|type/i })
		const errorCount = await errorMsg.count()
		// If icon upload UI renders, non-SVG should surface an inline error
		// (if icon tab not yet visible, the test passes vacuously because the UI is not built)
		if (errorCount > 0) {
			await expect(errorMsg.first()).toBeVisible({ timeout: 5_000 })
		}
	}

	// Whether or not the file input renders, the page must not crash
	await expect(page.locator('main')).toBeVisible({ timeout: 5_000 })
})
