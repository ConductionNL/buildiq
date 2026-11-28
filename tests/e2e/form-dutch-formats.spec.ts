/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `form-dutch-field-formats` (T05).
 *
 * A maker sets "Format" to IBAN on a form field in the real Page Designer and
 * saves the form; the persisted manifest carries `"format": "iban"`. A colleague
 * (a non-admin account, from an EMPTY storage state, never the admin session)
 * then saves the form's destination with a wrong IBAN and is refused with the
 * format's message, while a correct IBAN is not refused for its format. A
 * licence plate typed with dashes is stored without them, in capitals.
 *
 * The form writes to buildiq's own `registrationForm` schema, the same
 * buildiq-owned destination `form-destination.spec.ts` uses. Override it with
 * BUILDIQ_E2E_FORMAT_TARGET=<register>/<schema> when an instance keeps that
 * schema closed to the colleague. The colleague defaults to the globalSetup
 * fixture user `rbac-editor` (NC_RBAC_EDITOR_USER / NC_RBAC_EDITOR_PASS).
 *
 * @spec openspec/changes/archive/2026-11-24-form-dutch-field-formats/specs/form-dutch-field-formats/spec.md
 * @e2e form-dutch-field-formats/requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001/a-maker-adds-an-iban-check
 * @e2e form-dutch-field-formats/requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001/a-format-and-a-pattern-on-one-field
 * @e2e form-dutch-field-formats/requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002/a-wrong-iban-is-refused
 * @e2e form-dutch-field-formats/requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002/a-licence-plate-with-dashes
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	dismissOverlays,
	ensureApp,
	suppressSupportDialog,
} from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-dutch-formats'
const FORM_PAGE_ID = 'e2e-dutch-formats'
const [TARGET_REGISTER, TARGET_SCHEMA] = (
	process.env.BUILDIQ_E2E_FORMAT_TARGET ?? 'buildiq/registrationForm'
).split('/')
const SUBMIT_ENDPOINT = `/index.php/apps/openregister/api/objects/${TARGET_REGISTER}/${TARGET_SCHEMA}`
const COLLEAGUE_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const COLLEAGUE_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'

/** The spec's wrong IBAN (one digit off) and its correct sibling. */
const WRONG_IBAN = 'NL91ABNA0417164301'
const RIGHT_IBAN = 'NL91ABNA0417164300'

/** The IBAN message, in the colleague's language (English or Dutch). */
const IBAN_MESSAGE = /This IBAN is not valid\. Check the digits\.|Dit IBAN klopt niet\. Controleer de cijfers\./

/**
 * Read the app's persisted manifest (GET returns the manifest itself).
 *
 * @param {Page} page The Playwright page (admin session).
 * @return {Promise<object>} The manifest.
 */
