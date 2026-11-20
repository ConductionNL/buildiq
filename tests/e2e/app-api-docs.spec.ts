/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: an app's API tab (change operate-api-docs, task T03,
 * REQ-BQAD-001 and REQ-BQAD-003).
 *
 * The fixture app is cloned from the `permit-tracker` template, whose pages use
 * the schema `permit-application` (the spec's `permit`). The test opens the
 * app detail page's "API" sidebar tab (`ApplicationApiTab`, registered in
 * `src/registry.js` and listed in `VirtualAppDetail.sidebarTabs`), finds the
 * schema's list call and the base address, and downloads the OpenAPI file,
 * which must parse as OpenAPI 3 titled after the app. Every request the page
 * makes while the tab is open must stay on this instance.
 *
 * A second test opens the same tab as a NON-ADMIN viewer of the app
 * (`rbac-viewer`, provisioned by tests/e2e/global-setup.ts), because the tab
 * is meant for owners, editors and viewers alike.
 *
 * NOT COVERED HERE: REQ-BQAD-002 (a data register with 30 schemas cut down to
 * the one the pages use) needs a shared register bound to the app, which the
 * CI stack does not seed; the cut itself is asserted in Vitest
 * (`tests/services/appOpenApi.spec.js`). The tab renders the structured list,
 * not nextcloud-vue's OpenAPI reference component (task T04, not shipped), so
 * nothing here depends on that component.
 *
 * The fixture app `pw-app-api-docs` is created in beforeAll and removed, data
 * included, in afterAll.
 *
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md
 * @e2e app-api-docs/requirement-an-app-has-an-api-tab-per-version-req-bqad-001/an-integrator-reads-the-permit-api
 * @e2e app-api-docs/requirement-the-reference-stays-inside-the-instance-and-downloads-as-a-file-req-bqad-003/a-maker-downloads-the-file-for-a-supplier
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import { suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const VIEWER_USER = process.env.NC_RBAC_VIEWER_USER ?? 'rbac-viewer'
const VIEWER_PASS = process.env.NC_RBAC_VIEWER_PASS ?? 'RbacViewer-1!'

const APP_SLUG = 'pw-app-api-docs'
const APP_NAME = 'PW Vergunningen'
const TEMPLATE = 'permit-tracker'
const SCHEMA = 'permit-application'

let appUuid = ''

/**
 * Call a Nextcloud route from inside the page, with its session and request token.
 *
 * @param page   A signed-in page on a Nextcloud route.
 * @param method The HTTP method.
 * @param url    The path from the web root.
 * @param body   The JSON body, if any.
 * @return The status and the parsed answer.
 */
async function api(page: Page, method: string, url: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, url, body }) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(url, {
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
		{ method, url, body },
	)
}

/**
 * Log in through the login form.
 *
 * @param page Playwright page in an empty storage state.
 * @param user User id.
 * @param pass Password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${E2E_BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} appears to have failed, still on ${page.url()}.`)
	}
}

/**
 * Open the app detail page and its "API" sidebar tab.
 *
 * The sidebar re-mounts once the detail page has loaded, which can undo an
 * early tab click, so the end state (the tab's body on screen) is polled the
 * way version-rollback.spec.ts opens "Version history".
 *
 * @param page A signed-in page.
 * @return Resolves once the tab's body is visible.
 */
async function openApiTab(page: Page): Promise<void> {
	await page.goto(`${E2E_BASE_URL}/apps/buildiq/applications/${appUuid}`, { waitUntil: 'domcontentloaded' })
	const tab = page.getByRole('tab', { name: /^API$/ }).first()
	const openSidebar = page.getByRole('button', { name: 'Open sidebar', exact: true })
	const body = page.locator('.ob-api-tab').first()
	await expect(async () => {
		if (!(await tab.isVisible().catch(() => false))) {
			await openSidebar.click({ timeout: 10_000 })
		}
		await tab.click({ timeout: 10_000 })
		await expect(tab).toHaveAttribute('aria-selected', 'true', { timeout: 5_000 })
		await expect(body).toBeVisible({ timeout: 5_000 })
	}, 'the API tab must open and render its body').toPass({ timeout: 60_000, intervals: [500, 1_000, 2_000] })
}

