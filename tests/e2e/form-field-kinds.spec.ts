/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `forms-designer-file-camera-children-and-assign`, task T07 (REQ-BQFK-001 to
 * REQ-BQFK-005): a maker builds a form from the palette with a multi-file
 * field that opens the camera and a currency filled from the country; and,
 * with an order and order-line schema on the instance, the built form saves
 * an order with two lines and the currency.
 *
 * The tests run as a NON-ADMIN editor (`rbac-editor`, provisioned by
 * tests/e2e/global-setup.ts) in an empty storage state; `beforeAll` builds the
 * fixture app as admin in its own context and grants the editor on it.
 *
 * The built-form scenario needs nextcloud-vue to render `child-records` and
 * `assign`, and an order schema with a line schema pointing back at it. It
 * runs when the instance names them, and skips with that reason otherwise:
 *
 *   FORM_KINDS_REGISTER     the register holding both schemas
 *   FORM_KINDS_ORDER_SCHEMA the order schema (properties `country`, `currency`)
 *   FORM_KINDS_LINE_SCHEMA  the line schema (`order` referencing the order, `product`, `quantity`)
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
const REGISTER = process.env.FORM_KINDS_REGISTER ?? ''
const ORDER_SCHEMA = process.env.FORM_KINDS_ORDER_SCHEMA ?? ''
const LINE_SCHEMA = process.env.FORM_KINDS_LINE_SCHEMA ?? ''

const APP_SLUG = 'pw-form-field-kinds'
const FORM_PAGE_ID = 'bestelling'

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
 * Replace the app's form page with these fields.
 *
 * @param page   A logged-in page.
 * @param config The page config.
 * @return Index of the page in `manifest.pages`.
 */
