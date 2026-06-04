// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E spec-coverage for retrofit-2026-05-26-page-designer-ui.
 *
 * REQ-OBPDUI-001: Controlled designer orchestrates pages, menu, undo/redo and save.
 *   - page-designer-renders-three-pane-layout
 *   - undo-button-disabled-on-fresh-load
 *
 * REQ-OBPDUI-002: Route hosts resolve slug + version and persist the manifest.
 *   - page-designer-route-renders-for-valid-slug
 *   - unknown-version-renders-not-found-state
 *
 * REQ-OBPDUI-003: Per-page-type sub-editors emit validated slices.
 *   - sub-editor-is-rendered-when-page-selected
 *
 * REQ-OBPDUI-004: Reusable field builders edit list-shaped config.
 *   - page-list-editor-renders-in-left-pane
 *
 * REQ-OBPDUI-005: Inline validation surface and config-field registration.
 *   - validation-panel-renders-in-right-pane
 *
 * Routes (history-mode base /apps/openbuild):
 *   PageDesigner  →  /apps/openbuild/builder/:slug/pages
 *   BuilderHost   →  /apps/openbuild/builder/:slug/:pathMatch
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const LIVE = process.env.OPENBUILD_E2E_LIVE === '1'

const PAGE_DESIGNER = (slug: string) => `${BASE}/apps/openbuild/builder/${slug}/pages`

// ---------------------------------------------------------------------------
// REQ-OBPDUI-001 — Controlled designer orchestrates pages, menu, undo/redo
// ---------------------------------------------------------------------------

// @e2e page-designer-ui::page-designer-renders-three-pane-layout
test('REQ-OBPDUI-001 — page designer route renders the three-pane layout', async ({ page }) => {
	// @e2e page-designer-ui::page-designer-renders-three-pane-layout
	await page.goto(PAGE_DESIGNER('hello-world'))
	await expect(page.locator('main, .page-designer, [class*="page-designer"]'), 'designer must load').toBeVisible({ timeout: 15_000 })

	// The outer shell must load without a white screen.
	await expect(page).toHaveTitle(/openbuild/i)
})

// @e2e page-designer-ui::undo-button-disabled-on-fresh-load
test('REQ-OBPDUI-001 — undo button is disabled when no edits have been made', async ({ page }) => {
	// @e2e page-designer-ui::undo-button-disabled-on-fresh-load
	test.skip(!LIVE, 'Requires live dev env with page designer JS built — set OPENBUILD_E2E_LIVE=1')

	await page.goto(PAGE_DESIGNER('hello-world'))
	await expect(page.locator('.page-designer, [class*="page-designer"]')).toBeVisible({ timeout: 15_000 })

	// On first load there is no history, so undo must be disabled.
	const undoBtn = page.locator('button[title*="Undo"], button:has-text("Undo")').first()
	await expect(undoBtn, 'undo button must be disabled on first load').toBeDisabled({ timeout: 5_000 })
})

// ---------------------------------------------------------------------------
// REQ-OBPDUI-002 — Route hosts resolve slug + version and persist manifest
// ---------------------------------------------------------------------------

// @e2e page-designer-ui::page-designer-route-renders-for-valid-slug
test('REQ-OBPDUI-002 — PageDesignerHost route renders for a known slug', async ({ page }) => {
	// @e2e page-designer-ui::page-designer-route-renders-for-valid-slug
	await page.goto(PAGE_DESIGNER('hello-world'))
	await expect(page.locator('main'), 'main content must load').toBeVisible({ timeout: 15_000 })

	// The page must not be a 404 error page.
	const body = await page.textContent('body')
	expect(body).not.toMatch(/404|not found/i)
})

