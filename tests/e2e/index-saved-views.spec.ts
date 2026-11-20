/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: saved views on an index page (change
 * data-index-grid-and-saved-views, task T05, REQ-BQIX-003).
 *
 * The fixture app is cloned from the `incident-reporter` template and three
 * incidents are seeded: two `new` ones with different `reportedAt`, and one
 * `resolved`. The spec's `Requests` page and "Open requests" view become the
 * template's "All incidents" index page and a view "New incidents" filtered on
 * `status` = `new` and sorted by `reportedAt`, newest first.
 *
 * 1. A maker opens the index page in the page designer, switches on "Let app
 *    users save their own views", adds a view for everyone with that filter
 *    and sort, and saves. The stored page config is read back:
 *    `allowSavedViews: true` and the view in `config.defaultSavedViews`
 *    (task T05 records why the views travel in the manifest rather than as
 *    public OpenRegister views). The app is then published.
 * 2. In the published app the view is found in the views list and applied: the
 *    list shows only the two new incidents, the newer one first.
 *
 * Applying the view is nextcloud-vue's `CnIndexPage` / saved-views control
 * (2.180 lists `defaultSavedViews` read-only for every user of the page); the
 * test looks the view up by its name in either the chips or the views panel,
 * opening the index sidebar's views tab when the panel is not on screen.
 *
 * Setting the index page's register and columns is set-up, done through the
 * manifest API in beforeAll; the fixture app is removed, data included, in
 * afterAll.
 *
 * @spec openspec/changes/data-index-grid-and-saved-views/specs/index-page-grid-and-views/spec.md
 * @e2e index-page-grid-and-views/requirement-a-maker-switches-on-saved-views-and-defines-views-for-everyone-req-bqix-003/every-app-user-gets-the-view-open-requests
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import path from 'node:path'
import { dismissOverlays, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }

const RUN = Date.now().toString(36)
const APP_SLUG = 'pw-index-saved-views'
const TEMPLATE = 'incident-reporter'
const SCHEMA = 'incident'
const VIEW_NAME = 'New incidents'
const MANIFEST_URL = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

/** The seeded incidents: their description, status and report date. */
const SEED = {
	older: { description: `Older new incident ${RUN}`, status: 'new', reportedAt: '2026-01-05T09:00:00+00:00' },
	newer: { description: `Newer new incident ${RUN}`, status: 'new', reportedAt: '2026-03-05T09:00:00+00:00' },
	resolved: { description: `Resolved incident ${RUN}`, status: 'resolved', reportedAt: '2026-04-05T09:00:00+00:00' },
}

let appUuid = ''
let register = ''
let indexPageId = ''
let indexRoute = ''
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
 * The stored config of the suite's index page.
 *
 * @param page A signed-in page.
 * @return The config.
 */
async function storedIndexConfig(page: Page): Promise<Record<string, any>> {
	const manifest = (await api(page, 'GET', MANIFEST_URL)).data
	return ((manifest.pages || []).find((p: any) => p.id === indexPageId) || {}).config || {}
}

