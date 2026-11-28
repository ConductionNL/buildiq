/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `pages-installable-offline-and-mobile`,
 * tasks T02, T03, T05, T06 and T08 (REQ-BQOM-001 to REQ-BQOM-006): an app
 * runs under its own path with a web app manifest, works offline only once an
 * administrator allows it, takes the declared records along onto the device,
 * and exports a mobile wrapper project.
 *
 *   1. A maker sets the short name and theme colour in App settings; the run
 *      page links a web app manifest with them, started and scoped at the run
 *      path, full screen (REQ-BQOM-001). The browser's own "Install" prompt
 *      and the home-screen icon are a manual check on a phone (T02).
 *   2. A logged-in user without a role on the app gets 404 for its web app
 *      manifest and its worker (REQ-BQOM-001).
 *   3. With "Let apps work offline" off, opening an app with an offline
 *      declaration registers no worker and stores no record (REQ-BQOM-002).
 *   4. With it on, opening the app registers a worker scoped to the run path
 *      and stores the submitted applications with their download time and a
 *      12-hour expiry; the draft one stays on the server (REQ-BQOM-003).
 *   5. An offline form submission waits and arrives once the connection
 *      returns (REQ-BQOM-004). FIXME, see below.
 *   6. "Waiting to sync" shows a stuck submission with "Try again"
 *      (REQ-BQOM-005). FIXME, see below.
 *   7. An export with "Mobile app project" holds `mobile/` with the project,
 *      its README and icons, and allows this instance only (REQ-BQOM-006).
 *
 * Fixture: the app `pw-offline-app`, cloned from the `permit-tracker`
 * template (schema `permit-application`, a submit form page
 * `ApplicationForm`), with an `offline` block in its manifest that takes the
 * submitted applications along for 12 hours and declares the form offline.
 * Three applications are saved in the app's register: two submitted, one
 * draft. The app, its data and the export job's archive go in afterAll, and
 * the instance's offline setting is put back to what it was.
 *
 * FIXME: scenarios 5 and 6 need the sibling half in nextcloud-vue (task T07):
 * `CnFormPage` does not yet read the manifest's `offline` block, so a form
 * submitted without a connection fails instead of entering the queue, and
 * nothing a test can do puts a real submission in the queue or makes the
 * server refuse it three times. They are kept as fixme until that ships.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/specs/installable-offline-apps/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { zipEntries, zipText } from './support/zipArchive.ts'

const APP_SLUG = 'pw-offline-app'
const APP_NAME = 'PW Permit inspections'
const SHORT_NAME = 'PW Permits'
const THEME_COLOR = '#1f6feb'
const TEMPLATE = 'permit-tracker'
const SCHEMA = 'permit-application'
const FORM_PAGE = 'ApplicationForm'
const BUNDLE_ID = 'nl.example.permits'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const OUTSIDER_STATE = 'tests/e2e/.auth/rbac-outsider.json'

const API = `${BASE_URL}/index.php/apps/buildiq/api`
const OR_API = `${BASE_URL}/index.php/apps/openregister/api`
const RUN_PATH = `/apps/buildiq/run/${APP_SLUG}/`
const RUN_URL = `${BASE_URL}/index.php${RUN_PATH}`
const HEADERS = { 'OCS-APIRequest': 'true' }

/** The offline cache of nextcloud-vue's offline core, and this app's collection in it. */
const OFFLINE_DB = 'conduction-offline-collection'
const COLLECTION = `buildiq:${APP_SLUG}`
const TTL_HOURS = 12

/** Shared between the serial tests. */
const state = {
	appUuid: '',
	register: '',
	/** The clone's own slug of the template's schema (`<app>-permit-application`). */
	schema: '',
	offlineBefore: false,
	submitted: [] as string[],
	draft: '',
	exportJob: '',
}

/**
 * The uuid of an OpenRegister object or buildiq response.
 *
 * @param body The decoded response.
 * @return The uuid, or ''.
 */
function uuidOf(body: Record<string, any> | null | undefined): string {
	return String(body?.['@self']?.id ?? body?.uuid ?? body?.id ?? '')
}

