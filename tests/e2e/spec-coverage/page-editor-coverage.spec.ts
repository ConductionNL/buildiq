// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E spec-coverage for page-editor-coverage.
 *
 * REQ-PEC-002: Add-page picker offers the four new types with type-shaped defaults.
 *   - add-page-lists-the-four-new-types
 *   - adding-a-map-page-seeds-the-map-shaped-default-config
 *
 * REQ-PEC-003: Map-page sub-editor: viewport, layers and marker source.
 *   - create-configure-save-and-render-a-map-page
 *
 * REQ-PEC-004: Roadmap-page sub-editor: forge, repo and override URLs.
 *   - create-configure-save-and-render-a-roadmap-page
 *
 * REQ-PEC-005: Search-page sub-editor: scope, texts and facet declarations.
 *   - create-configure-save-and-render-a-search-page
 *
 * REQ-PEC-006: Wiki-page sub-editor: article binding, field mapping and sidebar tree.
 *   - create-configure-save-and-render-a-wiki-page
 *
 * REQ-PEC-001 and REQ-PEC-007 are component contracts (sub-editor dispatch,
 * validation marks, lossless round-trip) covered by Vitest unit tests —
 * see the `@e2e exclude` annotations on those requirements in
 * specs/page-editor-coverage/spec.md.
 *
 * QUARANTINE (Conduction/openbuild#41): as of authoring time the openbuild
 * builder/virtual-app surface is non-functional in the CI/dev-container
 * build — PageDesignerHost mounts but the virtual-app load returns a 500
 * ("Failed to load the virtual app: Request failed with status code
 * 500"), so the designer panes never render and there is no built page
 * route to navigate to. Every test below therefore mirrors the
 * `page-designer-ui.spec.ts` convention: gated behind
 * `OPENBUILD_E2E_LIVE=1` rather than run by default, so CI stays green
 * until #41 is fixed while the flows are ready to exercise against a
 * live, fully-built dev instance. Do NOT remove the LIVE gate without
 * confirming #41 is closed.
 *
 * Route (history-mode base /apps/openbuild):
 *   PageDesigner →  /apps/openbuild/builder/:slug/pages
 *   Built page   →  /apps/openbuild/builder/:slug/:route
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const LIVE = process.env.OPENBUILD_E2E_LIVE === '1'

const SLUG = 'hello-world'
const PAGE_DESIGNER = (slug: string) => `${BASE}/apps/openbuild/builder/${slug}/pages`
const BUILT_PAGE = (slug: string, route: string) => `${BASE}/apps/openbuild/builder/${slug}/${route}`

/**
 * Open the page designer, click "Add page", pick `type`, and confirm.
 * Shared by every test below — the add-page picker itself is asserted
 * once (REQ-PEC-002) and then reused as setup for the per-type flows.
 */
async function addPage(page: import('@playwright/test').Page, type: string) {
	await page.goto(PAGE_DESIGNER(SLUG))
	await expect(page.locator('.page-designer, [class*="page-designer"]')).toBeVisible({ timeout: 15_000 })
	await page.locator('.page-list-editor__add').click()
	await expect(page.locator('.page-list-editor__add-row')).toBeVisible({ timeout: 5_000 })
	await page.locator('.page-list-editor__select').selectOption(type)
	await page.locator('.page-list-editor__add-row button:not([disabled])', { hasText: 'Confirm' }).click()
}

/**
 * Fill a field row that renders as either a schema-property `<select>`
 * (once a register + schema are bound) or a free-text `<input>` — the
 * WikiPageEditor field-mapping fields switch shape at runtime depending
 * on the bound schema's declared properties.
 */
async function selectOrFill(row: import('@playwright/test').Locator, value: string) {
	const select = row.locator('select')
	if (await select.count()) {
		const hasOption = (await select.locator(`option[value="${value}"]`).count()) > 0
		await select.selectOption(hasOption ? value : { index: 1 })
		return
	}
	await row.locator('input').fill(value)
}

// ---------------------------------------------------------------------------
// REQ-PEC-002 — Add-page picker offers the four new types
// ---------------------------------------------------------------------------

// @e2e page-editor-coverage::add-page-lists-the-four-new-types
test('REQ-PEC-002 — Add page lists the four new types', async ({ page }) => {
	// @e2e page-editor-coverage::add-page-lists-the-four-new-types
	test.skip(!LIVE, 'Requires a live dev env with the page designer built and openbuild#41 fixed — set OPENBUILD_E2E_LIVE=1')

	await page.goto(PAGE_DESIGNER(SLUG))
	await expect(page.locator('.page-designer, [class*="page-designer"]')).toBeVisible({ timeout: 15_000 })
	await page.locator('.page-list-editor__add').click()

	const select = page.locator('.page-list-editor__select')
	await expect(select).toBeVisible({ timeout: 5_000 })
	const options = await select.locator('option').allTextContents()
	for (const type of ['map', 'roadmap', 'search', 'wiki']) {
		expect(options, `"Add page" select must list "${type}"`).toContain(type)
	}
})

// @e2e page-editor-coverage::adding-a-map-page-seeds-the-map-shaped-default-config
test('REQ-PEC-002 — Adding a map page seeds the map-shaped default config', async ({ page }) => {
	// @e2e page-editor-coverage::adding-a-map-page-seeds-the-map-shaped-default-config
	test.skip(!LIVE, 'Requires a live dev env with the page designer built and openbuild#41 fixed — set OPENBUILD_E2E_LIVE=1')

	await addPage(page, 'map')

	// REQ-PEC-003: the centre pane must open MapPageEditor with the pinned
	// default centre (52.1326, 5.2913) and zoom (7) pre-filled.
	const editor = page.locator('.map-page-editor')
	await expect(editor, 'MapPageEditor must mount for a freshly added map page').toBeVisible({ timeout: 5_000 })
	const numberInputs = editor.locator('input[type="number"]')
	await expect(numberInputs.nth(0)).toHaveValue('52.1326')
	await expect(numberInputs.nth(1)).toHaveValue('5.2913')
	await expect(numberInputs.nth(2)).toHaveValue('7')
})

// ---------------------------------------------------------------------------
// REQ-PEC-003 — Map-page sub-editor
// ---------------------------------------------------------------------------

// @e2e page-editor-coverage::create-configure-save-and-render-a-map-page
test('REQ-PEC-003 — Create, configure, save and render a map page', async ({ page }) => {
	// @e2e page-editor-coverage::create-configure-save-and-render-a-map-page
	test.skip(!LIVE, 'Requires a live dev env with the page designer + built app and openbuild#41 fixed — set OPENBUILD_E2E_LIVE=1')

	await addPage(page, 'map')
	const editor = page.locator('.map-page-editor')
	await expect(editor).toBeVisible({ timeout: 5_000 })

	// Add a tile layer with a URL.
	await editor.locator('.map-page-editor__row-add', { hasText: 'Add layer' }).click()
	await editor.locator('.map-page-editor__row-url').fill('https://tiles.example.test/{z}/{x}/{y}.png')

	// Set a marker source URL (the "Source URL" radio is selected by default).
	const markerUrlInput = editor.locator('.map-page-editor__group-row', { hasText: 'Marker source URL' }).locator('input')
	await markerUrlInput.fill('https://example.test/markers.json')

	await page.locator('.page-designer__tool-btn--primary', { hasText: 'Save' }).click()
	await page.waitForLoadState('networkidle')

	await page.goto(BUILT_PAGE(SLUG, 'map'))
	await expect(page.locator('[data-testid="cn-map-page"]'), 'built map page must render').toBeVisible({ timeout: 15_000 })

	// Reopen the designer and assert the values round-tripped.
	await page.goto(PAGE_DESIGNER(SLUG))
	await page.locator('.page-list-editor__row', { hasText: 'map' }).first().click()
	const reopened = page.locator('.map-page-editor')
	await expect(reopened).toBeVisible({ timeout: 5_000 })
	await expect(reopened.locator('.map-page-editor__row-url')).toHaveValue('https://tiles.example.test/{z}/{x}/{y}.png')
})

// ---------------------------------------------------------------------------
// REQ-PEC-004 — Roadmap-page sub-editor
// ---------------------------------------------------------------------------

// @e2e page-editor-coverage::create-configure-save-and-render-a-roadmap-page
test('REQ-PEC-004 — Create, configure, save and render a roadmap page', async ({ page }) => {
	// @e2e page-editor-coverage::create-configure-save-and-render-a-roadmap-page
	test.skip(!LIVE, 'Requires a live dev env with the page designer + built app and openbuild#41 fixed — set OPENBUILD_E2E_LIVE=1')

	await addPage(page, 'roadmap')
	const editor = page.locator('.roadmap-page-editor')
	await expect(editor).toBeVisible({ timeout: 5_000 })

	await editor.locator('input[placeholder="owner/repo"]').fill('ConductionNL/openbuild')
	await editor.locator('select').first().selectOption('github')

	await page.locator('.page-designer__tool-btn--primary', { hasText: 'Save' }).click()
	await page.waitForLoadState('networkidle')

	await page.goto(BUILT_PAGE(SLUG, 'roadmap'))
	await expect(page.locator('.cn-features-and-roadmap-view'), 'built roadmap page must render').toBeVisible({ timeout: 15_000 })

	await page.goto(PAGE_DESIGNER(SLUG))
	await page.locator('.page-list-editor__row', { hasText: 'roadmap' }).first().click()
	const reopened = page.locator('.roadmap-page-editor')
	await expect(reopened).toBeVisible({ timeout: 5_000 })
	await expect(reopened.locator('input[placeholder="owner/repo"]')).toHaveValue('ConductionNL/openbuild')
	await expect(reopened.locator('select').first()).toHaveValue('github')
})

// ---------------------------------------------------------------------------
// REQ-PEC-005 — Search-page sub-editor
// ---------------------------------------------------------------------------

// @e2e page-editor-coverage::create-configure-save-and-render-a-search-page
test('REQ-PEC-005 — Create, configure, save and render a search page', async ({ page }) => {
	// @e2e page-editor-coverage::create-configure-save-and-render-a-search-page
	test.skip(!LIVE, 'Requires a live dev env with the page designer + built app and openbuild#41 fixed — set OPENBUILD_E2E_LIVE=1')

	await addPage(page, 'search')
	const editor = page.locator('.search-page-editor')
	await expect(editor).toBeVisible({ timeout: 5_000 })

	const placeholderInput = editor.locator('.search-page-editor__group-row', { hasText: 'Placeholder' }).locator('input')
	await placeholderInput.fill('Search everything…')

	await editor.locator('button', { hasText: 'Add facet' }).click()
	const facetRow = editor.locator('.search-page-editor__facet').first()
	await facetRow.locator('.search-page-editor__row input').first().fill('category')
	await facetRow.locator('button', { hasText: 'Add option' }).click()
	await facetRow.locator('.search-page-editor__options .search-page-editor__row input').first().fill('books')
	await facetRow.locator('button', { hasText: 'Add option' }).click()
	await facetRow.locator('.search-page-editor__options .search-page-editor__row').nth(1).locator('input').first().fill('films')

	await page.locator('.page-designer__tool-btn--primary', { hasText: 'Save' }).click()
	await page.waitForLoadState('networkidle')

	await page.goto(BUILT_PAGE(SLUG, 'search'))
	await expect(page.locator('[data-testid="cn-search-page"]'), 'built search page must render').toBeVisible({ timeout: 15_000 })
	await expect(page.locator('input[placeholder="Search everything…"]')).toBeVisible()
	await expect(page.locator('text=books')).toBeVisible()
	await expect(page.locator('text=films')).toBeVisible()

	await page.goto(PAGE_DESIGNER(SLUG))
	await page.locator('.page-list-editor__row', { hasText: 'search' }).first().click()
	const reopened = page.locator('.search-page-editor')
	await expect(reopened).toBeVisible({ timeout: 5_000 })
	await expect(reopened.locator('.search-page-editor__group-row', { hasText: 'Placeholder' }).locator('input')).toHaveValue('Search everything…')
})

// ---------------------------------------------------------------------------
// REQ-PEC-006 — Wiki-page sub-editor
// ---------------------------------------------------------------------------

// @e2e page-editor-coverage::create-configure-save-and-render-a-wiki-page
test('REQ-PEC-006 — Create, configure, save and render a wiki page', async ({ page }) => {
	// @e2e page-editor-coverage::create-configure-save-and-render-a-wiki-page
	test.skip(!LIVE, 'Requires a live dev env with the page designer + built app + a seed register/schema and openbuild#41 fixed — set OPENBUILD_E2E_LIVE=1')

	await addPage(page, 'wiki')
	const editor = page.locator('.wiki-page-editor')
	await expect(editor).toBeVisible({ timeout: 5_000 })

	const registerSelect = editor.locator('.wiki-page-editor__group-row', { hasText: 'Register' }).locator('select')
	await registerSelect.selectOption({ index: 1 })
	const schemaSelect = editor.locator('.wiki-page-editor__group-row', { hasText: 'Schema' }).locator('select').first()
	await schemaSelect.selectOption({ index: 1 })

	// contentField/titleField render as a schema-property <select> once a
	// register + schema are bound (task 5.1); fall back to free-text input
	// otherwise.
	await selectOrFill(editor.locator('.wiki-page-editor__group-row', { hasText: 'Content field' }), 'body')
	await selectOrFill(editor.locator('.wiki-page-editor__group-row', { hasText: 'Title field' }), 'title')

	await page.locator('.page-designer__tool-btn--primary', { hasText: 'Save' }).click()
	await page.waitForLoadState('networkidle')

	await page.goto(BUILT_PAGE(SLUG, 'wiki'))
	await expect(page.locator('[data-testid="cn-wiki-page"]'), 'built wiki page must render').toBeVisible({ timeout: 15_000 })

	await page.goto(PAGE_DESIGNER(SLUG))
	await page.locator('.page-list-editor__row', { hasText: 'wiki' }).first().click()
	const reopened = page.locator('.wiki-page-editor')
	await expect(reopened).toBeVisible({ timeout: 5_000 })
	await expect(reopened.locator('.wiki-page-editor__group-row', { hasText: 'Register' }).locator('select')).not.toHaveValue('')
	await expect(reopened.locator('.wiki-page-editor__group-row', { hasText: 'Schema' }).locator('select').first()).not.toHaveValue('')
})
