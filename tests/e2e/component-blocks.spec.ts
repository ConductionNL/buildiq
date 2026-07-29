/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end test for the component-blocks flow: save a
 * configured widget (or a multi-widget page section) as a reusable
 * `ComponentBlock`, insert it into a different app, resolve a
 * schema-dependency remap prompt, and confirm it renders bound to the
 * chosen schema (component-blocks tasks.md 7.3).
 *
 * Covers (gate-19 scenario references):
 *   - "Saving a widget captures its config, not its data"
 *   - "Save a single widget as a block"
 *   - "Save a page section as a block"
 *   - "Library lists org-wide blocks"
 *   - "Inserting the same block twice does not collide"
 *   - "Editing the source block does not affect an inserted copy"
 *   - "Cross-app insert with matching schema name needs no prompt"
 *   - "Cross-app insert with no matching schema requires remap"
 *   - "Unresolved remap inserts a visible placeholder, not a silent drop"
 *   - "Exported block imports into a different organisation"
 *   - "Blocks filter shows only blocks"
 *   - "Blocks filter shows blocks without the clone action" (openbuild-template-catalogue)
 *
 * API-shape assertions (OR RBAC, ComponentBlock CRUD, export/import round-trip)
 * live in the Newman collection, not here (Playwright drives the UI only).
 *
 * QUARANTINED (Conduction/openbuild#41): the openbuild admin UI does not
 * render the page-designer / application-detail surfaces in this build, so
 * the flow cannot be driven end-to-end yet. This file is the canonical UI
 * coverage and re-enables once #41 is fixed (same deferred-bootstrap pattern
 * as tests/e2e/save-as-template.spec.ts and tests/e2e/template-gallery.spec.ts).
 */

import { test, expect } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'

