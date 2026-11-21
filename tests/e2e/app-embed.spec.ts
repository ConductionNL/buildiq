/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: embedding an app in another site (change
 * apps-embed-in-other-sites, task T04, REQ-BQEM-001 to REQ-BQEM-004).
 *
 * A maker switches embedding on in App settings, lists the intranet and copies
 * the snippet; the runtime then answers the snippet URL without the Nextcloud
 * header and with a `frame-ancestors` policy naming exactly that origin.
 *
 * WHAT IS ASSERTED, AND WHERE
 * ---------------------------
 * - The setting is driven through the Embed section of App settings
 *   (`AppEmbedSettings.vue`) and read back from the OpenRegister object, so a
 *   save that never reached the server cannot pass.
 * - Framing is asserted on the HEADER the app sends, as the design's risk note
 *   asks: a front end may add its own `X-Frame-Options`, and a real
 *   cross-site frame carries no `SameSite=Lax` session at all, so a browser
 *   frame from a fake origin would be refused for both the listed and the
 *   unlisted site and prove nothing.
 * - The sign-in notice (REQ-BQEM-003) needs an embedded runtime whose manifest
 *   call has no session. A single browser cannot be signed in and signed out
 *   for the same request, so the manifest call is answered 401 by the test:
 *   that is exactly what the frame on another site receives, and it is the
 *   path `needsSignIn()` in `src/builder.js` takes.
 *
 * The fixture app `pw-app-embed` is created in beforeAll with two pages and
 * removed, data included, in afterAll.
 *
 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md
 * @e2e app-embedding/requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001/a-maker-allows-the-intranet
 * @e2e app-embedding/requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001/a-wildcard-is-refused
 * @e2e app-embedding/requirement-only-listed-sites-can-frame-the-app-req-bqem-002/the-intranet-shows-the-app
 * @e2e app-embedding/requirement-only-listed-sites-can-frame-the-app-req-bqem-002/another-site-cannot-frame-it
 * @e2e app-embedding/requirement-the-frame-never-asks-for-a-password-req-bqem-003/a-colleague-is-not-signed-in
 * @e2e app-embedding/requirement-a-maker-embeds-one-page-req-bqem-004/only-the-booking-form-goes-on-the-intranet
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import path from 'node:path'
import { dismissOverlays, ensureApp, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }

const APP_SLUG = 'pw-app-embed'
const APP_NAME = 'PW Room bookings'
const ORIGIN = 'https://intranet.example.nl'
const OTHER_ORIGIN = 'https://other.example.com'
const WILDCARD = 'https://*.example.nl'
const BOOK_PAGE = { id: 'book', title: 'Book a room' }
const OVERVIEW_PAGE = { id: 'overview', title: 'Room overview' }
const OR_APPLICATIONS = '/index.php/apps/openregister/api/objects/buildiq/built-app'
const SITE_LABEL = 'Allowed site, e.g. https://intranet.example.nl'

/**
 * Call a Nextcloud route from inside the page, with its session and request token.
 *
 * @param page   A signed-in page on a Nextcloud route.
 * @param method The HTTP method.
 * @param path   The path from the web root.
 * @param body   The JSON body, if any.
 * @return The status and the parsed answer.
 */
