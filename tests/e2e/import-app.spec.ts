/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `lifecycle-import-app-and-cli`, task T04
 * (REQ-BQIC-001): export an app, import the archive under a new slug through
 * "Import app" on the Applications page, and open it; and an import under a
 * slug that is taken is refused in the dialog without creating anything.
 *
 * `beforeAll`, as admin: builds the fixture app `pw-import-source` with one
 * extra index page and menu item, exports its production version as a ZIP
 * through the export pipeline (`POST /api/applications/{slug}/exports`, then
 * `POST /api/exports/{uuid}/run` so no cron is needed, then
 * `GET /api/exports/{uuid}/download`), and keeps the archive in memory. The
 * tests hand that archive to the dialog's file input, so the archive under
 * test is exactly what buildiq exports.
 *
 * `afterAll` deletes the source app and the imported copy.
 *
 * Not covered here: the unsafe-path and broken-schema refusals (REQ-BQIC-002,
 * REQ-BQIC-009) need a hand-built archive and no ZIP writer is a dependency of
 * this repo; PHPUnit `AppPackageImportServiceTest` builds those archives.
 * Records (REQ-BQIC-003) need a register with data to export; PHPUnit covers
 * records on, off and one schema failing. The `occ buildiq:app:*` commands
 * (app-management-cli) are PHPUnit-covered and cannot be driven from a
 * browser.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-import-app-and-cli/specs/app-package-import/spec.md
 * @e2e app-package-import/requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001/a-maker-moves-the-permit-app-to-the-test-instance
 * @e2e app-package-import/requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001/a-slug-that-is-taken-is-refused
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const SOURCE_SLUG = 'pw-import-source'
const SOURCE_NAME = 'PW Import Source'
const IMPORTED_SLUG = `pw-import-copy-${Date.now().toString(36)}`.slice(0, 32)
const EXTRA_PAGE = { id: 'vergunningen', title: 'Permits' }
const API = '/index.php/apps/buildiq/api'
const POLL_TIMEOUT_MS = 60_000

/** The exported archive, read once in beforeAll. */
const archive: { buffer: Buffer; name: string } = { buffer: Buffer.alloc(0), name: `${SOURCE_SLUG}.zip` }

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
 * The applications the caller sees whose slug is `slug`.
 *
 * @param page A logged-in page.
 * @param slug The app slug.
 * @return The matching rows.
 */
async function appsWithSlug(page: Page, slug: string): Promise<Array<Record<string, any>>> {
	const listed = await call(page, 'GET', `${API}/applications`)
	const rows = Array.isArray(listed.data) ? listed.data : (listed.data?.results ?? [])
	return rows.filter((row: Record<string, any>) => (row?.slug ?? row?.['@self']?.slug) === slug)
}

/**
 * Delete an application by slug, if it exists.
 *
 * @param page A logged-in admin page.
 * @param slug The app slug.
 */
async function removeApp(page: Page, slug: string): Promise<void> {
	for (const app of await appsWithSlug(page, slug)) {
		const uuid = String(app?.uuid ?? app?.['@self']?.id ?? app?.id ?? '')
		if (uuid !== '') {
			await call(page, 'DELETE', `${API}/applications/${uuid}`)
		}
	}
}

/**
 * The page ids and menu ids of an app's manifest.
 *
 * @param page A logged-in page.
 * @param slug The app slug.
 * @return The ids, in manifest order.
 */
async function shapeOf(page: Page, slug: string): Promise<{ pages: string[]; menu: string[] }> {
	const read = await call(page, 'GET', `${API}/applications/${slug}/manifest`)
	expect(read.status, `GET manifest of ${slug}`).toBe(200)
	return {
		pages: (read.data.pages ?? []).map((p: { id: string }) => p.id),
		menu: (read.data.menu ?? []).map((m: { id: string }) => m.id),
	}
}

/**
 * Open the Applications page and the "Import app" dialog, and pick the archive.
 *
 * @param page The page.
 * @return The dialog.
 */
