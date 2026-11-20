// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: dragging and resizing widgets in a published app's Edit
 * with Buildiq mode (change pages-widget-grid-drag-and-resize, tasks 2 and 3,
 * REQ-OBPD-012).
 *
 * WHAT THIS ASSERTS THAT NOTHING ELSE CAN
 * ---------------------------------------
 * The grid is nextcloud-vue's (`CnDashboardGrid` on GridStack) and the save is
 * buildiq's (`persistManifestDelta` in src/builder.js, which PUTs the manifest
 * the shell edits in place). Each side has its own unit tests; neither can see
 * that a drag in the browser ends up as `gridX` and `gridWidth` in the STORED
 * manifest and comes back after a reload. tests/e2e/widget-placement-editor
 * .spec.ts excludes pointer drags for the designer's own panel; this file is
 * where they are driven, on the published app, which is where the change says
 * they live.
 *
 * THE FIXTURE
 * -----------
 * `beforeAll` clones `incident-reporter` into a `pw-` app and gives it:
 *
 *   - a dashboard page `/grid` with three text widgets, the first two side by
 *     side with room to their right and the third on the row below;
 *   - its detail page body as a widget grid of the data widget at half width
 *     and a text widget on the row below, and one incident to open it on.
 *
 * `afterAll` removes the app with its record. Each test stores those two pages
 * again first, so no test depends on where the previous one left a widget.
 *
 * Drags are real pointer drags (mouse down, move, up) of one column width, so
 * GridStack snaps them to the grid itself; keyboard moves use the arrow keys
 * CnDashboardGrid documents.
 *
 * @spec openspec/changes/pages-widget-grid-drag-and-resize/specs/openbuild-page-designer/spec.md
 * @e2e openbuild-page-designer/requirement-widget-grids-are-draggable-and-resizable-in-edit-mode-req-obpd-012/a-maker-moves-and-widens-a-widget
 * @e2e openbuild-page-designer/requirement-widget-grids-are-draggable-and-resizable-in-edit-mode-req-obpd-012/a-keyboard-user-moves-a-widget
 * @e2e openbuild-page-designer/requirement-widget-grids-are-draggable-and-resizable-in-edit-mode-req-obpd-012/a-detail-page-body
 * @e2e openbuild-page-designer/requirement-widget-grids-are-draggable-and-resizable-in-edit-mode-req-obpd-012/a-user-who-is-not-editing
 */

