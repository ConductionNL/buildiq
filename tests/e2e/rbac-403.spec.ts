/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Playwright end-to-end test for the buildiq-rbac change covering the
 * non-member's blackout: no Applications visible in the editor list AND a
 * deny screen on direct /builder/{slug} navigation. Together with the
 * Newman + PHPUnit suites this closes REQ-OBRBAC-002 (manifest 403) and
 * REQ-OBRBAC-003 (list filter) at the rendered-UI layer.
 *
 * Pre-conditions assumed by this spec — set up by Newman's Setup folder
 * (tests/integration/buildiq-rbac.postman_collection.json) before the
 * Playwright run kicks off, OR by the CI harness:
 *
 *   - Nextcloud reachable at NC_BASE_URL (default http://localhost:8080)
 *     with the buildiq app enabled and the SeedHelloWorld repair step
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

import { test, expect } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'
const TEST_SLUG = process.env.NC_RBAC_TEST_SLUG ?? 'hello-world'

// STILL SKIPPED, with the true reason replacing the #41 one.
//
// Blocker 1 (hard): needs the Nextcloud user `rbac-outsider` / `RbacOutsider-1!`,
// in no group named by hello-world's `permissions` block. It is created by
// tests/integration/buildiq-rbac.postman_collection.json step 1.6, and this
// Playwright suite does not run Newman. Without that user the whole premise —
// a NON-admin, NON-member session — is unreachable; the shared admin
// storageState exercises the admin bypass (REQ-OBRBAC-006) instead.
//
// Blocker 2 (would make it green-but-dead even with the user): the deny
// assertion is `expect(page.locator('[data-app-slug="hello-world"],
// [data-testid="builder-host-hello-world"]')).toHaveCount(0)`. Neither attribute
// is emitted anywhere in src/ — BuilderHost.vue stamps
// data-testid="buildiq-builder-host", with no slug in it. So that count is 0
// for an admin who CAN see the app, and the assertion proves nothing. Retarget
// it before re-enabling.
//
// This block is also the canonical home the buildiq-runtime spec points at
// for REQ-OBR-006c ("@e2e exclude … already covered by rbac-403.spec.ts") and
// is where REQ-OBR-007c's empty-list scenario belongs, since both need exactly
// this outsider session.
// UN-QUARANTINED 2026-07-31. Both documented blockers are gone:
//
// Blocker 1 (fixed): the outsider user now exists and its session is minted
// ONCE by globalSetup (tests/e2e/global-setup.ts, seedRoleUsersAndSessions),
// stored at tests/e2e/.auth/rbac-outsider.json. The spec attaches that stored
// session instead of form-logging-in per test, which is what kept this suite
// from being safe to enable: consecutive logins from one IP trip Nextcloud's
// brute-force throttle and knock over every later spec.
//
// Blocker 2 (fixed): the deny assertions targeted `[data-app-slug="…"]` and
// `[data-testid="builder-host-hello-world"]`. Neither is emitted anywhere in
// src/ — BuilderHost.vue stamps `data-testid="buildiq-builder-host"`, with no
// slug — so both counts were 0 for an admin who CAN see the app and the
// assertions proved nothing. Retargeted at what an outsider actually gets,
// measured against the instance: an empty application list carrying no cards
// and no mention of the slug, and an "App not found" screen with no builder
// host mounted.
test.describe('buildiq-rbac — non-member blackout (REQ-OBRBAC-002 / REQ-OBRBAC-003)', () => {
	// A NON-admin, NON-member session. The shared admin storageState would
	// exercise the admin bypass (REQ-OBRBAC-006) and never reach the deny path.
	test.use({ storageState: 'tests/e2e/.auth/rbac-outsider.json' })

	test('REQ-OBRBAC-003: outsider sees no Applications in the editor list', async ({
		page,
	}) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/buildiq/applications`, {
			waitUntil: 'domcontentloaded',
		})

		// Nothing they may not see is listed: no application cards at all, and
		// the seeded slug appears nowhere on the page.
		await expect(page.locator('.ob-app-card')).toHaveCount(0, {
			timeout: 30_000,
		})
		await expect(page.locator('body')).not.toContainText(TEST_SLUG)
	})

	test('REQ-OBRBAC-002: direct /builder/{slug} URL renders the no-access screen', async ({
		page,
	}) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/buildiq/builder/${TEST_SLUG}`, {
			waitUntil: 'domcontentloaded',
		})

		// The deny screen, not a stack trace and not a half-rendered app.
		await expect(page.locator('body')).toContainText(
			/App not found|could not be loaded|no access/i,
			{ timeout: 30_000 },
		)

		// And the builder host itself must never mount for a non-member.
		await expect(
			page.locator('[data-testid="buildiq-builder-host"]'),
		).toHaveCount(0)
	})
})