async function seedFormPage(page: Page, config: Record<string, unknown>): Promise<number> {
	const url = `${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
	const read = await page.request.get(url, { headers: { 'OCS-APIRequest': 'true' } })
	expect(read.ok(), 'GET manifest must succeed').toBeTruthy()
	const manifest = await read.json()
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== FORM_PAGE_ID)
	pages.push({ id: FORM_PAGE_ID, type: 'form', route: `/${FORM_PAGE_ID}`, title: 'Bestelling', config })
	const write = await page.request.put(url, { headers: { 'OCS-APIRequest': 'true' }, data: { manifest: { ...manifest, pages } } })
	expect(write.ok(), 'seeding the form page via the manifest API must succeed').toBeTruthy()
	return pages.length - 1
}

/**
 * Seed the form page and open it in the page designer.
 *
 * @param page   A logged-in page.
 * @param config The page config.
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
 * The staged fields of the form page.
 *
 * @param page A page with the designer open.
 * @return The fields.
 */
async function stagedFields(page: Page): Promise<Array<Record<string, any>>> {
	const manifest = await readStagedManifest(page)
	return manifest.pages.find((p: { id: string }) => p.id === FORM_PAGE_ID).config.fields
}

/**
 * Open the details of the field row at this index.
 *
 * @param page  A page with the designer open.
 * @param index The row index.
 */
async function openDetails(page: Page, index: number): Promise<void> {
	const toggle = page.locator('.form-field-builder__disclosure').nth(index)
	if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
		await toggle.click()
	}
}

test.describe('forms-designer-file-camera-children-and-assign: field kinds', () => {
	test.use({ storageState: { cookies: [], origins: [] }, viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Form Field Kinds')
			await grantAppRoles(page, APP_SLUG, { owners: [`user:${ADMIN_USER}`], editors: [`user:${EDITOR_USER}`] })
		} finally {
			await admin.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
	})

	test('REQ-BQFK-001, REQ-BQFK-002, REQ-BQFK-005: a multi-file field with the back camera, added from the palette', async ({ page }) => {
		// @e2e openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-file-field-with-its-limits-req-bqfk-001
		await openFormPage(page, { mode: 'create', fields: [{ key: 'naam', label: 'Naam', type: 'string' }] })

		await page.getByTestId('palette-file').focus()
		await page.keyboard.press('Enter')
		const rows = page.locator('.form-field-builder__row')
		await expect(rows).toHaveCount(2)
		await rows.nth(1).getByLabel('Key').fill('bonnetjes')
		await rows.nth(1).getByLabel('Label').fill('Bonnetjes')

		await openDetails(page, 1)
		await page.getByTestId('file-accept').fill('.pdf,image/*')
		await page.getByTestId('file-max-size').fill('10')
		await page.getByTestId('file-multiple').check()
		await page.getByTestId('file-capture').selectOption('environment')

		const fields = await stagedFields(page)
		expect(fields.map((field) => field.key)).toEqual(['naam', 'bonnetjes'])
		expect(fields[1]).toMatchObject({ type: 'file', accept: '.pdf,image/*', maxSize: 10485760, multiple: true, capture: 'environment' })

		await page.getByTestId('file-capture').selectOption('')
		expect((await stagedFields(page))[1]).not.toHaveProperty('capture')
	})

	test('REQ-BQFK-004: the currency follows the country, and a field cannot fill itself', async ({ page }) => {
		// @e2e openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-field-is-filled-in-from-another-answer-req-bqfk-004
		await openFormPage(page, {
			mode: 'create',
			fields: [
				{ key: 'country', label: 'Land', type: 'string' },
				{ key: 'currency', label: 'Valuta', type: 'string' },
			],
		})

		await openDetails(page, 1)
		await page.getByTestId('assign-add').click()
		const rule = page.getByTestId('assign-rule').first()
		await rule.getByTestId('assign-when-field').selectOption('country')
		await rule.getByTestId('assign-when-op').selectOption('eq')
		await rule.getByTestId('assign-when-value').fill('NL')
		await rule.getByTestId('assign-value-kind').selectOption('literal')
		await rule.getByTestId('assign-value-literal').fill('EUR')

		expect((await stagedFields(page))[1].assign).toEqual([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }])

		await rule.getByTestId('assign-when-field').selectOption('currency')
		await expect(page.getByTestId('assign-refused')).toBeVisible()
	})

	test('REQ-BQFK-003: the built form saves an order with two lines and the currency', async ({ page }) => {
		// @e2e openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-table-of-child-records-req-bqfk-003
		test.skip(
			REGISTER === '' || ORDER_SCHEMA === '' || LINE_SCHEMA === '',
			'set FORM_KINDS_REGISTER, FORM_KINDS_ORDER_SCHEMA and FORM_KINDS_LINE_SCHEMA once nextcloud-vue renders child-records and assign',
		)

		await seedFormPage(page, {
			mode: 'create',
			register: REGISTER,
			schema: ORDER_SCHEMA,
			fields: [
				{ key: 'country', label: 'Land', type: 'string', enum: ['NL', 'BE'] },
				{ key: 'currency', label: 'Valuta', type: 'string', assign: [{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }] },
				{ key: 'bonnetjes', label: 'Bonnetjes', type: 'file', multiple: true, capture: 'environment' },
				{ key: 'lines', label: 'Regels', type: 'child-records', schema: LINE_SCHEMA, parentField: 'order', columns: ['product', 'quantity'] },
			],
		})

		await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}/${FORM_PAGE_ID}`, { waitUntil: 'domcontentloaded' })
		const lines = page.getByRole('table').filter({ hasText: 'Regels' }).or(page.locator('[data-field="lines"]'))
		for (const [product, quantity] of [['Koffie', '2'], ['Thee', '3']]) {
			await page.getByRole('button', { name: /add|toevoegen/i }).first().click()
			await lines.getByRole('textbox').nth(-2).fill(product)
			await lines.getByRole('spinbutton').last().fill(quantity)
		}
		await page.getByLabel('Land').selectOption('NL')
		await expect(page.getByLabel('Valuta')).toHaveValue('EUR')
		await page.getByRole('button', { name: /submit|versturen|opslaan/i }).click()

		let orderId = ''
		await expect.poll(async () => {
			const read = await page.request.get(`${BASE_URL}/index.php/apps/openregister/api/objects/${REGISTER}/${ORDER_SCHEMA}?_order[created]=desc&_limit=1`, { headers: { 'OCS-APIRequest': 'true' } })
			const order = (await read.json()).results?.[0]
			orderId = order?.['@self']?.id ?? order?.id ?? ''
			return order?.currency
		}, { timeout: 30_000 }).toBe('EUR')

		const read = await page.request.get(`${BASE_URL}/index.php/apps/openregister/api/objects/${REGISTER}/${LINE_SCHEMA}?order=${orderId}`, { headers: { 'OCS-APIRequest': 'true' } })
		const saved = (await read.json()).results ?? []
		expect(saved.map((line: { product: string }) => line.product).sort()).toEqual(['Koffie', 'Thee'])
	})
})