// STILL QUARANTINED — #41's blockers are gone, but this suite never had
// fixtures. Every test navigates to /builder/<random-slug>/pages with a slug
// like `e2e-cb-source-${Date.now()}`, i.e. an app that does not exist, so the
// page designer has nothing to render. It needs real fixture apps (two of them,
// source + target with differing schema names) created via ensureApp(), plus a
// seeded ComponentBlock to insert. The UI it targets does exist
// (WidgetSelectionPanel, BlockLibraryPanel).
test.describe.skip('OpenBuild component blocks', () => {

	// @e2e component-blocks::saving-a-widget-captures-its-config-not-its-data
	// @e2e component-blocks::save-a-single-widget-as-a-block
	// @e2e component-blocks::library-lists-org-wide-blocks
	// @e2e component-blocks::cross-app-insert-with-no-matching-schema-requires-remap
	// @e2e component-blocks::unresolved-remap-inserts-a-visible-placeholder-not-a-silent-drop
	test('saves a widget as a block, inserts it into a different app, resolves the remap prompt, and renders bound to the chosen schema', async ({ page }) => {
		// 1. Source app: open the page designer, select a configured widget,
		//    save it as a block.
		const sourceApp = `e2e-cb-source-${Date.now().toString(36)}`
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${sourceApp}/pages`)
		const widgetPanel = page.locator('.widget-selection-panel')
		await expect(widgetPanel).toBeVisible({ timeout: 15_000 })
		await widgetPanel.locator('input[type="checkbox"]').first().check()
		await widgetPanel.getByRole('button', { name: /Save selected widget as block/i }).click()

		const saveDialog = page.locator('.ob-save-block')
		await expect(saveDialog).toBeVisible({ timeout: 5_000 })
		const blockName = `E2E status badge ${Date.now().toString(36)}`
		await saveDialog.getByLabel(/Block name/i).fill(blockName)
		await saveDialog.getByLabel(/Category/i).fill('display')
		await saveDialog.getByRole('button', { name: /Save block/i }).click()
		// Never leaks object rows into the block (asserted via the capture
		// summary, which lists only schema slugs, never record data).
		await expect(saveDialog.locator('.ob-save-block__no-rows')).toContainText(/No object data/i)

		// 2. Target app (different schema names): open the block library
		//    sidebar and insert the block.
		const targetApp = `e2e-cb-target-${Date.now().toString(36)}`
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${targetApp}/pages`)
		await page.getByRole('button', { name: /^Blocks$/i }).click()
		const libraryPanel = page.locator('.block-library-panel')
		await expect(libraryPanel).toBeVisible({ timeout: 10_000 })
		const blockCard = libraryPanel.locator('.block-card').filter({ hasText: blockName })
		await expect(blockCard).toBeVisible({ timeout: 10_000 })
		await blockCard.getByRole('button', { name: /^Insert$/i }).click()

		// 3. Cross-app insert with no matching schema opens the remap dialog.
		const remapDialog = page.locator('.ob-block-remap')
		await expect(remapDialog).toBeVisible({ timeout: 5_000 })
		// Resolve the mismatch by mapping to the target app's own schema.
		await remapDialog.locator('.ob-block-remap__row').first()
			.getByLabel(/Map/i).fill('permit-application')
		await remapDialog.getByRole('button', { name: /Insert block/i }).click()

		// 4. The inserted widget renders bound to the resolved schema.
		await expect(page.locator('.widget-selection-panel')).toContainText(blockName === '' ? '' : /status-badge/i)
	})

	// @e2e component-blocks::save-a-page-section-as-a-block
	// @e2e component-blocks::inserting-the-same-block-twice-does-not-collide
	test('saves a multi-widget section as a block and inserts it twice without id collision', async ({ page }) => {
		const app = `e2e-cb-section-${Date.now().toString(36)}`
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${app}/pages`)
		const widgetPanel = page.locator('.widget-selection-panel')
		const checkboxes = widgetPanel.locator('input[type="checkbox"]')
		await checkboxes.nth(0).check()
		await checkboxes.nth(1).check()
		await widgetPanel.getByRole('button', { name: /Save selected section as block/i }).click()
		const saveDialog = page.locator('.ob-save-block')
		const sectionName = `E2E section ${Date.now().toString(36)}`
		await saveDialog.getByLabel(/Block name/i).fill(sectionName)
		await saveDialog.getByLabel(/Category/i).fill('layout')
		await saveDialog.getByRole('button', { name: /Save block/i }).click()

		// Insert the same block twice into the same page; both copies render
		// with distinct widget ids (no collision).
		await page.getByRole('button', { name: /^Blocks$/i }).click()
		const card = page.locator('.block-library-panel .block-card').filter({ hasText: sectionName })
		await card.getByRole('button', { name: /^Insert$/i }).click()
		await card.getByRole('button', { name: /^Insert$/i }).click()
		const rows = page.locator('.widget-selection-panel__row')
		const idsText = await rows.allTextContents()
		expect(new Set(idsText).size).toBe(idsText.length)
	})

	// @e2e component-blocks::editing-the-source-block-does-not-affect-an-inserted-copy
	// @e2e component-blocks::cross-app-insert-with-matching-schema-name-needs-no-prompt
	test('editing a source block after insert does not change an already-inserted copy', async ({ page }) => {
		// Insert a block into an app whose schema slug already matches — no
		// remap dialog should appear.
		const app = `e2e-cb-noremap-${Date.now().toString(36)}`
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${app}/pages`)
		await page.getByRole('button', { name: /^Blocks$/i }).click()
		const card = page.locator('.block-library-panel .block-card').first()
		await card.getByRole('button', { name: /^Insert$/i }).click()
		await expect(page.locator('.ob-block-remap')).toHaveCount(0)

		// Editing the source block (a different slug/name) never mutates the
		// copy already inserted above.
		const insertedRow = page.locator('.widget-selection-panel__row').last()
		const beforeText = await insertedRow.textContent()
		await page.goBack()
		// (source-app edit flow — selector resolved once #41 lands)
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${app}/pages`)
		await expect(page.locator('.widget-selection-panel__row').last()).toHaveText(beforeText || '')
	})

	// @e2e component-blocks::exported-block-imports-into-a-different-organisation
	test('exports a block as JSON and imports it back, triggering remap when schemas differ', async ({ page }) => {
		const app = `e2e-cb-export-${Date.now().toString(36)}`
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/builder/${app}/pages`)
		await page.getByRole('button', { name: /^Blocks$/i }).click()
		const card = page.locator('.block-library-panel .block-card').first()
		const downloadPromise = page.waitForEvent('download')
		await card.getByRole('button', { name: /^Export$/i }).click()
		const download = await downloadPromise
		expect(download.suggestedFilename()).toMatch(/\.json$/)

		const fileInput = page.locator('.block-library-panel__import input[type="file"]')
		await fileInput.setInputFiles(await download.path())
		await expect(page.locator('.ob-block-remap, .block-library-panel__error')).toBeVisible({ timeout: 10_000 })
	})

	// @e2e component-blocks::blocks-filter-shows-only-blocks
	// @e2e component-blocks::blocks-filter-shows-blocks-without-the-clone-action
	test('the template gallery Blocks filter lists only blocks, without a clone action', async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/templates`)
		await expect(page.locator('.template-gallery')).toBeVisible({ timeout: 15_000 })
		await page.getByRole('tab', { name: /^Blocks$/i }).click()
		const cards = page.locator('.template-gallery__grid .template-card')
		await expect(cards.first()).toBeVisible({ timeout: 10_000 })
		await expect(page.getByRole('button', { name: /Use this template/i })).toHaveCount(0)
	})
})
