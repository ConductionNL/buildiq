// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: the page designer's error log (change
 * operate-debug-log-and-monitoring, task T04, REQ-BQDM-001, REQ-BQDM-002 and
 * REQ-BQDM-003).
 *
 * WHAT THIS ASSERTS THAT THE VITEST SUITE CANNOT
 * ----------------------------------------------
 * DebugLogPanel.spec.js and useDebugLog.spec.js feed the panel hand-made
 * entries. What they cannot see is the chain that produces a real one: the
 * preview sandbox's store fetching a real schema, nextcloud-vue's diagnostics
 * channel reporting the column the schema lacks or the request that failed,
 * and `PreviewSandbox` handing that report to the designer's log. Any link of
 * it can break silently, and the log then just says "No problems in the
 * preview." while the preview shows an empty list.
 *
 * THE FIXTURE
 * -----------
 * `beforeAll` clones `incident-reporter` into a `pw-` app, for a real register
 * and a real `incident` schema to bind to; `afterAll` removes it. Each test
 * stores the pages it needs and opens the designer on them: the page under
 * test is the first page and sits on `/`, which is the route the preview opens
 * on. The column the schema lacks is the spec's `kvkNumber`; the page is the
 * spec's `permits`, bound to `incident` rather than to a `permit` schema.
 *
 * The 403 of REQ-BQDM-001 is produced by answering the preview's own list
 * request with a 403 (`page.route`), since the admin this suite runs as is
 * never refused a read. The request still leaves the preview and its failure
 * still travels the real diagnostics channel; only the server's answer is
 * stood in for.
 *
 * @spec openspec/changes/archive/2026-11-24-operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md
 * @e2e builder-debug-log/requirement-the-designer-logs-the-previews-requests-and-errors-req-bqdm-001/a-maker-sees-a-failed-list-request
 * @e2e builder-debug-log/requirement-the-log-names-binding-problems-req-bqdm-002/a-column-points-at-a-property-that-is-gone
 * @e2e builder-debug-log/requirement-the-log-stays-in-the-makers-browser-req-bqdm-003/a-reload-starts-a-clean-log
 */

