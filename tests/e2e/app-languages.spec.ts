/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `experience-multilingual-apps`, task T05 (REQ-BQML-003): a user with Dutch
 * as Nextcloud language sees the Dutch menu and column labels of an app, and
 * the English text where a label has no Dutch translation.
 *
 * `beforeAll` runs as admin in its own context: it clones the
 * `incident-reporter` template (an index page on a real schema, so the table
 * and its column headers render; an index without records shows only its
 * empty state), adds one record, grants `rbac-viewer` (provisioned by
 * tests/e2e/global-setup.ts) as viewer, gives the app English labels with
 * Dutch translations for one menu item and one column, and sets the viewer's
 * Nextcloud language to Dutch. `afterAll` puts the viewer's language back and
 * removes the app, so other suites keep reading English.
 *
 * The test itself runs as that NON-ADMIN viewer, logged in from an empty
 * storage state, on the app's runtime page (`/apps/buildiq/builder/{slug}/`).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { adminPage, cloneTemplateApp, deleteApp, fixtureSlug } from './support/templateApp.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const VIEWER_USER = process.env.NC_RBAC_VIEWER_USER ?? 'rbac-viewer'
const VIEWER_PASS = process.env.NC_RBAC_VIEWER_PASS ?? 'RbacViewer-1!'

const MENU_TRANSLATED = 'Products'
const MENU_TRANSLATED_NL = 'Producten'
const MENU_UNTRANSLATED = 'Suppliers'
const COLUMN_TRANSLATED = 'Place'
const COLUMN_TRANSLATED_NL = 'Plaats'
const COLUMN_UNTRANSLATED = 'Reported by'

/** The fixture app, cloned in beforeAll. */
let app: TemplateApp | null = null

/** The route of its translated index page, without the leading slash. */
let pageRoute = ''

/** The viewer's language before this suite, restored in afterAll. */
let previousLanguage = ''

/**
 * Call a Nextcloud route from the page, with its session and request token.
 *
 * @param page   A logged-in page.
 * @param method HTTP method.
 * @param path   Path from the web root.
 * @param body   JSON body, or a URLSearchParams form, if any.
 * @return Status and parsed body.
 */
