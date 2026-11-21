/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `openbuild-connector-widget-runtime`, section 4 (REQ-CWR-002, REQ-CWR-004,
 * REQ-OCAS-006): a registry-resolved widget renders on the PUBLISHED route
 * `/apps/buildiq/builder/{slug}`, not only in the preview host.
 *
 * `beforeAll` builds the fixture app `pw-cwr-fixture` as admin (the `pw-`
 * prefix and the "Playwright fixture" description mark it as test data) and
 * writes one dashboard page through the manifest API:
 *
 *   - widget `cwr-connector`: a `connector-data` widget whose
 *     `dataSource.connector` names the placeholder endpoint `pw-cwr-rows`, with
 *     `itemsPath` and two `fields` and no credential-bearing key (4.1);
 *   - widget `cwr-down`: the same on `pw-cwr-down`, which answers 502, so the
 *     widget must show its error state with Retry (4.2);
 *   - widget `cwr-table`: a library `object-table` whose
 *     `content.endpointSource` reads `pw-cwr-table` and plucks its rows at
 *     `responsePath` (4.4, the `props.endpointSource` half of the matrix).
 *
 * The endpoints are answered by the browser with `page.route`, on both fleet
 * app ids (`integriq` and `openconnector`), so no connector install, source or
 * credential is needed and the rows are known exactly.
 *
 * Every test fails on a `[CnWidgetGrid] Unknown widgetKey` console warning and
 * also asserts the widget's own surface (4.3), so removing the runtime
 * registry from `src/builder.js` fails on both the warning and the missing
 * `.connector-data-view`, and a reworded warning alone cannot make it pass.
 *
 * `afterAll` deletes the fixture app.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/openbuild-connector-widget-runtime/specs/connector-widget-runtime/spec.md#requirement-req-cwr-004-published-path-proof-obligation
 * @spec openspec/changes/openbuild-connector-widget-runtime/specs/connector-widget-runtime/spec.md#requirement-req-cwr-002-normative-endpoint-binding-matrix-for-the-published-path
 * @spec openspec/changes/openbuild-connector-widget-runtime/specs/openconnector-api-sources/spec.md#requirement-req-ocas-006-runtime-fetch-path-with-caching
 * @e2e connector-widget-runtime/requirement-req-cwr-004-published-path-proof-obligation/connector-data-resolves-on-the-published-route
 * @e2e connector-widget-runtime/requirement-req-cwr-004-published-path-proof-obligation/an-unresolved-widget-key-fails-the-test
 * @e2e connector-widget-runtime/requirement-req-cwr-004-published-path-proof-obligation/fixture-carries-no-credential
 * @e2e connector-widget-runtime/requirement-req-cwr-002-normative-endpoint-binding-matrix-for-the-published-path/an-endpoint-bound-object-table-renders-on-the-published-path
 * @e2e openconnector-api-sources/requirement-req-ocas-006-runtime-fetch-path-with-caching/error-state-without-cache
 * @e2e openconnector-api-sources/requirement-req-ocas-006-runtime-fetch-path-with-caching/the-binding-is-exercised-on-the-published-route-not-only-in-preview
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const APP_SLUG = 'pw-cwr-fixture'
const MANIFEST = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
const PAGE_ID = 'cwr-dashboard'

/** Keys a connector binding must never carry (REQ-CWR-004). */
const CREDENTIAL_KEYS = ['headers', 'token', 'apiKey', 'authorization']

const CONNECTOR = {
	widgetId: 'cwr-connector',
	endpoint: 'pw-cwr-rows',
	items: [
		{ code: 'PW-001', owner: { name: 'Fixture One' } },
		{ code: 'PW-002', owner: { name: 'Fixture Two' } },
	],
}
const DOWN = { widgetId: 'cwr-down', endpoint: 'pw-cwr-down' }
const TABLE = {
	widgetId: 'cwr-table',
	endpoint: 'pw-cwr-table',
	rows: [
		{ id: 't1', title: 'Fixture row A', status: 'open' },
		{ id: 't2', title: 'Fixture row B', status: 'closed' },
		{ id: 't3', title: 'Fixture row C', status: 'open' },
	],
}

/**
 * The `dataSource` block of a `connector-data` widget: no credential key.
 *
 * @param endpointPath The placeholder endpoint.
 * @return The data source.
 */
