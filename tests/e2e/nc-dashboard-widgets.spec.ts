/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `publish-widgets-to-nc-dashboard`, task 9.1: an author promotes a widget in
 * the page designer, the Nextcloud dashboard offers it in its widget picker, a
 * user adds it and it renders in the native panel chrome, and a user outside
 * the placement's roles is never offered it.
 *
 * FIXTURES (built in `beforeAll` as admin, deleted in `afterAll`):
 *
 *   - `pw-ncdash-a`: a dashboard page `pw-ncdash` with one `text` placement
 *     that has NO id and no `ncDashboard` object, and `roles` naming only the
 *     `rbac-editors` group. The test promotes it through the designer.
 *     `rbac-viewer` is a viewer of the app, so they may see the Application
 *     but match none of the placement's roles.
 *   - `pw-ncdash-b`: a second app whose placement is promoted in the stored
 *     manifest, so the picker must list two widgets across two apps.
 *
 * Both apps are published (`POST /api/applications/{uuid}/publish`): only a
 * published Application's production manifest reaches the dashboard.
 *
 * A promoted widget's Nextcloud id is `buildiq-<application uuid>-<placement
 * id>` (`WidgetDescriptor::deriveId()`); the uuid and the minted placement id
 * are both plain, so the id is read back without the sha1 suffix.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-a-user-only-sees-widgets-they-are-allowed-to-see
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-the-widget-renders-in-the-nextcloud-panel-chrome
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/openbuild-page-designer/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const VIEWER_STORAGE_STATE = 'tests/e2e/.auth/rbac-viewer.json'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const VIEWER_USER = process.env.NC_RBAC_VIEWER_USER ?? 'rbac-viewer'

const APP_A = { slug: 'pw-ncdash-a', name: 'PW Dashboard Widget A' }
const APP_B = { slug: 'pw-ncdash-b', name: 'PW Dashboard Widget B' }
const PAGE_ID = 'pw-ncdash'
const PANEL_A = 'PW panel from app A'
const PANEL_B = 'PW panel from app B'
const BODY_A = 'Text the promoted panel shows'

type Placement = Record<string, any>

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
 * The manifest endpoint of an app.
 *
 * @param slug The app slug.
 * @return The path.
 */
function manifestPath(slug: string): string {
	return `/index.php/apps/buildiq/api/applications/${slug}/manifest`
}

/**
 * Replace the fixture page of an app with one carrying the given placement.
 *
 * @param page      An admin page on the buildiq app.
 * @param slug      The app slug.
 * @param placement The page's single placement.
 */
async function seedPage(page: Page, slug: string, placement: Placement): Promise<void> {
	const read = await call(page, 'GET', manifestPath(slug))
	expect(read.status, `GET manifest of ${slug}`).toBe(200)
	const manifest = read.data
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== PAGE_ID)
	pages.unshift({ id: PAGE_ID, route: `/${PAGE_ID}`, type: 'dashboard', title: 'Dashboard widget fixture', config: {}, widgets: [placement] })
	const write = await call(page, 'PUT', manifestPath(slug), { manifest: { ...manifest, pages } })
	expect(write.status, `seed ${slug}: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)
}

/**
 * The stored placements of the fixture page.
 *
 * @param page A logged-in page.
 * @param slug The app slug.
 * @return The placements.
 */
async function storedPlacements(page: Page, slug: string): Promise<Placement[]> {
	const read = await call(page, 'GET', manifestPath(slug))
	const target = (read.data?.pages || []).find((p: { id: string }) => p.id === PAGE_ID)
	return (target?.widgets || []) as Placement[]
}

/**
 * The uuid of an app, from the caller's app list.
 *
 * @param page A logged-in page.
 * @param slug The app slug.
 * @return The uuid, or '' when the app is not listed.
 */
async function appUuid(page: Page, slug: string): Promise<string> {
	const listed = await call(page, 'GET', '/index.php/apps/buildiq/api/applications')
	const rows = Array.isArray(listed.data) ? listed.data : (listed.data?.results ?? [])
	const app = rows.find((row: Record<string, any>) => (row?.slug ?? row?.['@self']?.slug) === slug)
	return String(app?.uuid ?? app?.['@self']?.id ?? app?.id ?? '')
}

