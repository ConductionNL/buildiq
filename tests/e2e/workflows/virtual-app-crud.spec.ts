// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * DEEP, data-dependent e2e — full CRUD-WITH-PERSISTENCE for a Virtual App.
 *
 * A Virtual App is an OpenRegister object (register `openbuild` / schema
 * `application`). The OpenBuild shell exposes a generic OR object-browser for
 * it at the `/apps/openbuild/schemas` route (the in-app nav labels it
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
 *   - EDIT via the row detail/edit sidebar — Conduction/openbuild#41 (the
 *     application detail/editor sidebar does not populate the object's fields).
 *   - CREATE via the four-step wizard endpoint — BUG-A (the wizard's TOCTOU
 *     advisory lock calls OR `lockObject('createApp:<slug>')` on a not-yet-
 *     existing object; OR's current LockHandler rejects identifiers that do not
 *     resolve to a stored object, so every wizard create returns 422
 *     `app_slug_conflict`).
 *
 * Pre-conditions: Docker stack up at PLAYWRIGHT_BASE_URL (default
 * http://localhost:8080); OpenBuild + OpenRegister enabled; admin/admin.
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
	await page.goto('/index.php/apps/openbuild/')
	await page.waitForTimeout(1200)
	await page.getByRole('link', { name: /^schemas$/i }).first().click()
	await page
		.waitForResponse(
			(r) => r.url().includes('/objects/openbuild/application?') && r.status() === 200,
			{ timeout: 20_000 },
		)
		.catch(() => { /* list may already be cached */ })
	await page.waitForTimeout(1500)
}

test.describe('Virtual App — full CRUD with persistence', () => {
	test.afterAll(async ({ request }) => {
		await cleanupByPrefix(request)
	})

	test('CREATE via UI modal persists and the new row appears', async ({ page, request }) => {
		const slug = `${E2E_PREFIX}-create-${Math.floor(Math.random() * 1e4)}`
		const name = `E2E Create ${slug}`

		await gotoAppBrowser(page)

		await page.getByRole('button', { name: /add application/i }).first().click()
		const dialog = page.locator('[role="dialog"], .modal-container').first()
		await expect(dialog).toBeVisible({ timeout: 8_000 })

		// The create modal is OR's generic object form for the `application`
		// schema; field order (verified live): description, name*, productionVersion, slug*.
		const nameInput = dialog.locator('input[placeholder*="Human-readable name" i]').first()
		const slugInput = dialog.locator('input[placeholder*="Kebab-case slug" i]').first()
		await expect(nameInput).toBeVisible()
		await nameInput.fill(name)
		await slugInput.fill(slug)

		const createPost = page.waitForResponse(
			(r) => r.url().includes('/objects/openbuild/application') && r.request().method() === 'POST',
			{ timeout: 15_000 },
		)
		await dialog.getByRole('button', { name: /^create$/i }).click()
		const resp = await createPost
		expect(resp.status(), 'create POST must return 201').toBe(201)

		const created = await resp.json()
		const uuid = String(created.id ?? created['@self']?.id ?? '')
		expect(uuid, 'created object must carry a uuid').not.toBe('')

		// Row appears in the list (UI reflects the new object).
		await expect(page.getByText(name, { exact: false }).first()).toBeVisible({ timeout: 10_000 })

		// TRUE PERSISTENCE: read the object back through the OR API independently
		// of the optimistic UI state.
		const persisted = await findVirtualApp(request, uuid)
		expect(persisted, 'object must be persisted and readable via OR API').not.toBeNull()
		expect(persisted?.slug, 'persisted slug must match what we typed').toBe(slug)
		expect(persisted?.name, 'persisted name must match what we typed').toBe(name)

		// Clean up this object explicitly (afterAll also sweeps by prefix).
		await deleteVirtualApp(request, uuid)
	})

	test('READ — a seeded app is listed in the UI by name and slug', async ({ page, request }) => {
		const app = await seedVirtualApp(request, { name: `E2E Read ${E2E_PREFIX}` })

		await gotoAppBrowser(page)

		await expect(page.getByText(app.name, { exact: false }).first()).toBeVisible({ timeout: 10_000 })
		// Slug is rendered in the row too.
		await expect(page.locator('body')).toContainText(app.slug)

		await deleteVirtualApp(request, app.uuid)
	})

	test('DELETE via UI bulk action removes the row and the object', async ({ page, request }) => {
		const app = await seedVirtualApp(request, { name: `E2E Delete ${E2E_PREFIX}` })

		await gotoAppBrowser(page)
		await expect(page.getByText(app.name, { exact: false }).first()).toBeVisible({ timeout: 10_000 })

		// Select the seeded row. The OR object browser uses NcCheckboxRadioSwitch,
		// whose visible label span intercepts pointer events — so we click the
		// label (the actual hit target) rather than the visually-hidden input.
		const row = page.locator('tr', { hasText: app.slug }).first()
		await expect(row).toBeVisible({ timeout: 8_000 })
		const checkboxLabel = row.locator('.checkbox-radio-switch, label').first()
		await checkboxLabel.click({ force: true })
		// Confirm the row is actually selected before invoking the bulk action.
		await expect(row.locator('input[type="checkbox"]').first()).toBeChecked({ timeout: 5_000 })

		// Open the table Actions menu → "Delete selected".
		await page.getByRole('button', { name: /^actions$/i }).first().click()
		const deleteSelected = page.getByRole('menuitem', { name: /delete selected/i })
			.or(page.getByText(/delete selected/i))
			.first()
		await expect(deleteSelected).toBeVisible({ timeout: 6_000 })

		await deleteSelected.click()

		// "Delete selected" opens a confirm dialog ("Delete items … Cancel /
		// Delete") listing the selected app. Confirm via the dialog's own
		// Delete button (scoped so we don't re-hit the menu item).
		const confirmDialog = page.locator('.modal-container, [role="dialog"]').filter({ hasText: /delete items/i }).first()
		await expect(confirmDialog).toBeVisible({ timeout: 6_000 })
		await expect(confirmDialog).toContainText(app.name)

		// The action-menu popper that launched the dialog lingers on top and can
		// intercept clicks. Click inside the dialog body first (dismisses the
		// popper, keeps the modal) so the subsequent Delete click hit-tests cleanly.
		await confirmDialog.getByText(/delete items/i).first().click()

		const deletePromise = page.waitForResponse(
			(r) => r.url().includes('/objects/openbuild/application') && r.request().method() === 'DELETE',
			{ timeout: 15_000 },
		)
		await confirmDialog.getByRole('button', { name: /^delete$/i }).click()
		await deletePromise
		await page.waitForTimeout(2_000)

		// TRUE persistence check: the object is gone from the backend.
		const gone = await findVirtualApp(request, app.uuid)
		expect(gone, 'deleted app must no longer be readable via OR API').toBeNull()
	})

	// ---- BUG-A: wizard create is broken in this build ----------------------
	test.fixme(
		'CREATE via the four-step wizard endpoint (BUG-A: OR lockObject on a non-existent object)',
		async ({ request }) => {
			// The documented "build a virtual app" entry point. Currently the
			// wizard's TOCTOU mitigation locks `createApp:<slug>` via OR
			// ObjectService::lockObject, but OR's LockHandler requires the
			// identifier to resolve to a stored object, so EVERY create returns
			// 422 app_slug_conflict ("...is being created by another request").
			// Re-enable once the wizard uses a lock primitive that does not
			// require a pre-existing object (or OR's lock accepts synthetic keys).
			const slug = `${E2E_PREFIX}-wiz-${Math.floor(Math.random() * 1e4)}`
			const { status, body } = await wizardCreate(request, {
				name: `E2E Wizard ${slug}`,
				slug,
				description: 'wizard create',
				preset: 'single',
			})
			expect(status, 'wizard create should return 201').toBe(201)
			expect(body.applicationUuid, 'wizard must return the new app uuid').toBeTruthy()
		},
	)

	// ---- #41: detail/editor sidebar does not populate ----------------------
	test.fixme(
		'EDIT via the row detail sidebar (Conduction/openbuild#41: editor does not render app fields)',
		async ({ page, request }) => {
			const app = await seedVirtualApp(request, { name: `E2E Edit ${E2E_PREFIX}` })
			await gotoAppBrowser(page)
			await page.getByText(app.name, { exact: false }).first().click()
			// In a fixed build the detail/edit sidebar shows the app's fields;
			// today it opens but never populates the object — so the edit + save
			// + persist assertions below cannot run honestly.
			const sidebar = page.locator('.app-sidebar, [class*="detail"]').first()
			await expect(sidebar).toContainText(app.slug, { timeout: 8_000 })
			await deleteVirtualApp(request, app.uuid)
		},
	)
})
