/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: a "Grid" page that edits records in place (change
 * data-index-grid-and-saved-views, task T04, REQ-BQIX-001 and REQ-BQIX-002).
 *
 * The fixture app is cloned from the `incident-reporter` template, so it has a
 * register and an `incident` schema; two incidents are seeded through
 * OpenRegister. The spec's `StockItem` and `quantity` become `incident` and
 * `location`: the shape is the same (one schema, one editable column), and no
 * template ships a stock schema.
 *
 * 1. A maker adds a page with the "Grid (edit records in place)" choice in the
 *    page designer, picks the register, the schema, the columns `location` and
 *    `severity`, marks `location` editable and saves. The stored page must be
 *    `{type: 'custom', component: 'record-grid', config: {...}}`; the app is
 *    then published.
 * 2. An app user changes a `location` cell in the published app and presses
 *    Enter; the record is read back from OpenRegister and the cell is read
 *    again after a reload.
 * 3. A colleague (`rbac-editor`, provisioned by tests/e2e/global-setup.ts)
 *    holds the ADR-033 lock on the second incident; editing that row must be
 *    refused, name the colleague, and change nothing. The lock is taken through
 *    OpenRegister's lock route with the colleague's credentials; when that
 *    route refuses them (no right on this register on the instance under
 *    test), the test skips with that reason rather than asserting on a lock
 *    that does not exist.
 *
 * The grid cells are nextcloud-vue's `CnDataMatrix` (`td[data-row-id]
 * [data-col-key]`); the page is buildiq's `RecordGridPage`. The fixture app is
 * removed, data included, in afterAll.
 *
 * @spec openspec/changes/archive/2026-11-24-data-index-grid-and-saved-views/specs/index-page-grid-and-views/spec.md
 * @e2e index-page-grid-and-views/requirement-a-maker-can-build-a-grid-page-req-bqix-001/a-maker-adds-a-grid-of-stock-items
 * @e2e index-page-grid-and-views/requirement-a-cell-edit-is-saved-to-its-record-or-refused-visibly-req-bqix-002/an-app-user-corrects-a-quantity
 * @e2e index-page-grid-and-views/requirement-a-cell-edit-is-saved-to-its-record-or-refused-visibly-req-bqix-002/a-colleague-is-editing-the-same-record
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import path from 'node:path'
import { dismissOverlays, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }
const COLLEAGUE_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const COLLEAGUE_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'

const APP_SLUG = 'pw-record-grid'
const TEMPLATE = 'incident-reporter'
const SCHEMA = 'incident'
const GRID_TITLE = 'Stock grid'
const GRID_ID = 'pw-stock-grid'
const EDITABLE = 'location'
const READ_ONLY = 'severity'
const BEFORE = 'Storeroom A'
const AFTER = 'Storeroom B'
const MANIFEST_URL = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

let appUuid = ''
let register = ''
let gridRoute = ''
const records: Record<string, string> = {}

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
 * An object's UUID in any of the shapes OpenRegister returns it in.
 *
 * @param obj The decoded object.
 * @return The UUID.
 */
function idOf(obj: any): string {
	return String(obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? '')
}

/**
 * The OpenRegister path of one incident.
 *
 * @param id The incident.
 * @return The path from the web root.
 */
function objectPath(id: string): string {
	return `/index.php/apps/openregister/api/objects/${register}/${SCHEMA}/${id}`
}

/**
 * A column's property key, from either stored column shape.
 *
 * @param column A string key or `{ key }`.
 * @return The key.
 */
function keyOf(column: unknown): string {
	return typeof column === 'string' ? column : String((column as { key?: string })?.key ?? '')
}

/**
 * The colleague's request context: an empty storage state, so the admin
 * session from the project config is not carried, and the colleague's own
 * credentials.
 *
 * @param playwright The `playwright` fixture.
 * @return The context; the caller disposes it.
 */
async function colleagueContext(playwright: any): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL: E2E_BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: COLLEAGUE_USER, password: COLLEAGUE_PASS, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
}

/**
 * The grid cell of one record and column.
 *
 * @param page The page with the grid.
 * @param id   The record.
 * @param key  The column.
 * @return The cell.
 */
function cell(page: Page, id: string, key: string) {
	return page.locator(`td.cn-data-matrix__cell[data-row-id="${id}"][data-col-key="${key}"]`)
}

test.describe('data-index-grid-and-saved-views: a grid page edits records in place', () => {
	test.describe.configure({ mode: 'serial', timeout: 180_000 })
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			const created = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/from-template/${TEMPLATE}`, {
				name: 'PW Record grid',
				slug: APP_SLUG,
			})
			expect(created.status, JSON.stringify(created.data).slice(0, 300)).toBeLessThan(300)
			appUuid = String(created.data?.uuid ?? created.data?.id ?? created.data?.application?.uuid ?? '')

			const manifest = (await api(page, 'GET', MANIFEST_URL)).data
			const detail = (manifest.pages || []).find((p: any) => p.type === 'detail' && p.config?.schema === SCHEMA)
			register = String(detail?.config?.register ?? '')
			expect(register, 'the template\'s detail page names its register').not.toBe('')

			for (const [key, description] of [['paper', 'Printer paper'], ['toner', 'Toner']]) {
				const obj = await api(page, 'POST', `/index.php/apps/openregister/api/objects/${register}/${SCHEMA}`, {
					reportedBy: 'e2e',
					incidentType: 'supplies',
					severity: 'low',
					location: BEFORE,
					description,
				})
				expect(obj.status, JSON.stringify(obj.data).slice(0, 300)).toBeLessThan(300)
				records[key] = idOf(obj.data)
			}
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			for (const id of Object.values(records)) {
				await api(page, 'DELETE', objectPath(id)).catch(() => undefined)
			}
			if (appUuid !== '') {
				await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${appUuid}`, { deleteData: true })
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e index-page-grid-and-views/requirement-a-maker-can-build-a-grid-page-req-bqix-001/a-maker-adds-a-grid-of-stock-items
	test('REQ-BQIX-001: a maker adds a Grid page with one editable column and publishes', async ({ page }) => {
		await page.goto(`/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)

		await page.locator('.page-list-editor__add').click()
		await page.getByLabel('Page type').selectOption({ label: 'Grid (edit records in place)' })
		await page.locator('.page-list-editor__add-title').fill(GRID_TITLE)
		await page.locator('.page-list-editor__add-id').fill(GRID_ID)
		await page.locator('.page-list-editor__add-row').getByRole('button', { name: 'Confirm' }).click()

		const row = page.locator('.page-list-editor__row').filter({ hasText: GRID_TITLE }).first()
		await row.scrollIntoViewIfNeeded()
		await row.dispatchEvent('click')
		const editor = page.locator('.grid-page-editor')
		await expect(editor, 'a Grid page opens the grid editor, not raw custom-page JSON').toBeVisible({ timeout: 30_000 })

		await editor.getByLabel('Register').selectOption(register)
		await editor.getByLabel('Schema').selectOption(SCHEMA)
		const addColumn = editor.locator('.column-builder__add')
		await addColumn.click()
		await addColumn.click()
		const keys = editor.locator('.column-builder__row select.column-builder__key')
		await keys.nth(0).selectOption(EDITABLE)
		await keys.nth(1).selectOption(READ_ONLY)
		await editor.locator('.grid-page-editor__inline').filter({ hasText: EDITABLE }).locator('input[type="checkbox"]').check()

		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => {
				const manifest = (await api(page, 'GET', MANIFEST_URL)).data
				const grid = (manifest.pages || []).find((p: any) => p.component === 'record-grid')
				if (!grid) {
					return null
				}
				gridRoute = String(grid.route ?? '')
				return {
					type: grid.type,
					register: grid.config?.register,
					schema: grid.config?.schema,
					columns: (grid.config?.columns || []).map(keyOf),
					editable: grid.config?.editable,
				}
			}, { timeout: 30_000, message: 'the grid page must reach the stored manifest in the custom-page shape' })
			.toEqual({ type: 'custom', register, schema: SCHEMA, columns: [EDITABLE, READ_ONLY], editable: [EDITABLE] })
		expect(gridRoute, 'the grid page has a route').not.toBe('')

		const published = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/${appUuid}/publish`)
		expect(published.status, `publishing: ${JSON.stringify(published.data).slice(0, 200)}`).toBeLessThan(300)

		await page.goto(`/index.php/apps/buildiq/builder/${APP_SLUG}${gridRoute}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.locator('[data-testid="cn-data-matrix"]'), 'the published app renders the grid').toBeVisible({ timeout: 30_000 })
		for (const id of Object.values(records)) {
			await expect(cell(page, id, EDITABLE), 'one row per record').toBeVisible()
		}
		await expect(cell(page, records.paper, EDITABLE).getByRole('button'), 'the editable column can be edited in place').toBeVisible()
		await expect(cell(page, records.paper, READ_ONLY).getByRole('button'), 'an unmarked column cannot').toHaveCount(0)
	})

	// @e2e index-page-grid-and-views/requirement-a-cell-edit-is-saved-to-its-record-or-refused-visibly-req-bqix-002/an-app-user-corrects-a-quantity
	test('REQ-BQIX-002: an app user changes a cell, presses Enter, and the change is kept after a reload', async ({ page }) => {
		test.skip(gridRoute === '', 'needs the grid page from the previous test')

		await page.goto(`/index.php/apps/buildiq/builder/${APP_SLUG}${gridRoute}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		const target = cell(page, records.paper, EDITABLE)
		await expect(target).toContainText(BEFORE, { timeout: 30_000 })

		await target.getByRole('button').click()
		const input = target.locator('input.cn-data-matrix__cell-input')
		await input.fill(AFTER)
		await input.press('Enter')

		await expect
			.poll(async () => (await api(page, 'GET', objectPath(records.paper))).data?.[EDITABLE], {
				timeout: 30_000,
				message: 'the record is saved with the new value',
			})
			.toBe(AFTER)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(cell(page, records.paper, EDITABLE), 'a reload shows the saved value').toContainText(AFTER, { timeout: 30_000 })
	})

	// @e2e index-page-grid-and-views/requirement-a-cell-edit-is-saved-to-its-record-or-refused-visibly-req-bqix-002/a-colleague-is-editing-the-same-record
	test('REQ-BQIX-002: a row a colleague has locked is refused and names the colleague', async ({ page, playwright }) => {
		test.skip(gridRoute === '', 'needs the grid page from the first test')

		const colleague = await colleagueContext(playwright)
		const lockUrl = `${objectPath(records.toner)}/lock`
		try {
			const locked = await colleague.post(lockUrl, { data: { duration: 600 } })
			test.skip(!locked.ok(), `the colleague cannot lock this record here (HTTP ${locked.status()}), so there is no lock to refuse`)

			await page.goto(`/index.php/apps/buildiq/builder/${APP_SLUG}${gridRoute}`, { waitUntil: 'domcontentloaded' })
			await dismissOverlays(page)
			const rowHeader = page.locator('tr.cn-data-matrix__row').filter({ has: cell(page, records.toner, EDITABLE) }).locator('th')
			await expect(rowHeader, 'the grid says the colleague is editing the row').toContainText(/is editing this record/, { timeout: 30_000 })

			const target = cell(page, records.toner, EDITABLE)
			await target.getByRole('button').click()
			const input = target.locator('input.cn-data-matrix__cell-input')
			if (await input.isEditable().catch(() => false)) {
				await input.fill('Somewhere else')
				await input.press('Enter')
			}
			await expect(target, 'the old value stays in the cell').toContainText(BEFORE, { timeout: 15_000 })
			expect((await api(page, 'GET', objectPath(records.toner))).data?.[EDITABLE], 'the record is unchanged').toBe(BEFORE)
		} finally {
			await colleague.post(`${objectPath(records.toner)}/unlock`).catch(() => undefined)
			await colleague.dispose()
		}
	})
})