import type { Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
import {
	adminPage,
	cloneTemplateApp,
	deleteApp,
	fixtureSlug,
	readManifest,
	writeManifest,
} from './support/templateApp.ts'

const PAGE_ID = 'permits'
const GONE = 'kvkNumber'
const MISSING_SCHEMA = 'pw-no-such-schema'

let app: TemplateApp | null = null

/**
 * Store the app's pages, the page under test first and on `/`.
 *
 * @param page   A signed-in page.
 * @param config The index page's config, over the app's register and schema.
 * @return Resolves once stored.
 */
async function seedIndexPage(page: Page, config: Record<string, unknown>): Promise<void> {
	const manifest = await readManifest(page, app!.slug)
	const others = ((manifest.pages ?? []) as Array<Record<string, any>>)
		.filter((p) => p.id !== PAGE_ID)
		.map((p) => (p.route === '/' ? { ...p, route: `/${p.id}` } : p))
	await writeManifest(page, app!.slug, {
		...manifest,
		pages: [
			{
				id: PAGE_ID,
				type: 'index',
				route: '/',
				title: 'Permits',
				config: { register: app!.register, schema: app!.schema, columns: ['location', 'status'], ...config },
			},
			...others,
		],
	})
}

/**
 * Open the designer and wait for the preview's error log.
 *
 * @param page The Playwright page.
 * @return The log.
 */
async function openDesigner(page: Page) {
	await page.goto(`/index.php/apps/buildiq/builder/${app!.slug}/pages?_version=production`, {
		waitUntil: 'domcontentloaded',
	})
	await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
	await dismissOverlays(page)
	const log = page.locator('[data-testid="debug-log"]')
	await expect(log, 'the error log sits under the live preview').toBeVisible({ timeout: 60_000 })
	return log
}

test.describe('page designer error log', () => {
	// A three-pane desktop surface: below this the preview and its log land
	// out of view, where nothing settles.
	test.use({ viewport: { width: 1600, height: 1400 } })

	test.beforeAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			app = await cloneTemplateApp(page, fixtureSlug('debug-log'), 'PW Debug log')
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			await deleteApp(page, app?.uuid ?? '')
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	})

	test('REQ-BQDM-001: a failed list request is logged with its path, status and duration', async ({ page }) => {
		await seedIndexPage(page, {})
		const listPath = `/apps/openregister/api/objects/${app!.register}/${app!.schema}`
		await page.route(
			(url) => url.pathname.endsWith(listPath),
			(route) => route.fulfill({ status: 403, contentType: 'application/json', body: '{"message":"Forbidden"}' }),
		)

		const log = await openDesigner(page)
		const row = log.locator('[data-testid="debug-log-row"]').filter({ hasText: /answered 403/i })
		await expect(row, 'the refused list request is one entry').toHaveCount(1, { timeout: 30_000 })
		await expect(row).toContainText('Permits')
		await expect(row).toContainText(listPath)

		// The duration is in the entry's detail, next to the status.
		await row.getByRole('button', { name: 'Permits' }).click()
		await expect(log.locator('pre').first()).toContainText(/403\s+\d+ ms/)

		// "Failed requests" keeps it; "Broken bindings" does not.
		const filter = log.locator('[data-testid="debug-log-filter"]')
		await filter.selectOption('request')
		await expect(log.locator('[data-testid="debug-log-row"]').filter({ hasText: /answered 403/i })).toHaveCount(1)
		await filter.selectOption('binding')
		await expect(log.locator('[data-testid="debug-log-row"]').filter({ hasText: /answered 403/i })).toHaveCount(0)

		// The designer's own calls to buildiq's API are never logged.
		await filter.selectOption('all')
		await expect(log.locator('[data-testid="debug-log-row"]').filter({ hasText: '/apps/buildiq/api/' })).toHaveCount(0)
	})

	test('REQ-BQDM-002: a column the schema lacks is a binding entry that leads to its page', async ({ page }) => {
		await seedIndexPage(page, { columns: ['location', GONE] })

		const log = await openDesigner(page)
		const row = log.locator('[data-testid="debug-log-row"]').filter({
			hasText: new RegExp(`${GONE} is not a field of schema`),
		})
		await expect(row, `the log names ${GONE} as a broken binding`).toHaveCount(1, { timeout: 30_000 })
		await expect(row).toContainText(app!.schema)

		await log.locator('[data-testid="debug-log-filter"]').selectOption('binding')
		await expect(row).toHaveCount(1)

		// Select another page first, so "Go to the page" has something to do.
		await page.locator('.page-list-editor__row').nth(1).click()
		await row.getByRole('button', { name: 'Permits' }).click()
		await log.getByRole('button', { name: /go to the page/i }).click()
		await expect(
			page.locator('.page-list-editor__row').first(),
			'"Go to the page" selects the page the entry is about',
		).toHaveClass(/page-list-editor__row--selected/)
	})

	test('REQ-BQDM-002: a schema the preview cannot find is logged', async ({ page }) => {
		await seedIndexPage(page, { schema: MISSING_SCHEMA })

		const log = await openDesigner(page)
		// Either the binding entry for the missing schema, or the 404 of the
		// request that looked for it: both name the page and the problem.
		const row = log.locator('[data-testid="debug-log-row"]').filter({
			hasText: new RegExp(`Schema ${MISSING_SCHEMA} does not exist|answered 404`),
		})
		await expect(row.first(), 'the missing schema is in the log').toBeVisible({ timeout: 30_000 })
		await expect(row.first()).toContainText('Permits')
	})

	test('REQ-BQDM-003: a reload starts a clean log, and no entry ever leaves the browser', async ({ page }) => {
		await seedIndexPage(page, { columns: ['location', GONE] })

		// Every request the designer makes from here on, with its body.
		const sent: string[] = []
		page.on('request', (request) => {
			sent.push(`${request.method()} ${request.url()} ${request.postData() ?? ''}`)
		})

		const log = await openDesigner(page)
		await expect(log.locator('[data-testid="debug-log-row"]').first()).toBeVisible({ timeout: 30_000 })

		await page.reload({ waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		const fresh = page.locator('[data-testid="debug-log"]')
		await expect(fresh).toBeVisible({ timeout: 60_000 })

		// The reloaded preview reports the same binding again, so "clean" is
		// one entry with a count of one, not the old entry carried over.
		const rows = fresh.locator('[data-testid="debug-log-row"]')
		const count = await rows.count()
		expect(count, 'the reloaded log holds no more than the reloaded preview reported').toBeLessThanOrEqual(1)
		if (count === 1) {
			await expect(rows.first()).not.toContainText(/times/)
		}

		// Clear empties it outright.
		await fresh.locator('[data-testid="debug-log-clear"]').click()
		await expect(fresh.locator('[data-testid="debug-log-empty"]')).toBeVisible()

		const carried = sent.filter((line) => /^(POST|PUT|PATCH) /.test(line)
			&& (line.includes(`${GONE} is not a field`) || line.includes('missing-property')))
		expect(carried, 'no request carried a log entry to the server').toEqual([])
	})
})