function connectorSource(endpointPath: string) {
	return {
		connector: {
			endpointPath,
			method: 'GET',
			itemsPath: 'results',
			fields: { code: 'code', owner: 'owner.name' },
		},
	}
}

/**
 * The fixture dashboard page with its three widgets.
 *
 * @return The manifest page.
 */
function fixturePage() {
	const widgets = [
		{ id: CONNECTOR.widgetId, title: 'Connector rows', type: 'connector-data', content: { dataSource: connectorSource(CONNECTOR.endpoint) } },
		{ id: DOWN.widgetId, title: 'Connector down', type: 'connector-data', content: { dataSource: connectorSource(DOWN.endpoint) } },
		{
			id: TABLE.widgetId,
			title: 'Endpoint table',
			type: 'object-table',
			content: {
				endpointSource: { url: `/apps/openconnector/api/endpoint/${TABLE.endpoint}`, method: 'GET', responsePath: 'data.items' },
				columns: [{ key: 'title', label: 'Title' }, { key: 'status', label: 'Status' }],
			},
		},
	]
	return {
		id: PAGE_ID,
		type: 'dashboard',
		route: `/${PAGE_ID}`,
		title: 'Widget runtime',
		config: {
			widgets,
			layout: widgets.map((w, i) => ({ id: w.id, widgetId: w.id, gridX: 0, gridY: i * 4, gridWidth: 12, gridHeight: 4 })),
		},
	}
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
 * Give the fixture app its dashboard page.
 *
 * @param page An admin page on the buildiq app.
 */
async function seedPage(page: Page): Promise<void> {
	const read = await call(page, 'GET', MANIFEST)
	expect(read.status, 'GET manifest').toBe(200)
	const manifest = read.data
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== PAGE_ID)
	pages.push(fixturePage())
	const menu = [
		...(manifest.menu || []).filter((m: { id: string }) => m.id !== PAGE_ID),
		{ id: PAGE_ID, label: 'Widget runtime', route: PAGE_ID },
	]
	const write = await call(page, 'PUT', MANIFEST, { manifest: { ...manifest, description: 'Playwright fixture (openbuild-connector-widget-runtime)', pages, menu } })
	expect(write.status, `seed page: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)
}

/**
 * Answer one endpoint from the test, on either fleet app id.
 *
 * @param page     The page.
 * @param endpoint The endpoint path.
 * @param answer   The JSON body to answer with, or an HTTP status to fail with.
 * @return A counter of the requests answered.
 */
async function fixtureEndpoint(page: Page, endpoint: string, answer: unknown | number): Promise<{ hits: number }> {
	const counter = { hits: 0 }
	const pattern = new RegExp(`/apps/(integriq|openconnector)/api/endpoint/${endpoint}(\\?|$)`)
	await page.route(pattern, async (route) => {
		counter.hits++
		if (typeof answer === 'number') {
			await route.fulfill({ status: answer, contentType: 'application/json', body: JSON.stringify({ message: 'Upstream answered an error' }) })
			return
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(answer) })
	})
	return counter
}

/**
 * Collect the grid's unresolved-widget warnings from the console, matched on
 * the `CnWidgetGrid` prefix together with the key token (task 4.3).
 *
 * @param page The page.
 * @return The warnings seen so far (live array).
 */
function watchUnresolvedKeys(page: Page): string[] {
	const seen: string[] = []
	page.on('console', (message) => {
		const text = message.text()
		if (/CnWidgetGrid/.test(text) && /widgetKey/i.test(text)) {
			seen.push(text)
		}
	})
	return seen
}

/**
 * Collect uncaught page errors (an unhandled rejection surfaces here).
 *
 * @param page The page.
 * @return The errors seen so far (live array).
 */
function watchPageErrors(page: Page): string[] {
	const seen: string[] = []
	page.on('pageerror', (error) => seen.push(String(error?.message ?? error)))
	return seen
}

/**
 * Answer every fixture endpoint and open the published dashboard.
 *
 * @param page The page.
 * @return The request counters per endpoint.
 */
async function openPublished(page: Page): Promise<{ connector: { hits: number }; down: { hits: number }; table: { hits: number } }> {
	const connector = await fixtureEndpoint(page, CONNECTOR.endpoint, { results: CONNECTOR.items })
	const down = await fixtureEndpoint(page, DOWN.endpoint, 502)
	const table = await fixtureEndpoint(page, TABLE.endpoint, { data: { items: TABLE.rows } })
	await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}/${PAGE_ID}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	return { connector, down, table }
}

test.describe('openbuild-connector-widget-runtime: registry widgets render on the published route', () => {
	test.describe.configure({ timeout: 90_000 })

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Connector Widget Runtime')
			await seedPage(page)
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

	test('REQ-CWR-004: the stored connector bindings carry no credential-bearing key', async ({ page }) => {
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		const read = await call(page, 'GET', MANIFEST)
		expect(read.status).toBe(200)
		const stored = (read.data.pages || []).find((p: { id: string }) => p.id === PAGE_ID)
		expect(stored, 'the fixture page is stored').toBeTruthy()
		const connectors = (stored.config.widgets || []).filter((w: { type: string }) => w.type === 'connector-data')
		expect(connectors).toHaveLength(2)
		for (const widget of connectors) {
			const binding = widget.content.dataSource.connector
			for (const key of CREDENTIAL_KEYS) {
				expect(Object.keys(binding), `${widget.id} carries no ${key}`).not.toContain(key)
			}
		}
	})

	test('REQ-CWR-004: a connector-data widget renders its projected table on /apps/buildiq/builder/{slug}', async ({ page }) => {
		const unresolved = watchUnresolvedKeys(page)
		const { connector } = await openPublished(page)

		const widget = page.locator('.connector-data-view').first()
		await expect(widget, 'the published route renders the ConnectorDataView surface').toBeVisible({ timeout: 45_000 })
		await expect(widget.getByRole('columnheader', { name: 'code', exact: true })).toBeVisible()
		await expect(widget.getByRole('columnheader', { name: 'owner', exact: true })).toBeVisible()
		const rows = widget.locator('tbody tr')
		await expect(rows).toHaveCount(CONNECTOR.items.length)
		await expect(rows.nth(1)).toContainText('PW-002')
		await expect(rows.nth(1)).toContainText('Fixture Two')
		expect(connector.hits, 'the published runtime asked the endpoint').toBeGreaterThan(0)

		await expect(page.locator('.cn-dashboard-page__unknown'), 'no unknown-widget tile is drawn').toHaveCount(0)
		expect(unresolved, 'no unresolved widgetKey warning is logged').toEqual([])
	})

	test('REQ-OCAS-006: a connector-data widget whose endpoint fails shows the error with Retry', async ({ page }) => {
		const unresolved = watchUnresolvedKeys(page)
		const errors = watchPageErrors(page)
		const { down } = await openPublished(page)

		const failing = page.locator('.connector-data-view').filter({ has: page.getByRole('button', { name: 'Retry' }) }).first()
		await expect(failing, 'the failing widget shows its error state').toBeVisible({ timeout: 45_000 })
		await expect(failing.locator('tbody tr')).toHaveCount(0)

		const before = down.hits
		await failing.getByRole('button', { name: 'Retry' }).click()
		await expect.poll(() => down.hits, { message: 'Retry asks the endpoint again' }).toBeGreaterThan(before)

		expect(errors, 'no uncaught error or rejection reaches the page').toEqual([])
		expect(unresolved, 'no unresolved widgetKey warning is logged').toEqual([])
	})

	test('REQ-CWR-002: an endpoint-bound object-table renders the rows at responsePath', async ({ page }) => {
		const unresolved = watchUnresolvedKeys(page)
		const { table } = await openPublished(page)

		const widget = page.locator('.cn-widget-object-table').first()
		await expect(widget, 'the published route renders the object-table widget').toBeVisible({ timeout: 45_000 })
		const rows = widget.locator('tbody tr')
		await expect(rows).toHaveCount(TABLE.rows.length)
		await expect(rows.nth(0)).toContainText('Fixture row A')
		await expect(rows.nth(1)).toContainText('closed')
		expect(table.hits, 'the table read its endpoint, not OpenRegister').toBeGreaterThan(0)

		expect(unresolved, 'no unresolved widgetKey warning is logged').toEqual([])
	})
})