test.describe('operate-api-docs: the API tab of an app', () => {
	test.describe.configure({ mode: 'serial', timeout: 150_000 })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			const created = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/from-template/${TEMPLATE}`, {
				name: APP_NAME,
				slug: APP_SLUG,
			})
			expect(created.status, JSON.stringify(created.data).slice(0, 300)).toBeLessThan(300)
			appUuid = String(created.data?.uuid ?? created.data?.id ?? created.data?.application?.uuid ?? '')
			expect(appUuid, 'the cloned app has a uuid').not.toBe('')
			await grantAppRoles(page, APP_SLUG, { owners: [`user:${ADMIN_USER}`], viewers: [`user:${VIEWER_USER}`] })
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (appUuid === '') {
			return
		}
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${appUuid}`, { deleteData: true })
		} finally {
			await page.close()
		}
	})

	// @e2e app-api-docs/requirement-an-app-has-an-api-tab-per-version-req-bqad-001/an-integrator-reads-the-permit-api
	// @e2e app-api-docs/requirement-the-reference-stays-inside-the-instance-and-downloads-as-a-file-req-bqad-003/a-maker-downloads-the-file-for-a-supplier
	test('the owner reads the schema\'s calls and downloads an OpenAPI 3 file, with nothing fetched from outside', async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)

		const instance = new URL(E2E_BASE_URL).origin
		const outside: string[] = []
		page.on('request', (request) => {
			const url = request.url()
			if (/^(data|blob|about):/.test(url)) {
				return
			}
			if (new URL(url).origin !== instance) {
				outside.push(url)
			}
		})

		await openApiTab(page)
		const tab = page.locator('.ob-api-tab').first()

		const card = tab.locator('.ob-api-tab__schema').filter({ hasText: SCHEMA }).first()
		await expect(card, `the tab lists ${SCHEMA}`).toBeVisible({ timeout: 30_000 })
		await expect(
			card.locator('.ob-api-tab__calls code').filter({ hasText: new RegExp(`^GET \\S*/objects/[^/]+/${SCHEMA}$`) }).first(),
			'the schema\'s list call is shown',
		).toBeVisible()
		await expect(card.locator('.ob-api-tab__fields'), 'its fields are shown').toBeVisible()
		await expect(tab.locator('.ob-api-tab__base'), 'the base address of this instance').toContainText(`${instance}`)
		await expect(tab.getByText(/app password/i).first(), 'it says how to sign in with an app password').toBeVisible()

		const [download] = await Promise.all([
			page.waitForEvent('download'),
			tab.getByRole('button', { name: 'Download OpenAPI file' }).click(),
		])
		expect(download.suggestedFilename()).toMatch(/\.openapi\.json$/)
		const file = await download.path()
		const document = JSON.parse(await readFile(file, 'utf8'))
		expect(String(document.openapi), 'the file is OpenAPI 3').toMatch(/^3\./)
		expect(document.info?.title, 'titled after the app').toBe(APP_NAME)
		expect(String(document.info?.version ?? ''), 'it carries the version').not.toBe('')
		const paths = Object.keys(document.paths ?? {})
		expect(paths.some((p) => p.endsWith(`/${SCHEMA}`)), 'the file holds the schema\'s list call').toBeTruthy()

		expect(outside, 'no request left the instance while the tab was open').toEqual([])
	})

	// @e2e app-api-docs/requirement-an-app-has-an-api-tab-per-version-req-bqad-001/an-integrator-reads-the-permit-api
	test.describe('as a viewer of the app', () => {
		test.use({ storageState: { cookies: [], origins: [] } })

		test('a viewer opens the API tab and reads the same schema', async ({ page }) => {
			await loginAs(page, VIEWER_USER, VIEWER_PASS)
			await suppressSupportDialog(page)
			await suppressSetupWizard(page)
			await openApiTab(page)
			await expect(
				page.locator('.ob-api-tab__schema').filter({ hasText: SCHEMA }).first(),
				'a viewer sees the schema the app uses',
			).toBeVisible({ timeout: 30_000 })
		})
	})
})
