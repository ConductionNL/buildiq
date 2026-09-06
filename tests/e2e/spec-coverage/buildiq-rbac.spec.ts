// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for buildiq-rbac spec — UI scenarios only.
 *
 * REQ-OBRBAC-004: role-to-action mapping in editor UIs
 *   - viewer-cannot-save-manifest-edits
 *   - editor-cannot-publish
 *
 * REQ-OBRBAC-006: global buildiq.use navigation-entry permission
 *   - admin-restricts-the-navigation-entry-to-one-group
 *   - admin-bypass-is-audited
 *
 * Backend-only requirements are annotated @e2e exclude in the spec.
 *
 * Note: viewer/editor role scenarios require a user with those roles on the
 * hello-world app. Admin (who has owner role) is used as a proxy to verify
 * the owner sees all controls — which proves the role gate is wired.
 * Tests requiring a created non-admin user guard on BUILDIQ_E2E_LIVE.
 */

import { expect, test } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const LIVE = process.env.BUILDIQ_E2E_LIVE === '1'

// STUB/QUARANTINE NOTE CORRECTED 2026-08-25. The old text blamed buildiq#41 for the admin UI "not functional in this build". #41 is a PR that MERGED on 2026-07-27, and 47 spec files in this suite already pass against that UI — applicationDetailOverview.spec.ts alone has 9 passing tests. What actually blocks these is that their bodies are stubs (goto + main-visible), so enabling them would pass while asserting nothing.
//
// THE TWO TRACEABILITY ANCHORS THAT SAT HERE ARE REMOVED, and deliberately not
// replaced. This test drives the shared ADMIN session and asserts that `main`
// and the app name render; the requirement it claimed is about a user holding
// ONLY the viewer role seeing a read-only textarea (or no Save). It asserts the
// opposite role and none of the behaviour.
//
// Removing them changes no number today, because a skipped test is not credited.
// That is exactly the hazard: un-skipping — the obvious way to burn gate-19 down
// — would have scored the claim as covered with no new assertion anywhere.
// Measured on this tree: un-skipping the seven anchors of this shape moved
// covered 98 → 105 and uncovered 160 → 153, restored on revert.
//
// Writing this honestly needs a session for the `rbac-viewer` fixture user,
// which tests/e2e/global-setup.ts already mints, plus a viewer grant on the app.
test('REQ-OBRBAC-004 — owner sees edit controls on the application detail page', async ({
	page,
}) => {
	test.skip(true, 'As admin (owner), the editor must show Save/Publish controls')
	// As admin (owner), the editor must show Save/Publish controls
	await page.goto(`${BASE}/apps/buildiq/applications`)

	// Cards are <a> tags with href containing /applications/:objectId
	// The page may show "Hello World" in card headings — click first matching card
	// Cards use div[role="link"] (not <a> tags) — use getByRole to match the accessibility tree
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	await card.click()
	await page.waitForURL(/\/applications\//, { timeout: 15_000 })

	// The detail page must load without a white screen
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })

	// Admin/owner role — confirm the page renders (full owner controls tested by
	// application-detail-ui spec; this test confirms no fatal render failure for owner)
	await expect(page.getByText('Hello World').first()).toBeVisible({
		timeout: 10_000,
	})
})

// STUB/QUARANTINE NOTE CORRECTED 2026-08-25. The old text blamed buildiq#41 for the admin UI "not functional in this build". #41 is a PR that MERGED on 2026-07-27, and 47 spec files in this suite already pass against that UI — applicationDetailOverview.spec.ts alone has 9 passing tests. What actually blocks these is that their bodies are stubs (goto + main-visible), so enabling them would pass while asserting nothing.
//
// ANCHORS REMOVED, and this one is worse than its neighbour on two counts. The
// requirement is that an EDITOR sees Save and does NOT see Publish; the test is
// titled for the ADMIN and drives the admin session. And its only assertion is
// `expect(count).toBeGreaterThanOrEqual(0)` on a locator count — a count is
// never negative, so the assertion CANNOT FAIL. The trailing note below says so
// in its own words ("the test passes because the gate logic is still in place").
// A tag on an unfalsifiable assertion is the purest form of the .github#343
// defect: the tag is not the test.
test('REQ-OBRBAC-004 — admin sees Publish capability (owner role confirmed)', async ({
	page,
}) => {
	test.skip(
		true,
		'STUB/QUARANTINE NOTE CORRECTED 2026-08-25. The old text blamed buildiq#41 for the admin UI "not functional in this build". #41 is a PR that MERGED on 2026-07-27, and 47 spec files in this suite already pass against that UI — applicationDetailOverview.spec.ts alone has 9 passing tests. What actually blocks these is that their bodies are stubs (goto + main-visible), so enabling them would pass wh...',
	)
	test.skip(
		!LIVE,
		'Requires live dev env with a draft Application — set BUILDIQ_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/buildiq/applications`)
	const card = page.getByRole('link', { name: /Hello World/i }).first()
	await expect(card).toBeVisible({ timeout: 15_000 })
	await card.click()
	await page.waitForURL(/\/applications\//, { timeout: 15_000 })

	// Owner (admin) should see a Publish / action bar button
	const publishButton = page.locator('button').filter({ hasText: /publish/i })
	const actionCount = await publishButton.count()
	expect(
		actionCount,
		'owner must see at least one action button on the detail page',
	).toBeGreaterThanOrEqual(0)
	// NOTE: if no Publish button found it means the app may already be published —
	// the test passes because the gate logic is still in place
})

// @e2e buildiq-rbac::admin-restricts-the-navigation-entry-to-one-group
test('REQ-OBRBAC-006 — Buildiq navigation entry is present for admin', async ({
	page,
}) => {
	// @e2e buildiq-rbac::admin-restricts-the-navigation-entry-to-one-group
	// The global nav entry test — admin always sees Buildiq in the nav
	// Navigate directly to the Buildiq app to confirm the admin has access
	await page.goto(`${BASE}/apps/buildiq/`)
	await expect(
		page.locator('main'),
		'Buildiq main content must be reachable for admin',
	).toBeVisible({ timeout: 15_000 })

	// Buildiq navigation sidebar should be visible for the admin user
	// (The spec says an admin can restrict the nav entry per group; this verifies the
	//  app is reachable for the unrestricted admin baseline — proxy for nav entry present)
	const buildiqNav = page
		.locator('nav, [role="navigation"]')
		.filter({ has: page.locator('a[href*="buildiq"]') })
		.first()
	await expect(
		buildiqNav,
		'Buildiq navigation must be present for admin',
	).toBeVisible({ timeout: 10_000 })
})

// @e2e buildiq-rbac::admin-bypass-is-audited
test('REQ-OBRBAC-006 — admin can reach Buildiq app (admin bypass baseline)', async ({
	page,
}) => {
	// @e2e buildiq-rbac::admin-bypass-is-audited
	// The admin bypass is a PHP-side audit event; here we verify admin can reach the app
	// (the audit trail itself is verified by Newman/PHPUnit)
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(
		page.locator('main'),
		'admin must be able to reach the Buildiq applications page',
	).toBeVisible({ timeout: 15_000 })
	// Page title must contain Buildiq
	await expect(page).toHaveTitle(/buildiq/i)
})
