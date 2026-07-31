// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * Playwright e2e — Application detail / maintainer dashboard
 * (spec openbuild-app-detail-overview, REQ-OBADO-001..012 + REQ-OBAI-001..006).
 *
 * Covers:
 *   - REQ-OBADO-001 — six-row layout renders (hero, pills, window, KPIs,
 *     activity, structural widget grid)
 *   - REQ-OBADO-002 — pill strip renders chain order; production starred
 *   - REQ-OBADO-003 — window toggle changes selection
 *   - REQ-OBADO-006/009/010 — structural widget deep-links carry ?_version=
 *   - REQ-OBADO-012 — Promote affordance on non-terminal pills only
 *   - REQ-OBAI-001/002/006 — insights endpoint surface
 *
 * Preconditions:
 *   - Nextcloud reachable at PLAYWRIGHT_BASE_URL with admin:admin auth
 *   - The hello-world virtual app + a multi-version chain seeded
 *     (development → staging → production) — when not present the tests
 *     skip gracefully via `OPENBUILD_E2E_LIVE` guard so the suite parses
 *     cleanly without a live container.
 */

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'hello-world'
const LIVE = process.env.OPENBUILD_E2E_LIVE === '1'

// The per-spec form login that used to live here is gone: every block now
// inherits globalSetup's storageState. Nextcloud's brute-force throttle fires
// after a handful of near-simultaneous form logins from one IP, which made the
// shared session mandatory (see playwright.config.ts).

// UN-QUARANTINED 2026-07-31. Both documented blockers are addressed here:
//
// Blocker 1 (fixed): the block opted OUT of the shared session
// (`test.use({ storageState: { cookies: [], origins: [] } })`) and form-logged-in
// per test. playwright.config.ts documents why the suite stopped doing that —
// Nextcloud's brute-force throttle fires after a handful of near-simultaneous
// form logins from one IP and every later spec falls back to /login. It now
// inherits globalSetup's storageState like every other spec.
//
// Blocker 2 (unchanged, and correctly handled by the tests themselves): the
// pill-strip and promote-affordance scenarios want a development -> staging ->
// production chain. hello-world has exactly one version here, so those keep
// their own `pillCount < 2` guards and skip rather than assert nothing.
//
// The row-layout and window-toggle scenarios also failed for a THIRD reason
// nobody had spotted: they addressed the KPI, activity and widget rows as
// `.ob-detail-header__*`. Those rows are rendered by
// ApplicationDetailDashboard.vue under an `ob-detail-dashboard__` prefix — the
// UI was built and rendering the whole time, the selectors just named the wrong
// component. Corrected in place.
test.describe('Application detail — maintainer dashboard (REQ-OBADO-001..012)', () => {
	test('renders the six stacked rows when the hello-world app is opened', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'hello-world Application not found')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'hello-world Application not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header', { timeout: 15_000 })

		// REQ-OBADO-001 — hero, controls, KPIs, activity, widgets all present.
		//
		// The rows below the hero live in ApplicationDetailDashboard.vue, under an
		// `ob-detail-dashboard__` prefix; only the hero/controls/pills belong to
		// ApplicationDetailHeader.vue. This spec asked for all five under the
		// HEADER prefix, so the three dashboard rows could never match — the UI is
		// built and rendering, the selectors named a component that does not own it.
		await expect(page.locator('.ob-detail-header__hero')).toBeVisible()
		await expect(page.locator('.ob-detail-header__controls')).toBeVisible()
		await expect(page.locator('.ob-detail-dashboard__kpis')).toBeVisible()
		await expect(page.locator('.ob-detail-dashboard__activity, .ob-detail-dashboard__activity-empty').first()).toBeVisible()
		await expect(page.locator('.ob-detail-dashboard__widgets')).toBeVisible()
	})

	test('pill strip carries production-asterisk marker (REQ-OBADO-002)', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'app lookup failed')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'app not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		const pills = page.locator('.ob-detail-header__pill')
		const count = await pills.count()
		expect(count).toBeGreaterThan(0)
		const allText = await pills.allTextContents()
		// Production is marked by `*` prefix in its label.
		expect(allText.some((t) => t.includes('*'))).toBe(true)
	})

	test('window toggle change reloads the insights payload (REQ-OBADO-003)', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'app lookup failed')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'app not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		// The time-range control is a `role="group"` of NcButtons in the KPI
		// toolbar (ApplicationDetailDashboard.vue), not a `__window-btn` in the
		// header, and the active one is marked with `aria-pressed`, not an
		// `--active` class — NcButton owns its own class names.
		const windowButtons = page.locator('.ob-detail-dashboard__range button')
		await expect(windowButtons.first()).toBeVisible({ timeout: 20_000 })

		// Click 30d and assert the insights payload is actually RE-FETCHED for the
		// new window. The original captured this request and then discarded it
		// (`void req`, "best-effort") — which is the green-but-dead shape: it would
		// have reported coverage of REQ-OBADO-003 while proving only that a button
		// highlights. The whole requirement is that changing the window reloads the
		// payload, so the request is the assertion.
		const requestPromise = page.waitForRequest(/\/insights\?.*window=30d/, { timeout: 15_000 })
		await windowButtons.nth(1).click()
		const req = await requestPromise
		expect(req.url(), 'changing the window must re-fetch insights for that window').toMatch(/window=30d/)

		const activeBtn = page.locator('.ob-detail-dashboard__range button[aria-pressed="true"]')
		await expect(activeBtn).toHaveText('30d')
	})

	test('Promote affordance does not appear on the terminal production pill (REQ-OBADO-012)', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'app lookup failed')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'app not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		const pills = page.locator('.ob-detail-header__pill-group')
		const pillCount = await pills.count()
		test.skip(pillCount < 2, 'need at least two versions for this test')
		// Terminal pill — production — has no Promote affordance.
		const last = pills.nth(pillCount - 1)
		await expect(last.locator('.ob-detail-header__pill-promote')).toHaveCount(0)
	})
})

