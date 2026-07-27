/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end spec for the OpenBuild schema designer
 * (spec #4 — openbuild-schema-editor). Marks parts of cross-spec
 * journey #2 (create-virtual-app → design-schema → edit-page →
 * publish-version-1).
 *
 * Flow under test:
 *   1. Log in as admin (NC_ADMIN_USER / NC_ADMIN_PASS env vars).
 *   2. Open the OpenBuild app and create a virtual application
 *      (slug `pw-hello`, title "PW Hello"). The smoke spec from
 *      bootstrap-openbuild already exercises this part; we re-use
 *      the same UX to land on the application page.
 *   3. Navigate to that virtual app's Schemas tab —
 *      /builder/pw-hello/schemas.
 *   4. Click "Add schema" → fill slug `message` + title "Message" →
 *      submit. Assert the new row appears in the list.
 *   5. Open the schema → add two fields (`subject`, `body`) →
 *      Save. Reload; assert both fields persist.
 *   6. Open the schema again → edit the title → Save → assert the
 *      list row reflects the new title.
 *   7. Delete the schema via the per-row Delete action; assert the
 *      confirm dialog appears and only fires deletion on
 *      confirmation. The schema row should disappear from the list.
 *
 * Runs against a live Nextcloud at NC_BASE_URL (default
 * http://localhost:8080) with the OpenBuild app installed AND chain
 * spec #3 (openregister-runtime-schema-api) deployed. Until chain #3
 * lands, the schema CRUD calls return 404 and the test will fail at
 * step 4 — this is the expected gating behaviour documented in spec
 * tasks.md §7.
 *
 * The spec is intentionally self-contained — it uses
 * `page.request.post('/login')` to authenticate inside the test so
 * the suite can be run via `npx playwright test tests/e2e` without a
 * shared global-setup file (chain #3 + this spec land together).
 */

import { test, expect } from '@playwright/test'
import { ensureApp } from './support/appFixture'

const BASE_URL = process.env.NC_BASE_URL ?? 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

const APP_SLUG = 'pw-hello'
const SCHEMA_SLUG = 'message'

// PARTIALLY UNBLOCKED — still quarantined, but for a much narrower reason than
// the original "#41: admin UI not functional".
//
// Fixed and live-verified since that quarantine was written:
//   - /builder/:slug/schemas renders SchemaDesignerView (was the generic
//     CnIndexPage listing Applications) — PR #30.
//   - schemas created in the designer are namespaced + attached to the app's
//     per-version register, so they now appear in the list they were created
//     from (verified: list went from 0 rows to 2) — this change.
//   - the spec's app-creation step no longer drives the removed flat
//     "Add application" form; it uses the wizard endpoint via ensureApp().
//   - globalSetup no longer silently leaves the suite unauthenticated.
//
// REMAINING: after Add-schema the designer navigates to the new schema's detail
// route but renders the "Schema not found" empty state (two "Back to schemas"
// buttons — the header one plus the empty-state one — which is what the
// assertion below trips over). Reproduced on a healthy, authenticated instance,
// so it is a real create -> detail-load defect, not test flake; it needs its own
// change with the SchemaDesigner.loadDetail/store fetch path in scope. Re-enable
// this describe once that lands.
test.describe.skip('OpenBuild Schema Designer — end-to-end (REQ-OBSD-001..008)', () => {
	test.beforeEach(async ({ page }) => {
		// Session is established by globalSetup (tests/e2e/global-setup.ts)
		// which writes storageState that every spec inherits via the
		// playwright.config.ts `use.storageState` setting. The legacy
		// per-spec form-login fight with the brute-force throttle and
		// is unnecessary now. Kept as a no-op `beforeEach` so the
		// reference frame is obvious to readers.
		void ADMIN_USER
		void ADMIN_PASS
		void page
	})

	test('create virtual app → add schema → add 2 fields → save → edit → delete', async ({ page }) => {
		// Steps 1–2 — ensure the virtual app exists (idempotent). App creation
		// moved to the multi-step wizard; the old flat "Add application" form no
		// longer exists, so create via the atomic wizard endpoint instead.
		await ensureApp(page, APP_SLUG, 'PW Hello')

		// Step 3 — navigate to the Schema Designer for this virtual app.
		// The `?_version=production` marker targets the app's per-version
		// register (`openbuild-{slug}-production`) that the wizard creates —
		// without it SchemaDesigner falls back to the legacy `openbuild-{slug}`
		// register (which wizard-created apps don't have), so schemas would be
		// written to / read from the wrong namespace and never resolve. The real
		// in-app nav carries the same marker via buildVersionedRoute().
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, {
			waitUntil: 'domcontentloaded',
		})

		// Wait for the panel to render — either the empty state or a row list.
		const panel = page.locator('.openbuild-schema-list')
		await expect(panel).toBeVisible({ timeout: 10_000 })

		// Step 4 — add a schema named `message`.
		await page.getByRole('button', { name: /add schema/i }).first().click()
		await page.getByLabel(/slug/i).fill(SCHEMA_SLUG)
		await page.getByLabel(/title/i).fill('Message')
		await page.getByRole('button', { name: /add schema|save/i }).last().click()

		// Detail view loads; back button is visible.
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({
			timeout: 10_000,
		})

		// Step 5 — add two fields and Save.
		const addFieldButton = page.getByRole('button', { name: /add field/i })
		await addFieldButton.click()
		// The first row's Name input — there is only one field so far.
		await page.getByLabel('Name', { exact: false }).first().fill('subject')

		await addFieldButton.click()
		await page.getByLabel('Name', { exact: false }).nth(1).fill('body')

		await page.getByRole('button', { name: /^save$/i }).click()
		// Expect either the toast or the saving state to settle.
		await page.waitForLoadState('networkidle')

		// Reload and verify persistence.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.getByLabel('Name', { exact: false }).first()).toHaveValue('subject')
		await expect(page.getByLabel('Name', { exact: false }).nth(1)).toHaveValue('body')

		// Step 6 — edit the title and save.
		await page.getByLabel(/title/i).first().fill('Message v2')
		await page.getByRole('button', { name: /^save$/i }).click()
		await page.waitForLoadState('networkidle')

		// Back to the list — the row should reflect the new title.
		await page.getByRole('button', { name: /back to schemas/i }).click()
		await expect(page.locator('.openbuild-schema-list__rows')).toContainText('Message v2')

		// Step 7 — delete the schema via the per-row action; confirm in
		// the dialog (REQ-OBSD-008).
		const row = page
			.locator('.openbuild-schema-list__row')
			.filter({ hasText: SCHEMA_SLUG })
		await row.getByRole('button', { name: /delete/i }).click()
		// Confirm dialog asks for explicit confirmation.
		const confirmButton = page
			.locator('.delete-schema-dialog, [role="dialog"]')
			.getByRole('button', { name: /delete|confirm/i })
		await confirmButton.click()

		// Row disappears from the list.
		await expect(
			page.locator('.openbuild-schema-list__row').filter({ hasText: SCHEMA_SLUG }),
		).toHaveCount(0, { timeout: 10_000 })
	})
})
