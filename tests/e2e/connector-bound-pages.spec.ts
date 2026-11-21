/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `integrations-connector-bound-pages`, task T05 (REQ-BQCB-001, REQ-BQCB-002,
 * REQ-BQCB-003): a published app whose pages are bound to an integriq endpoint
 * renders that endpoint's rows at `/apps/buildiq/builder/{slug}`, and never
 * the renderer's `Unknown widgetKey` warning.
 *
 * `beforeAll` builds the fixture app `pw-connector-pages` as admin and writes
 * its manifest through the manifest API, the shape the page designer stores:
 *
 *   - `leveranciers`: an index page with `config.dataSource.connector` on the
 *     endpoint `pw-suppliers`, fields `name` and `city`;
 *   - `storing`: the same, on the endpoint `pw-suppliers-down`;
 *   - `overzicht`: a dashboard with a placed `connector-data` widget on the
 *     endpoint `pw-open-requests`, the shape WidgetBuilder's "add connector
 *     widget" stores (two mapped fields).
 *
 * THE FIXTURE ENDPOINT IS THE BROWSER'S, NOT INTEGRIQ'S. The runtime fetches
 * `GET /apps/integriq/api/endpoint/{path}` (or `/apps/openconnector/...` on an
 * instance that still serves that app id, `fleetAppId.js`). Each test answers
 * those requests with `page.route`, so no integriq install, source or
 * credential is needed and the rows are known exactly. What is exercised is
 * buildiq's own half: the in-memory normaliser, the `connector-list` runtime
 * page, the `connector-data` widget and the projection through `itemsPath`
 * and `fields`.
 *
 * Not covered here: authoring through the origin toggle, source picker and
 * field mapper (Vitest, T04), preview/published parity (the unit host-parity
 * guard, T03, REQ-BQCB-004), and integriq's ready-made connectors
 * (REQ-BQCB-005, which needs a configured spreadsheet connector).
 *
 * `afterAll` deletes the fixture app.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md
 * @e2e connector-bound-page-runtime/requirement-a-connector-bound-index-page-renders-its-records-req-bqcb-001/an-app-user-opens-a-page-bound-to-an-external-api
 * @e2e connector-bound-page-runtime/requirement-a-connector-bound-index-page-renders-its-records-req-bqcb-001/the-page-designer-still-shows-what-the-maker-authored
 * @e2e connector-bound-page-runtime/requirement-the-list-page-shows-every-state-of-the-fetch-req-bqcb-002/the-endpoint-is-down
 * @e2e connector-bound-page-runtime/requirement-a-maker-can-place-a-connector-widget-on-a-dashboard-req-bqcb-003/a-maker-adds-an-external-list-to-a-dashboard
 * @e2e openconnector-api-sources::index-page-renders-external-rows
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const APP_SLUG = 'pw-connector-pages'
const MANIFEST = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

const SUPPLIERS = {
	id: 'leveranciers',
	title: 'Suppliers',
	endpoint: 'pw-suppliers',
	items: [
		{ name: 'Bakkerij De Korenaar', address: { city: 'Utrecht' } },
		{ name: 'Groothandel Van Dijk', address: { city: 'Zwolle' } },
		{ name: 'Drukkerij Noord', address: { city: 'Groningen' } },
	],
}
const DOWN = { id: 'storing', title: 'Outage', endpoint: 'pw-suppliers-down' }
const DASHBOARD = {
	id: 'overzicht',
	title: 'Overview',
	endpoint: 'pw-open-requests',
	widgetId: 'connector-1',
	items: [
		{ reference: 'REQ-0101', state: { label: 'open' } },
		{ reference: 'REQ-0102', state: { label: 'in behandeling' } },
	],
}

/**
 * The `dataSource` block a connector-bound page or widget carries.
 *
 * @param endpointPath The integriq endpoint.
 * @param fields       Column name to dot-path selector.
 * @return The data source.
 */
function connectorSource(endpointPath: string, fields: Record<string, string>) {
	return { connector: { endpointPath, method: 'GET', itemsPath: 'results', fields } }
}

/**
 * Call a Nextcloud route from the page, with its session and request token.
 *
 * @param page   A logged-in page.
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
 * Give the fixture app its connector-bound index pages and dashboard.
 *
 * @param page An admin page on the buildiq app.
 */