// UN-QUARANTINED 2026-07-31. Re-checked each stated blocker against the file as
// it actually stands:
//
//   - "own form login instead of the shared storageState" — not true of THIS
//     block; it never opted out, and inherits globalSetup's session already.
//   - "needs a multi-version chain" — true, and the two scenarios that need one
//     already guard themselves with `test.skip(pillCount < 2, …)`, so they skip
//     honestly instead of asserting nothing.
//   - "the 14.5 scenario ends on `void req`" — that discarded request was in the
//     window-toggle scenario in the block ABOVE, not here; 14.5 asserts its
//     `?_version=` param properly. The dead assertion is now a real one there.
//
// The 14.7/14.8 scenarios additionally addressed the widget and activity rows as
// `.ob-detail-header__*`; those rows belong to ApplicationDetailDashboard.vue
// (`ob-detail-dashboard__`). Corrected, so they now exercise the UI they name.
test.describe('Application detail overview — content scenarios (14.4/14.5/14.7/14.8)', () => {
	const TEST_SLUG = process.env.NC_OBADO_TEST_SLUG ?? 'hello-world'

	async function loadFirstApp(page: import('@playwright/test').Page): Promise<string | null> {
		const lookup = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		if (!lookup.ok()) return null
		const apps = (await lookup.json()).results || []
		if (apps.length === 0) return null
		return apps[0].uuid || apps[0].id
	}

	test('REQ-OBADO-002 (14.4) — viewer / non-member sees only the production pill', async ({ page }) => {
		const objectId = await loadFirstApp(page)
		test.skip(!objectId, 'hello-world app not seeded')

		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		const pillTexts = await page.locator('.ob-detail-header__pill').allTextContents()
		// The viewer-blackout assertion is exercised by openbuild-rbac;
		// this case asserts the contract that the admin/owner sees ALL
		// pills AND the production pill carries the `*` marker.
		const hasProductionMarker = pillTexts.some((t) => t.includes('*'))
		expect(hasProductionMarker).toBe(true)
	})

	test('REQ-OBADO-002 (14.5) — clicking a pill updates `?_version=` and re-renders the page', async ({ page }) => {
		const objectId = await loadFirstApp(page)
		test.skip(!objectId, 'hello-world app not seeded')

		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		const pillCount = await page.locator('.ob-detail-header__pill').count()
		test.skip(pillCount < 2, 'need at least two versions for this test')

		// Click the FIRST pill (upstream-most — usually development).
		const firstPill = page.locator('.ob-detail-header__pill').first()
		const firstPillText = (await firstPill.innerText()).trim().toLowerCase()
		await firstPill.click()

		// URL must carry `?_version=<slug>` after the click.
		await page.waitForURL((url) => /[?&]_version=/.test(url.toString()), { timeout: 5_000 })
		const url = new URL(page.url())
		const versionParam = url.searchParams.get('_version')
		expect(versionParam, 'pill click must add ?_version= to the URL').toBeTruthy()
		expect(firstPillText).toContain((versionParam || '').toLowerCase())
	})

	test('REQ-OBADO-007/009/010 (14.7) — structural widget deep-links preserve ?_version=', async ({ page }) => {
		const objectId = await loadFirstApp(page)
		test.skip(!objectId, 'hello-world app not seeded')

		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		// Click first non-production pill to populate ?_version=.
		const pills = page.locator('.ob-detail-header__pill')
		const count = await pills.count()
		test.skip(count < 2, 'need at least two versions')
		await pills.first().click()
		await page.waitForURL((url) => /[?&]_version=/.test(url.toString()), { timeout: 5_000 })

		const versionSlug = new URL(page.url()).searchParams.get('_version')
		expect(versionSlug).toBeTruthy()

		// Find every deep-link anchor inside the structural widgets row
		// (Register / Schemas / Pages / Menu cards). If any of them carry
		// a builder-host or openregister-target href, ensure the version is
		// either embedded in the path (`-{slug}`) or forwarded as `?_version=`.
		const widgetLinks = page.locator('.ob-detail-dashboard__widgets a[href]')
		const linkCount = await widgetLinks.count()
		test.skip(linkCount === 0, 'no deep-link anchors rendered in widget shelf')

		for (let i = 0; i < linkCount; i++) {
			const href = await widgetLinks.nth(i).getAttribute('href')
			if (!href) continue
			const carriesVersion
				= href.includes(`-${versionSlug}`)
				|| href.includes(`_version=${versionSlug}`)
				|| href.includes(`?_version=${versionSlug}`)
			if (!carriesVersion) {
				// Some links (e.g. external Open in OpenRegister) carry the version
				// in the register slug itself; the assertion above already covers that.
				// If neither path nor query carries the slug, fail.
				expect(carriesVersion, `widget link ${href} must carry ?_version=${versionSlug} or the register-suffix form`).toBe(true)
			}
		}
	})

	test('REQ-OBADO-005 (14.8) — activity row renders either the chart or the empty-state', async ({ page }) => {
		const objectId = await loadFirstApp(page)
		test.skip(!objectId, 'hello-world app not seeded')

		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-dashboard__activity', { timeout: 20_000 })

		// EITHER the chart is rendered (non-empty activity[]) OR the empty-state
		// copy is ("No activity in the selected window").
		//
		// Match the CHART, not the row: `.ob-detail-dashboard__activity` is the
		// wrapper that CONTAINS the empty state, so using it here made the
		// mutually-exclusive assertion below fail against a perfectly correct UI —
		// wrapper and empty state are both visible by construction.
		const chart = page.locator('.ob-detail-dashboard__activity-chart').first()
		const empty = page.locator('.ob-detail-dashboard__activity-empty').first()
		const chartVisible = await chart.isVisible({ timeout: 2_000 }).catch(() => false)
		const emptyVisible = await empty.isVisible({ timeout: 2_000 }).catch(() => false)
		expect(chartVisible || emptyVisible, 'activity row must render either chart or empty-state').toBe(true)

		// Never both at the same time.
		if (chartVisible) expect(emptyVisible).toBe(false)
	})
})