/**
 * Switch "Let apps work offline" for the instance.
 *
 * @param page A logged-in admin page.
 * @param on   Whether apps may work offline.
 */
async function setOfflineAllowed(page: Page, on: boolean): Promise<void> {
	const resp = await page.request.post(`${API}/settings`, { headers: HEADERS, data: { allow_offline_apps: on } })
	expect(resp.ok(), `switch the offline setting: ${resp.status()}`).toBeTruthy()
}

/**
 * The service worker scopes registered for this origin.
 *
 * @param page A page on the instance.
 * @return The scopes.
 */
async function workerScopes(page: Page): Promise<string[]> {
	return page.evaluate(async () => {
		if (!('serviceWorker' in navigator)) {
			return []
		}
		return (await navigator.serviceWorker.getRegistrations()).map((registration) => registration.scope)
	})
}

/**
 * What the offline cache holds for one collection: its records and its
 * download record (`syncedAt`, `expiresAt`). Reads IndexedDB without creating
 * the database when it does not exist.
 *
 * @param page       A page on the instance.
 * @param dbName     The offline cache database.
 * @param collection The collection.
 * @return The record ids and the download records.
 */
async function cached(page: Page, dbName: string, collection: string): Promise<{ ids: string[], meta: Array<{ syncedAt: string, expiresAt: string }> }> {
	return page.evaluate(async ({ dbName, collection }) => {
		const known = typeof indexedDB.databases === 'function' ? await indexedDB.databases() : []
		if (!known.some((entry) => entry.name === dbName)) {
			return { ids: [], meta: [] }
		}
		const db: IDBDatabase = await new Promise((resolve, reject) => {
			const request = indexedDB.open(dbName)
			request.onsuccess = () => resolve(request.result)
			request.onerror = () => reject(request.error)
		})
		const all = (store: string): Promise<Array<Record<string, any>>> => new Promise((resolve, reject) => {
			if (!db.objectStoreNames.contains(store)) {
				resolve([])
				return
			}
			const request = db.transaction(store, 'readonly').objectStore(store).getAll()
			request.onsuccess = () => resolve(request.result)
			request.onerror = () => reject(request.error)
		})
		const rows = (await all('objectCache')).filter((row) => row.collection === collection)
		const meta = (await all('meta')).filter((row) => row.collection === collection)
		db.close()
		return {
			ids: rows.map((row) => String(row.objectId)),
			meta: meta.map((row) => ({ syncedAt: String(row.syncedAt), expiresAt: String(row.expiresAt) })),
		}
	}, { dbName, collection })
}

/**
 * Open the app at its run path and wait until it has rendered its first page.
 *
 * @param page A logged-in page.
 */
async function openRunPage(page: Page): Promise<void> {
	const manifest = page.waitForResponse((resp) => resp.url().includes(`/api/applications/${APP_SLUG}/manifest`), { timeout: 45_000 })
	await page.goto(RUN_URL, { waitUntil: 'domcontentloaded' })
	expect((await manifest).status(), 'the runtime gets the app manifest').toBe(200)
	await dismissOverlays(page)
	await expect(page.getByText('App not found'), 'the app opens').toHaveCount(0)
}

