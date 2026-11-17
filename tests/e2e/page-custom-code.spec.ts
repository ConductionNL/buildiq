/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change `pages-custom-code`,
 * task T08 (REQ-BQCC-001, REQ-BQCC-002, REQ-BQCC-003, REQ-BQCC-005): an owner
 * adds a code widget and a computed column; the code runs in its sandbox
 * document, whose policy blocks a request to the API; an editor is refused;
 * and with the instance setting off even the owner is refused.
 *
 * `beforeAll` runs as admin in its own context: it builds the fixture app,
 * grants `rbac-owner` as owner and `rbac-editor` as editor (both provisioned by
 * tests/e2e/global-setup.ts), and switches "Allow code on pages" on. `afterAll`
 * puts the setting back as it was. The tests run as those NON-ADMIN users in
 * an empty storage state.
 *
 * Rendering code inside a built app waits for nextcloud-vue's `CnCodeFrame`
 * (task T06); the sandbox document buildiq serves is checked directly, with
 * the session of an app user, which is what the frame loads.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureApp, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const OWNER_USER = process.env.NC_RBAC_OWNER_USER ?? 'rbac-owner'
const OWNER_PASS = process.env.NC_RBAC_OWNER_PASS ?? 'RbacOwner-1!'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'

const APP_SLUG = 'pw-page-code'
const CODE_PAGE = 'code-widget'
const INDEX_PAGE = 'orders'
const CODE = {
	html: '<p id="hello">Hallo</p>',
	css: '#hello { font-weight: bold; }',
	js: "document.getElementById('hello').textContent = 'Hallo uit code';",
	inputs: ['user'],
}

/** The page-code setting before this suite, restored in afterAll. */
let previousSetting = false

/**
 * Call a buildiq route from the page with its session and request token.
 *
 * @param page   A logged-in page on a buildiq URL.
 * @param method HTTP method.
 * @param path   Path from the web root.
 * @param body   JSON body, if any.
 * @return Status and parsed body.
 */
async function call(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(path, {
				method,
				headers: { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
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
 * Log in through the login form and land on buildiq.
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
	await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
}

/**
 * Switch "Allow code on pages" as admin, returning what it was.
 *
 * @param browser The browser.
 * @param on      Whether to switch it on.
 * @return The previous value.
 */
async function setPageCode(browser: Browser, on: boolean): Promise<boolean> {
	const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
	const page = await admin.newPage()
	try {
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		const read = await call(page, 'GET', '/index.php/apps/buildiq/api/settings')
		const before = read.data?.allow_page_code === true
		const write = await call(page, 'POST', '/index.php/apps/buildiq/api/settings', { allow_page_code: on })
		expect(write.status, 'switch the page-code setting').toBeLessThan(300)
		return before
	} finally {
		await admin.close()
	}
}

/**
 * The app's manifest with a code widget page and an index page with a computed column.
 *
 * @param manifest The stored manifest.
 * @param code     The code of the widget page.
 * @return The new manifest.
 */
function withCode(manifest: Record<string, any>, code: Record<string, unknown>): Record<string, any> {
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== CODE_PAGE && p.id !== INDEX_PAGE)
	pages.push({ id: CODE_PAGE, type: 'custom', route: `/${CODE_PAGE}`, title: 'Code', config: { code } })
	pages.push({
		id: INDEX_PAGE,
		type: 'index',
		route: `/${INDEX_PAGE}`,
		title: 'Orders',
		config: { columns: [{ key: 'total', label: 'Total', expression: 'row.price * row.quantity' }] },
	})
	return { ...manifest, pages }
}

/**
 * Save the manifest with this code as the logged-in user.
 *
 * @param page A logged-in page.
 * @param code The code of the widget page.
 * @return The save's status and body.
 */
async function saveCode(page: Page, code: Record<string, unknown>): Promise<{ status: number; data: any }> {
	const path = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
	const read = await call(page, 'GET', path)
	expect(read.status, 'GET manifest').toBe(200)
	return call(page, 'PUT', path, { manifest: withCode(read.data, code) })
}

test.describe('pages-custom-code: code on pages', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Page Code')
			await grantAppRoles(page, APP_SLUG, { owners: [`user:${OWNER_USER}`, `user:${ADMIN_USER}`], editors: [`user:${EDITOR_USER}`] })
		} finally {
			await admin.close()
		}
		previousSetting = await setPageCode(browser, true)
	})

	test.afterAll(async ({ browser }) => {
		await setPageCode(browser, previousSetting)
	})

	test('REQ-BQCC-001, REQ-BQCC-002: an owner adds a code widget and a computed column', async ({ page }) => {
		// @e2e openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-a-code-component-req-bqcc-001
		// @e2e openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		await loginAs(page, OWNER_USER, OWNER_PASS)

		const saved = await saveCode(page, CODE)
		expect(saved.status, JSON.stringify(saved.data).slice(0, 300)).toBeLessThan(300)

		const read = await call(page, 'GET', `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`)
		const pages = read.data.pages as Array<Record<string, any>>
		expect(pages.find((p) => p.id === CODE_PAGE)?.config.code).toMatchObject(CODE)
		expect(pages.find((p) => p.id === INDEX_PAGE)?.config.columns[0].expression).toBe('row.price * row.quantity')
	})

	test('REQ-BQCC-003: the code runs in its sandbox document, and a request to the API is blocked', async ({ page }) => {
		// @e2e openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
		await loginAs(page, OWNER_USER, OWNER_PASS)
		const saved = await saveCode(page, CODE)
		expect(saved.status).toBeLessThan(300)

		const response = await page.goto(`${BASE_URL}/index.php/apps/buildiq/sandbox/${APP_SLUG}/production/${CODE_PAGE}`)
		expect(response?.status()).toBe(200)
		const policy = response?.headers()['content-security-policy'] ?? ''
		expect(policy).toContain("default-src 'none'")
		expect(policy).not.toMatch(/connect-src [^;]*('self'|https?:|\*)/)

		await expect(page.locator('#hello')).toHaveText('Hallo uit code')
		const outcome = await page.evaluate(() => fetch('/index.php/apps/buildiq/api/applications').then(() => 'answered', () => 'blocked'))
		expect(outcome).toBe('blocked')
	})

	test('REQ-BQCC-005: an editor who is not an owner is refused', async ({ page }) => {
		// @e2e openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
		await loginAs(page, EDITOR_USER, EDITOR_PASS)

		const saved = await saveCode(page, { ...CODE, js: "document.body.textContent = 'editor';" })

		expect(saved.status).toBe(403)
		expect(saved.data.code).toBe('buildiq.code.owners_only')
	})

	test('REQ-BQCC-005: with the instance setting off, even an owner is refused', async ({ browser, page }) => {
		// @e2e openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
		await setPageCode(browser, false)
		try {
			await loginAs(page, OWNER_USER, OWNER_PASS)
			const saved = await saveCode(page, { ...CODE, js: "document.body.textContent = 'off';" })

			expect(saved.status).toBe(403)
			expect(saved.data.code).toBe('buildiq.code.disabled')
		} finally {
			await setPageCode(browser, true)
		}
	})
})
