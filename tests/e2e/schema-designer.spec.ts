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
import { ensureApp, dismissOverlays } from './support/appFixture'
import { saveSchemaAndAwait } from './support/schemaSave'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts. This used to be
// `NC_BASE_URL ?? 'http://localhost:8080'`, i.e. the SHARED dev instance.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

const APP_SLUG = 'pw-hello'
const SCHEMA_SLUG = 'message'
// SchemaDesigner namespaces a newly created schema to `{app}-{version}-{slug}`
// so it lands in (and is listed from) the app's per-version register.
const NAMESPACED_SLUG = `${APP_SLUG}-production-${SCHEMA_SLUG}`

// UN-QUARANTINED 2026-07-28 — openbuild#41 is fixed and this suite is green
// end to end against a live instance (create app → add schema → add 2 fields →
// save → reload → edit title → save → delete).
//
// What it took (each was a real defect, not test debt):
//   - /builder/:slug/schemas rendered the generic app-list, not the designer (#30).
//   - created schemas were written with a raw slug the designer's own list filter
//     hides, and were never attached to the app's per-version register (#33).
//   - "Schema not found" rendered while the detail was still loading (#34).
//   - the detail request queued behind a ~1900-row organisation-wide collection
//     fetch, and after create the store never issued it at all (#41).
//   - CnDetailPage mounted its create-archetype form dialog over the designer
//     because its "does the page have a body?" guard missed scoped slots
//     (nc-vue #544/#545 → beta.225; these pages now declare config.createForm
//     "never" — openbuild #42/#43).
//   - 🔑 OpenRegister's schema API is READ-BY-SLUG but WRITE-BY-ID: PUT/DELETE
//     on a slug (or a uuid) 404 "Schema not found". Saving and deleting by slug
//     silently did nothing — the toast fired, the change never landed.
test.describe('OpenBuild Schema Designer — end-to-end (REQ-OBSD-001..008)', () => {
	// 45s inner waits inside the config's 30s per-test budget could never
	// elapse: the test died first with a bare "Test timeout of 30000ms
	// exceeded" rather than the assertion's own message, so the guard could
	// never actually fire. Budget > longest wait, so the waits mean what they
	// say; no assertion is relaxed.
	test.describe.configure({ timeout: 90_000 })

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

	test('create virtual app → add schema → add 2 fields → save → edit → delete', async ({
		page,
	}) => {
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
		await page.goto(
			`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`,
			{
				waitUntil: 'domcontentloaded',
			},
		)

		// Wait for the panel to render — either the empty state or a row list.
		const panel = page.locator('.openbuild-schema-list')
		await expect(panel).toBeVisible({ timeout: 45_000 })
		// The onboarding tour mounts a modal a beat after the page settles; its
		// wrapper intercepts every click on the page beneath it.
		await dismissOverlays(page)

		// Step 4 — add a schema named `message`.
		//
		// Every locator here is scoped to the Add-schema dialog. The Schemas page
		// is a `type:"detail"` manifest page bound to the `application` schema, so
		// CnDetailPage renders an Application form (with its own "Application
		// Slug" / title fields) behind the designer — an unscoped
		// `getByLabel(/slug/i)` matches BOTH that and the dialog's "Schema slug"
		// and dies on strict mode (intermittently, depending on which renders
		// first). Same for the submit button, which shares its label with the
		// list's "Add schema" action.
		await page
			.getByRole('button', { name: /add schema/i })
			.first()
			.click()
		const addDialog = page
			.locator('[role="dialog"]')
			.filter({ hasText: /add schema/i })
			.first()
		await expect(addDialog).toBeVisible({ timeout: 15_000 })
		await addDialog
			.getByRole('textbox', { name: /schema slug/i })
			.fill(SCHEMA_SLUG)
		await addDialog.getByRole('textbox', { name: /^title$/i }).fill('Message')
		await addDialog.getByRole('button', { name: /^add schema$/i }).click()

		// Detail view loads; back button is visible.
		await expect(
			page.getByRole('button', { name: /back to schemas/i }),
		).toBeVisible({
			timeout: 45_000,
		})

		// The tour/dialog stack re-mounts on the detail route too — clear it again
		// before interacting with the editor beneath it.
		await dismissOverlays(page)

		// Step 5 — add two fields and Save.
		const addFieldButton = page.getByRole('button', { name: /add field/i })
		await addFieldButton.click()
		// The first row's Name input — there is only one field so far.
		await page.getByLabel('Name', { exact: false }).first().fill('subject')

		await addFieldButton.click()
		await page.getByLabel('Name', { exact: false }).nth(1).fill('body')

		// Wait for the schema WRITE to land 2xx. `networkidle` never settles on
		// Nextcloud (ADR-074 rule 4) and does not wait for the save XHR anyway,
		// so the reload below could race it and read back the PRE-save schema.
		await saveSchemaAndAwait(page)

		// Reload and verify persistence.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.getByLabel('Name', { exact: false }).first()).toHaveValue(
			'subject',
		)
		await expect(page.getByLabel('Name', { exact: false }).nth(1)).toHaveValue(
			'body',
		)

		// Step 6 — edit the title and save.
		await page.getByLabel(/title/i).first().fill('Message v2')
		await saveSchemaAndAwait(page)

		// Back to the list — the row should reflect the new title.
		await page.getByRole('button', { name: /back to schemas/i }).click()
		await expect(page.locator('.openbuild-schema-list__rows')).toContainText(
			'Message v2',
		)

		// Step 7 — delete the schema via the per-row action; confirm in
		// the dialog (REQ-OBSD-008).
		//
		// Open / Rename / Delete live inside the row's collapsed `NcActions`
		// menu, so the Delete button only exists once that menu's toggle is
		// clicked — the toggle itself carries no "delete" in its accessible
		// name, which is why looking for Delete on the row directly never
		// resolves.
		// Match on the FULL namespaced slug: the designer namespaces a new
		// schema to `{app}-{version}-{slug}`, and a bare "message" also matches
		// the seeded "…-hello-message" row.
		const row = page
			.locator('.openbuild-schema-list__row')
			.filter({ hasText: NAMESPACED_SLUG })
		await row
			.getByRole('button', { name: /actions|more/i })
			.first()
			.click()
		await page
			.getByRole('menuitem', { name: /delete/i })
			.or(page.getByRole('button', { name: /^delete$/i }))
			.first()
			.click()
		// REQ-OBSD-008: deletion is gated — the confirm button stays disabled
		// until the exact schema slug is typed, so a stray click cannot destroy
		// a schema. Type it, then confirm.
		const confirmDialog = page
			.locator('[role="dialog"]')
			.filter({ hasText: /delete schema/i })
			.first()
		await expect(confirmDialog).toBeVisible({ timeout: 15_000 })
		await confirmDialog
			.getByRole('textbox', { name: /type the slug to confirm/i })
			.fill(NAMESPACED_SLUG)
		await confirmDialog.getByRole('button', { name: /^delete schema$/i }).click()

		// Row disappears from the list.
		await expect(
			page
				.locator('.openbuild-schema-list__row')
				.filter({ hasText: NAMESPACED_SLUG }),
		).toHaveCount(0, { timeout: 45_000 })
	})
})