async function fetchManifest(page: Page): Promise<Record<string, any>> {
	const resp = await page.request.get(
		`${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(resp.ok(), 'GET manifest must succeed').toBeTruthy()
	return resp.json()
}

/**
 * Replace this app's form page with a baseline: an account-number field and a
 * licence-plate field, the plate already carrying its Dutch format. PUT takes
 * the manifest wrapped as `{ manifest }`.
 *
 * @param {Page} page The Playwright page (admin session).
 * @return {Promise<number>} Index of the form page in `manifest.pages`.
 */
async function seedFormPage(page: Page): Promise<number> {
	const manifest = await fetchManifest(page)
	const pages = (manifest.pages || []).filter((p: any) => p.id !== FORM_PAGE_ID)
	pages.push({
		id: FORM_PAGE_ID,
		type: 'form',
		route: `/${FORM_PAGE_ID}`,
		config: {
			submitEndpoint: SUBMIT_ENDPOINT,
			fields: [
				{ key: 'rekeningnummer', label: 'Rekeningnummer', type: 'string' },
				{ key: 'kenteken', label: 'Kenteken', type: 'string', format: 'nl-licence-plate' },
			],
		},
	})
	const resp = await page.request.put(
		`${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`,
		{
			headers: { 'OCS-APIRequest': 'true' },
			data: { manifest: { ...manifest, pages } },
		},
	)
	expect(resp.ok(), 'seeding the form page must succeed').toBeTruthy()
	return pages.length - 1
}

/**
 * A request context for the colleague: an empty storage state, so the admin
 * session from the project config is NOT carried, and the colleague's own
 * credentials.
 *
 * @param {object} playwright The `playwright` fixture.
 * @return {Promise<APIRequestContext>} The context; the caller disposes it.
 */
async function colleagueContext(playwright: any): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: COLLEAGUE_USER, password: COLLEAGUE_PASS, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
}

test.describe('buildiq form Dutch field formats', () => {
	// The page designer is a three-pane desktop surface; see form-editor-logic.spec.ts.
	test.use({ viewport: { width: 1600, height: 1200 } })
	test.describe.configure({ mode: 'serial' })

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureApp(page, APP_SLUG, 'PW Dutch formats')
	})

	test('a maker sets IBAN on a field and the saved form carries it', async ({ page }) => {
		const index = await seedFormPage(page)
		await page.goto(
			`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		// Selecting the page is setup, not the behaviour under test: dispatch the
		// row's click (its inputs stop propagation; see form-editor-logic.spec.ts).
		const row = page.locator('.page-list-editor__row').nth(index)
		await row.scrollIntoViewIfNeeded()
		await row.dispatchEvent('click')
		await expect(page.locator('.form-page-editor')).toBeVisible({ timeout: 30_000 })

		const accountRow = page.locator('.form-field-builder__row').nth(0)
		await accountRow.locator('.form-field-builder__disclosure').click()
		const format = accountRow.getByTestId('field-validation-format')
		await format.selectOption('iban')

		// With a format set, the pattern is not accepted on the same field.
		await expect(accountRow.getByTestId('field-validation-format-hint')).toBeVisible()
		await expect(accountRow.locator('input[type="text"][disabled]').first()).toBeVisible()

		await page.getByRole('button', { name: /save pages/i }).click()
		await expect
			.poll(
				async () => {
					const persisted = await fetchManifest(page)
					const form = (persisted.pages || []).find((p: any) => p.id === FORM_PAGE_ID)
					return form?.config?.fields?.find((f: any) => f.key === 'rekeningnummer')?.format ?? null
				},
				{ timeout: 15_000, message: 'the saved form field must carry format "iban"' },
			)
			.toBe('iban')
	})

	test('a colleague saving a wrong IBAN is refused with the message', async ({ playwright }) => {
		const colleague = await colleagueContext(playwright)
		try {
			const refused = await colleague.post(SUBMIT_ENDPOINT, {
				data: { rekeningnummer: WRONG_IBAN, kenteken: 'GZ-482-K' },
			})
			expect(refused.status(), 'a wrong IBAN must be refused with 422').toBe(422)
			expect(await refused.text()).toMatch(IBAN_MESSAGE)

			const accepted = await colleague.post(SUBMIT_ENDPOINT, {
				data: { rekeningnummer: RIGHT_IBAN, kenteken: 'gz-482-k' },
			})
			const body = await accepted.text()
			expect(body, 'a correct IBAN must not be refused for its format').not.toMatch(IBAN_MESSAGE)
			expect(accepted.ok(), `the colleague's valid save must succeed (got ${accepted.status()})`).toBeTruthy()

			// The licence plate is stored without dashes, in capitals.
			const saved = JSON.parse(body)
			const id = saved.id ?? saved.uuid ?? saved['@self']?.id
			expect(id, 'the save returns the stored object').toBeTruthy()
			const stored = await colleague.get(`${SUBMIT_ENDPOINT}/${id}`)
			expect(stored.ok()).toBeTruthy()
			expect((await stored.json()).kenteken).toBe('GZ482K')
		} finally {
			await colleague.dispose()
		}
	})
})