async function pickArchive(page: Page) {
	await suppressSupportDialog(page)
	await page.goto('/apps/buildiq/applications', { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await page.getByRole('button', { name: 'Import app' }).click()
	const dialog = page.getByRole('dialog', { name: 'Import app' })
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	await dialog.getByTestId('import-app-file').setInputFiles({ name: archive.name, mimeType: 'application/zip', buffer: archive.buffer })
	await expect(dialog.getByTestId('import-app-summary'), 'the dialog reads the archive\'s summary').toBeVisible({ timeout: 30_000 })
	return dialog
}

test.describe('lifecycle-import-app-and-cli: import an exported app', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 })

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(180_000)
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, SOURCE_SLUG, SOURCE_NAME)

			const manifestPath = `${API}/applications/${SOURCE_SLUG}/manifest`
			const read = await call(page, 'GET', manifestPath)
			expect(read.status, 'GET source manifest').toBe(200)
			const manifest = read.data
			const pages = [...(manifest.pages ?? []).filter((p: { id: string }) => p.id !== EXTRA_PAGE.id), {
				id: EXTRA_PAGE.id,
				type: 'index',
				route: `/${EXTRA_PAGE.id}`,
				title: EXTRA_PAGE.title,
				config: { columns: [] },
			}]
			const menu = [...(manifest.menu ?? []).filter((m: { id: string }) => m.id !== EXTRA_PAGE.id), { id: EXTRA_PAGE.id, label: EXTRA_PAGE.title, route: EXTRA_PAGE.id }]
			const write = await call(page, 'PUT', manifestPath, { manifest: { ...manifest, pages, menu } })
			expect(write.status, `seed the source app: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)

			// Export the production version as a ZIP, the payload ExportDialog sends.
			const queued = await call(page, 'POST', `${API}/applications/${SOURCE_SLUG}/exports`, {
				target: 'zip',
				applicationVersion: '0.1.0',
				applicationVersionSlug: 'production',
				license: 'EUPL-1.2',
				includeSeedData: false,
				dataRegisters: [],
				flows: [],
			})
			expect(queued.status, `queue the export: ${JSON.stringify(queued.data).slice(0, 200)}`).toBe(202)
			const jobUuid = String(queued.data.uuid)
			// 409 means a worker already started it; either way it runs.
			const started = await call(page, 'POST', `${API}/exports/${jobUuid}/run`)
			expect([200, 409], `start the export: ${JSON.stringify(started.data).slice(0, 200)}`).toContain(started.status)

			await expect.poll(async () => {
				const resp = await page.request.get(`${BASE_URL}${API}/exports/${jobUuid}/download`)
				if (resp.status() === 200 && (resp.headers()['content-type'] ?? '').includes('zip')) {
					archive.buffer = await resp.body()
				}
				return archive.buffer.length > 0
			}, { timeout: POLL_TIMEOUT_MS, message: 'the export finishes and its ZIP downloads' }).toBeTruthy()
			// A ZIP starts with the local-file-header signature "PK\x03\x04".
			expect(archive.buffer.subarray(0, 4).toString('latin1')).toBe('PK\u0003\u0004')
		} finally {
			await admin.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			await removeApp(page, IMPORTED_SLUG)
			await removeApp(page, SOURCE_SLUG)
		} finally {
			await admin.close()
		}
	})

	test('REQ-BQIC-001: the archive imports under a new slug with the same pages and menu, owned by the maker, and opens', async ({ page }) => {
		const dialog = await pickArchive(page)
		await expect(dialog.getByTestId('import-app-summary')).toContainText(SOURCE_NAME)
		await expect(dialog.getByTestId('import-app-summary')).toContainText(/\d+ pages?/)

		const slug = dialog.getByRole('textbox', { name: 'Slug' })
		await expect(slug, 'the slug starts as the archive\'s own').toHaveValue(SOURCE_SLUG)
		await slug.fill(IMPORTED_SLUG)

		const imported = page.waitForResponse((resp) => resp.url().includes('/api/applications/import') && !resp.url().includes('/preview') && resp.request().method() === 'POST')
		await dialog.getByTestId('import-app-submit').click()
		const response = await imported
		expect(response.status(), `import: ${(await response.text()).slice(0, 300)}`).toBe(201)
		await expect(dialog).toBeHidden({ timeout: 30_000 })

		// The app is on the Applications list, with the source's pages and menu.
		expect(await appsWithSlug(page, IMPORTED_SLUG), 'one app under the new slug').toHaveLength(1)
		const source = await shapeOf(page, SOURCE_SLUG)
		const copy = await shapeOf(page, IMPORTED_SLUG)
		expect(copy.pages).toEqual(source.pages)
		expect(copy.menu).toEqual(source.menu)
		expect(copy.pages).toContain(EXTRA_PAGE.id)

		// The maker owns it.
		const [app] = await appsWithSlug(page, IMPORTED_SLUG)
		expect(app.permissions?.owners ?? [], 'the importing maker is its owner').toContain(`user:${ADMIN_USER}`)

		// And it opens.
		const opened = await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${IMPORTED_SLUG}/${EXTRA_PAGE.id}`, { waitUntil: 'domcontentloaded' })
		expect(opened?.status() ?? 0, 'the imported app is served').toBeLessThan(400)
		await dismissOverlays(page)
		await expect(page.locator('#app-navigation, nav').first().getByText(EXTRA_PAGE.title, { exact: true })).toBeVisible({ timeout: 30_000 })
	})

	test('REQ-BQIC-001: an import under a slug that is taken is refused and creates nothing', async ({ page }) => {
		const dialog = await pickArchive(page)
		const before = (await appsWithSlug(page, SOURCE_SLUG)).length
		expect(before, 'the source app holds the slug').toBe(1)

		await dialog.getByRole('textbox', { name: 'Slug' }).fill(SOURCE_SLUG)
		const refused = page.waitForResponse((resp) => resp.url().includes('/api/applications/import') && !resp.url().includes('/preview') && resp.request().method() === 'POST')
		await dialog.getByTestId('import-app-submit').click()
		expect((await refused).status()).toBe(409)

		await expect(dialog.getByTestId('import-app-error')).toContainText(`The slug "${SOURCE_SLUG}" is already taken. Choose another slug.`)
		await expect(dialog, 'the dialog stays open for another slug').toBeVisible()
		expect(await appsWithSlug(page, SOURCE_SLUG), 'no second app under that slug').toHaveLength(before)
	})
})