import type { Locator, Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
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

const DASHBOARD_ID = 'pw-grid-dashboard'
const DASHBOARD_ROUTE = '/grid'
const COLUMNS = 12

type Cell = { id: string; widgetId: string; gridX: number; gridY: number; gridWidth: number; gridHeight: number }

const DASHBOARD_LAYOUT: Cell[] = [
	{ id: 'cell-first', widgetId: 'first', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 3 },
	{ id: 'cell-second', widgetId: 'second', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 3 },
	{ id: 'cell-third', widgetId: 'third', gridX: 0, gridY: 3, gridWidth: 3, gridHeight: 3 },
]

const DETAIL_LAYOUT: Cell[] = [
	{ id: 'cell-data', widgetId: 'incident-data', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 5 },
	{ id: 'cell-note', widgetId: 'note', gridX: 0, gridY: 5, gridWidth: 4, gridHeight: 2 },
]

test.describe.configure({ mode: 'serial', timeout: 120_000 })

let app: TemplateApp | null = null
let detailId = ''
let detailRoute = ''
let recordId = ''

/**
 * A text widget the library draws without any data.
 *
 * @param id   The widget id.
 * @param text Its text.
 * @return The widget definition.
 */
function textWidget(id: string, text: string): Record<string, unknown> {
	return { id, type: 'text', title: text, content: { text } }
}

/**
 * Store the dashboard page and the detail page body in their starting layout.
 *
 * @param page A signed-in page.
 * @return Resolves once stored.
 */
async function seedGrids(page: Page): Promise<void> {
	const manifest = await readManifest(page, app!.slug)
	const pages = ((manifest.pages ?? []) as Array<Record<string, any>>)
		.filter((p) => p.id !== DASHBOARD_ID)
		.map((p) => {
			if (p.id !== detailId) {
				return p
			}
			const widgets = ((p.config?.widgets ?? []) as Array<Record<string, any>>).filter((w) => w.id !== 'note')
			return {
				...p,
				config: { ...p.config, widgets: [...widgets, textWidget('note', 'Note')], layout: DETAIL_LAYOUT },
			}
		})
	pages.push({
		id: DASHBOARD_ID,
		route: DASHBOARD_ROUTE,
		type: 'dashboard',
		title: 'Grid',
		config: {
			widgets: [textWidget('first', 'First'), textWidget('second', 'Second'), textWidget('third', 'Third')],
			layout: DASHBOARD_LAYOUT,
		},
	})
	await writeManifest(page, app!.slug, { ...manifest, pages })
}

/**
 * A widget's stored cell, wherever the page keeps its grid.
 *
 * @param page     A signed-in page.
 * @param pageId   The manifest page id.
 * @param widgetId The widget id.
 * @return The cell, or null.
 */
async function storedCell(page: Page, pageId: string, widgetId: string): Promise<Partial<Cell> | null> {
	const manifest = await readManifest(page, app!.slug)
	const target = ((manifest.pages ?? []) as Array<Record<string, any>>).find((p) => p.id === pageId)
	const cells = [
		...((target?.config?.layout ?? []) as Array<Record<string, any>>),
		...((target?.layout ?? []) as Array<Record<string, any>>),
		...((target?.widgets ?? []) as Array<Record<string, any>>),
	]
	const cell = cells.find((c) => c?.widgetId === widgetId || c?.id === widgetId)
	return cell ? { gridX: cell.gridX, gridWidth: cell.gridWidth } : null
}

/**
 * The grid item that holds a widget.
 *
 * @param page     The Playwright page.
 * @param widgetId The widget id.
 * @return The item.
 */
function gridItem(page: Page, widgetId: string): Locator {
	return page.locator(`.grid-stack-item[data-cn-widget-id="${widgetId}"]`)
}

/**
 * The width of one grid column, in pixels.
 *
 * @param page The Playwright page.
 * @return The width.
 */
async function columnWidth(page: Page): Promise<number> {
	const box = await page.locator('.grid-stack').first().boundingBox()
	expect(box, 'the page has a widget grid').not.toBeNull()
	return box!.width / COLUMNS
}

/**
 * Drag from one point to another with the mouse, in small steps, the way a
 * hand does and GridStack expects.
 *
 * @param page The Playwright page.
 * @param from The start point.
 * @param from.x Start x.
 * @param from.y Start y.
 * @param dx   Pixels to the right.
 * @return Resolves once dropped.
 */
async function pointerDrag(page: Page, from: { x: number; y: number }, dx: number): Promise<void> {
	await page.mouse.move(from.x, from.y)
	await page.mouse.down()
	await page.mouse.move(from.x + dx / 2, from.y, { steps: 8 })
	await page.mouse.move(from.x + dx, from.y, { steps: 8 })
	await page.mouse.up()
}

/**
 * Drag a widget by its body one column to the right.
 *
 * @param page     The Playwright page.
 * @param widgetId The widget id.
 * @return Resolves once dropped.
 */
async function dragOneColumnRight(page: Page, widgetId: string): Promise<void> {
	const box = await gridItem(page, widgetId).boundingBox()
	expect(box, `${widgetId} is on the page`).not.toBeNull()
	await pointerDrag(page, { x: box!.x + box!.width / 2, y: box!.y + 12 }, await columnWidth(page))
}

/**
 * Widen a widget by one column, dragging its edge.
 *
 * @param page     The Playwright page.
 * @param widgetId The widget id.
 * @return Resolves once dropped.
 */
async function widenByOneColumn(page: Page, widgetId: string): Promise<void> {
	const item = gridItem(page, widgetId)
	await item.hover()
	const handle = item.locator('.ui-resizable-se, .ui-resizable-e').first()
	await expect(handle, 'an editable widget has a resize edge').toBeAttached()
	const box = await handle.boundingBox()
	expect(box, 'the resize edge is drawn').not.toBeNull()
	await pointerDrag(page, { x: box!.x + box!.width / 2, y: box!.y + box!.height / 2 }, await columnWidth(page))
}

/**
 * Pick an entry of the published app's Buildiq menu: "Edit page" or "Save page".
 *
 * @param page  The Playwright page.
 * @param entry The entry's name.
 * @return Resolves once picked.
 */
async function buildiqMenu(page: Page, entry: RegExp): Promise<void> {
	await page.getByRole('button', { name: 'buildiq', exact: true }).click()
	await page.getByRole('menuitem', { name: entry }).or(page.getByRole('button', { name: entry })).first().click()
}

/**
 * Save the page and wait for the manifest PUT to answer.
 *
 * @param page The Playwright page.
 * @return Resolves once stored.
 */
async function savePage(page: Page): Promise<void> {
	const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes(`/api/applications/${app!.slug}/manifest`))
	await buildiqMenu(page, /save page/i)
	expect((await saved).ok(), 'Save writes the manifest').toBeTruthy()
}

/**
 * Open a page of the published app and wait for its grid.
 *
 * @param page  The Playwright page.
 * @param route The page route.
 * @param first A widget that must be drawn.
 * @return Resolves once drawn.
 */
