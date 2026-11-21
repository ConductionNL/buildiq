/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end to end coverage for openspec change
 * `v2-widget-placement-editor`.
 *
 * WHAT THIS ASSERTS THAT THE VITEST SUITE CANNOT
 * ----------------------------------------------
 * `tests/components/page-editor/WidgetPlacementPanel.spec.js` mounts the panel
 * against stubs and asserts the array it emits. What it cannot see is whether
 * the panel is on a page at all, whether the type catalogue is populated in a
 * real bundle, and whether what the panel emits survives the trip through
 * `PageDesigner` to the STORED manifest. All three fail silently: an empty
 * registry renders an empty type picker and throws nothing, and a write that
 * never reaches the store leaves the editor looking correct.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the geometry clamp and not the lossless round trip. Both are pure
 * functions asserted across more cases than a browser run can reach, in
 * `tests/services/slotGeometry.spec.js` and
 * `tests/composables/manifestRoundTrip.spec.js`, which is why the matching
 * scenarios carry an `@e2e exclude` in the delta spec rather than an anchor.
 * Pointer accurate GridStack drags are likewise not reproducible here, so the
 * drag scenarios are excluded and covered by the panel's own spec.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import { expect, test } from '@playwright/test'
import {
	dismissOverlays,
	ensureApp,
	suppressSupportDialog,
} from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-widget-placement'
const MANIFEST_URL = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

type Placement = Record<string, unknown>

/**
 * Write a manifest straight to the store, so each scenario starts from a
 * known page rather than from whatever the previous one left behind.
 *
 * @param page The Playwright page, already on a buildiq route.
 * @param pages The `pages[]` to store.
 */
async function seedPages(page, pages: Record<string, unknown>[]): Promise<void> {
	await page.evaluate(
		async ({ manifestUrl, nextPages }) => {
			const tok =
				window.OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const headers = {
				requesttoken: tok,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			}
			const current = await (await fetch(manifestUrl, { headers })).json()
			const keep = (current.pages || []).filter(
				(p) => !String(p.id || '').startsWith('pw-placement'),
			)
			await fetch(manifestUrl, {
				method: 'PUT',
				headers,
				body: JSON.stringify({
					manifest: { ...current, pages: [...keep, ...nextPages] },
				}),
			})
		},
		{ manifestUrl: MANIFEST_URL, nextPages: pages },
	)
}

/**
 * Read the stored manifest back, which is the only place a save can be
 * observed: the editor buffer looks identical whether the write landed or not.
 *
 * @param page The Playwright page.
 * @return The stored manifest.
 */
async function storedManifest(page): Promise<Record<string, unknown>> {
	return await page.evaluate(async (url) => {
		const resp = await fetch(url, { headers: { 'OCS-APIRequest': 'true' } })
		return resp.json()
	}, MANIFEST_URL)
}

/**
 * The stored `widgets[]` of one seeded page.
 *
 * @param page The Playwright page.
 * @param pageId The manifest page id.
 * @return The stored placements.
 */
async function storedWidgets(page, pageId: string): Promise<Placement[]> {
	const manifest = await storedManifest(page)
	const target = ((manifest.pages as Record<string, unknown>[]) || []).find(
		(p) => p.id === pageId,
	)
	return ((target?.widgets as Placement[]) || []) as Placement[]
}

/**
 * Open the designer on a seeded page and wait for the placement panel.
 *
 * @param page The Playwright page.
 * @param pageIndex The page's position in the left-hand list.
 */
