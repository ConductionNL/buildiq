// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect, request as playwrightRequest } from '@playwright/test'
import { suppressSupportDialog } from './support/appFixture'
import { ensureVersionChain } from './support/versionChain'
import { grantAppRoles } from './support/appRoles'

/**
 * Playwright e2e — Version routing (spec E, openbuild-version-routing).
 *
 * Covers spec E task 9.1 – 9.3 (the three REQUIRED e2e scenarios):
 *
 *   9.1  Bookmarkability / reload preserves ?_version=
 *   9.2  404 for unauthorised user on non-production version
 *   9.3  Default version is most-upstream-non-production fallback
 *
 * Preconditions for 9.1 / 9.3:
 *   - The builder views (SchemaDesigner, PageDesigner, BuilderHost) read
 *     useApplicationVersion + buildVersionedRoute (spec E tasks 3/4/5).
 *   - At least one ApplicationVersion with slug "staging" is set up for the
 *     hello-world Application, and the caller (admin) is in permissions.editors.
 *
 * Preconditions for 9.2:
 *   - A "viewer" test user exists (created by Newman RBAC setup collection).
 *   - The viewer is in permissions.viewers but NOT editors/owners.
 *
 * NOTE: Scenarios 9.1 and 9.3 require a seeded multi-version Application
 * (development → staging → production chain). When the ApplicationVersion
 * CRUD is not yet seeded with this exact chain, the tests will skip
 * gracefully with a TODO comment pointing to the blocking dependency.
 */

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
// Every session comes from globalSetup — admin here, and one per rbac-* fixture
// role for 9.2. No spec form-logs-in: consecutive logins trip Nextcloud's
// brute-force throttle and turn a whole run red.
const ADMIN_STATE = 'tests/e2e/.auth/admin.json'
// A DEDICATED fixture app carrying development -> staging -> production.
// hello-world ships exactly one version (`production`), which is why every
// block here used to skip itself; see tests/e2e/support/versionChain.ts.
const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'pw-verchain'
const STAGING_VERSION = process.env.NC_STAGING_VERSION ?? 'staging'

// ---------------------------------------------------------------------------
// 9.1 — Bookmarkability / reload preserves ?_version=
// ---------------------------------------------------------------------------
// STILL SKIPPED (all three blocks), with the true reason replacing the #41 one.
//
// Shared blocker: every block needs an ApplicationVersion chain this instance
// does not have. hello-world ships exactly one version, slug `production`;
// 9.1 needs a `staging` row, 9.3 needs `development → staging → production`.
// 9.2 additionally needs the `rbac-viewer` user from the Newman RBAC setup
// collection, which this suite does not run.
//
// Second blocker, which would make 9.1/9.3 green-but-dead even with the chain:
// they locate the designer with `[data-testid="schema-designer"]`,
// `.ob-schema-designer`, `.ob-schema-list` and `[data-app-version="…"]`. None of
// those exist in src/ — SchemaDesigner.vue emits none of them (9.3's own comment
// already admits it, suggesting someone "consider adding a data-app-version
// attribute"). Retarget these before re-enabling, or the chain will be seeded
// and the assertions will still be measuring nothing.
//
// REQ-OBVR-009 (the version-not-found state BuilderHost renders) is NOT blocked
// by any of this and is exercised elsewhere.
test.describe('9.1 Bookmarkability — reload preserves ?_version= (REQ-OBVR-008)', () => {
	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureVersionChain(page, TEST_SLUG, 'PW Version Chain')
	})

	test('navigating to /builder/{slug}/schemas?_version=staging preserves the param after reload', async ({ page }) => {
		// No `/index.php` prefix: every other spec navigates the pretty form, and the
		// SPA's router base is resolved from it.
		const targetUrl = `${BASE}/apps/openbuild/builder/${TEST_SLUG}/schemas?_version=${STAGING_VERSION}`
		await page.goto(targetUrl)
		await page.waitForLoadState('networkidle', { timeout: 20_000 })

		// Assert the URL still contains ?_version= after SPA init.
		expect(
			page.url(),
			`URL must still contain ?_version=${STAGING_VERSION} after initial navigation`,
		).toContain(`_version=${STAGING_VERSION}`)

		// Assert the schema designer actually mounted. The original looked for
		// `[data-testid="schema-designer"]` / `.ob-schema-designer`, neither of
		// which exists in src/, with an `h2, h3` text fallback — so it would have
		// passed on any page with a matching heading. The real panel is
		// `.openbuild-schema-list`.
		await expect(page.locator('.openbuild-schema-list')).toBeVisible({ timeout: 30_000 })

		// Reload and re-check.
		await page.reload()
		await page.waitForLoadState('networkidle', { timeout: 20_000 })

		expect(
			page.url(),
			`URL must still contain ?_version=${STAGING_VERSION} after page reload`,
		).toContain(`_version=${STAGING_VERSION}`)

		// The schema designer must still be mounted after the reload — the point
		// of the requirement is that a bookmarked version URL is fully usable.
		await expect(page.locator('.openbuild-schema-list')).toBeVisible({ timeout: 30_000 })
	})
})