async function openPublished(page: Page, route: string, first: string): Promise<void> {
	await page.goto(`/index.php/apps/buildiq/builder/${app!.slug}${route}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await expect(gridItem(page, first)).toBeVisible({ timeout: 60_000 })
}

test.describe('widget grid drag and resize in Edit with Buildiq', () => {
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			app = await cloneTemplateApp(page, fixtureSlug('grid-drag'), 'PW Grid drag')
			const manifest = await readManifest(page, app.slug)
			const detail = ((manifest.pages ?? []) as Array<Record<string, any>>).find((p) => p.type === 'detail' && p.config?.schema === app!.schema)
			expect(detail, 'the template has a detail page on its schema').toBeTruthy()
			detailId = String(detail!.id)
			detailRoute = String(detail!.route)

			const created = await api(page, 'POST', `/index.php/apps/openregister/api/objects/${app.register}/${app.schema}`, {
				reportedBy: 'e2e',
				incidentType: 'equipment',
				severity: 'low',
				location: 'Grid e2e',
			})
			expect(created.status, JSON.stringify(created.json).slice(0, 300)).toBeLessThan(300)
			recordId = idOf(created.json)
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
		await seedGrids(page)
	})

	test('a user who is not editing cannot move a widget', async ({ page }) => {
		await openPublished(page, DASHBOARD_ROUTE, 'first')
		await dragOneColumnRight(page, 'first')
		await expect(gridItem(page, 'first'), 'outside edit mode the grid is static').toHaveAttribute('gs-x', '0')
		expect(await storedCell(page, DASHBOARD_ID, 'first')).toEqual({ gridX: 0, gridWidth: 3 })
	})

	test('a maker drags a dashboard widget one column right, widens it, saves, and the reload keeps it', async ({ page }) => {
		await openPublished(page, DASHBOARD_ROUTE, 'first')
		await buildiqMenu(page, /edit page/i)

		await dragOneColumnRight(page, 'first')
		await expect(gridItem(page, 'first')).toHaveAttribute('gs-x', '1')
		await widenByOneColumn(page, 'first')
		await expect(gridItem(page, 'first')).toHaveAttribute('gs-w', '4')
		await savePage(page)

		await expect
			.poll(async () => storedCell(page, DASHBOARD_ID, 'first'), { timeout: 30_000, message: 'the manifest stores the new x and w' })
			.toEqual({ gridX: 1, gridWidth: 4 })

		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(gridItem(page, 'first')).toHaveAttribute('gs-x', '1', { timeout: 60_000 })
		await expect(gridItem(page, 'first')).toHaveAttribute('gs-w', '4')
	})

	test('a keyboard user moves widgets with the arrow keys and the manifest stores the positions', async ({ page }) => {
		await openPublished(page, DASHBOARD_ROUTE, 'first')
		await buildiqMenu(page, /edit page/i)

		// The second widget, one column to the right.
		await gridItem(page, 'second').focus()
		await page.keyboard.press('ArrowRight')
		await expect(gridItem(page, 'second')).toHaveAttribute('gs-x', '7')

		// The first widget, moved right by hand and back again by keyboard.
		await dragOneColumnRight(page, 'first')
		await expect(gridItem(page, 'first')).toHaveAttribute('gs-x', '1')
		await gridItem(page, 'first').focus()
		await page.keyboard.press('ArrowLeft')
		await expect(gridItem(page, 'first')).toHaveAttribute('gs-x', '0')
		await savePage(page)

		await expect
			.poll(async () => [await storedCell(page, DASHBOARD_ID, 'second'), await storedCell(page, DASHBOARD_ID, 'first')], {
				timeout: 30_000,
				message: 'the keyboard moves are stored',
			})
			.toEqual([{ gridX: 7, gridWidth: 3 }, { gridX: 0, gridWidth: 3 }])
	})

	test('a maker resizes a widget of a detail page body and the detail layout keeps the new size', async ({ page }) => {
		const route = detailRoute.replace(/:[A-Za-z]+/, recordId)
		await openPublished(page, route, 'incident-data')
		await buildiqMenu(page, /edit page/i)

		await widenByOneColumn(page, 'incident-data')
		await expect(gridItem(page, 'incident-data')).toHaveAttribute('gs-w', '7')
		await dragOneColumnRight(page, 'note')
		await expect(gridItem(page, 'note')).toHaveAttribute('gs-x', '1')
		await savePage(page)

		await expect
			.poll(async () => [await storedCell(page, detailId, 'incident-data'), await storedCell(page, detailId, 'note')], {
				timeout: 30_000,
				message: 'the detail page layout carries the new size and place',
			})
			.toEqual([{ gridX: 0, gridWidth: 7 }, { gridX: 1, gridWidth: 4 }])

		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(gridItem(page, 'incident-data')).toHaveAttribute('gs-w', '7', { timeout: 60_000 })
	})
})
