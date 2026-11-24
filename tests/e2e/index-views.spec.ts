// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: the views an index page offers, and a personal row order
 * (change pages-index-calendar-and-manual-order, task T05 for REQ-PEC-008 and
 * REQ-PEC-009, task T07 for REQ-PEC-010).
 *
 * WHAT THIS ASSERTS THAT THE VITEST SUITE CANNOT
 * ----------------------------------------------
 * IndexViewModesBuilder.spec.js proves the editor writes `config.viewModes`,
 * `config.calendar` and `manualOrder` into its own buffer. Rendering belongs to
 * nextcloud-vue, so what no unit test can show is the whole trip: the designer's
 * Save storing those keys, and the built app reading them back as a Calendar
 * view with the record on its date and as rows a user can drag into an order
 * that survives a reload and stays theirs.
 *
 * THE FIXTURE
 * -----------
 * `beforeAll` clones `incident-reporter` into a `pw-` app (its `incident`
 * schema has the date-time `reportedAt`, the string `location` and the enum
 * `status`), makes three incidents dated today, and grants `rbac-viewer`
 * (tests/e2e/global-setup.ts) the viewer role, as the "another user" of
 * REQ-PEC-009. `afterAll` removes the app with its records. The spec's
 * Inspectie with `inspectiedatum` is `incident` with `reportedAt` here.
 *
 * WHAT IS GUARDED
 * ---------------
 * The board of REQ-PEC-010: the view builder of task T07 writes the status
 * field and names buildiq's `board-move` handler (src/services/boardMove.js),
 * nextcloud-vue draws the board on the built index page, and a dropped card is
 * saved into its record through OpenRegister. The date axis scenario is not in
 * this file: T07 names only the board for Playwright.
 *
 * @spec openspec/changes/archive/2026-11-24-pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md
 * @e2e page-editor-coverage/requirement-an-index-page-can-offer-a-calendar-view-req-pec-008/calendar-without-a-date-field
 * @e2e page-editor-coverage/requirement-an-index-page-can-offer-a-calendar-view-req-pec-008/inspections-on-a-calendar
 * @e2e page-editor-coverage/requirement-an-index-page-can-let-users-order-rows-by-dragging-req-pec-009/a-personal-order
 * @e2e page-editor-coverage/requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010/cases-on-a-board-by-status
 */