/**
 * A `text` placement on the page body.
 *
 * @param overrides Keys to set on the entry.
 * @return The placement.
 */
function textPlacement(overrides: Placement = {}): Placement {
	return {
		widgetKey: 'text',
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 6,
		gridHeight: 3,
		props: { content: { text: BODY_A } },
		...overrides,
	}
}

/**
 * Open the designer of app A on the fixture page.
 *
 * @param page An admin page.
 */
async function openDesigner(page: Page): Promise<void> {
	await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_A.slug}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
	await dismissOverlays(page)
	await page.locator('.page-list-editor__row').first().click()
	await expect(page.locator('.widget-placement-panel')).toBeVisible({ timeout: 30_000 })
}

/**
 * Save the designer and wait until the stored placement satisfies a check.
 *
 * @param page  An admin page in the designer.
 * @param check What the stored placement must satisfy.
 * @param label The failure message.
 */
async function saveAndWait(page: Page, check: (p: Placement | undefined) => boolean, label: string): Promise<void> {
	await page.getByRole('button', { name: /save pages/i }).click()
	await expect.poll(async () => check((await storedPlacements(page, APP_A.slug))[0]), { timeout: 30_000, message: label }).toBe(true)
}

/**
 * Open the Nextcloud dashboard and its widget picker.
 *
 * @param page A logged-in page.
 */
