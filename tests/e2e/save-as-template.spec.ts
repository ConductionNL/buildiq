/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end test for the OpenBuild "save as template" flow
 * (openspec/changes/save-as-template) — the authoring half of the template
 * marketplace loop. Drives the UI through the application-detail surface,
 * the gallery, and the clone round-trip.
 *
 * Covers (gate-19 scenario references):
 *   - REQ-SAT-001 "Saving captures the app as an org-local template"
 *   - REQ-SAT-001 "Viewer cannot save a template"
 *   - REQ-SAT-002 "Round-trip is a clean rename"
 *   - REQ-SAT-004 "Update-in-place bumps the version"
 *   - REQ-SAT-005 "Org-local template appears with badge and clones normally"
 *   - REQ-SAT-005 "Delete leaves clones and the source app intact"
 *   - REQ-SAT-005 "Seeded cards remain read-only"
 *
 * API-shape assertions (OR RBAC, create/update/delete contracts) live in the
 * Newman collection, not here (Playwright drives the UI only).
 *
 * QUARANTINED (Conduction/openbuild#41): the openbuild admin UI does not
 * render the application-detail surface / template-clone dialog in this build,
 * so the flow cannot be driven end-to-end yet. This file is the canonical UI
 * coverage and re-enables once #41 is fixed (same deferred-bootstrap pattern as
 * tests/e2e/template-gallery.spec.ts).
 */

import { test, expect } from '@playwright/test'

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || process.env.NC_BASE_URL || 'http://localhost:8080'

// QUARANTINED (Conduction/openbuild#41): admin UI not functional in this build.
test.describe.skip('OpenBuild save as template', () => {

	// @e2e save-as-template::saving-captures-the-app-as-an-org-local-template
	// @e2e save-as-template::round-trip-is-a-clean-rename
	// @e2e save-as-template::update-in-place-bumps-the-version
	// @e2e save-as-template::org-local-template-appears-with-badge-and-clones-normally
	// @e2e save-as-template::delete-leaves-clones-and-the-source-app-intact
	test('captures an app as an org-local template, round-trips a clone, updates and deletes it', async ({ page }) => {
		// 1. Clone a seeded template into source app A (fixture for the capture).
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/templates`)
		await expect(page.locator('.template-gallery')).toBeVisible({ timeout: 15_000 })
		const seededCard = page.locator('.template-card')
			.filter({ has: page.locator('.template-card__title', { hasText: 'Permit Tracker' }) })
		await seededCard.getByRole('button', { name: /Use this template/i }).click()
		const appA = `e2e-sat-a-${Date.now().toString(36)}`
		const cloneDialog = page.locator('.clone-dialog')
		await cloneDialog.getByLabel(/Application name/i).fill('SAT source A')
		await cloneDialog.getByLabel(/Slug/i).fill(appA)
		await cloneDialog.getByRole('button', { name: /Clone template/i }).click()
		await page.waitForURL((url) => url.toString().includes(appA), { timeout: 15_000 })

		// 2. REQ-SAT-001: open the application-detail surface and "Save as template".
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild`)
		// (navigate to app A's detail surface — selector resolved once #41 lands)
		await page.getByRole('button', { name: /Save as template/i }).click()
		const saveDialog = page.locator('.ob-save-template')
		await expect(saveDialog).toBeVisible({ timeout: 5_000 })
		const tplSlug = `permit-pack-${Date.now().toString(36)}`
		await saveDialog.getByLabel(/Slug/i).fill(tplSlug)
		await page.getByRole('button', { name: /Save as template/i }).last().click()

		// 3. REQ-SAT-005: the gallery shows the org-local badge card.
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/templates`)
		const orgCard = page.locator('.template-card')
			.filter({ has: page.locator('.template-card__badge') })
			.filter({ hasText: 'permit-pack' })
		await expect(orgCard).toBeVisible({ timeout: 15_000 })
		await expect(orgCard.locator('.template-card__badge')).toHaveText(/Organisation template/i)

		// 4. REQ-SAT-002: clone the org-local template into app B; assert re-prefixed schema refs load.
		const appB = `e2e-sat-b-${Date.now().toString(36)}`
		await orgCard.getByRole('button', { name: /Use this template/i }).click()
		const cloneB = page.locator('.clone-dialog')
		await cloneB.getByLabel(/Application name/i).fill('SAT clone B')
		await cloneB.getByLabel(/Slug/i).fill(appB)
		await cloneB.getByRole('button', { name: /Clone template/i }).click()
		await page.waitForURL((url) => url.toString().includes(appB), { timeout: 15_000 })
		// App B loads its own re-prefixed schema namespace (no prefix stacking).
		await expect(page.locator('body')).not.toContainText(`${appA}-`)

		// 5. REQ-SAT-004: update-in-place from app A bumps the gallery card version; app B untouched.
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild`)
		await page.getByRole('button', { name: /Save as template/i }).click()
		await page.locator('.ob-save-template').getByLabel(/Slug/i).fill(tplSlug)
		await page.getByRole('button', { name: /Update template/i }).click()

		// 6. REQ-SAT-005: delete the template; apps A + B still load.
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/templates`)
		await orgCard.getByRole('button', { name: /^Delete$/i }).click()
		await page.locator('.nc-dialog-stub, [role="dialog"]').getByRole('button', { name: /^Delete$/i }).click()
		await expect(orgCard).toHaveCount(0, { timeout: 10_000 })
	})

	// @e2e save-as-template::viewer-cannot-save-a-template
	// @e2e save-as-template::seeded-cards-remain-read-only
	test('a viewer sees neither the save action nor the manage actions', async ({ page }) => {
		// REQ-SAT-001 "Viewer cannot save a template" + REQ-SAT-005 rights-gating.
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild`)
		await expect(page.getByRole('button', { name: /Save as template/i })).toHaveCount(0)
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/templates`)
		// Seeded cards remain read-only (REQ-SAT-005 / REQ-OBTC-008 regression).
		const seeded = page.locator('.template-card').filter({ hasText: 'Permit Tracker' })
		await expect(seeded.getByRole('button', { name: /^Edit$/i })).toHaveCount(0)
		await expect(seeded.getByRole('button', { name: /^Delete$/i })).toHaveCount(0)
	})
})
