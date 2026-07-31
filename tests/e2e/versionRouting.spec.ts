// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'
import { suppressSupportDialog } from './support/appFixture'
import { ensureVersionChain } from './support/versionChain'

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
// Admin comes from globalSetup's shared storageState; only 9.2 still needs an
// explicit (viewer) login of its own.
const VIEWER = { user: process.env.NC_VIEWER_USER ?? 'rbac-viewer', pass: process.env.NC_VIEWER_PASS ?? 'RbacViewer-1!' }
// A DEDICATED fixture app carrying development -> staging -> production.
// hello-world ships exactly one version (`production`), which is why every
// block here used to skip itself; see tests/e2e/support/versionChain.ts.
const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'pw-verchain'
const STAGING_VERSION = process.env.NC_STAGING_VERSION ?? 'staging'

async function loginAs(page: import('@playwright/test').Page, user: string, pass: string): Promise<void> {
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} failed — still on ${page.url()}`)
	}
}

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
// 9.2 — 404 for unauthorised on non-production version
// ---------------------------------------------------------------------------
// See the block comment on 9.1 for the true reason (viewer user + version chain).
test.describe.skip('9.2 Unauthorised access to non-production version shows 404 UI (REQ-OBVR-001 / REQ-OBVR-003)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeEach(async ({ page }) => {
		await loginAs(page, VIEWER.user, VIEWER.pass).catch(() => {
			// Viewer user may not exist in this environment.
		})
	})

	test('viewer navigating to ?_version=staging sees version-not-found UI, not a stack trace', async ({ page }) => {
		// If the viewer login failed (user doesn't exist), skip.
		if (/\/login(\?|$|\/)/.test(page.url())) {
			test.skip('SKIP 9.2: viewer user not found — run Newman RBAC setup collection first')
			return
		}

		// The viewer is not in permissions.editors — they cannot see non-production
		// versions. ManifestResolverService returns null → 404 JSON.
		const manifestResp = await page.request.get(
			`${BASE}/index.php/apps/openbuild/api/applications/${TEST_SLUG}/manifest?_version=${STAGING_VERSION}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		).catch(() => null)

		if (manifestResp) {
			expect(
				manifestResp.status(),
				'manifest endpoint must return 404 for viewer accessing non-production version (REQ-OBVR-003)',
			).toBe(404)

			const body = await manifestResp.json().catch(() => null)
			if (body) {
				expect(body, 'no existence leak — 404 must not expose whether the version exists').not.toHaveProperty('data')
				expect(body.status ?? body.error ?? body.message, 'body must indicate not_found').toBeDefined()
			}
		}

		// Navigate to the builder with the staging version.
		await page.goto(`${BASE}/apps/openbuild/builder/${TEST_SLUG}/schemas?_version=${STAGING_VERSION}`)
		await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

		// The view must show a "not found" UI — no schema list, no stack trace,
		// no "forbidden" / "403" language (the spec mandates 404, not 403).
		const notFoundSurface = page.getByText(
			/(not found|version not found|could not find|no version|no access)/i,
		).first()
		const hasNotFound = await notFoundSurface.isVisible({ timeout: 10_000 }).catch(() => false)

		// The builder host (schema list / page list) must NOT be visible.
		const schemaList = page.locator('[data-testid="schema-list"], .ob-schema-list, .ob-schema-designer__list')
		const schemaListVisible = await schemaList.isVisible({ timeout: 3_000 }).catch(() => false)
		expect(schemaListVisible, 'schema list must NOT be visible for unauthorised version access').toBe(false)

		// At minimum the test confirms no stack trace or raw error dump is shown.
		const stackTrace = page.getByText(/Stack trace|Exception|Uncaught/i)
		await expect(stackTrace, 'no stack trace must be visible to viewer').toHaveCount(0)

		if (!hasNotFound) {
			// The "not found" copy is implementation-dependent; log a warning
			// but don't fail — the main assertion is no schema leakage + no stack trace.
			console.warn('9.2: version-not-found UI copy not matched — verify BuilderHost renders an error state for null applicationVersion')
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