async function call(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body, form }) => {
			const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(path, {
				method,
				headers: {
					requesttoken: tok,
					'OCS-APIRequest': 'true',
					'Content-Type': form ? 'application/x-www-form-urlencoded' : 'application/json',
				},
				body: body === undefined ? undefined : (form ? String(body) : JSON.stringify(body)),
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
		{ method, path, body: body instanceof URLSearchParams ? body.toString() : body, form: body instanceof URLSearchParams },
	)
}

/**
 * Set a user's Nextcloud language, as admin, returning the one it had.
 *
 * @param page     An admin page.
 * @param user     The user id.
 * @param language The language code.
 * @return The previous language.
 */
async function setLanguage(page: Page, user: string, language: string): Promise<string> {
	const read = await call(page, 'GET', `/ocs/v2.php/cloud/users/${encodeURIComponent(user)}?format=json`)
	expect(read.status, `read ${user}`).toBe(200)
	const before = String(read.data?.ocs?.data?.language ?? '')
	const write = await call(page, 'PUT', `/ocs/v2.php/cloud/users/${encodeURIComponent(user)}?format=json`, new URLSearchParams({ key: 'language', value: language }))
	expect(write.status, `set ${user}'s language`).toBe(200)
	return before
}

/**
 * Give the app's index page an English title and columns, a second menu
 * entry, and Dutch translations for one menu item and one column; add one
 * record so the table renders.
 *
 * @param page    An admin page.
 * @param fixture The cloned app.
 * @return The route of the index page, without the leading slash.
 */
async function seedLabels(page: Page, fixture: TemplateApp): Promise<string> {
	const path = `/index.php/apps/buildiq/api/applications/${fixture.slug}/manifest`
	const read = await call(page, 'GET', path)
	expect(read.status, 'GET manifest').toBe(200)
	const manifest = read.data
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== 'leveranciers')
	const index = pages.find((p: Record<string, any>) => p.type === 'index' && p.config?.schema === fixture.schema)
	expect(index, 'the template has an index page on its schema').toBeTruthy()
	index.title = MENU_TRANSLATED
	index.config = { ...index.config, columns: [{ key: 'location', label: COLUMN_TRANSLATED }, { key: 'reportedBy', label: COLUMN_UNTRANSLATED }] }
	pages.push({ id: 'leveranciers', type: 'index', route: '/leveranciers', title: MENU_UNTRANSLATED, config: { columns: [] } })
	const route = String(index.route ?? `/${index.id}`).replace(/^\/+/, '')
	const menu = [
		{ id: index.id, label: MENU_TRANSLATED, route: index.id },
		{ id: 'leveranciers', label: MENU_UNTRANSLATED, route: 'leveranciers' },
	]
	const i18n = {
		sourceLanguage: 'en',
		languages: ['nl'],
		labels: { nl: { [MENU_TRANSLATED]: MENU_TRANSLATED_NL, [COLUMN_TRANSLATED]: COLUMN_TRANSLATED_NL } },
	}
	const write = await call(page, 'PUT', path, { manifest: { ...manifest, pages, menu, i18n } })
	expect(write.status, `seed labels: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)

	const record = await call(page, 'POST', `/index.php/apps/openregister/api/objects/${fixture.register}/${fixture.schema}`, {
		reportedBy: 'e2e',
		incidentType: 'inspection',
		severity: 'low',
		status: 'new',
		reportedAt: new Date().toISOString(),
		location: 'Stadhuis',
	})
	expect(record.status, `seed a record: ${JSON.stringify(record.data).slice(0, 200)}`).toBeLessThan(300)
	return route
}

/**
 * Log in through the login form.
 *
 * @param page Playwright page in an empty storage state.
 * @param user User id.
 * @param pass Password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} appears to have failed, still on ${page.url()}.`)
	}
}

test.describe('experience-multilingual-apps: users see the app in their own language', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			await suppressSupportDialog(page)
			app = await cloneTemplateApp(page, fixtureSlug('app-languages'), 'PW App Languages')
			await grantAppRoles(page, app.slug, { owners: [`user:${ADMIN_USER}`], viewers: [`user:${VIEWER_USER}`] })
			pageRoute = await seedLabels(page, app)
			previousLanguage = await setLanguage(page, VIEWER_USER, 'nl')
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			await setLanguage(page, VIEWER_USER, previousLanguage || 'en')
			await deleteApp(page, app?.uuid ?? '')
		} finally {
			await admin.close()
		}
	})

	test('REQ-BQML-003: a Dutch user sees the Dutch menu and column labels, and English where one is missing', async ({ page }) => {
		// @e2e openspec/changes/archive/2026-11-24-experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-an-app-user-sees-the-app-in-their-language-req-bqml-003
		await loginAs(page, VIEWER_USER, VIEWER_PASS)
		await suppressSupportDialog(page)
		expect(app, 'the fixture app was cloned').not.toBeNull()
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${app!.slug}/${pageRoute}`, { waitUntil: 'domcontentloaded' })

		// The app's own navigation (NcAppNavigation, the skip link's target):
		// the first <nav> on the page is Nextcloud's header app menu.
		const navigation = page.locator('#app-navigation-vue')
		await expect(navigation.getByText(MENU_TRANSLATED_NL, { exact: true })).toBeVisible({ timeout: 30_000 })
		await expect(navigation.getByText(MENU_UNTRANSLATED, { exact: true })).toBeVisible()
		await expect(navigation.getByText(MENU_TRANSLATED, { exact: true })).toHaveCount(0)

		await expect(page.getByRole('columnheader', { name: COLUMN_TRANSLATED_NL })).toBeVisible({ timeout: 30_000 })
		await expect(page.getByRole('columnheader', { name: COLUMN_UNTRANSLATED })).toBeVisible()
	})
})