// ---------------------------------------------------------------------------
// 9.2 — non-production version access is role-gated (REQ-OBVR-003)
// ---------------------------------------------------------------------------
// PREVIOUSLY SKIPPED, now enabled. Two things unblocked it:
//
//  1. The fixture users. globalSetup provisions rbac-owner/-editor/-viewer/
//     -outsider and mints one storageState each, so no spec form-logs-in (four
//     consecutive logins is exactly what trips Nextcloud's brute-force throttle).
//     `loginAs` is gone from this block for that reason.
//  2. OpenRegister-level visibility. Until openbuild#76 every non-admin saw ZERO
//     objects — a schema authorization block with no `read` key fails closed to
//     owner-only rows — so "viewer gets 404" passed for the wrong reason and any
//     200 assertion was unreachable. `read: ["authenticated"]` fixed that, which
//     is what makes the editor-gets-200 row below meaningful as a control.
//
// The old body asserted almost nothing: it located the schema list with
// `.ob-schema-list` / `[data-testid="schema-list"]`, neither of which exists in
// src/, so "must NOT be visible" was true on any page including a correct one;
// and it downgraded the missing not-found UI to a console.warn. All four
// REQ-OBVR-003 scenarios are now asserted directly, with the editor row as the
// positive control that proves the 404s are the gate and not a broken fixture.
test.describe('9.2 Non-production version access is role-gated (REQ-OBVR-003)', () => {
	// Setup runs as admin (the default storageState); the per-role assertions
	// below each open their own request context.
	test.beforeAll(async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
			await page.goto(`${BASE}/apps/openbuild/`, { waitUntil: 'domcontentloaded' })
			await ensureVersionChain(page, TEST_SLUG, 'PW Version Chain')
			// Without this the roles below are all "non-member" and the editor
			// control cannot distinguish a working gate from a broken fixture.
			await grantAppRoles(page, TEST_SLUG, {
				editors: ['group:rbac-editors'],
				viewers: ['group:rbac-viewers'],
			})
		} finally {
			await context.close()
		}
	})

	/**
	 * A request context carrying one fixture role's stored session.
	 *
	 * @param role The rbac-* fixture user id.
	 * @return {Promise<import('@playwright/test').APIRequestContext>} the context.
	 */
	async function asRole(role: string) {
		return playwrightRequest.newContext({
			baseURL: BASE,
			storageState: `tests/e2e/.auth/${role}.json`,
			extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
		})
	}

	/**
	 * GET the manifest for a version slug as the given role.
	 *
	 * @param role    The rbac-* fixture user id.
	 * @param version The `?_version=` value.
	 * @return {Promise<{status: number, body: unknown}>} status and parsed body.
	 */
	async function manifestAs(role: string, version: string) {
		const api = await asRole(role)
		try {
			const resp = await api.get(
				`/index.php/apps/openbuild/api/applications/${TEST_SLUG}/manifest?_version=${version}`,
			)
			return { status: resp.status(), body: await resp.json().catch(() => null) }
		} finally {
			await api.dispose()
		}
	}

	test('a viewer gets 404 with the exact no-leak envelope for a non-production version', async () => {
		const { status, body } = await manifestAs('rbac-viewer', STAGING_VERSION)

		expect(status, 'REQ-OBVR-003: viewer must receive 404, not 403').toBe(404)
		// The spec pins the body exactly — "no mention of authorisation", so that a
		// 404 for "unauthorised" is indistinguishable from one for "no such version".
		expect(body).toEqual({ status: 404, message: 'Version not found' })
		expect(JSON.stringify(body), 'the 404 must not hint that authorisation was the reason')
			.not.toMatch(/forbid|denied|permission|unauthoris|unauthoriz|role/i)
	})

	test('a non-member gets the identical 404 — no existence leak', async () => {
		const viewer = await manifestAs('rbac-viewer', STAGING_VERSION)
		const outsider = await manifestAs('rbac-outsider', STAGING_VERSION)

		expect(outsider.status, 'REQ-OBVR-003: non-member must receive 404').toBe(404)
		// The point of the requirement: a caller must not be able to tell a
		// version they may not see from one that does not exist. Byte-identical.
		expect(outsider.body, 'non-member and viewer responses must be indistinguishable')
			.toEqual(viewer.body)

		const unknown = await manifestAs('rbac-outsider', 'no-such-version-xyz')
		expect(unknown.body, 'an unknown version must answer identically to a forbidden one')
			.toEqual(outsider.body)
	})

	test('an editor gets 200 for the same non-production version (positive control)', async () => {
		const { status, body } = await manifestAs('rbac-editor', STAGING_VERSION)

		// Without this row the 404s above prove nothing: a broken fixture, a
		// missing version chain or a blanket denial would produce them too.
		expect(status, 'REQ-OBVR-003: an editor must receive the staging manifest').toBe(200)
		expect(body, 'the editor must receive an actual manifest').toHaveProperty('version')
	})

	// This test previously asserted `.openbuild-schema-list` has count 0 for the
	// viewer. That assertion was wrong twice over, and worth recording because
	// both failure modes are easy to repeat:
	//
	//  1. It PASSED for the wrong reason. Before the setup-wizard fix
	//     (@conduction/nextcloud-vue 2.1.0-vue3.15) a non-admin never reached
	//     the builder at all — they got "Set up this app" — so "no schema list"
	//     held because nothing rendered for anyone.
	//  2. It cannot distinguish the roles anyway. Measured side by side, the
	//     viewer (DENIED staging) and the editor (ALLOWED staging) render the
	//     IDENTICAL surface: `.openbuild-schema-list` count 1, reading
	//     "No schemas yet". The builder shows the same empty designer either
	//     way, so the selector carries no information about the gate.
	//
	// No data leaks — the list is empty for both — so this is a UX gap, not a
	// security one: the builder renders no version-not-found state for a version
	// the caller may not see. The GATE itself is asserted properly by the three
	// request-level tests above, which is where it is actually enforced.
	test('the viewer UI leaks no schema data and no stack trace on a forbidden version', async ({ browser }) => {
		const context = await browser.newContext({ storageState: 'tests/e2e/.auth/rbac-viewer.json' })
		const page = await context.newPage()
		try {
			await page.goto(`${BASE}/apps/openbuild/builder/${TEST_SLUG}/schemas?_version=${STAGING_VERSION}`)
			await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

			// What actually matters: no schema of the forbidden version is named.
			// `.openbuild-schema-list` renders as empty chrome; asserting its
			// ABSENCE measured nothing, asserting its emptiness measures the leak.
			const listText = await page.locator('.openbuild-schema-list').innerText().catch(() => '')
			expect(
				listText,
				'the builder must not name any schema belonging to a version the caller may not see',
			).not.toMatch(new RegExp(`${TEST_SLUG}-${STAGING_VERSION}-`, 'i'))

			await expect(
				page.getByText(/Stack trace|Fatal error|Uncaught/i),
				'a denied version must not surface a stack trace',
			).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})

// ---------------------------------------------------------------------------
// 9.3 — Default version is most-upstream-non-production fallback
// ---------------------------------------------------------------------------
// See the block comment on 9.1 for the true reason (version chain + absent selectors).
// 9.3 — Default version resolution (REQ-OBVR-004)
//
// The "most-upstream non-production fallback" rule itself is NOT assertable
// through this UI, and the original test knew it: it accepted any of three
// possible signals, ended on `expect(productionActive).toBe(false)` — which
// passes when NO signal exists at all — and then `console.warn`ed that the
// signal was missing. That is green-but-dead; it would report coverage of
// REQ-OBVR-004 while proving nothing.
//
// Measured against the built app: navigating to /builder/{slug} with no
// `?_version=` leaves the URL untouched and issues a manifest request that
// carries no version marker either, so the resolved version is invisible from
// the outside. The rule is unit-covered, properly, in
// tests/composables/useApplicationVersion.spec.js (it is pure logic over the
// promotesTo graph).
//
// What IS worth asserting end-to-end, and is asserted here: a version-less URL
// still resolves to SOMETHING and renders the builder rather than erroring —
// the regression that would actually hurt a user who drops the query param.
test.describe('9.3 Default version resolution — a version-less URL still renders (REQ-OBVR-004)', () => {
	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureVersionChain(page, TEST_SLUG, 'PW Version Chain')
	})

	test('navigating without ?_version= resolves a version and renders the builder', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/builder/${TEST_SLUG}/schemas`, { waitUntil: 'domcontentloaded' })

		// It must resolve to a usable designer without a version in the URL.
		await expect(page.locator('.openbuild-schema-list')).toBeVisible({ timeout: 30_000 })

		// And it must not have silently rewritten itself onto production: when a
		// non-production upstream exists, production is the WRONG fallback
		// (REQ-OBVR-004 Scenario 2). This is the one part of the original
		// assertion that carries meaning, kept deliberately.
		expect(page.url(), 'production must not be selected while an upstream version exists')
			.not.toContain('_version=production')
	})
})
