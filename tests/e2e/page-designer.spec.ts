/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change `buildiq-page-editor`.
 *
 * Implements task 7.5 (add-page → save → render).
 *
 * Requirements covered: REQ-OBPD-002, REQ-OBPD-003, REQ-OBPD-009.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * UN-QUARANTINED AND REWRITTEN 2026-07-31. The file blamed buildiq#41, but it
 * was written against a surface that does not exist and never did on this
 * branch:
 *
 *   - it navigated to `/apps/buildiq/applications/hello-world/design`. There
 *     is no `/design` route — src/manifest.json declares the designer at
 *     `/builder/:slug/pages` (PageDesignerHost);
 *   - it asserted `.application-editor__tab--active`,
 *     `.application-editor__textarea` and `.application-editor__dirty`. No
 *     `application-editor__*` class exists anywhere in src/.
 *
 * The add-page → save → render journey below is now driven against the real
 * designer. The file's second scenario ("edits survive a Design ↔ Raw JSON tab
 * switch") is deliberately NOT rewritten: that tab pair is spec drift — the app
 * never shipped a Design/Raw-JSON toggle in the page designer, and the raw
 * manifest editor is a sidebar tab on the app DETAIL page
 * (ApplicationManifestTab). Rewriting it here would invent coverage for a
 * surface that does not exist; the same drift is recorded at the matching skip
 * in tests/e2e/spec-coverage/buildiq-runtime.spec.ts, whose REQ-OBR-005 test
 * covers the real manifest round-trip.
 */

import { test, expect } from '@playwright/test'
import {
	ensureApp,
	dismissOverlays,
	suppressSupportDialog,
} from './support/appFixture'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'

const APP_SLUG = 'pw-page-designer'
const NEW_ROUTE = '/added-by-e2e'

test.describe('buildiq page designer', () => {
	// The designer is a three-pane desktop surface; at the default 1280x720 the
	// page-list rows land below the fold, where a click never settles.
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeEach(async ({ page }) => {
		// The first-open support dialog mounts a mask over the designer and
		// swallows every click; suppress it before the first navigation.
		await suppressSupportDialog(page)
		// A dedicated fixture app: this scenario adds and SAVES a page, which must
		// not accumulate on the shared hello-world seed other suites assert on.
		await ensureApp(page, APP_SLUG, 'PW Page Designer')
	})

	test('REQ-OBPD-002 + REQ-OBPD-003 + REQ-OBPD-009: add page → save → renders in builder', async ({
		page,
	}) => {
		// Reset to a known baseline so the run is idempotent: this test saves the
		// page it adds, so a second run would otherwise stack duplicate routes.
		const manifestUrl = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
		await page.goto(`${BASE_URL}/apps/buildiq/`, {
			waitUntil: 'domcontentloaded',
		})
		await page.evaluate(
			async ({ manifestUrl, route }) => {
				const tok =
					window.OC?.requestToken
					|| document
						.querySelector('head')
						?.getAttribute('data-requesttoken')
					|| ''
				const headers = {
					requesttoken: tok,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				}
				const current = await (await fetch(manifestUrl, { headers })).json()
				const pages = (current.pages || []).filter((p) => p.route !== route)
				await fetch(manifestUrl, {
					method: 'PUT',
					headers,
					body: JSON.stringify({ manifest: { ...current, pages } }),
				})
			},
			{ manifestUrl, route: NEW_ROUTE },
		)

		await page.goto(
			`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`,
			{
				waitUntil: 'domcontentloaded',
			},
		)
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)

		const rows = page.locator('.page-list-editor__row')
		const initialPageCount = await rows.count()

		// REQ-OBPD-002 — adding a page prompts for the `type` from the canonical
		// closed enum before any other field is shown: the add row exposes only
		// the type picker, and Confirm stays disabled until a type is chosen.
		await page.locator('.page-list-editor__add').click()
		const confirm = page.getByRole('button', { name: /^confirm$/i })
		await expect(
			confirm,
			'Confirm is gated on choosing a page type first',
		).toBeDisabled()
		await page.locator('.page-list-editor__select').selectOption('index')
		await expect(confirm).toBeEnabled()
		await confirm.click()

		await expect(rows).toHaveCount(initialPageCount + 1)

		// Give the new page a known id + route. Each row renders both as bare
		// inputs, distinguished by placeholder.
		const newRow = rows.nth(initialPageCount)
		await newRow.getByPlaceholder('page id').fill('added-by-e2e')
		await newRow.getByPlaceholder('/route/:param').fill(NEW_ROUTE)

		// REQ-OBPD-009 — save the draft.
		await page.getByRole('button', { name: /save pages/i }).click()

		// The save must reach the STORED manifest, not just the editor buffer.
		await expect
			.poll(
				async () => {
					const stored = await page.evaluate(async (url) => {
						const resp = await fetch(url, {
							headers: { 'OCS-APIRequest': 'true' },
						})
						return resp.json()
					}, manifestUrl)
					return (stored.pages || []).some((p) => p.route === NEW_ROUTE)
				},
				{
					timeout: 30_000,
					message: 'the added page must be persisted to the manifest',
				},
			)
			.toBe(true)

		// REQ-OBPD-003 — the newly-added route renders in the built virtual app.
		await page.goto(
			`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}${NEW_ROUTE}?_version=production`,
			{
				waitUntil: 'domcontentloaded',
			},
		)
		await expect(
			page
				.locator('#buildiq-builder, .cn-app-root, .cn-page-renderer')
				.first(),
			'the saved route must render inside the builder host',
		).toBeVisible({ timeout: 45_000 })
	})
})
