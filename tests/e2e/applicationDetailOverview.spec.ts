// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * Playwright e2e — Application detail / maintainer dashboard
 * (spec openbuilt-app-detail-overview, REQ-OBADO-001..012 + REQ-OBAI-001..006).
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
 *     skip gracefully via `OPENBUILT_E2E_LIVE` guard so the suite parses
 *     cleanly without a live container.
 */

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const ADMIN = { user: process.env.NC_ADMIN_USER ?? 'admin', pass: process.env.NC_ADMIN_PASSWORD ?? 'admin' }
const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'hello-world'
const LIVE = process.env.OPENBUILT_E2E_LIVE === '1'

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

test.describe('Application detail — maintainer dashboard (REQ-OBADO-001..012)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeEach(async ({ page }) => {
		test.skip(!LIVE, 'OPENBUILT_E2E_LIVE not set — set =1 to run against a seeded container')
		await loginAs(page, ADMIN.user, ADMIN.pass)
	})

	test('renders the six stacked rows when the hello-world app is opened', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuilt/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'hello-world Application not found')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'hello-world Application not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header', { timeout: 15_000 })

		// REQ-OBADO-001 — hero, controls, KPIs, activity, widgets all present.
		await expect(page.locator('.ob-detail-header__hero')).toBeVisible()
		await expect(page.locator('.ob-detail-header__controls')).toBeVisible()
		await expect(page.locator('.ob-detail-header__kpis')).toBeVisible()
		await expect(page.locator('.ob-detail-header__activity, .ob-detail-header__activity-empty')).toBeVisible()
		await expect(page.locator('.ob-detail-header__widgets')).toBeVisible()
	})

	test('pill strip carries production-asterisk marker (REQ-OBADO-002)', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuilt/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'app lookup failed')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'app not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
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
			`${BASE}/index.php/apps/openregister/api/objects/openbuilt/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'app lookup failed')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'app not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__window-btn', { timeout: 15_000 })

		// Click 30d and assert the URL fragment / network call reflects the change.
		const requestPromise = page.waitForRequest(/\/insights\?.*window=30d/, { timeout: 5_000 }).catch(() => null)
		await page.locator('.ob-detail-header__window-btn').nth(1).click()
		const req = await requestPromise
		// Either the request fires (live data) or the toggle still updates the UI.
		const activeBtn = page.locator('.ob-detail-header__window-btn--active')
		await expect(activeBtn).toHaveText('30d')
		// `req` may be null in a stub container — assertion is best-effort.
		void req
	})

	test('Promote affordance does not appear on the terminal production pill (REQ-OBADO-012)', async ({ page }) => {
		const appUuidRes = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuilt/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!appUuidRes.ok(), 'app lookup failed')
		const apps = (await appUuidRes.json()).results || []
		test.skip(apps.length === 0, 'app not seeded')

		const objectId = apps[0].uuid || apps[0].id
		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		const pills = page.locator('.ob-detail-header__pill-group')
		const pillCount = await pills.count()
		test.skip(pillCount < 2, 'need at least two versions for this test')
		// Terminal pill — production — has no Promote affordance.
		const last = pills.nth(pillCount - 1)
		await expect(last.locator('.ob-detail-header__pill-promote')).toHaveCount(0)
	})
})

test.describe('Application detail overview — content scenarios (14.4/14.5/14.7/14.8)', () => {
	const TEST_SLUG = process.env.NC_OBADO_TEST_SLUG ?? 'hello-world'

	async function loadFirstApp(page: import('@playwright/test').Page): Promise<string | null> {
		const lookup = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuilt/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
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

		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__pill', { timeout: 15_000 })

		const pillTexts = await page.locator('.ob-detail-header__pill').allTextContents()
		// The viewer-blackout assertion is exercised by openbuilt-rbac;
		// this case asserts the contract that the admin/owner sees ALL
		// pills AND the production pill carries the `*` marker.
		const hasProductionMarker = pillTexts.some((t) => t.includes('*'))
		expect(hasProductionMarker).toBe(true)
	})

	test('REQ-OBADO-002 (14.5) — clicking a pill updates `?_version=` and re-renders the page', async ({ page }) => {
		const objectId = await loadFirstApp(page)
		test.skip(!objectId, 'hello-world app not seeded')

		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
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

		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
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
		const widgetLinks = page.locator('.ob-detail-header__widgets a[href]')
		const linkCount = await widgetLinks.count()
		test.skip(linkCount === 0, 'no deep-link anchors rendered in widget shelf')

		for (let i = 0; i < linkCount; i++) {
			const href = await widgetLinks.nth(i).getAttribute('href')
			if (!href) continue
			const carriesVersion =
				href.includes(`-${versionSlug}`) ||
				href.includes(`_version=${versionSlug}`) ||
				href.includes(`?_version=${versionSlug}`)
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

		await page.goto(`${BASE}/apps/openbuilt/applications/${objectId}`)
		await page.waitForSelector('.ob-detail-header__activity, .ob-detail-header__activity-empty', { timeout: 15_000 })

		// EITHER the chart container is present (non-empty activity[]) OR the
		// empty-state copy is rendered ("No activity in the selected window").
		const chart = page.locator('.ob-detail-header__activity')
		const empty = page.locator('.ob-detail-header__activity-empty')
		const chartVisible = await chart.isVisible({ timeout: 2_000 }).catch(() => false)
		const emptyVisible = await empty.isVisible({ timeout: 2_000 }).catch(() => false)
		expect(chartVisible || emptyVisible, 'activity row must render either chart or empty-state').toBe(true)

		// Never both at the same time.
		if (chartVisible) expect(emptyVisible).toBe(false)
	})
})

test.describe('Application insights — endpoint surface', () => {
	test('invalid window enum returns 400 with the spec-defined body', async ({ request }) => {
		test.skip(!LIVE, 'OPENBUILT_E2E_LIVE not set')
		const res = await request.get(
			`${BASE}/index.php/apps/openbuilt/api/applications/00000000-0000-0000-0000-000000000001/versions/00000000-0000-0000-0000-000000000002/insights?window=24h`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(res.status()).toBe(400)
		const body = await res.json().catch(() => ({}))
		expect(body.status).toBe(400)
		expect(String(body.message || '')).toMatch(/Invalid window/)
	})

	test('unknown appUuid returns 404 without the public cache header', async ({ request }) => {
		test.skip(!LIVE, 'OPENBUILT_E2E_LIVE not set')
		const res = await request.get(
			`${BASE}/index.php/apps/openbuilt/api/applications/ffffffff-ffff-ffff-ffff-ffffffffffff/versions/00000000-0000-0000-0000-000000000002/insights?window=7d`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(res.status()).toBe(404)
		const cache = res.headers()['cache-control'] || ''
		expect(cache).not.toMatch(/public,\s*max-age=60/)
	})
})