test.describe('pages-installable-offline-and-mobile: an installable app that works offline', () => {
	test.describe.configure({ mode: 'serial', timeout: 150_000 })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			const settings = await page.request.get(`${API}/settings`, { headers: HEADERS })
			expect(settings.ok()).toBeTruthy()
			state.offlineBefore = (await settings.json())?.allow_offline_apps === true

			const created = await page.request.post(`${API}/applications/from-template/${TEMPLATE}`, {
				headers: HEADERS,
				data: { name: APP_NAME, slug: APP_SLUG },
			})
			expect(created.status(), (await created.text()).slice(0, 300)).toBeLessThan(300)
			const body = await created.json()
			state.appUuid = String(body?.uuid ?? body?.id ?? body?.application?.uuid ?? '')
			expect(state.appUuid, 'the cloned app has a uuid').not.toBe('')
			await grantAppRoles(page, APP_SLUG, { owners: [`user:${ADMIN_USER}`] })

			const versions = await page.request.get(`${API}/applications/${APP_SLUG}/versions`, { headers: HEADERS })
			const rows = await versions.json()
			const production = (Array.isArray(rows) ? rows : (rows?.results ?? [])).find((v: Record<string, any>) => v?.slug === 'production')
			state.register = typeof production?.register === 'string' ? production.register : ''
			expect(state.register, 'the production version has a register').not.toBe('')

			// A clone prefixes the template's schema slug with the app's slug.
			const manifest = await page.request.get(`${API}/applications/${APP_SLUG}/manifest`, { headers: HEADERS })
			const current = await manifest.json()
			const bound = (current.pages ?? []).find((p: Record<string, any>) => [SCHEMA, `${APP_SLUG}-${SCHEMA}`].includes(String(p?.config?.schema ?? '')))
			state.schema = String(bound?.config?.schema ?? SCHEMA)

			const objects = `${OR_API}/objects/${state.register}/${state.schema}`
			for (const [applicant, status] of [['Field visit north', 'submitted'], ['Field visit south', 'submitted'], ['Still a draft', 'draft']]) {
				const saved = await page.request.post(objects, { headers: HEADERS, data: { applicant, status } })
				expect(saved.status(), `save an application: ${(await saved.text()).slice(0, 200)}`).toBeLessThan(300)
				const uuid = uuidOf(await saved.json())
				if (status === 'submitted') {
					state.submitted.push(uuid)
				} else {
					state.draft = uuid
				}
			}

			// The maker's offline declaration: today's submitted applications, and the form.
			const put = await page.request.put(`${API}/applications/${APP_SLUG}/manifest`, {
				headers: HEADERS,
				data: {
					manifest: {
						...current,
						offline: {
							takeAlong: [{ schema: state.schema, filter: { status: 'submitted' }, limit: 50, ttlHours: TTL_HOURS }],
							forms: [FORM_PAGE],
						},
					},
				},
			})
			expect(put.ok(), `store the offline declaration: ${put.status()} ${(await put.text()).slice(0, 300)}`).toBeTruthy()
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			await setOfflineAllowed(page, state.offlineBefore).catch(() => undefined)
			if (state.appUuid !== '') {
				await page.request.delete(`${API}/applications/${state.appUuid}`, {
					headers: HEADERS,
					data: { deleteData: true },
				}).catch(() => undefined)
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e installable-offline-apps::an-inspector-puts-the-inspection-app-on-the-home-screen
	test('the run page links a web app manifest with the name, short name and colour the maker set, full screen at the run path', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/buildiq/applications/${state.appUuid}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await page.waitForSelector('.ob-detail-header', { timeout: 30_000 })
		await page.locator('[data-testid="cn-action-app-settings-action"]').first().click()
		const installable = page.locator('[data-test="app-settings-installable"]')
		await expect(installable).toBeVisible({ timeout: 30_000 })
		await installable.getByLabel('Short name').fill(SHORT_NAME)
		await installable.getByLabel('Short name').press('Tab')
		// Each change saves on its own and the fields are disabled while a save
		// runs; set the colour once the short name is stored, as a person would.
		await expect.poll(async () => {
			const resp = await page.request.get(`${RUN_URL}manifest.webmanifest`)
			return resp.ok() ? String((await resp.json())?.short_name ?? '') : ''
		}, { timeout: 30_000, message: 'the short name is saved' }).toBe(SHORT_NAME)
		const colour = installable.locator('[data-test="app-settings-theme-color"]')
		await expect(colour).toBeEnabled({ timeout: 30_000 })
		await colour.fill(THEME_COLOR)

		let webManifest: Record<string, any> = {}
		await expect.poll(async () => {
			const resp = await page.request.get(`${RUN_URL}manifest.webmanifest`)
			webManifest = resp.ok() ? await resp.json() : {}
			return `${webManifest.short_name ?? ''} ${webManifest.theme_color ?? ''}`
		}, { timeout: 30_000, message: 'the saved settings reach the web app manifest' }).toBe(`${SHORT_NAME} ${THEME_COLOR}`)
		expect(webManifest).toMatchObject({ name: APP_NAME, start_url: './', scope: './', display: 'standalone' })
		expect(Array.isArray(webManifest.icons) && webManifest.icons.length > 0, 'the manifest carries the app icon').toBe(true)

		await openRunPage(page)
		// Nextcloud's theming app links its own manifest on every page too; this
		// is the app's.
		const link = page.locator('link[rel="manifest"][href="manifest.webmanifest"]')
		await expect(link, 'the run page links its manifest').toHaveAttribute('href', 'manifest.webmanifest')
		await expect(link, 'with the session, since the manifest is logged-in only').toHaveAttribute('crossorigin', 'use-credentials')
	})

	// @e2e installable-offline-apps::the-manifest-is-not-served-to-strangers
	test('a logged-in user who may not open the app gets 404 for its manifest and its worker', async ({ browser }) => {
		const context = await browser.newContext({ baseURL: BASE_URL, storageState: OUTSIDER_STATE })
		try {
			const outsider = await context.newPage()
			expect((await outsider.request.get(`${RUN_URL}manifest.webmanifest`)).status()).toBe(404)
			expect((await outsider.request.get(`${RUN_URL}offline-worker.js`)).status()).toBe(404)
		} finally {
			await context.close()
		}
	})

	// @e2e installable-offline-apps::a-default-instance-keeps-nothing-on-devices
	test('with offline not allowed, opening the app registers no worker and stores no record', async ({ page }) => {
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		await setOfflineAllowed(page, false)
		expect((await page.request.get(`${RUN_URL}offline-worker.js`)).status(), 'no worker is served').toBe(404)

		await openRunPage(page)
		await expect(page.getByText('Permit applications').first()).toBeVisible({ timeout: 45_000 })
		// The runtime decides on offline right after the manifest arrives;
		// give a registration that should not happen time to show.
		await page.waitForTimeout(3_000)
		expect(await workerScopes(page), 'no service worker is registered').toEqual([])
		expect((await cached(page, OFFLINE_DB, COLLECTION)).ids, 'no record is stored in the browser').toEqual([])
	})

	// @e2e installable-offline-apps::todays-inspections-go-along
	test('with offline allowed, the app registers its worker on the run path and takes the submitted applications along', async ({ page }) => {
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		await setOfflineAllowed(page, true)
		const worker = await page.request.get(`${RUN_URL}offline-worker.js`)
		expect(worker.status(), 'the worker is served').toBe(200)
		expect(worker.headers()['service-worker-allowed'], 'its scope is the run path').toBe('./')

		await openRunPage(page)
		await expect.poll(() => workerScopes(page), {
			timeout: 30_000,
			message: 'a worker is registered for this app only',
		}).toEqual([expect.stringMatching(new RegExp(`${RUN_PATH.replace(/[/.]/g, '\\$&')}$`))])

		let stored = { ids: [] as string[], meta: [] as Array<{ syncedAt: string, expiresAt: string }> }
		await expect.poll(async () => {
			stored = await cached(page, OFFLINE_DB, COLLECTION)
			return stored.ids.slice().sort()
		}, { timeout: 30_000, message: 'the submitted applications are on the device' }).toEqual(state.submitted.slice().sort())
		expect(stored.ids, 'the draft stays on the server').not.toContain(state.draft)

		expect(stored.meta, 'one download record for the take-along').toHaveLength(1)
		const downloaded = Date.parse(stored.meta[0].syncedAt)
		const expires = Date.parse(stored.meta[0].expiresAt)
		expect(Number.isNaN(downloaded), 'the download time is kept').toBe(false)
		expect(Math.abs(expires - downloaded - TTL_HOURS * 3_600_000), 'the copy expires 12 hours after download').toBeLessThan(60_000)
	})

	// @e2e installable-offline-apps::an-inspection-report-made-in-a-basement-arrives
	test('a form submitted without a connection waits and arrives once the connection returns', async ({ page, context }) => {
		test.fixme(true, 'nextcloud-vue does not queue a form submission yet: CnFormPage does not read the manifest\'s offline block (sibling half, task T07).')
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		await setOfflineAllowed(page, true)
		await openRunPage(page)
		await page.goto(`${RUN_URL}submit`, { waitUntil: 'domcontentloaded' })
		await context.setOffline(true)
		await page.getByLabel(/applicant/i).fill('Report from the basement')
		await page.getByRole('button', { name: /submit/i }).click()
		await expect(page.getByText(/waiting to sync/i).first()).toBeVisible({ timeout: 20_000 })
		await context.setOffline(false)
		await expect.poll(async () => {
			const resp = await page.request.get(`${OR_API}/objects/${state.register}/${state.schema}`, {
				headers: HEADERS,
				params: { applicant: 'Report from the basement' },
			})
			return resp.ok() ? ((await resp.json())?.results ?? []).length : 0
		}, { timeout: 60_000, message: 'the report is saved on the server' }).toBe(1)
	})

	// @e2e installable-offline-apps::a-stuck-submission-is-visible
	test('"Waiting to sync" counts a stuck submission apart and offers "Try again"', async ({ page }) => {
		test.fixme(true, 'No submission can enter the queue until CnFormPage queues offline forms (sibling half in nextcloud-vue, task T07), and the server cannot be made to refuse one three times on demand.')
		await openRunPage(page)
		await page.goto(`${RUN_URL}waiting-to-sync`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('0 waiting, 1 stuck').first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('button', { name: 'Try again' }).first()).toBeVisible()
	})

	// @e2e installable-offline-apps::an-organisation-takes-the-project-to-the-stores
	test('an export with "Mobile app project" holds mobile/ with its README and icons, allowing this instance only', async ({ page }) => {
		const versions = await page.request.get(`${API}/applications/${APP_SLUG}/versions`, { headers: HEADERS })
		const rows = await versions.json()
		const production = (Array.isArray(rows) ? rows : (rows?.results ?? [])).find((v: Record<string, any>) => v?.slug === 'production')

		const submitted = await page.request.post(`${API}/applications/${APP_SLUG}/exports`, {
			headers: HEADERS,
			data: {
				applicationVersion: String(production?.semver ?? ''),
				applicationVersionSlug: 'production',
				target: 'zip',
				license: 'EUPL-1.2',
				includeSeedData: false,
				includeMobileProject: true,
				mobileBundleId: BUNDLE_ID,
			},
		})
		expect(submitted.status(), `queue the export: ${(await submitted.text()).slice(0, 300)}`).toBeLessThan(300)
		state.exportJob = String((await submitted.json())?.uuid ?? '')
		expect(state.exportJob).not.toBe('')
		// Run it now rather than waiting for cron, as the export dialog does.
		await page.request.post(`${API}/exports/${state.exportJob}/run`, { headers: HEADERS, timeout: 120_000 })

		let archive = Buffer.alloc(0)
		await expect.poll(async () => {
			const resp = await page.request.get(`${API}/exports/${state.exportJob}/download`, { headers: HEADERS })
			if (resp.status() !== 200) {
				return resp.status()
			}
			archive = await resp.body()
			return 200
		}, { timeout: 90_000, message: 'the archive can be downloaded' }).toBe(200)

		const entries = zipEntries(archive)
		const named = (suffix: string) => entries.find((entry) => entry.name.endsWith(suffix))
		const config = named('mobile/capacitor.config.json')
		expect(config, 'the archive holds the Capacitor project').toBeTruthy()
		expect(named('mobile/README.md'), 'with a README on building and publishing').toBeTruthy()
		expect(
			entries.some((entry) => /mobile\/resources\/icon\.(png|svg)$/.test(entry.name)),
			'and the app icon',
		).toBe(true)

		const capacitor = JSON.parse(zipText(archive, config!))
		expect(capacitor.appId, 'the bundle id the maker gave').toBe(BUNDLE_ID)
		expect(capacitor.appName).toBe(APP_NAME)
		expect(String(capacitor.server?.url ?? ''), 'the project opens the run page').toContain(RUN_PATH)
		const allowed = capacitor.server?.allowNavigation ?? []
		expect(allowed, 'one allowed origin').toHaveLength(1)
		// Capacitor matches allowNavigation against the host name, without a port.
		expect(allowed[0], 'and it is this instance').toBe(new URL(String(capacitor.server.url)).hostname)
	})
})