async function openDesignerOnPage(page, pageIndex: number): Promise<void> {
	await page.goto(
		`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`,
		{ waitUntil: 'domcontentloaded' },
	)
	await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
	await dismissOverlays(page)
	await page.locator('.page-list-editor__row').nth(pageIndex).click()
	await expect(page.locator('.widget-placement-panel')).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * A dashboard page carrying the given placements.
 *
 * @param id The manifest page id.
 * @param widgets The page's placements.
 * @return The page.
 */
function dashboardPage(id: string, widgets: Placement[] = []) {
	return {
		id,
		route: `/${id}`,
		type: 'dashboard',
		title: 'Placement fixture',
		config: {},
		widgets,
	}
}

/**
 * A body placement whose widgetKey the library itself renders.
 *
 * @param id The placement id.
 * @param overrides Keys to override on the entry.
 * @return The placement.
 */
function bodyPlacement(id: string, overrides: Placement = {}): Placement {
	return {
		id,
		widgetKey: 'text',
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 6,
		gridHeight: 3,
		...overrides,
	}
}

test.describe('v2 widget placement editor', () => {
	// A three-pane desktop surface: at the default 1280x720 the placement rows
	// land below the fold, where a click never settles.
	test.use({ viewport: { width: 1600, height: 1400 } })

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureApp(page, APP_SLUG, 'PW Widget Placement')
		await page.goto(`${BASE_URL}/apps/buildiq/`, {
			waitUntil: 'domcontentloaded',
		})
	})

	// @e2e openbuild-page-designer::adding-a-placement-to-an-empty-page
	test('adds a v2 placement to an empty page', async ({ page }) => {
		await seedPages(page, [dashboardPage('pw-placement-add')])
		await openDesignerOnPage(page, 0)

		await expect(
			page.locator('[data-testid="placement-empty-state"]'),
			'a page with no widgets must invite an addition, not report an error',
		).toBeVisible()

		await page.locator('[data-testid="placement-add"]').click()
		await page.locator('[data-testid="add-widget-save"]').click()
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () => (await storedWidgets(page, 'pw-placement-add')).length,
				{
					timeout: 30_000,
					message: 'the added placement must reach the stored manifest',
				},
			)
			.toBe(1)

		const [stored] = await storedWidgets(page, 'pw-placement-add')
		for (const key of [
			'widgetKey',
			'slot',
			'gridX',
			'gridY',
			'gridWidth',
			'gridHeight',
		]) {
			expect(stored, `a new placement must carry ${key}`).toHaveProperty(key)
		}
	})

	// @e2e openbuild-page-designer::editing-an-existing-placement
	// @e2e openbuild-page-designer::editing-a-placement-keeps-its-id
	test('edits an existing v2 placement', async ({ page }) => {
		await seedPages(page, [
			dashboardPage('pw-placement-edit', [
				bodyPlacement('first'),
				bodyPlacement('second', { gridY: 3 }),
			]),
		])
		await openDesignerOnPage(page, 0)

		await page.locator('[data-testid="placement-edit"]').nth(1).click()
		// The type select is hidden in edit mode: a placement's type is fixed,
		// which is itself the proof the modal opened on the existing entry.
		await expect(page.locator('[data-testid="widget-type-select"]')).toHaveCount(
			0,
		)
		await page.locator('[data-testid="add-widget-save"]').click()
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () =>
					(await storedWidgets(page, 'pw-placement-edit')).map(
						(w) => w.id,
					),
				{
					timeout: 30_000,
					message: 'the edit must reach the stored manifest',
				},
			)
			// The placement keeps its position in widgets[], and its id is never
			// regenerated: a stored delta override keys widgets[] by id.
			.toEqual(['first', 'second'])
	})

	// @e2e openbuild-page-designer::deleting-a-placement-leaves-its-neighbours-alone
	test('deletes one placement and leaves the rest', async ({ page }) => {
		await seedPages(page, [
			dashboardPage('pw-placement-delete', [
				bodyPlacement('keep-one'),
				bodyPlacement('drop-me', { gridX: 6 }),
				bodyPlacement('keep-two', { gridY: 3, gridHeight: 4 }),
			]),
		])
		await openDesignerOnPage(page, 0)

		await page.locator('[data-testid="placement-delete"]').nth(1).click()
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () =>
					(await storedWidgets(page, 'pw-placement-delete')).map(
						(w) => w.id,
					),
				{
					timeout: 30_000,
					message: 'only the deleted entry must disappear',
				},
			)
			.toEqual(['keep-one', 'keep-two'])

		const survivors = await storedWidgets(page, 'pw-placement-delete')
		expect(survivors[1]).toMatchObject({ gridY: 3, gridHeight: 4 })
	})

	// @e2e openbuild-page-designer::positioning-a-sidebar-placement-without-dragging
	test('positions a sidebar placement through numeric fields', async ({
		page,
	}) => {
		await seedPages(page, [
			dashboardPage('pw-placement-sidebar', [
				bodyPlacement('aside', {
					slot: 'sidebar',
					gridWidth: 1,
					gridHeight: 2,
				}),
			]),
		])
		await openDesignerOnPage(page, 0)

		// No drag interaction is required, or offered: a one-column slot has no
		// column or span control at all.
		await expect(page.locator('[data-testid="placement-grid-x"]')).toHaveCount(0)
		await expect(
			page.locator('[data-testid="placement-grid-width"]'),
		).toHaveCount(0)

		await page.locator('[data-testid="placement-grid-y"]').fill('2')
		await page.locator('[data-testid="placement-grid-height"]').fill('6')
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () => (await storedWidgets(page, 'pw-placement-sidebar'))[0],
				{ timeout: 30_000, message: 'the field edits must be stored' },
			)
			.toMatchObject({
				slot: 'sidebar',
				gridY: 2,
				gridHeight: 6,
				gridWidth: 1,
			})
	})

	// @e2e openbuild-page-designer::moving-a-placement-to-another-slot-re-applies-that-slots-rules
	test('moves a placement to another slot', async ({ page }) => {
		await seedPages(page, [
			dashboardPage('pw-placement-slot', [
				bodyPlacement('mover', { gridX: 4, gridY: 5, gridWidth: 6 }),
			]),
		])
		await openDesignerOnPage(page, 0)

		await page
			.locator('[data-testid="placement-slot"]')
			.selectOption('header-actions')

		// The row control disappears with the move, because header-actions has
		// no row to choose.
		await expect(page.locator('[data-testid="placement-grid-y"]')).toHaveCount(0)

		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => (await storedWidgets(page, 'pw-placement-slot'))[0], {
				timeout: 30_000,
				message:
					"the slot move must be stored with that slot's rules applied",
			})
			.toMatchObject({ slot: 'header-actions', gridY: 0 })
	})

	// @e2e openbuild-page-designer::header-actions-stay-on-the-first-row
	test('keeps a header-actions placement on row zero', async ({ page }) => {
		await seedPages(page, [
			dashboardPage('pw-placement-header', [
				bodyPlacement('action', {
					slot: 'header-actions',
					gridWidth: 2,
					gridHeight: 1,
				}),
			]),
		])
		await openDesignerOnPage(page, 0)

		await expect(
			page.locator('[data-testid="placement-grid-y"]'),
			'the editor must not offer a row control in header-actions',
		).toHaveCount(0)

		await page.locator('[data-testid="placement-grid-height"]').fill('2')
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () => (await storedWidgets(page, 'pw-placement-header'))[0],
				{
					timeout: 30_000,
					message: 'a header action must stay on row zero',
				},
			)
			.toMatchObject({ slot: 'header-actions', gridY: 0 })
	})

	// @e2e openbuild-page-designer::a-lone-full-width-custom-widget-on-a-dashboard-page-is-flagged-where-it-happens
	test('flags a lone full-width custom widget on a dashboard page', async ({
		page,
	}) => {
		await seedPages(page, [
			dashboardPage('pw-placement-disguise', [
				bodyPlacement('only', {
					widgetKey: 'case-timeline',
					gridWidth: 12,
					gridHeight: 12,
				}),
			]),
		])
		await openDesignerOnPage(page, 0)

		const warning = page.locator('[data-testid="disguise-warning"]')
		await expect(
			warning,
			'the editor must report this where the author is, not as a rejected save',
		).toBeVisible()
		// Both documented ways out, named.
		await expect(warning).toContainText('custom page in disguise')
		await expect(warning).toContainText('Declare this page as custom')
		await expect(page.locator('[data-testid="disguise-component"]')).toHaveText(
			'case-timeline',
		)
		await expect(warning).toContainText('add a second widget')
	})

	// @e2e openbuild-page-designer::adding-a-placement-to-a-custom-page-requires-a-note
	test('requires a note on a custom page placement', async ({ page }) => {
		await seedPages(page, [
			{
				id: 'pw-placement-custom',
				route: '/pw-placement-custom',
				type: 'custom',
				title: 'Bespoke',
				_note: 'a bespoke surface with no standard page type',
				config: {},
				widgets: [],
			},
		])
		await openDesignerOnPage(page, 0)

		const add = page.locator('[data-testid="placement-add"]')
		await expect(
			add,
			'a placement on a custom page cannot be confirmed without a note',
		).toBeDisabled()
		await expect(
			page.locator('[data-testid="pending-note-hint"]'),
		).toContainText('why a standard page type was not feasible')

		await page
			.locator('[data-testid="pending-note"]')
			.fill('the dossier viewer has no standard page type')
		await expect(add).toBeEnabled()
	})

	// @e2e openbuild-page-designer::a-non-custom-page-does-not-demand-a-note
	test('adds a placement to a dashboard page without a note', async ({ page }) => {
		await seedPages(page, [dashboardPage('pw-placement-nonote')])
		await openDesignerOnPage(page, 0)

		await expect(page.locator('[data-testid="pending-note"]')).toHaveCount(0)
		await expect(page.locator('[data-testid="placement-add"]')).toBeEnabled()

		await page.locator('[data-testid="placement-add"]').click()
		await page.locator('[data-testid="add-widget-save"]').click()
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () =>
					(await storedWidgets(page, 'pw-placement-nonote')).length,
				{ timeout: 30_000, message: 'the placement must be stored' },
			)
			.toBe(1)

		const [stored] = await storedWidgets(page, 'pw-placement-nonote')
		expect(
			stored,
			'no _note key is written on a dashboard page',
		).not.toHaveProperty('_note')
	})

	// @e2e openbuild-page-designer::two-placements-of-the-same-widget-type-do-not-collide
	test('mints unique ids for two placements of one type', async ({ page }) => {
		await seedPages(page, [dashboardPage('pw-placement-ids')])
		await openDesignerOnPage(page, 0)

		for (let i = 0; i < 2; i++) {
			await page.locator('[data-testid="placement-add"]').click()
			await page.locator('[data-testid="add-widget-save"]').click()
			await expect(page.locator('[data-testid="placement-row"]')).toHaveCount(
				i + 1,
			)
		}
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(
				async () => (await storedWidgets(page, 'pw-placement-ids')).length,
				{
					timeout: 30_000,
					message: 'both placements must be stored',
				},
			)
			.toBe(2)

		const stored = await storedWidgets(page, 'pw-placement-ids')
		expect(stored[0].id).toBeTruthy()
		expect(stored[1].id).toBeTruthy()
		expect(stored[1].id).not.toBe(stored[0].id)
		for (const entry of stored) {
			expect(String(entry.id)).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/)
		}
	})

	// @e2e openbuild-page-designer::opening-and-saving-a-page-changes-nothing-by-itself
	test('opens and saves a page without changing the manifest', async ({
		page,
	}) => {
		await seedPages(page, [
			dashboardPage('pw-placement-roundtrip', [
				bodyPlacement('kept', {
					tabGroup: 'general',
					roles: ['beheerders'],
					requiredApp: 'openregister',
					props: { label: 'Open cases' },
				}),
			]),
		])
		await page.goto(`${BASE_URL}/apps/buildiq/`, {
			waitUntil: 'domcontentloaded',
		})
		const before = JSON.stringify(await storedManifest(page))

		await openDesignerOnPage(page, 0)
		await page.getByRole('button', { name: /save pages/i }).click()
		// Give the save a moment to land before reading the store back.
		await expect(page.locator('.widget-placement-panel')).toBeVisible()

		await expect
			.poll(async () => JSON.stringify(await storedManifest(page)), {
				timeout: 30_000,
				message:
					'opening and saving with no edit must leave the manifest identical, '
					+ 'including keys the placement editor does not surface',
			})
			.toBe(before)
	})

	// @e2e openbuild-page-designer::a-detail-only-type-is-not-offered-on-a-dashboard-page
	test('hides detail-only widget types on a dashboard page', async ({ page }) => {
		await seedPages(page, [dashboardPage('pw-placement-surface')])
		await openDesignerOnPage(page, 0)

		await page.locator('[data-testid="placement-add"]').click()
		const select = page.locator('[data-testid="widget-type-select"]')
		await expect(select).toBeVisible()

		// `data` declares surfaces: ['detail-page'] in the library catalogue, so
		// the dashboard picker must not offer it.
		await expect(
			page.locator('[data-testid="widget-type-option-data"]'),
			'a detail-only type must not be offered on a dashboard page',
		).toHaveCount(0)
	})

	// @e2e openbuild-page-designer::an-administrator-sees-types-a-user-picking-for-themselves-would-not
	test('offers the full authoring catalogue', async ({ page }) => {
		await seedPages(page, [dashboardPage('pw-placement-catalogue')])
		await openDesignerOnPage(page, 0)

		await page.locator('[data-testid="placement-add"]').click()
		const options = page.locator('[data-testid="widget-type-select"] option')
		await expect(options.first()).toBeAttached()

		const offered = await options.evaluateAll((nodes) =>
			nodes.map((node) => (node as HTMLOptionElement).value),
		)
		// `object-table` needs a register and a schema, which is exactly the
		// configuration a user picking for their own dashboard cannot supply and
		// an author here can. Its presence is the proof the designer asked for
		// the administrator catalogue rather than the user-addable subset.
		expect(
			offered,
			'the designer must be offered the administrator catalogue',
		).toContain('object-table')
		expect(offered.length).toBeGreaterThan(1)
	})
})