async function api(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(path, {
				method,
				headers: { requesttoken: token, 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await resp.text()
			let data: unknown = text
			try {
				data = JSON.parse(text)
			} catch {
				// Not JSON; keep the text for the failure message.
			}
			return { status: resp.status, data }
		},
		{ method, path, body },
	)
}

/**
 * The fixture app's OpenRegister object, as the settings modal saves it.
 *
 * @param page A signed-in page.
 * @return The Application object, or null.
 */
async function storedApp(page: Page): Promise<any> {
	const { data } = await api(page, 'GET', `${OR_APPLICATIONS}?slug=${APP_SLUG}&_limit=1`)
	const rows = Array.isArray(data) ? data : (data?.results ?? [])
	return rows[0] ?? null
}

/**
 * The fixture app's object id, which the detail route takes.
 *
 * @param page A signed-in page.
 * @return The id.
 */
async function appId(page: Page): Promise<string> {
	const app = await storedApp(page)
	return String(app?.id ?? app?.uuid ?? app?.['@self']?.id ?? '')
}

/**
 * Open the app detail page and its App settings modal.
 *
 * @param page A signed-in page.
 * @return The Embed section.
 */
async function openEmbedSettings(page: Page): Promise<Locator> {
	const id = await appId(page)
	expect(id, 'the fixture app must be listed').not.toBe('')
	await page.goto(`/apps/buildiq/applications/${id}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await page.waitForSelector('.ob-detail-header', { timeout: 30_000 })
	// Settings is promoted inline for an owner; its test id avoids Nextcloud's
	// own "Settings" entry in the user menu (see export-flows-and-agents.spec.ts).
	await page.locator('[data-testid="cn-action-app-settings-action"]').first().click()
	const section = page.locator('[data-test="app-embed-settings"]')
	await expect(section, 'App settings has an Embed section').toBeVisible({ timeout: 20_000 })
	return section
}

/**
 * Arm a wait for the settings modal's write of the Application object.
 *
 * @param page The page.
 * @return The response promise.
 */
function applicationWrite(page: Page) {
	return page.waitForResponse(
		(r) => r.url().includes('/apps/openregister/api/objects/') && r.request().method() === 'PUT',
		{ timeout: 30_000 },
	)
}

/**
 * The `src` of the snippet shown in the Embed section.
 *
 * @param section The Embed section.
 * @return The URL inside the snippet's iframe.
 */
async function snippetSrc(section: Locator): Promise<string> {
	const snippet = await section.locator('#app-embed-snippet').inputValue()
	const src = /src="([^"]+)"/.exec(snippet)?.[1] ?? ''
	return src.replace(/&amp;/g, '&')
}

test.describe('apps-embed-in-other-sites: an app shown inside the intranet', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 })

	let snippetUrl = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, APP_NAME)
			const manifestPath = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
			const read = await api(page, 'GET', manifestPath)
			expect(read.status, 'GET manifest').toBe(200)
			const keep = (read.data.pages || []).filter((p: { id: string }) => p.id !== BOOK_PAGE.id && p.id !== OVERVIEW_PAGE.id)
			const pages = [
				...keep,
				{ id: OVERVIEW_PAGE.id, route: `/${OVERVIEW_PAGE.id}`, type: 'dashboard', title: OVERVIEW_PAGE.title, config: { widgets: [], layout: [] } },
				{ id: BOOK_PAGE.id, route: `/${BOOK_PAGE.id}`, type: 'dashboard', title: BOOK_PAGE.title, config: { widgets: [], layout: [] } },
			]
			const menu = [
				{ id: OVERVIEW_PAGE.id, label: OVERVIEW_PAGE.title, route: OVERVIEW_PAGE.id },
				{ id: BOOK_PAGE.id, label: BOOK_PAGE.title, route: BOOK_PAGE.id },
			]
			const write = await api(page, 'PUT', manifestPath, { manifest: { ...read.data, pages, menu } })
			expect(write.status, `seed pages: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			const id = await appId(page)
			if (id !== '') {
				await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${id}`, { deleteData: true })
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	test('with embedding off, an embed request is served as usual and frames nothing', async ({ page }) => {
		const response = await page.request.get(`/index.php/apps/buildiq/builder/${APP_SLUG}?embed=1`)
		expect(response.ok()).toBeTruthy()
		const policy = response.headers()['content-security-policy'] ?? ''
		expect(policy, 'embedding is off by default').not.toContain(ORIGIN)
	})

	// @e2e app-embedding/requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001/a-wildcard-is-refused
	// @e2e app-embedding/requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001/a-maker-allows-the-intranet
	test('REQ-BQEM-001: the owner switches embedding on, a wildcard is refused, the intranet is stored', async ({ page }) => {
		const section = await openEmbedSettings(page)

		const enabled = applicationWrite(page)
		await section.getByText('Allow other sites to show this app').click()
		expect((await enabled).ok(), 'switching embedding on is saved').toBeTruthy()

		await section.getByRole('button', { name: 'Add site' }).click()
		const site = section.getByLabel(SITE_LABEL).first()
		await site.fill(WILDCARD)
		await expect(
			section.getByText('A wildcard is not allowed: list each site separately.'),
			'the row says to list each site separately',
		).toBeVisible()
		const refused = await storedApp(page)
		expect(refused?.embed?.origins ?? [], 'a wildcard never reaches the stored setting').not.toContain(WILDCARD)

		const saved = applicationWrite(page)
		await site.fill(ORIGIN)
		expect((await saved).ok(), 'the listed site is saved').toBeTruthy()

		await expect
			.poll(async () => (await storedApp(page))?.embed ?? null, { timeout: 20_000, message: 'the app stores the origin' })
			.toEqual({ enabled: true, origins: [ORIGIN] })

		await expect(section.locator('#app-embed-snippet'), 'the section shows a snippet to copy').toBeVisible({ timeout: 20_000 })
		snippetUrl = await snippetSrc(section)
		expect(snippetUrl).toContain(`/apps/buildiq/builder/${APP_SLUG}`)
		expect(snippetUrl).toContain('embed=1')
	})

	// @e2e app-embedding/requirement-only-listed-sites-can-frame-the-app-req-bqem-002/the-intranet-shows-the-app
	// @e2e app-embedding/requirement-only-listed-sites-can-frame-the-app-req-bqem-002/another-site-cannot-frame-it
	test('REQ-BQEM-002: the snippet URL lists only the intranet as frame ancestor and has no Nextcloud header', async ({ page }) => {
		test.skip(snippetUrl === '', 'needs the snippet from the previous test')

		const response = await page.request.get(snippetUrl)
		expect(response.ok()).toBeTruthy()
		const policy = response.headers()['content-security-policy'] ?? ''
		const ancestors = /frame-ancestors([^;]*)/.exec(policy)?.[1] ?? ''
		expect(ancestors, 'the policy names the intranet as a frame ancestor').toContain(ORIGIN)
		expect(ancestors, 'another site is not a frame ancestor').not.toContain(OTHER_ORIGIN)
		expect(ancestors, 'no wildcard source lets any site frame it').not.toMatch(/(^|\s)\*(\s|$)/)

		await page.goto(snippetUrl, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#content'), 'the app renders').toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#header'), 'the embedded app has no Nextcloud header').toHaveCount(0)

		// The same app without `embed=1` keeps the header: only the embed request changes.
		await page.goto(`/index.php/apps/buildiq/builder/${APP_SLUG}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#header')).toBeVisible({ timeout: 30_000 })
	})

	// @e2e app-embedding/requirement-a-maker-embeds-one-page-req-bqem-004/only-the-booking-form-goes-on-the-intranet
	test('REQ-BQEM-004: the snippet of one page opens that page in embed mode without navigation', async ({ page }) => {
		const section = await openEmbedSettings(page)
		await expect(section.locator('#app-embed-snippet')).toBeVisible({ timeout: 20_000 })

		await section.getByLabel('What to embed', { exact: true }).click()
		await page.getByRole('option', { name: BOOK_PAGE.title }).first().click()
		await expect.poll(() => snippetSrc(section)).toContain(`/${BOOK_PAGE.id}?embed=1`)
		const pageUrl = await snippetSrc(section)

		await page.goto(pageUrl, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#content')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#header')).toHaveCount(0)
		await expect(page.getByText(BOOK_PAGE.title).first(), 'the chosen page shows').toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(OVERVIEW_PAGE.title), 'no navigation leads to the other page').toHaveCount(0)
	})

	// @e2e app-embedding/requirement-the-frame-never-asks-for-a-password-req-bqem-003/a-colleague-is-not-signed-in
	test('REQ-BQEM-003: without a session the frame offers a sign-in link in a new tab, never a login form', async ({ page }) => {
		test.skip(snippetUrl === '', 'needs embedding switched on by the earlier test')

		// What a frame on another site receives: the manifest call carries no
		// session cookie and is refused.
		await page.route(`**/apps/buildiq/api/applications/${APP_SLUG}/manifest**`, (route) => route.fulfill({
			status: 401,
			contentType: 'application/json',
			body: JSON.stringify({ message: 'Current user is not logged in' }),
		}))
		await page.goto(snippetUrl, { waitUntil: 'domcontentloaded' })

		await expect(page.getByText('Sign in to use this app')).toBeVisible({ timeout: 30_000 })
		const link = page.getByRole('link', { name: 'Sign in to Nextcloud (opens in a new tab)' })
		await expect(link).toHaveAttribute('target', '_blank')
		await expect(link).toHaveAttribute('href', /\/login\?redirect_url=/)
		await expect(page.locator('input[type="password"]'), 'no login form inside the frame').toHaveCount(0)
	})
})
