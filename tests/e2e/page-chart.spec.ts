/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end to end coverage for openspec change
 * `pages-chart-widget-authoring` (task T03).
 *
 * A maker adds a chart to a dashboard page with "Add chart", configures it in
 * the library's chart form as a bar chart by category, saves, and the published
 * page draws one bar per category.
 *
 * WHAT THIS ASSERTS THAT THE VITEST SUITE CANNOT
 * ----------------------------------------------
 * `WidgetBuilder.spec.js` and `chartWidget.spec.js` assert the widget the form
 * emits and validate it against the library's `$defs.widgetDef`. They cannot see
 * whether the saved widget reaches the STORED manifest, and whether the runtime
 * then draws it: a chart whose dataSource the runtime cannot read renders an
 * empty state and throws nothing. So the stored manifest is read back, and the
 * bars are counted on the published page against the categories the register
 * actually holds.
 *
 * THE DATA. The change's scenario charts requests by status. Every buildiq
 * install already holds a register with a status-bearing schema of its own,
 * buildiq's `application`, so the chart groups those by `status`: the shape is
 * the same (register, schema, group-by field), and the expected bar count is
 * read from the register rather than seeded.
 *
 * @e2e page-chart-authoring/requirement-a-chart-widget-is-configured-with-the-librarys-chart-form-req-bqcw-001/a-maker-configures-a-bar-chart-by-category
 * @e2e page-chart-authoring/requirement-a-new-chart-is-placed-on-the-page-req-bqcw-003/a-new-chart-appears-without-a-separate-layout-step
 * @e2e page-chart-authoring/requirement-the-chart-is-saved-in-the-shape-the-manifest-schema-allows-req-bqcw-002/the-saved-chart-validates-and-draws-after-publishing
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	dismissOverlays,
	ensureApp,
	suppressSupportDialog,
} from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-page-chart'
const PAGE_ID = 'pw-chart-dashboard'
const MANIFEST_URL = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

/** The chart's source: a register and schema every buildiq install holds. */
const SOURCE = { register: 'buildiq', schema: 'application', field: 'status' }

/**
 * Call the app's own API from the page, with its request token.
 *
 * @param page The page, on a buildiq route.
 * @param url The URL.
 * @param init The method and body.
 * @param init.method The HTTP method.
 * @param init.body The JSON body.
 * @return The parsed answer and status.
 */