// UN-QUARANTINED 2026-07-30. Quarantining this block was spurious: it opens no
// browser and asserts no UI — both tests are `request`-only contract checks on
// the insights endpoint (400 for an invalid window enum with the spec-defined
// body; 404 for an unknown appUuid, and specifically WITHOUT the
// `public, max-age=60` cache header a 200 carries). A non-functional admin UI
// could not have affected either.
test.describe('Application insights — endpoint surface', () => {
	test('invalid window enum returns 400 with the spec-defined body', async ({ request }) => {
		test.skip(!LIVE, 'OPENBUILD_E2E_LIVE not set')
		const res = await request.get(
			`${BASE}/index.php/apps/openbuild/api/applications/00000000-0000-0000-0000-000000000001/versions/00000000-0000-0000-0000-000000000002/insights?window=24h`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(res.status()).toBe(400)
		const body = await res.json().catch(() => ({}))
		expect(body.status).toBe(400)
		expect(String(body.message || '')).toMatch(/Invalid window/)
	})

	test('unknown appUuid returns 404 without the public cache header', async ({ request }) => {
		test.skip(!LIVE, 'OPENBUILD_E2E_LIVE not set')
		const res = await request.get(
			`${BASE}/index.php/apps/openbuild/api/applications/ffffffff-ffff-ffff-ffff-ffffffffffff/versions/00000000-0000-0000-0000-000000000002/insights?window=7d`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(res.status()).toBe(404)
		const cache = res.headers()['cache-control'] || ''
		expect(cache).not.toMatch(/public,\s*max-age=60/)
	})
})
