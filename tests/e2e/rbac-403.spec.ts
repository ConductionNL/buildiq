/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Playwright end-to-end test for the openbuild-rbac change covering the
 * non-member's blackout: no Applications visible in the editor list AND a
 * deny screen on direct /builder/{slug} navigation. Together with the
 * Newman + PHPUnit suites this closes REQ-OBRBAC-002 (manifest 403) and
 * REQ-OBRBAC-003 (list filter) at the rendered-UI layer.
 *
 * Pre-conditions assumed by this spec — set up by Newman's Setup folder
 * (tests/integration/openbuild-rbac.postman_collection.json) before the
 * Playwright run kicks off, OR by the CI harness:
 *
 *   - Nextcloud reachable at NC_BASE_URL (default http://localhost:8080)
 *     with the openbuild app enabled and the SeedHelloWorld repair step
 *     having produced a `hello-world` Application.
 *   - Test user `rbac-outsider` / `RbacOutsider-1!` exists and is NOT a
 *     member of any group referenced in the hello-world Application's
 *     `permissions` block. The user is created in Newman step 1.6.
 *   - Default permissions on hello-world grant only the `admin` group as
 *     owner and the two RBAC test groups as editors/viewers (Newman 1.10).
 *
 * Running:
 *   npx playwright test tests/e2e/rbac-403.spec.ts
 *
 * The suite is intentionally single-worker (config below). Nextcloud's
 * login redirect path and the OR shared state are not safe to parallelise.
 */

import { test, expect, type Page } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'
const OUTSIDER_USER = process.env.NC_RBAC_OUTSIDER_USER ?? 'rbac-outsider'
const OUTSIDER_PASS = process.env.NC_RBAC_OUTSIDER_PASS ?? 'RbacOutsider-1!'
const TEST_SLUG = process.env.NC_RBAC_TEST_SLUG ?? 'hello-world'

/**
 * Log a fresh browser context into Nextcloud as the supplied user. We do
 * NOT reuse storageState here because the suite needs a NON-admin session
 * — the shared admin auth would short-circuit the deny path via the
 * admin-bypass (REQ-OBRBAC-006).
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
	// Wait for the global header — URL waits race with the in-flight
	// click navigation and are unreliable on slow rigs.
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(
			`Login as ${user} appears to have failed — still on ${page.url()}. `
			+ 'Verify the Newman Setup folder ran successfully and the user exists.',
		)
	}
}

// STILL SKIPPED, with the true reason replacing the #41 one.
//
// Blocker 1 (hard): needs the Nextcloud user `rbac-outsider` / `RbacOutsider-1!`,
// in no group named by hello-world's `permissions` block. It is created by
// tests/integration/openbuild-rbac.postman_collection.json step 1.6, and this
// Playwright suite does not run Newman. Without that user the whole premise —
// a NON-admin, NON-member session — is unreachable; the shared admin
// storageState exercises the admin bypass (REQ-OBRBAC-006) instead.
//
// Blocker 2 (would make it green-but-dead even with the user): the deny
// assertion is `expect(page.locator('[data-app-slug="hello-world"],
// [data-testid="builder-host-hello-world"]')).toHaveCount(0)`. Neither attribute
// is emitted anywhere in src/ — BuilderHost.vue stamps
// data-testid="openbuild-builder-host", with no slug in it. So that count is 0
// for an admin who CAN see the app, and the assertion proves nothing. Retarget
// it before re-enabling.
//
// This block is also the canonical home the openbuild-runtime spec points at
// for REQ-OBR-006c ("@e2e exclude … already covered by rbac-403.spec.ts") and
// is where REQ-OBR-007c's empty-list scenario belongs, since both need exactly
// this outsider session.
test.describe.skip('openbuild-rbac — non-member blackout (REQ-OBRBAC-002 / REQ-OBRBAC-003)', () => {
	// Skip storageState — we need a freshly authed outsider context, not
	// the shared admin session.
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeEach(async ({ page }) => {
		await loginAs(page, OUTSIDER_USER, OUTSIDER_PASS)
	})

	test('REQ-OBRBAC-003: outsider sees no Applications in the editor list', async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/applications`)

		// Give the SPA up to 10s to fetch the filtered list. The empty
		// state copy is exact text from src/views/ApplicationEditor.vue
		// per task 2.3 — "No applications available — ask an owner to
		// grant you access".
		const emptyState = page.getByText(/No applications available/i)
		await expect(emptyState).toBeVisible({ timeout: 10_000 })

		// Belt-and-braces: assert there is no visible card / row carrying
		// the seeded slug. The Application list-item exposes the slug in
		// either a [data-slug] attribute or the visible card text; check
		// both so future re-wires don't silently fail the assertion.
		const slugCard = page.locator(`[data-slug="${TEST_SLUG}"]`)
		await expect(slugCard).toHaveCount(0)
		const slugText = page.getByText(TEST_SLUG, { exact: true })
		await expect(slugText).toHaveCount(0)
	})

	test('REQ-OBRBAC-002: direct /builder/{slug} URL renders the no-access screen', async ({ page }) => {
		// Listen for the manifest XHR — it's the load-bearing 403 surface.
		const manifestRequestPromise = page.waitForResponse(
			(resp) => resp.url().includes(`/applications/${TEST_SLUG}/manifest`),
			{ timeout: 10_000 },
		).catch(() => null)

		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${TEST_SLUG}`)

		const manifestResp = await manifestRequestPromise
		// The page may render a deny screen without hitting the manifest
		// endpoint (frontend gating from useRole + filtered list); accept
		// either path. When the XHR fires, it MUST be 403.
		if (manifestResp !== null) {
			expect(manifestResp.status(), 'manifest endpoint must 403 for non-member').toBe(403)
		}

		// Assert the rendered surface shows a forbidden/deny UI. We accept
		// any of the three canonical deny-copy candidates so the test is
		// resilient to copy tweaks across builds:
		//   - "No access" / "no access"        (NcEmptyContent default)
		//   - "Forbidden"                       (HTTP-status fallback)
		//   - "ask an owner to grant you"       (list-empty-state copy)
		// At least one must be visible within 10s.
		const denySurface = page.getByText(
			/(no access|forbidden|ask an owner to grant you|openbuild\.rbac\.no_role)/i,
		).first()
		await expect(denySurface).toBeVisible({ timeout: 10_000 })

		// Negative assertion — the BuilderHost (or its main editor scrim)
		// must NOT have rendered the manifest content. We check for the
		// absence of any element bearing the slug as a data-testid or
		// data-app-slug attribute. (Both attributes are conventions used
		// elsewhere in the editor view; either present = leakage.)
		const builderHost = page.locator(`[data-app-slug="${TEST_SLUG}"], [data-testid="builder-host-${TEST_SLUG}"]`)
		await expect(builderHost).toHaveCount(0)
	})
})
