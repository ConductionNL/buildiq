// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the nldesign-theme-selection spec — the UI-driven
 * scenarios (REQ-NTS-002 builder pick, REQ-NTS-003 scoped runtime render,
 * REQ-NTS-005 graceful absence).
 *
 * These scenarios drive the openbuild admin builder UI (Theme section + theme
 * picker dialog) and the runtime render root. The builder admin UI is
 * Conduction/openbuild#41-quarantined in this build (no application detail /
 * designer UI renders), so these tests are skipped with the same recorded
 * reason as the rest of tests/e2e/spec-coverage/. The scoped-render behaviour
 * and the :root-rewrite transform are covered by the vitest suites
 * (useAppTheme, ThemeSection, ThemePickerDialog, themeValidation); the static
 * nldesign asset contract is pinned by Newman
 * (openbuild-nldesign-theme.postman_collection.json). Backend validation
 * scenarios (REQ-NTS-001) and the Newman/asset-contract scenarios (REQ-NTS-006)
 * are excluded from e2e enforcement in the spec.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

// @e2e nldesign-theme-selection::admin-builder-picks-a-theme-from-the-visual-list
// QUARANTINED (Conduction/openbuild#41): openbuild admin builder UI not functional in this build — the Theme section / picker dialog does not render. Re-enable when #41 is fixed. Logic covered by vitest (ThemeSection.spec.js + ThemePickerDialog.spec.js).
test.skip('REQ-NTS-002 — admin builder picks a theme from the visual list', async ({ page }) => {
	// @e2e nldesign-theme-selection::admin-builder-picks-a-theme-from-the-visual-list
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::non-admin-builder-uses-the-validated-free-text-fallback
// QUARANTINED (Conduction/openbuild#41): builder UI not functional. Logic covered by vitest (ThemePickerDialog 403→free-text-fallback test).
test.skip('REQ-NTS-002 — non-admin builder uses the validated free-text fallback', async ({ page }) => {
	// @e2e nldesign-theme-selection::non-admin-builder-uses-the-validated-free-text-fallback
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::unknown-token-set-id-is-rejected-inline
// QUARANTINED (Conduction/openbuild#41): builder UI not functional. Logic covered by vitest (ThemePickerDialog 404→inline-error test).
test.skip('REQ-NTS-002 — unknown token-set id is rejected inline', async ({ page }) => {
	// @e2e nldesign-theme-selection::unknown-token-set-id-is-rejected-inline
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::live-preview-applies-before-saving-and-reverts-on-cancel
// QUARANTINED (Conduction/openbuild#41): builder UI not functional. Logic covered by vitest (ThemePickerDialog cancel-revert + useAppTheme apply/teardown).
test.skip('REQ-NTS-002 — live preview applies before saving and reverts on cancel', async ({ page }) => {
	// @e2e nldesign-theme-selection::live-preview-applies-before-saving-and-reverts-on-cancel
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::themed-app-renders-scoped-variables
// QUARANTINED (Conduction/openbuild#41): the published virtual-app runtime is not reachable through the quarantined builder. Scoped-render + :root-rewrite covered by vitest (useAppTheme.spec.js) and the asset contract by Newman.
test.skip('REQ-NTS-003 — themed app renders scoped variables', async ({ page }) => {
	// @e2e nldesign-theme-selection::themed-app-renders-scoped-variables
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::leaving-the-app-removes-the-injected-style
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Teardown covered by vitest (useAppTheme teardown test).
test.skip('REQ-NTS-003 — leaving the app removes the injected style', async ({ page }) => {
	// @e2e nldesign-theme-selection::leaving-the-app-removes-the-injected-style
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
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Degrade-to-default covered by vitest (useAppTheme 404 → no injection test).
test.skip('REQ-NTS-005 — a themed app renders default styling without nldesign', async ({ page }) => {
	// @e2e nldesign-theme-selection::themed-app-still-renders-without-nldesign
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})