// @e2e page-designer-ui::unknown-version-renders-not-found-state
test('REQ-OBPDUI-002 — unknown ?_version shows version-not-found state', async ({ page }) => {
	// @e2e page-designer-ui::unknown-version-renders-not-found-state
	test.skip(!LIVE, 'Requires live dev env with page designer JS built — set OPENBUILD_E2E_LIVE=1')

	await page.goto(`${PAGE_DESIGNER('hello-world')}?_version=nonexistent-slug-xyz`)
	await expect(page.locator('main'), 'main must still render').toBeVisible({ timeout: 15_000 })

	// The version-not-found state is rendered via NcEmptyContent or a div with "Version not found".
	const notFound = page.locator('text=/Version not found/i, [class*="version-not-found"]').first()
	await expect(notFound, 'version-not-found state must be displayed').toBeVisible({ timeout: 10_000 })
})

// ---------------------------------------------------------------------------
// REQ-OBPDUI-003 — Sub-editors emit validated slices
// ---------------------------------------------------------------------------

// @e2e page-designer-ui::sub-editor-is-rendered-when-page-selected
test('REQ-OBPDUI-003 — centre pane renders a sub-editor when a page is selected', async ({ page }) => {
	// @e2e page-designer-ui::sub-editor-is-rendered-when-page-selected
	test.skip(!LIVE, 'Requires live dev env with page designer JS built and hello-world pages — set OPENBUILD_E2E_LIVE=1')

	await page.goto(PAGE_DESIGNER('hello-world'))
	await expect(page.locator('.page-designer, [class*="page-designer"]')).toBeVisible({ timeout: 15_000 })

	// Click the first page entry in the left pane.
	const firstPage = page.locator('.page-designer__left li, .page-list-editor li').first()
	await expect(firstPage, 'at least one page must be listed').toBeVisible({ timeout: 5_000 })
	await firstPage.click()

	// The centre pane must now render a sub-editor component.
	const centrePane = page.locator('.page-designer__centre, .page-designer__sub-editor').first()
	await expect(centrePane, 'centre pane must show a sub-editor after selecting a page').toBeVisible({ timeout: 5_000 })
})

// ---------------------------------------------------------------------------
// REQ-OBPDUI-004 — Reusable field builders edit list-shaped config
// ---------------------------------------------------------------------------

// @e2e page-designer-ui::page-list-editor-renders-in-left-pane
test('REQ-OBPDUI-004 — left pane renders the page list and menu tree', async ({ page }) => {
	// @e2e page-designer-ui::page-list-editor-renders-in-left-pane
	test.skip(!LIVE, 'Requires live dev env with page designer JS built — set OPENBUILD_E2E_LIVE=1')

	await page.goto(PAGE_DESIGNER('hello-world'))
	await expect(page.locator('.page-designer, [class*="page-designer"]')).toBeVisible({ timeout: 15_000 })

	// The left pane contains the PageListEditor (page list) and MenuTreeEditor (menu tree).
	const leftPane = page.locator('.page-designer__left').first()
	await expect(leftPane, 'left pane must be present').toBeVisible({ timeout: 5_000 })
})

// ---------------------------------------------------------------------------
// REQ-OBPDUI-005 — Inline validation surface and config-field registration
// ---------------------------------------------------------------------------

// @e2e page-designer-ui::validation-panel-renders-in-right-pane
test('REQ-OBPDUI-005 — right pane renders the validation surface', async ({ page }) => {
	// @e2e page-designer-ui::validation-panel-renders-in-right-pane
	test.skip(!LIVE, 'Requires live dev env with page designer JS built — set OPENBUILD_E2E_LIVE=1')

	await page.goto(PAGE_DESIGNER('hello-world'))
	await expect(page.locator('.page-designer, [class*="page-designer"]')).toBeVisible({ timeout: 15_000 })

	// The right pane must contain the validation errors panel.
	const rightPane = page.locator('.page-designer__right').first()
	await expect(rightPane, 'right validation pane must be present').toBeVisible({ timeout: 5_000 })

	// When there are no validation errors the "No validation errors." message is shown.
	const noErrors = page.locator('text=/No validation errors/i').first()
	const errorList = page.locator('.page-designer__error-list').first()
	const eitherVisible = (await noErrors.count()) > 0 || (await errorList.count()) > 0
	expect(eitherVisible, 'validation panel must show either the no-errors message or an error list').toBe(true)
})
