/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `ai-smart-paste-into-forms`, task T06 (REQ-BQSP-001, REQ-BQSP-002,
 * REQ-BQSP-003): a maker authors smart paste on a form page, a public form
 * refuses it, the designer preview shows the control with a sample answer,
 * and, once the renderer and the fill endpoint ship, an app user fills the
 * form from pasted text against a stub endpoint.
 *
 * The tests run as a NON-ADMIN editor (`rbac-editor`, provisioned by
 * tests/e2e/global-setup.ts) in an empty storage state. `beforeAll` builds the
 * fixture app as admin, in its own context, and grants the editor on it.
 *
 * The rendered-app scenario needs nextcloud-vue's `CnFormPage` control and
 * Hermiq's fill endpoint (tasks T04 and T05 of the change). It runs only when
 * `SMART_PASTE_FILL_ROUTE` names the endpoint's URL glob, which it stubs, and
 * skips with that reason otherwise.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { readStagedManifest } from './support/stagedManifest.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const FILL_ROUTE = process.env.SMART_PASTE_FILL_ROUTE ?? ''

const APP_SLUG = 'pw-form-smart-paste'
const FORM_PAGE_ID = 'nieuwe-klant'
const FIELDS = [
	{ key: 'naam', label: 'Naam', type: 'string' },
	{ key: 'adres', label: 'Adres', type: 'string' },
	{ key: 'telefoon', label: 'Telefoon', type: 'string' },
	{ key: 'notities', label: 'Notities', type: 'string' },
]

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

/**
 * Replace the app's form page with the `Nieuwe klant` baseline.
 *
 * @param page   A logged-in page.
 * @param config Extra page config, e.g. `mode` or `smartPaste`.
 * @return Index of the page in `manifest.pages`.
 */