async function seedPages(page: Page): Promise<void> {
	const read = await call(page, 'GET', MANIFEST)
	expect(read.status, 'GET manifest').toBe(200)
	const manifest = read.data
	const ours = new Set([SUPPLIERS.id, DOWN.id, DASHBOARD.id])
	const pages = (manifest.pages || []).filter((p: { id: string }) => !ours.has(p.id))
	pages.push(
		{
			id: SUPPLIERS.id,
			type: 'index',
			route: `/${SUPPLIERS.id}`,
			title: SUPPLIERS.title,
			config: { dataSource: connectorSource(SUPPLIERS.endpoint, { name: 'name', city: 'address.city' }) },
		},
		{
			id: DOWN.id,
			type: 'index',
			route: `/${DOWN.id}`,
			title: DOWN.title,
			config: { dataSource: connectorSource(DOWN.endpoint, { name: 'name', city: 'address.city' }) },
		},
		{
			id: DASHBOARD.id,
			type: 'dashboard',
			route: `/${DASHBOARD.id}`,
			title: DASHBOARD.title,
			config: {
				widgets: [{
					id: DASHBOARD.widgetId,
					title: 'Open requests',
					type: 'connector-data',
					content: { dataSource: connectorSource(DASHBOARD.endpoint, { reference: 'reference', state: 'state.label' }) },
				}],
				layout: [{ id: DASHBOARD.widgetId, widgetId: DASHBOARD.widgetId, gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 4 }],
			},
		},
	)
	const menu = [
		...(manifest.menu || []).filter((m: { id: string }) => !ours.has(m.id)),
		{ id: SUPPLIERS.id, label: SUPPLIERS.title, route: SUPPLIERS.id },
		{ id: DOWN.id, label: DOWN.title, route: DOWN.id },
		{ id: DASHBOARD.id, label: DASHBOARD.title, route: DASHBOARD.id },
	]
	const write = await call(page, 'PUT', MANIFEST, { manifest: { ...manifest, pages, menu } })
	expect(write.status, `seed pages: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)
}

/**
 * Answer one integriq endpoint from the test, on either fleet app id.
 *
 * @param page     The page.
 * @param endpoint The endpoint path.
 * @param answer   The JSON body, or an HTTP status to fail with.
 * @return A counter of the requests answered.
 */
async function fixtureEndpoint(page: Page, endpoint: string, answer: unknown[] | number): Promise<{ hits: number }> {
	const counter = { hits: 0 }
	const pattern = new RegExp(`/apps/(integriq|openconnector)/api/endpoint/${endpoint}(\\?|$)`)
	await page.route(pattern, async (route) => {
		counter.hits++
		if (typeof answer === 'number') {
			await route.fulfill({ status: answer, contentType: 'application/json', body: JSON.stringify({ message: 'Upstream answered an error' }) })
			return
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ results: answer }) })
	})
	return counter
}

/**
 * Collect the renderer's unknown-widget warnings from the console.
 *
 * @param page The page.
 * @return The warnings seen so far (live array).
 */
function watchUnknownWidgets(page: Page): string[] {
	const seen: string[] = []
	page.on('console', (message) => {
		const text = message.text()
		if (/Unknown widgetKey|Unknown widget/i.test(text)) {
			seen.push(text)
		}
	})
	return seen
}

/**
 * Open a page of the published fixture app.
 *
 * @param page   The page.
 * @param pageId The manifest page id (its route).
 */
async function openPublished(page: Page, pageId: string): Promise<void> {
	await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}/${pageId}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
}

test.describe('integrations-connector-bound-pages: pages bound to an integriq endpoint render', () => {
	test.describe.configure({ timeout: 90_000 })

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Connector Pages')
			await seedPages(page)
		} finally {
			await admin.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			const listed = await call(page, 'GET', '/index.php/apps/buildiq/api/applications')
			const rows = Array.isArray(listed.data) ? listed.data : (listed.data?.results ?? [])
			const app = rows.find((row: Record<string, any>) => (row?.slug ?? row?.['@self']?.slug) === APP_SLUG)
			const uuid = String(app?.uuid ?? app?.['@self']?.id ?? app?.id ?? '')
			if (uuid !== '') {
				await call(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}`)
			}
		} finally {
			await admin.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
	})

	test('REQ-BQCB-001: a connector-bound index page lists the endpoint\'s rows under the mapped columns', async ({ page }) => {
		// @e2e openconnector-api-sources::index-page-renders-external-rows
		const unknown = watchUnknownWidgets(page)
		const endpoint = await fixtureEndpoint(page, SUPPLIERS.endpoint, SUPPLIERS.items)

		await openPublished(page, SUPPLIERS.id)

		const list = page.getByTestId('connector-list-page')
		await expect(list, 'the index page is rendered as the connector list page').toBeVisible({ timeout: 45_000 })
		await expect(list.getByRole('heading', { name: SUPPLIERS.title })).toBeVisible()
		await expect(list.getByRole('columnheader', { name: 'name', exact: true })).toBeVisible()
		await expect(list.getByRole('columnheader', { name: 'city', exact: true })).toBeVisible()
		const rows = list.locator('tbody tr')
		await expect(rows).toHaveCount(SUPPLIERS.items.length)
		await expect(rows.nth(0)).toContainText('Bakkerij De Korenaar')
		await expect(rows.nth(0)).toContainText('Utrecht')
		expect(endpoint.hits, 'the runtime asked the endpoint, not OpenRegister').toBeGreaterThan(0)

		// The search box filters the loaded rows on the mapped fields.
		await list.getByRole('textbox', { name: 'Search' }).fill('zwolle')
		await expect(rows).toHaveCount(1)
		await expect(rows.first()).toContainText('Groothandel Van Dijk')

		expect(unknown, 'no unknown-widget warning is logged').toEqual([])
	})

	test('REQ-BQCB-001: the stored manifest keeps the page as the maker authored it', async ({ page }) => {
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		const read = await call(page, 'GET', MANIFEST)
		expect(read.status).toBe(200)
		const stored = (read.data.pages || []).find((p: { id: string }) => p.id === SUPPLIERS.id)
		expect(stored, 'the authored page is still there').toBeTruthy()
		expect(stored.type, 'still an index page, not the in-memory custom page').toBe('index')
		expect(stored.component).toBeUndefined()
		expect(stored.config.dataSource).toEqual(connectorSource(SUPPLIERS.endpoint, { name: 'name', city: 'address.city' }))
	})

	test('REQ-BQCB-002: an endpoint that errors shows the error with Retry, not a blank page', async ({ page }) => {
		const unknown = watchUnknownWidgets(page)
		const endpoint = await fixtureEndpoint(page, DOWN.endpoint, 500)

		await openPublished(page, DOWN.id)

		const list = page.getByTestId('connector-list-page')
		await expect(list).toBeVisible({ timeout: 45_000 })
		await expect(list.getByText('Could not load data from OpenConnector.')).toBeVisible()
		const retry = list.getByRole('button', { name: 'Retry' })
		await expect(retry).toBeVisible()
		await expect(list.locator('tbody tr')).toHaveCount(0)

		const before = endpoint.hits
		await retry.click()
		await expect.poll(() => endpoint.hits, { message: 'Retry asks the endpoint again' }).toBeGreaterThan(before)

		expect(unknown, 'no unknown-widget warning is logged').toEqual([])
	})

	test('REQ-BQCB-003: a placed connector widget renders its two mapped columns on the published dashboard', async ({ page }) => {
		const unknown = watchUnknownWidgets(page)
		await fixtureEndpoint(page, DASHBOARD.endpoint, DASHBOARD.items)

		await openPublished(page, DASHBOARD.id)

		const widget = page.locator('.connector-data-view').first()
		await expect(widget, 'the dashboard renders the connector widget').toBeVisible({ timeout: 45_000 })
		await expect(widget.getByRole('columnheader', { name: 'reference', exact: true })).toBeVisible()
		await expect(widget.getByRole('columnheader', { name: 'state', exact: true })).toBeVisible()
		const rows = widget.locator('tbody tr')
		await expect(rows).toHaveCount(DASHBOARD.items.length)
		await expect(rows.nth(1)).toContainText('REQ-0102')
		await expect(rows.nth(1)).toContainText('in behandeling')

		expect(unknown, 'no unknown-widget warning is logged').toEqual([])
	})
})