async function call(page: Page, url: string, init: { method?: string; body?: unknown } = {}): Promise<{ status: number; body: any }> {
	return await page.evaluate(
		async ({ url, method, body }) => {
			const tok =
				(window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(url, {
				method: method || 'GET',
				headers: { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			return { status: resp.status, body: await resp.json().catch(() => ({})) }
		},
		{ url, method: init.method, body: init.body },
	)
}

/**
 * Store the app's manifest with one empty dashboard page of this suite.
 *
 * @param page The page.
 */
async function seedDashboard(page: Page): Promise<void> {
	const current = (await call(page, MANIFEST_URL)).body
	const keep = (current.pages || []).filter((p) => p.id !== PAGE_ID)
	const stored = await call(page, MANIFEST_URL, {
		method: 'PUT',
		body: {
			manifest: {
				...current,
				pages: [
					{ id: PAGE_ID, route: `/${PAGE_ID}`, type: 'dashboard', title: 'Chart fixture', config: { widgets: [], layout: [] } },
					...keep,
				],
			},
		},
	})
	expect(stored.status, `seeding the dashboard page: ${JSON.stringify(stored.body)}`).toBeLessThan(300)
}

/**
 * The stored config of the suite's dashboard page.
 *
 * @param page The page.
 * @return The config.
 */
async function storedConfig(page: Page): Promise<{ widgets?: any[]; layout?: any[] }> {
	const manifest = (await call(page, MANIFEST_URL)).body
	return ((manifest.pages || []).find((p) => p.id === PAGE_ID) || {}).config || {}
}

/**
 * Pick an option in an NcSelect found by its label.
 *
 * @param page The page.
 * @param label The select's label.
 * @param option The option's visible text.
 */
async function choose(page: Page, label: string, option: RegExp): Promise<void> {
	await page.getByLabel(label, { exact: true }).click()
	await page.getByRole('option', { name: option }).first().click()
}

test.describe('a chart on a dashboard page', () => {
	test.describe.configure({ mode: 'serial' })
	test.use({ viewport: { width: 1600, height: 1400 } })

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureApp(page, APP_SLUG, 'PW Page Chart')
		await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
	})

	// @e2e page-chart-authoring/requirement-a-chart-widget-is-configured-with-the-librarys-chart-form-req-bqcw-001/a-maker-configures-a-bar-chart-by-category
	// @e2e page-chart-authoring/requirement-a-new-chart-is-placed-on-the-page-req-bqcw-003/a-new-chart-appears-without-a-separate-layout-step
	test('a maker adds a bar chart by category and it is stored with its layout item', async ({ page }) => {
		test.setTimeout(180_000)
		await seedDashboard(page)

		await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		await page.locator('.page-list-editor__row').filter({ hasText: 'Chart fixture' }).first().click()

		const editor = page.locator('.dashboard-page-editor')
		await expect(editor, 'a dashboard page opens the dashboard editor').toBeVisible({ timeout: 30_000 })
		await editor.getByRole('button', { name: /Add chart/ }).click()

		const form = editor.locator('.cn-chart-widget-form').first()
		await expect(form, 'the new chart opens the library\'s chart form').toBeVisible({ timeout: 15_000 })
		await choose(page, 'Chart type', /^bar$/i)
		await choose(page, 'Breakdown', /By category/)
		await choose(page, 'Register', new RegExp(SOURCE.register, 'i'))
		await choose(page, 'Schema', new RegExp(`^${SOURCE.schema}$`, 'i'))

		const fieldSelect = form.getByRole('combobox', { name: 'Group by field' })
		if ((await fieldSelect.count()) > 0) {
			await choose(page, 'Group by field', new RegExp(`^${SOURCE.field}$`))
		} else {
			await form.getByLabel('Group by field').fill(SOURCE.field)
		}

		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => {
				const config = await storedConfig(page)
				const chart = (config.widgets || []).find((w) => w.id === 'chart-1')
				return {
					kind: chart?.props?.chartKind ?? null,
					register: chart?.dataSource?.register ?? null,
					schema: chart?.dataSource?.schema ?? null,
					field: chart?.dataSource?.groupBy?.field ?? null,
					placed: (config.layout || []).filter((item) => item.widgetId === 'chart-1').length,
				}
			}, { timeout: 30_000, message: 'the configured chart and its one layout item must reach the stored manifest' })
			.toEqual({ kind: 'bar', register: SOURCE.register, schema: SOURCE.schema, field: SOURCE.field, placed: 1 })

		const chart = ((await storedConfig(page)).widgets || []).find((w) => w.id === 'chart-1')
		expect(chart.content, 'the form\'s content is mapped onto props and dataSource, never stored as content').toBeUndefined()
	})

	// @e2e page-chart-authoring/requirement-the-chart-is-saved-in-the-shape-the-manifest-schema-allows-req-bqcw-002/the-saved-chart-validates-and-draws-after-publishing
	test('the published page draws one bar per category', async ({ page }) => {
		test.setTimeout(180_000)
		const chart = ((await storedConfig(page)).widgets || []).find((w) => w.id === 'chart-1')
		expect(chart, 'the first test stores the chart this one draws').toBeTruthy()

		const apps = (await call(page, '/index.php/apps/buildiq/api/applications')).body
		const app = (apps.results || apps || []).find((a) => (a.slug || a?.['@self']?.slug) === APP_SLUG)
		const appUuid = app?.id || app?.uuid || app?.['@self']?.id
		expect(appUuid, 'the fixture app must be listed').toBeTruthy()
		const published = await call(page, `/index.php/apps/buildiq/api/applications/${appUuid}/publish`, { method: 'POST' })
		expect(published.status, `publishing: ${JSON.stringify(published.body)}`).toBeLessThan(300)

		const rows = (await call(page, `/index.php/apps/openregister/api/objects/${SOURCE.register}/${SOURCE.schema}?_limit=500`)).body
		const categories = new Set(
			(rows.results || [])
				.map((row) => row?.[SOURCE.field])
				.filter((value) => value !== undefined && value !== null && value !== ''),
		)
		expect(categories.size, `the ${SOURCE.register}/${SOURCE.schema} register must hold a ${SOURCE.field} to chart`).toBeGreaterThan(0)

		await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/${PAGE_ID}?_version=production`, { waitUntil: 'domcontentloaded' })
		const drawn = page.locator('.cn-chart-widget').first()
		await expect(drawn, 'the published page draws the chart').toBeVisible({ timeout: 60_000 })
		await expect(drawn.locator('[data-testid="cn-chart-widget-empty"]'), 'the chart must not fall back to its empty state').toHaveCount(0)
		await expect(drawn.locator('.apexcharts-bar-area'), 'one bar per category').toHaveCount(categories.size, { timeout: 30_000 })
	})
})