import type { Locator, Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'
import {
	adminPage,
	api,
	cloneTemplateApp,
	deleteApp,
	fixtureSlug,
	idOf,
	readManifest,
	writeManifest,
} from './support/templateApp.ts'

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const VIEWER_USER = process.env.NC_RBAC_VIEWER_USER ?? 'rbac-viewer'
const VIEWER_PASS = process.env.NC_RBAC_VIEWER_PASS ?? 'RbacViewer-1!'
const RUN = Date.now().toString(36)
const LOCATIONS = [`Kerkstraat ${RUN}`, `Molenweg ${RUN}`, `Stationsplein ${RUN}`]

test.describe.configure({ mode: 'serial', timeout: 120_000 })

let app: TemplateApp | null = null
/** The index page bound to `incident`: its id, route and place in pages[]. */
let indexPage = { id: '', route: '', position: 0 }

/**
 * The stored index page.
 *
 * @param page A signed-in page.
 * @return The page object.
 */
async function storedIndexPage(page: Page): Promise<Record<string, any>> {
	const manifest = await readManifest(page, app!.slug)
	return ((manifest.pages ?? []) as Array<Record<string, any>>).find((p) => p.id === indexPage.id) ?? {}
}

/**
 * Put the index page back to no views and no drag order, so each test starts
 * from what the template ships.
 *
 * @param page A signed-in page.
 * @return Resolves once stored.
 */
async function resetIndexPage(page: Page): Promise<void> {
	const manifest = await readManifest(page, app!.slug)
	const pages = ((manifest.pages ?? []) as Array<Record<string, any>>).map((p) => {
		if (p.id !== indexPage.id) {
			return p
		}
		const next = { ...p, config: { ...(p.config ?? {}) } }
		delete next.manualOrder
		for (const key of ['viewModes', 'calendar', 'board', 'dateAxis']) {
			delete next.config[key]
		}
		return next
	})
	await writeManifest(page, app!.slug, { ...manifest, pages })
}

/**
 * Open the designer on the index page and wait for its Views section.
 *
 * @param page The Playwright page.
 * @return The Views section.
 */
async function openIndexEditor(page: Page): Promise<Locator> {
	await page.goto(`/index.php/apps/buildiq/builder/${app!.slug}/pages?_version=production`, {
		waitUntil: 'domcontentloaded',
	})
	await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
	await dismissOverlays(page)
	await page.locator('.page-list-editor__row').nth(indexPage.position).click()
	const views = page.locator('.index-view-modes')
	await expect(views, 'the index page editor has a Views section').toBeVisible({ timeout: 30_000 })
	return views
}

/**
 * Pick an option in one of the view builder's field selects.
 *
 * @param page   The Playwright page.
 * @param label  The select's label.
 * @param option The property to pick.
 * @return Resolves once picked.
 */
async function pickField(page: Page, label: RegExp, option: string): Promise<void> {
	await page.getByRole('combobox', { name: label }).click()
	await page.getByRole('option', { name: option, exact: true }).first().click()
}

/**
 * Open the built app on the index page.
 *
 * @param page The Playwright page.
 * @return Resolves once the list has rows.
 */
async function openBuiltIndex(page: Page): Promise<void> {
	await page.goto(`/index.php/apps/buildiq/builder/${app!.slug}${indexPage.route}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await expect(page.locator('[data-testid="cn-object-row"]').first()).toBeVisible({ timeout: 60_000 })
}

/**
 * The control that switches the list to a view, whichever role the toggle
 * gives its segments.
 *
 * @param page The Playwright page.
 * @param name The view's name.
 * @return The control.
 */
function viewToggle(page: Page, name: RegExp): Locator {
	return page.getByRole('radio', { name })
		.or(page.getByRole('tab', { name }))
		.or(page.getByRole('button', { name }))
		.first()
}

/**
 * The record ids of the list, top to bottom.
 *
 * @param page The Playwright page.
 * @return The ids.
 */
async function rowOrder(page: Page): Promise<string[]> {
	return page.locator('[data-testid="cn-object-row"]').evaluateAll((rows) => rows.map((row) => row.getAttribute('data-testid-row-id') ?? ''))
}

/**
 * Log in through the login form.
 *
 * @param page Playwright page in an empty storage state.
 * @param user User id.
 * @param pass Password.
 * @return Resolves once logged in.
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

test.describe('index page views and drag order', () => {
	// A three-pane desktop surface: below this the Views section lands out of view.
	test.use({ viewport: { width: 1600, height: 1400 } })

	test.beforeAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			app = await cloneTemplateApp(page, fixtureSlug('index-views'), `PW Index views ${RUN}`)
			const manifest = await readManifest(page, app.slug)
			const pages = (manifest.pages ?? []) as Array<Record<string, any>>
			const position = pages.findIndex((p) => p.type === 'index' && p.config?.schema === app!.schema)
			expect(position, 'the template has an index page on its schema').toBeGreaterThanOrEqual(0)
			indexPage = { id: String(pages[position].id), route: String(pages[position].route), position }

			await grantAppRoles(page, app.slug, { owners: [`user:${ADMIN_USER}`], viewers: [`user:${VIEWER_USER}`] })

			const today = new Date().toISOString()
			for (const location of LOCATIONS) {
				const created = await api(page, 'POST', `/index.php/apps/openregister/api/objects/${app.register}/${app.schema}`, {
					reportedBy: 'e2e',
					incidentType: 'inspection',
					severity: 'low',
					status: 'new',
					reportedAt: today,
					location,
				})
				expect(created.status, JSON.stringify(created.json).slice(0, 300)).toBeLessThan(300)
				expect(idOf(created.json)).not.toBe('')
			}
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

	test('REQ-PEC-008: a calendar without a date field is a finding', async ({ page }) => {
		await resetIndexPage(page)
		const views = await openIndexEditor(page)

		await views.locator('input[data-mode="calendar"]').check()
		await expect(
			views.getByRole('alert').filter({ hasText: 'The calendar needs a date field.' }),
			'the designer lists the missing date field where it is missing',
		).toBeVisible()
	})

	test('REQ-PEC-008 + REQ-PEC-009: the maker turns on the calendar and drag order, and the built app shows each record on its date', async ({ page }) => {
		await resetIndexPage(page)
		const views = await openIndexEditor(page)

		await views.locator('input[data-mode="calendar"]').check()
		await pickField(page, /^calendar date field$/i, 'reportedAt')
		await pickField(page, /^calendar title field/i, 'location')
		await expect(views.getByRole('alert')).toHaveCount(0)
		await page.locator('input[data-manual-order]').check()
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => {
				const stored = await storedIndexPage(page)
				return {
					viewModes: stored.config?.viewModes ?? [],
					dateField: stored.config?.calendar?.dateField ?? null,
					manualOrder: stored.manualOrder ?? null,
				}
			}, { timeout: 30_000, message: 'the views and the drag order must reach the stored manifest' })
			.toEqual({ viewModes: ['table', 'calendar'], dateField: 'reportedAt', manualOrder: true })

		// manualOrder sits on the page, not in its config.
		expect((await storedIndexPage(page)).config).not.toHaveProperty('manualOrder')

		await openBuiltIndex(page)
		await viewToggle(page, /calendar/i).click()
		for (const location of LOCATIONS) {
			await expect(page.getByText(location, { exact: false }).first(), `${location} is on the calendar on its date`).toBeVisible({ timeout: 30_000 })
		}
	})

	test('REQ-PEC-009: a user drags a row to the top, keeps it after a reload, and another user keeps the default order', async ({ page, browser }) => {
		const stored = await storedIndexPage(page)
		test.skip(stored.manualOrder !== true, 'the previous test did not store manualOrder, so there is no drag order to test')

		await openBuiltIndex(page)
		await viewToggle(page, /table/i).click().catch(() => undefined)
		const before = await rowOrder(page)
		expect(before.length, 'the list shows the seeded incidents').toBeGreaterThanOrEqual(LOCATIONS.length)
		const moved = before[before.length - 1]

		const rows = page.locator('[data-testid="cn-object-row"]')
		await rows.last().dragTo(rows.first())
		await expect.poll(async () => (await rowOrder(page))[0], { message: 'the dragged row is on top' }).toBe(moved)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(rows.first()).toBeVisible({ timeout: 60_000 })
		await viewToggle(page, /table/i).click().catch(() => undefined)
		await expect.poll(async () => (await rowOrder(page))[0], { timeout: 30_000, message: 'the order survives a reload' }).toBe(moved)

		// The order is this user's, never the record's: a colleague still
		// sees the list as it loads.
		const context = await browser.newContext({ baseURL: E2E_BASE_URL, storageState: { cookies: [], origins: [] } })
		const colleague = await context.newPage()
		try {
			await suppressSupportDialog(colleague)
			await loginAs(colleague, VIEWER_USER, VIEWER_PASS)
			await openBuiltIndex(colleague)
			await viewToggle(colleague, /table/i).click().catch(() => undefined)
			await expect.poll(async () => rowOrder(colleague), { timeout: 30_000, message: 'another user sees the default order' }).toEqual(before)
		} finally {
			await context.close()
		}
	})

	test('REQ-PEC-010: a user switches the list to Board and moves a card', async ({ page }) => {
		await resetIndexPage(page)
		const views = await openIndexEditor(page)
		await views.locator('input[data-mode="board"]').check()
		await pickField(page, /^board status field$/i, 'status')
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => {
				const stored = await storedIndexPage(page)
				return {
					viewModes: stored.config?.viewModes ?? [],
					statusField: stored.config?.board?.statusField ?? null,
					moveHandler: stored.config?.board?.moveHandler ?? null,
				}
			}, { timeout: 30_000 })
			.toEqual({ viewModes: ['table', 'board'], statusField: 'status', moveHandler: 'board-move' })

		await openBuiltIndex(page)
		await viewToggle(page, /board/i).click()

		// One column per state of the status enum.
		for (const state of ['new', 'triaged', 'resolved']) {
			await expect(page.getByRole('region', { name: new RegExp(state, 'i') }).or(page.getByText(state, { exact: true })).first()).toBeVisible()
		}

		const card = page.getByText(LOCATIONS[0]).first()
		const target = page.getByRole('region', { name: /triaged/i }).first()
		await card.dragTo(target)
		await expect
			.poll(async () => {
				const list = await api(page, 'GET', `/index.php/apps/openregister/api/objects/${app!.register}/${app!.schema}?_limit=200`)
				return ((list.json?.results ?? []) as Array<Record<string, any>>).find((r) => r?.location === LOCATIONS[0])?.status
			}, { timeout: 30_000, message: 'moving the card changes the record\'s status' })
			.toBe('triaged')
	})
})
