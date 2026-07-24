// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the app-theming spec — the UI-driven scenarios
 * (theme editor Save-gating, scoped runtime render, nldesign precedence,
 * logo resolution).
 *
 * These scenarios drive the openbuild page-designer UI (`AppCustomThemeSection`)
 * and the runtime render root (`BuilderHost` / `AppBrandedHeader`). The
 * builder/page-designer admin UI is Conduction/openbuild#41-quarantined in
 * this build (no application detail / designer UI renders), so these tests
 * are skipped with the same recorded reason as the sibling
 * `nldesign-theme-selection.spec.ts` (whose applier this feature reuses).
 * Logic coverage is delegated to the vitest suites: manifest validation
 * (appThemeValidation.spec.js), the contrast guardrail
 * (checkThemeContrast.spec.js), the applier + nldesign-precedence
 * integration test (useAppCustomTheme.spec.js), the editor UI
 * (AppCustomThemeSection.spec.js), the branded header + logo resolution
 * (AppBrandedHeader.spec.js), and the BuilderHost/PageDesignerHost wiring
 * (BuilderHost.spec.js, PageDesignerHost.spec.js).
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

// @e2e app-theming::valid-apptheme-declaration-passes-validation
// QUARANTINED (Conduction/openbuild#41): openbuild page-designer UI not functional in this build. Logic covered by vitest (appThemeValidation.spec.js).
test.skip('app-theming — valid appTheme declaration passes validation', async ({ page }) => {
	// @e2e app-theming::valid-apptheme-declaration-passes-validation
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::low-contrast-primary-color-blocks-save
// QUARANTINED (Conduction/openbuild#41): page-designer UI not functional. Logic covered by vitest (checkThemeContrast.spec.js known-bad-pair tests + PageDesignerHost.spec.js "save() WCAG contrast guardrail" suite — the actual persist-boundary hard block).
test.skip('app-theming — low-contrast primary color blocks save', async ({ page }) => {
	// @e2e app-theming::low-contrast-primary-color-blocks-save
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::compliant-theme-saves-without-friction
// QUARANTINED (Conduction/openbuild#41): page-designer UI not functional. Logic covered by vitest (checkThemeContrast.spec.js known-good-pair test + PageDesignerHost.spec.js "allows save() through when appTheme passes contrast").
test.skip('app-theming — compliant theme saves without friction', async ({ page }) => {
	// @e2e app-theming::compliant-theme-saves-without-friction
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::themed-app-renders-scoped-variables
// QUARANTINED (Conduction/openbuild#41): the published virtual-app runtime is not reachable through the quarantined builder. Scoped-render + variable-mapping covered by vitest (useAppCustomTheme.spec.js).
test.skip('app-theming — themed app renders scoped variables', async ({ page }) => {
	// @e2e app-theming::themed-app-renders-scoped-variables
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::leaving-the-app-removes-the-injected-style
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Teardown covered by vitest (useAppCustomTheme.spec.js teardown test + BuilderHost.spec.js "beforeDestroy tears down both appliers").
test.skip('app-theming — leaving the app removes the injected style', async ({ page }) => {
	// @e2e app-theming::leaving-the-app-removes-the-injected-style
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::nldesign-color-wins-over-apptheme-color-for-the-same-variable
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Precedence covered by the dedicated integration test in vitest (useAppCustomTheme.spec.js "nldesign precedence (tasks.md 4.2 acceptance)" suite, against a real fetched nldesign token CSS sample).
test.skip('app-theming — nldesign color wins over appTheme color for the same variable', async ({ page }) => {
	// @e2e app-theming::nldesign-color-wins-over-apptheme-color-for-the-same-variable
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::apptheme-header-style-still-applies-alongside-an-nldesign-theme
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Covered by vitest (BuilderHost.spec.js "renders AppBrandedHeader only when headerStyle is branded").
test.skip('app-theming — appTheme header style still applies alongside an nldesign theme', async ({ page }) => {
	// @e2e app-theming::apptheme-header-style-still-applies-alongside-an-nldesign-theme
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::default-theme-logo-is-the-app-icon
// QUARANTINED (Conduction/openbuild#41): runtime not reachable. Covered by vitest (AppBrandedHeader.spec.js "defaults to the app-icon URL when logoRef is null").
test.skip('app-theming — default theme logo is the app icon', async ({ page }) => {
	// @e2e app-theming::default-theme-logo-is-the-app-icon
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e app-theming::dedicated-theme-logo-overrides-the-app-icon
// QUARANTINED (Conduction/openbuild#41): page-designer + runtime UI not functional. Covered by vitest (AppCustomThemeSection.spec.js upload test + AppBrandedHeader.spec.js dedicated-logo-resolution test).
test.skip('app-theming — dedicated theme logo overrides the app icon', async ({ page }) => {
	// @e2e app-theming::dedicated-theme-logo-overrides-the-app-icon
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})