async function seedFormPage(page: Page, config: Record<string, unknown>): Promise<number> {
	const url = `${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
	const read = await page.request.get(url, { headers: { 'OCS-APIRequest': 'true' } })
	expect(read.ok(), 'GET manifest must succeed').toBeTruthy()
	const manifest = await read.json()
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== FORM_PAGE_ID)
	pages.push({ id: FORM_PAGE_ID, type: 'form', route: `/${FORM_PAGE_ID}`, title: 'Nieuwe klant', config: { fields: FIELDS, ...config } })
	const write = await page.request.put(url, {
		headers: { 'OCS-APIRequest': 'true' },
		data: { manifest: { ...manifest, pages } },
	})
	expect(write.ok(), 'seeding the form page via the manifest API must succeed').toBeTruthy()
	return pages.length - 1
}

/**
 * Seed the form page and open it in the page designer.
 *
 * @param page   A logged-in page.
 * @param config Extra page config.
 */
async function openFormPage(page: Page, config: Record<string, unknown>): Promise<void> {
	const index = await seedFormPage(page, config)
	await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
	await dismissOverlays(page)
	// Selecting the page is setup: dispatch the row's own click (its inputs stop
	// propagation, and the centre pane overlaps the list further down).
	const row = page.locator('.page-list-editor__row').nth(index)
	await row.scrollIntoViewIfNeeded()
	await row.dispatchEvent('click')
	await expect(page.locator('.form-page-editor')).toBeVisible({ timeout: 30_000 })
}

/**
 * The staged config of the form page.
 *
 * @param page A page with the designer open.
 * @return The page config.
 */
async function stagedConfig(page: Page): Promise<Record<string, any>> {
	const manifest = await readStagedManifest(page)
	return manifest.pages.find((p: { id: string }) => p.id === FORM_PAGE_ID).config
}

test.describe('ai-smart-paste-into-forms: authoring smart paste', () => {
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Form Smart Paste')
			await grantAppRoles(page, APP_SLUG, { owners: [`user:${ADMIN_USER}`], editors: [`user:${EDITOR_USER}`] })
		} finally {
			await admin.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
	})

	test('REQ-BQSP-001: a maker allows three fields', async ({ page }) => {
		// @e2e openspec/specs/form-smart-paste/spec.md#requirement-a-maker-turns-on-smart-paste-for-chosen-fields-req-bqsp-001
		await openFormPage(page, { mode: 'create' })

		const fieldset = page.getByTestId('form-smart-paste')
		await fieldset.getByTestId('smart-paste-enabled').check()
		for (const key of ['naam', 'adres', 'telefoon']) {
			await fieldset.getByTestId(`smart-paste-field-${key}`).check()
		}
		await fieldset.getByTestId('smart-paste-hint').fill('Een e-mail van een nieuwe klant')

		const config = await stagedConfig(page)
		expect(config.smartPaste.enabled).toBe(true)
		expect([...config.smartPaste.fields].sort()).toEqual(['adres', 'naam', 'telefoon'])
		expect(config.smartPaste.hint).toBe('Een e-mail van een nieuwe klant')
	})

	test('REQ-BQSP-002: a public form cannot use smart paste', async ({ page }) => {
		// @e2e openspec/specs/form-smart-paste/spec.md#requirement-smart-paste-is-validated-req-bqsp-002
		await openFormPage(page, { mode: 'public' })

		const fieldset = page.getByTestId('form-smart-paste')
		await expect(fieldset.getByTestId('smart-paste-enabled')).toBeDisabled()
		await expect(fieldset.getByTestId('smart-paste-public')).toContainText('Only signed-in forms can use AI.')
		expect((await stagedConfig(page)).smartPaste?.enabled ?? false).toBe(false)
	})

	test('REQ-BQSP-002: a removed field is caught', async ({ page }) => {
		// @e2e openspec/specs/form-smart-paste/spec.md#requirement-smart-paste-is-validated-req-bqsp-002
		await openFormPage(page, { mode: 'create', smartPaste: { enabled: true, fields: ['naam', 'gone'] } })

		// The fieldset carries the inline mark for the unknown key.
		await expect(page.getByTestId('form-smart-paste').locator('.inline-field-mark[role="alert"]')).toBeVisible()
	})

	test('REQ-BQSP-003: the preview shows the control with a sample answer', async ({ page }) => {
		// @e2e openspec/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		await openFormPage(page, { mode: 'create', smartPaste: { enabled: true, fields: ['naam', 'adres', 'telefoon'] } })

		const preview = page.getByTestId('smart-paste-preview')
		await expect(preview).toBeVisible({ timeout: 30_000 })
		await preview.getByTestId('smart-paste-preview-toggle').click()
		for (const key of ['naam', 'adres', 'telefoon']) {
			await expect(preview.getByTestId(`smart-paste-proposal-${key}`)).toBeVisible()
		}
		await expect(preview.getByTestId('smart-paste-proposal-notities')).toHaveCount(0)
	})

	test('REQ-BQSP-003: an app user fills a form from an email', async ({ page }) => {
		// @e2e openspec/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		test.skip(FILL_ROUTE === '', 'needs the CnFormPage control and the Hermiq fill endpoint (T04, T05); set SMART_PASTE_FILL_ROUTE to the endpoint URL glob')

		await seedFormPage(page, { mode: 'create', smartPaste: { enabled: true, fields: ['naam', 'adres', 'telefoon'] } })
		const sent: Array<Record<string, any>> = []
		await page.route(FILL_ROUTE, async (route) => {
			sent.push(route.request().postDataJSON())
			await route.fulfill({
				json: { proposals: { naam: 'Anna de Vries', adres: 'Dorpsstraat 1, Utrecht', telefoon: '030 123 4567' } },
			})
		})

		await page.goto(`${BASE_URL}/apps/buildiq/app/${APP_SLUG}/${FORM_PAGE_ID}`, { waitUntil: 'domcontentloaded' })
		await page.getByRole('button', { name: 'Paste to fill' }).click()
		await page.getByRole('textbox').last().fill('Met vriendelijke groet,\nAnna de Vries\nDorpsstraat 1, Utrecht\n030 123 4567')
		await page.getByRole('button', { name: /fill|vul/i }).last().click()

		await expect(page.getByLabel('Naam')).toHaveValue('Anna de Vries')
		await expect(page.getByLabel('Telefoon')).toHaveValue('030 123 4567')
		await expect(page.getByLabel('Notities')).toHaveValue('')
		expect(sent).toHaveLength(1)
		expect(JSON.stringify(sent[0])).not.toContain('notities')
	})
})