async function openWidgetPicker(page: Page): Promise<void> {
	await page.goto(`${BASE_URL}/index.php/apps/dashboard/`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await page.getByRole('button', { name: /customi[sz]e/i }).click()
	await expect(page.getByRole('dialog')).toBeVisible({ timeout: 30_000 })
}

test.describe('publish-widgets-to-nc-dashboard: a promoted widget reaches the Nextcloud dashboard', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 })
	// The designer is a three-pane surface; at 1280x720 the rows sit below the fold.
	test.use({ viewport: { width: 1600, height: 1400 } })

	let uuidA = ''
	let uuidB = ''
	let entryId = ''

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_A.slug, APP_A.name)
			await ensureApp(page, APP_B.slug, APP_B.name)
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			await seedPage(page, APP_A.slug, textPlacement({ roles: ['group:rbac-editors'] }))
			await seedPage(page, APP_B.slug, textPlacement({ id: 'panel-b', ncDashboard: { title: PANEL_B } }))
			await grantAppRoles(page, APP_A.slug, { owners: [`user:${ADMIN_USER}`], viewers: [`user:${VIEWER_USER}`] })
			uuidA = await appUuid(page, APP_A.slug)
			uuidB = await appUuid(page, APP_B.slug)
			expect(uuidA, 'app A has a uuid').not.toBe('')
			expect(uuidB, 'app B has a uuid').not.toBe('')
			for (const uuid of [uuidA, uuidB]) {
				const published = await call(page, 'POST', `/index.php/apps/buildiq/api/applications/${uuid}/publish`)
				expect(published.status, `publish ${uuid}: ${JSON.stringify(published.data).slice(0, 200)}`).toBeLessThan(300)
			}
		} finally {
			await admin.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			for (const uuid of [uuidA, uuidB]) {
				if (uuid !== '') {
					await call(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}`)
				}
			}
		} finally {
			await admin.close()
		}
	})

	// @e2e openbuild-page-designer::promoting-a-widget-assigns-it-a-stable-id
	test('promoting a widget in the designer gives it an id and an ncDashboard object', async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await openDesigner(page)
			const before = (await storedPlacements(page, APP_A.slug))[0]
			expect(before?.id, 'the fixture placement starts without an id').toBeUndefined()

			await page.getByTestId('placement-promote').first().check()
			await expect(page.getByTestId('placement-panel-title').first()).toBeVisible()
			await expect(page.getByTestId('placement-panel-icon').first()).toBeVisible()
			await page.getByTestId('placement-panel-title').first().fill(PANEL_A)

			await saveAndWait(page, (p) => typeof p?.id === 'string' && p.id !== '' && p?.ncDashboard?.title === PANEL_A, 'the promoted placement is stored with an id and its panel title')
			entryId = (await storedPlacements(page, APP_A.slug))[0].id
		} finally {
			await admin.close()
		}
	})

	// @e2e openbuild-page-designer::demoting-a-widget-keeps-its-identity
	test('demoting keeps the id, and promoting again gives the same dashboard widget id', async ({ browser }) => {
		expect(entryId, 'the promote test stored an id').not.toBe('')
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await openDesigner(page)

			await page.getByTestId('placement-promote').first().uncheck()
			await saveAndWait(page, (p) => p?.ncDashboard === undefined && p?.id === entryId, 'demoting removes ncDashboard and keeps the id')

			await page.getByTestId('placement-promote').first().check()
			await page.getByTestId('placement-panel-title').first().fill(PANEL_A)
			await saveAndWait(page, (p) => p?.id === entryId && p?.ncDashboard?.title === PANEL_A, 'promoting again keeps the same id')
		} finally {
			await admin.close()
		}
	})

	// @e2e nc-dashboard-widgets::every-promoted-widget-becomes-a-selectable-dashboard-widget
	test('the dashboard widget picker lists the promoted widgets of both apps, once each', async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await openWidgetPicker(page)
			const dialog = page.getByRole('dialog')
			await expect(dialog.getByRole('checkbox', { name: PANEL_A, exact: true })).toHaveCount(1)
			await expect(dialog.getByRole('checkbox', { name: PANEL_B, exact: true })).toHaveCount(1)
		} finally {
			await admin.close()
		}
	})

	// @e2e nc-dashboard-widgets::a-promoted-widget-renders-with-the-native-panel-chrome
	test('an added promoted widget renders as a native dashboard panel with its title and content', async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await openWidgetPicker(page)
			const box = page.getByRole('dialog').getByRole('checkbox', { name: PANEL_A, exact: true })
			if (!(await box.isChecked())) {
				await box.check()
			}
			await page.keyboard.press('Escape')

			const panel = page.locator('.panel').filter({ has: page.getByRole('heading', { name: PANEL_A }) })
			await expect(panel, 'the panel sits among the native dashboard panels').toHaveCount(1, { timeout: 30_000 })
			await expect(panel.getByText(BODY_A)).toBeVisible({ timeout: 30_000 })
			await expect(panel.locator('.cn-dashboard-page__unknown'), 'the widget key resolved').toHaveCount(0)

			// The same chrome as the panels beside it: the panel element is the
			// dashboard's own, a sibling of the other `.panel`s in one container.
			const container = page.locator('.panels').filter({ has: panel })
			await expect(container).toHaveCount(1)
		} finally {
			await admin.close()
		}
	})

	// @e2e nc-dashboard-widgets::a-user-outside-every-listed-role-is-not-offered-the-widget
	test('a viewer outside the placement roles is neither offered nor shown the widget', async ({ browser }) => {
		expect(uuidA, 'app A has a uuid').not.toBe('')
		const viewer = await browser.newContext({ storageState: VIEWER_STORAGE_STATE })
		const page = await viewer.newPage()
		try {
			await openWidgetPicker(page)
			const dialog = page.getByRole('dialog')
			await expect(dialog.getByRole('checkbox', { name: PANEL_A, exact: true }), 'the role-narrowed widget is not offered').toHaveCount(0)
			await page.keyboard.press('Escape')

			// Even with the id already stored on this user's dashboard, nothing renders.
			const widgetId = `buildiq-${uuidA}-${entryId}`
			const saved = await call(page, 'POST', '/index.php/apps/dashboard/layout', { layout: widgetId })
			expect(saved.status, `store the layout: ${JSON.stringify(saved.data).slice(0, 200)}`).toBeLessThan(300)
			await page.reload({ waitUntil: 'domcontentloaded' })
			await expect(page.getByRole('heading', { name: PANEL_A })).toHaveCount(0)
			await expect(page.getByText(BODY_A)).toHaveCount(0)
		} finally {
			await viewer.close()
		}
	})
})
