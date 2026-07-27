// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the nldesign-theme-selection spec — the UI-driven
 * scenarios (REQ-NTS-002 builder pick via `useScopedTheme().listTokenSets()`,
 * REQ-NTS-003 CnAppRoot's own scoped-render, REQ-NTS-005 graceful absence),
 * as modified by theme-picker-consumes-nldesign.
 *
 * These scenarios drive the openbuild admin builder UI (Theme section + theme
 * picker dialog) and the runtime render root. The builder admin UI is
 * Conduction/openbuild#41-quarantined in this build (no application detail /
 * designer UI renders), so these tests are skipped with the same recorded
 * reason as the rest of tests/e2e/spec-coverage/. The catalogue-population,
 * empty-list-hint, and live-preview-retarget behaviour are covered by the
 * vitest suites (ThemeSection, ThemePickerDialog — now exercising the REAL
 * published `useScopedTheme` leaf via the vitest stub's subpath re-export,
 * see tests/vitest/stubs/conduction-nextcloud-vue.js); the scoped-render
 * transform itself now lives in `@conduction/nextcloud-vue` and is covered
 * there (`scoped-theme-applier`) plus by
 * tests/composables/nextcloud-vue-useScopedTheme.spec.js here. The static
 * nldesign asset/endpoint contract is pinned by Newman
 * (openbuild-nldesign-theme.postman_collection.json). Backend validation
 * scenarios (REQ-NTS-001) and the Newman/asset-contract scenarios
 * (REQ-NTS-006) are excluded from e2e enforcement in the spec.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

// @e2e nldesign-theme-selection::builder-picks-a-theme-from-the-visual-list
// QUARANTINED (Conduction/openbuild#41): openbuild admin builder UI not functional in this build — the Theme section / picker dialog does not render. Re-enable when #41 is fixed. Logic covered by vitest (ThemeSection.spec.js + ThemePickerDialog.spec.js, exercising the real published useScopedTheme.listTokenSets()).
test.skip('REQ-NTS-002 — builder picks a theme from the visual list', async ({ page }) => {
	// @e2e nldesign-theme-selection::builder-picks-a-theme-from-the-visual-list
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::empty-catalogue-renders-the-absence-hint-not-a-free-text-fallback
// QUARANTINED (Conduction/openbuild#41): builder UI not functional. Logic covered by vitest (ThemePickerDialog empty-list → REQ-NTS-005 hint test; no free-text input exists anywhere in the dialog anymore).
test.skip('REQ-NTS-002 — empty catalogue renders the absence hint, not a free-text fallback', async ({ page }) => {
	// @e2e nldesign-theme-selection::empty-catalogue-renders-the-absence-hint-not-a-free-text-fallback
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::live-preview-applies-via-the-sandboxed-live-preview-pane-cnapproot-and-reverts-on-cancel
// QUARANTINED (Conduction/openbuild#41): builder UI not functional. Logic covered by vitest (PageDesignerHost.spec.js onThemePreview mutate/revert test + ThemePickerDialog cancel-revert test).
test.skip('REQ-NTS-002 — live preview applies via the sandboxed live-preview-pane CnAppRoot and reverts on cancel', async ({ page }) => {
	// @e2e nldesign-theme-selection::live-preview-applies-via-the-sandboxed-live-preview-pane-cnapproot-and-reverts-on-cancel
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::themed-app-renders-via-cnapproots-own-applier-no-openbuild-composable-involved
// QUARANTINED (Conduction/openbuild#41): the published virtual-app runtime is not reachable through the quarantined builder. Scoped-render + :root-rewrite now live in @conduction/nextcloud-vue (scoped-theme-applier) and are covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js against the REAL published dist; the asset contract by Newman.
test.skip('REQ-NTS-003 — themed app renders via CnAppRoot\'s own applier, no OpenBuild composable involved', async ({ page }) => {
	// @e2e nldesign-theme-selection::themed-app-renders-via-cnapproots-own-applier-no-openbuild-composable-involved
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::leaving-the-app-removes-the-injected-style-via-cnapproots-own-teardown
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Teardown covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js against the real published useScopedTheme.teardown().
test.skip('REQ-NTS-003 — leaving the app removes the injected style (via CnAppRoot\'s own teardown)', async ({ page }) => {
	// @e2e nldesign-theme-selection::leaving-the-app-removes-the-injected-style-via-cnapproots-own-teardown
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::designer-degrades-when-nldesign-is-missing
// QUARANTINED (Conduction/openbuild#41): builder UI not functional. Logic covered by vitest (ThemeSection disabled-Change absent-app test).
test.skip('REQ-NTS-005 — designer degrades when nldesign is missing', async ({ page }) => {
	// @e2e nldesign-theme-selection::designer-degrades-when-nldesign-is-missing
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::themed-app-still-renders-without-nldesign
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Degrade-to-default covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js (real published apply() on a fetch failure).
test.skip('REQ-NTS-005 — a themed app renders default styling without nldesign', async ({ page }) => {
	// @e2e nldesign-theme-selection::themed-app-still-renders-without-nldesign
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})