test.describe('data-index-grid-and-saved-views: views for everyone on an index page', () => {
	test.describe.configure({ mode: 'serial', timeout: 180_000 })
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			const created = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/from-template/${TEMPLATE}`, {
				name: 'PW Index saved views',
				slug: APP_SLUG,
			})
			expect(created.status, JSON.stringify(created.data).slice(0, 300)).toBeLessThan(300)
			appUuid = String(created.data?.uuid ?? created.data?.id ?? created.data?.application?.uuid ?? '')

			const manifest = (await api(page, 'GET', MANIFEST_URL)).data
			const pages = manifest.pages || []
			const detail = pages.find((p: any) => p.type === 'detail' && p.config?.schema === SCHEMA)
			const index = pages.find((p: any) => p.type === 'index' && p.config?.schema === SCHEMA)
			register = String(detail?.config?.register ?? '')
			expect(register, 'the template\'s detail page names its register').not.toBe('')
			expect(index, 'the template has an incident index page').toBeTruthy()
			indexPageId = String(index.id)
			indexRoute = String(index.route)
			index.config = { ...index.config, register, columns: ['description', 'status', 'reportedAt'] }
			const saved = await api(page, 'PUT', MANIFEST_URL, { manifest })
			expect(saved.status, JSON.stringify(saved.data).slice(0, 300)).toBeLessThan(300)

			for (const [key, values] of Object.entries(SEED)) {
				const obj = await api(page, 'POST', `/index.php/apps/openregister/api/objects/${register}/${SCHEMA}`, {
					reportedBy: 'e2e',
					incidentType: 'equipment',
					severity: 'low',
					location: 'Depot',
					...values,
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
				await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/${register}/${SCHEMA}/${id}`).catch(() => undefined)
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

	// @e2e index-page-grid-and-views/requirement-a-maker-switches-on-saved-views-and-defines-views-for-everyone-req-bqix-003/every-app-user-gets-the-view-open-requests
	test('REQ-BQIX-003: a maker switches on saved views and adds a view for everyone', async ({ page }) => {
		await page.goto(`/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)

		const pageIndex = ((await api(page, 'GET', MANIFEST_URL)).data.pages || []).findIndex((p: any) => p.id === indexPageId)
		const row = page.locator('.page-list-editor__row').nth(pageIndex)
		await row.scrollIntoViewIfNeeded()
		await row.dispatchEvent('click')
		const editor = page.locator('.index-page-editor')
		await expect(editor).toBeVisible({ timeout: 30_000 })

		const section = editor.locator('fieldset').filter({ has: page.locator('legend', { hasText: /^Saved views$/ }) })
		await section.getByLabel('Let app users save their own views').check()
		await section.getByRole('button', { name: /Add view for everyone/ }).click()

		const view = section.locator('.saved-views-builder__view').first()
		await view.getByLabel('View name').fill(VIEW_NAME)
		await view.getByRole('button', { name: /Add filter/ }).click()
		const filter = view.locator('.saved-views-builder__row').filter({ has: page.getByLabel('Filter on') }).first()
		await filter.getByLabel('Filter on').selectOption('status')
		await filter.getByLabel('Value').fill('new')
		await view.getByLabel('Sort by').selectOption('reportedAt')
		await view.getByLabel('Order').selectOption('desc')

		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => {
				const config = await storedIndexConfig(page)
				const stored = (config.defaultSavedViews || [])[0] || {}
				return {
					allowSavedViews: config.allowSavedViews ?? null,
					name: stored.name ?? null,
					filters: stored.filters ?? null,
					sort: stored.sort ?? null,
				}
			}, { timeout: 30_000, message: 'the switch and the view must reach the stored page config' })
			.toEqual({
				allowSavedViews: true,
				name: VIEW_NAME,
				filters: { status: 'new' },
				sort: [{ key: 'reportedAt', order: 'desc' }],
			})

		const published = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/${appUuid}/publish`)
		expect(published.status, `publishing: ${JSON.stringify(published.data).slice(0, 200)}`).toBeLessThan(300)
	})

	// @e2e index-page-grid-and-views/requirement-a-maker-switches-on-saved-views-and-defines-views-for-everyone-req-bqix-003/every-app-user-gets-the-view-open-requests
	test('REQ-BQIX-003: an app user applies the view and sees only new incidents, newest first', async ({ page }) => {
		const config = await (async () => {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			return storedIndexConfig(page)
		})()
		test.skip(!(config.defaultSavedViews || []).length, 'needs the view from the previous test')

		await page.goto(`/index.php/apps/buildiq/builder/${APP_SLUG}${indexRoute}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		const ours = page.getByRole('row').filter({ hasText: RUN })
		await expect(ours, 'before a view is applied, every seeded incident is listed').toHaveCount(3, { timeout: 30_000 })

		const view = page
			.getByTestId('cn-saved-views-item')
			.or(page.getByTestId('cn-saved-views-chip'))
			.filter({ hasText: VIEW_NAME })
			.first()
		const viewsTab = page.getByRole('tab', { name: /views/i }).first()
		await expect(async () => {
			if (!(await view.isVisible().catch(() => false)) && (await viewsTab.isVisible().catch(() => false))) {
				await viewsTab.click({ timeout: 5_000 })
			}
			await expect(view).toBeVisible({ timeout: 5_000 })
		}, 'the view for everyone is in the views list').toPass({ timeout: 45_000, intervals: [500, 1_000, 2_000] })
		await view.click()

		await expect(ours, 'only the new incidents are listed').toHaveCount(2, { timeout: 30_000 })
		await expect(ours.filter({ hasText: SEED.resolved.description })).toHaveCount(0)
		const order = await ours.allInnerTexts()
		expect(order[0], 'the newest new incident is first').toContain(SEED.newer.description)
		expect(order[1]).toContain(SEED.older.description)
	})
})
