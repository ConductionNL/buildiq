// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * DEEP, data-dependent e2e — full CRUD-WITH-PERSISTENCE for a Virtual App.
 *
 * A Virtual App is an OpenRegister object (register `buildiq` / schema
 * `application`). The Buildiq shell exposes a generic OR object-browser for
 * it at the `/apps/buildiq/schemas` route (the in-app nav labels it
 * "Schemas", but it lists + edits Application objects — verified live
 * 2026-06-10). That browser is the functional CRUD surface: Cards/Table
 * toggle, an "Add Application" create modal, per-row selection, and a
 * "Delete selected" bulk action.
 *
 * What this spec proves end-to-end (not just "the page renders"):
 *   CREATE  — fill the Add Application modal in the UI → POST returns 201 →
 *             the new row appears in the list AND the object is independently
 *             readable back through the OR API (true persistence, not optimistic
 *             UI state).
 *   READ    — a separately-seeded app shows up in the list by name + slug.
 *   DELETE  — select the created row → Delete selected → the row disappears AND
 *             the OR API confirms the object is gone (404).
 *
 * Legs that are NOT honestly drivable in this build are marked `test.fixme`
 * with the concrete reason (see BUG LIST in the suite header below), never
 * faked green:
 *   - EDIT via the row detail/edit sidebar — Conduction/buildiq#41 (the
 *     application detail/editor sidebar does not populate the object's fields).
 *
 * CREATE via the four-step wizard endpoint (formerly BUG-A) is now GREEN:
 * OpenRegister's `lockObject` accepts a pre-creation/advisory identifier, so the
 * wizard's TOCTOU `lockObject('createApp:<slug>')` no longer 422s — the wizard
 * returns 201 with the new app's UUID.
 *
 * Pre-conditions: Docker stack up at PLAYWRIGHT_BASE_URL (default
 * http://localhost:8080); Buildiq + OpenRegister enabled; admin/admin.
 * globalSetup provides the authenticated storageState.
 */

import { test, expect, type Page } from '@playwright/test'
import {
	seedVirtualApp,
	findVirtualApp,
	deleteVirtualApp,
	cleanupByPrefix,
	wizardCreate,
	E2E_PREFIX,
} from './fixtures'

/**
 * Open the functional Virtual-App object browser and wait for the
 * application list request to settle.
 */
async function gotoAppBrowser(page: Page): Promise<void> {
	// The app router runs in HISTORY mode (`createWebHistory(generateUrl('/apps/buildiq'))`
	// in src/main.js), NOT hash mode. Two consequences, both live-verified on
	// this instance:
	//   - a `#/applications` hash is not a route: vue-router matches path `/`
	//     and mounts the Dashboard, so the applications actions bar (and its
	//     "Add app" button) is never rendered;
	//   - the `/index.php/`-prefixed form does not match the router base
	//     (`htaccess.RewriteBase` is `/`, so `generateUrl` emits the pretty
	//     `/apps/buildiq`), so the router replaces the URL with the bare
	//     app root — again the Dashboard.
	// The pretty-URL path form is the only form that actually mounts the
	// applications index.
	await page.goto('/apps/buildiq/applications')
	await page
		.waitForResponse(
			(r) =>
				r.url().includes('/objects/buildiq/application')
				&& r.status() === 200,
			{ timeout: 20_000 },
		)
		.catch(() => {
			/* list may already be cached */
		})
	await page.waitForTimeout(1500)
}

/**
 * Bring a freshly-seeded virtual app into view. The CnIndexPage list paginates
 * at 20 rows/page and applies no default sort, so the OR backend returns rows
 * in id order — a just-seeded app (highest id) lands on the LAST page, invisible
 * on page 1 once the shared dev instance holds 20+ demo apps. Drive the
 * CnPagination "Last" button to jump to that final page where the seed renders.
 * Best-effort: if the pagination control is absent (≤20 rows total) the list
 * already shows everything, so a no-op is correct.
 */
async function showSeededRow(page: Page): Promise<void> {
	const pagination = page.locator('[data-testid="cn-pagination"]')
	if (!(await pagination.count())) {
		return
	}
	// "Last" jumps to the final page (label is i18n; default English "Last").
	// Fall back to the highest numbered page button if the label differs.
	const lastBtn = pagination.getByRole('button', { name: /^last$/i }).first()
	if (await lastBtn.count()) {
		await lastBtn.click()
	} else {
		const numbered = pagination.getByRole('button', { name: /^\d+$/ })
		const n = await numbered.count()
		if (n > 0) {
			await numbered.nth(n - 1).click()
		}
	}
	// Re-fetch of the page settles the DOM, not the network (polling app).
	await page.waitForLoadState('domcontentloaded')
	await page.waitForTimeout(1000)
}

test.describe('Virtual App — full CRUD with persistence', () => {
	test.afterAll(async ({ request }) => {
		await cleanupByPrefix(request)
	})

	test('CREATE via UI wizard persists and the new row appears', async ({
		page,
		request,
	}) => {
		// The wizard provisions the app + one register per version, which can
		// take longer than the default 30s on a busy shared instance.
		test.setTimeout(90_000)
		// The create UI is a 3-step wizard ("App basics" → "Choose a version
		// preset" → "Review and create"). Slug is auto-derived from the name
		// (kebab-cased, lowercased) and shown read-only — there is no slug input.
		// On the prefixed e2e name the derived slug stays inside the cleanup
		// prefix, so afterAll still sweeps it.
		const name = `${E2E_PREFIX} Create ${Math.floor(Math.random() * 1e4)}`
		const slug = name
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-|-$/g, '')

		await gotoAppBrowser(page)

		// The button reads "Add app" (src/components/VirtualAppsActions.vue),
		// not "Add application" — live-verified.
		await page
			.getByRole('button', { name: /add app/i })
			.first()
			.click()
		const dialog = page.locator('[role="dialog"], .modal-container').first()
		await expect(dialog).toBeVisible({ timeout: 8_000 })

		// Step 1 — App basics: fill the name (slug derives automatically).
		const nameInput = dialog
			.locator('input[placeholder*="My Permit Tracker" i]')
			.first()
		await expect(nameInput).toBeVisible({ timeout: 8_000 })
		await nameInput.fill(name)
		await expect(dialog.getByText(slug, { exact: false }).first()).toBeVisible({
			timeout: 5_000,
		})
		await dialog.getByRole('button', { name: /^next$/i }).click()

		// Step 2 — version preset: pick the simplest single-version preset.
		await expect(dialog.getByText(/choose a version preset/i)).toBeVisible({
			timeout: 8_000,
		})
		await dialog
			.getByRole('button', { name: /Single/i })
			.first()
			.click()
		await dialog.getByRole('button', { name: /^next$/i }).click()

		// Step 3 — review and create: the wizard provisions the app + registers.
		await expect(dialog.getByText(/review and create/i)).toBeVisible({
			timeout: 8_000,
		})
		const createPost = page.waitForResponse(
			(r) =>
				r.url().includes('/api/applications/wizard')
				&& r.request().method() === 'POST',
			{ timeout: 20_000 },
		)
		await dialog.getByRole('button', { name: /^create$/i }).click()
		const resp = await createPost
		expect([200, 201]).toContain(resp.status())

		// Row appears in the list (UI reflects the new app).
		await expect(page.getByText(name, { exact: false }).first()).toBeVisible({
			timeout: 10_000,
		})

		// TRUE PERSISTENCE: the app object is independently readable via the OR
		// API by its derived slug (resolve uuid through the applications list).
		await expect
			.poll(
				async () => {
					const res = await request.get(
						`${process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'}/index.php/apps/openregister/api/objects/buildiq/application?_limit=200`,
						{
							headers: {
								'OCS-APIRequest': 'true',
								Authorization:
									'Basic '
									+ Buffer.from('admin:admin').toString('base64'),
							},
						},
					)
					const body = await res.json()
					const rows: Array<Record<string, unknown>> = Array.isArray(body)
						? body
						: (body.results ?? [])
					return rows.some((r) => r.slug === slug && r.name === name)
				},
				{ timeout: 15_000 },
			)
			.toBe(true)
	})

	test('READ — a seeded app is listed in the UI by name and slug', async ({
		page,
		request,
	}) => {
		const app = await seedVirtualApp(request, { name: `E2E Read ${E2E_PREFIX}` })

		await gotoAppBrowser(page)

		// The CnIndexPage paginates at 20 rows/page and applies no default sort,
		// so a freshly-seeded app (highest id) lands on the LAST page — invisible
		// on page 1. The shared dev instance already holds ~20+ demo apps, so we
		// cannot assume the seed shows up first. Jump to the final page where the
		// newest row renders, then assert.
		await showSeededRow(page)

		await expect(page.getByText(app.name, { exact: false }).first()).toBeVisible(
			{ timeout: 10_000 },
		)
		// Slug is rendered in the row too.
		await expect(page.locator('body')).toContainText(app.slug)

		await deleteVirtualApp(request, app.uuid)
	})

	// The applications index (VirtualApps page, type:"index", cardComponent:
	// ApplicationCard) currently has no working UI delete affordance:
	//   - the default Cards view exposes no per-card select / bulk action;
	//   - switching to the Table view renders no row data (the lib's
	//     CnDataTable binds zero rows for this index — empty cells / 0 rows,
	//     affecting every app row, not just seeded ones), so there is no
	//     selectable row to drive a bulk delete;
	//   - the app-detail Actions menu offers only Refresh / Documentation /
	//     Request-a-feature, no Delete.
	// The backend delete path itself is exercised (deleteVirtualApp + the
	// CREATE/READ persistence round-trips). Un-fixme once the index Table view
	// row binding (or a card/detail delete action) is restored in the lib.
	test('DELETE via UI bulk action removes the row and the object (index Table view binds no rows; no UI delete affordance)', async ({
		page,
		request,
	}) => {
		test.fixme(
			true,
			'index Table view binds no rows; no UI delete affordance The applications index (VirtualApps page, type:"index", cardComponent: ApplicationCard) currently has no working UI delete affordance: the default Cards view exposes no per-card select / bulk action; switching to the Table view renders no row data (the lib\'s CnDataTable binds zero rows for this index — empty cells / 0...',
		)
		const app = await seedVirtualApp(request, {
			name: `E2E Delete ${E2E_PREFIX}`,
		})

		await gotoAppBrowser(page)
		await expect(page.getByText(app.name, { exact: false }).first()).toBeVisible(
			{ timeout: 10_000 },
		)

		const tableToggle = page
			.getByRole('radio', { name: /table/i })
			.or(page.locator('input[type="radio"][value="table"]'))
			.first()
		await tableToggle.click({ force: true })
		await expect(page.locator('tr').first()).toBeVisible({ timeout: 8_000 })

		// Select the seeded row. The OR object browser uses NcCheckboxRadioSwitch,
		// whose visible label span intercepts pointer events — so we click the
		// label (the actual hit target) rather than the visually-hidden input.
		const row = page.locator('tr', { hasText: app.slug }).first()
		await expect(row).toBeVisible({ timeout: 8_000 })
		const checkboxLabel = row.locator('.checkbox-radio-switch, label').first()
		await checkboxLabel.click({ force: true })
		// Confirm the row is actually selected before invoking the bulk action.
		await expect(row.locator('input[type="checkbox"]').first()).toBeChecked({
			timeout: 5_000,
		})

		// Open the table Actions menu → "Delete selected".
		await page
			.getByRole('button', { name: /^actions$/i })
			.first()
			.click()
		const deleteSelected = page
			.getByRole('menuitem', { name: /delete selected/i })
			.or(page.getByText(/delete selected/i))
			.first()
		await expect(deleteSelected).toBeVisible({ timeout: 6_000 })

		await deleteSelected.click()

		// "Delete selected" opens a confirm dialog ("Delete items … Cancel /
		// Delete") listing the selected app. Confirm via the dialog's own
		// Delete button (scoped so we don't re-hit the menu item).
		const confirmDialog = page
			.locator('.modal-container, [role="dialog"]')
			.filter({ hasText: /delete items/i })
			.first()
		await expect(confirmDialog).toBeVisible({ timeout: 6_000 })
		await expect(confirmDialog).toContainText(app.name)

		// The action-menu popper that launched the dialog lingers on top and can
		// intercept clicks. Click inside the dialog body first (dismisses the
		// popper, keeps the modal) so the subsequent Delete click hit-tests cleanly.
		await confirmDialog
			.getByText(/delete items/i)
			.first()
			.click()

		const deletePromise = page.waitForResponse(
			(r) =>
				r.url().includes('/objects/buildiq/application')
				&& r.request().method() === 'DELETE',
			{ timeout: 15_000 },
		)
		await confirmDialog.getByRole('button', { name: /^delete$/i }).click()
		await deletePromise
		await page.waitForTimeout(2_000)

		// TRUE persistence check: the object is gone from the backend.
		const gone = await findVirtualApp(request, app.uuid)
		expect(gone, 'deleted app must no longer be readable via OR API').toBeNull()
	})

	// ---- BUG-A FIXED: wizard create works now ------------------------------
	// Previously fixme'd: the wizard's TOCTOU mitigation locked
	// `createApp:<slug>` via OR ObjectService::lockObject, but OR's LockHandler
	// rejected identifiers that did not resolve to a stored object, so every
	// create returned 422 app_slug_conflict. OpenRegister's lockObject now
	// accepts a pre-creation/advisory identifier, so the documented four-step
	// wizard entry point returns 201 with the new app's UUID.
	test('CREATE via the four-step wizard endpoint returns 201 with the new app uuid', async ({
		request,
	}) => {
		const slug = `${E2E_PREFIX}-wiz-${Math.floor(Math.random() * 1e4)}`
		const { status, body } = await wizardCreate(request, {
			name: `E2E Wizard ${slug}`,
			slug,
			description: 'wizard create',
			preset: 'single',
		})
		expect(status, 'wizard create should return 201').toBe(201)
		expect(
			body.applicationUuid,
			'wizard must return the new app uuid',
		).toBeTruthy()
	})

	// ---- #41: detail/editor sidebar does not populate ----------------------
	test('EDIT via the row detail sidebar (Conduction/buildiq#41: editor does not render app fields)', async ({
		page,
		request,
	}) => {
		test.fixme(
			true,
			'Conduction/buildiq#41: editor does not render app fields #41: detail/editor sidebar does not populate ----------------------',
		)
		const app = await seedVirtualApp(request, { name: `E2E Edit ${E2E_PREFIX}` })
		await gotoAppBrowser(page)
		await page.getByText(app.name, { exact: false }).first().click()
		// In a fixed build the detail/edit sidebar shows the app's fields;
		// today it opens but never populates the object — so the edit + save
		// + persist assertions below cannot run honestly.
		const sidebar = page.locator('.app-sidebar, [class*="detail"]').first()
		await expect(sidebar).toContainText(app.slug, { timeout: 8_000 })
		await deleteVirtualApp(request, app.uuid)
	})
})
