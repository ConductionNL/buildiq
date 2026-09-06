// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the nldesign-theme-selection spec — the UI-driven
 * scenarios (REQ-NTS-002 builder pick via `useScopedTheme().listTokenSets()`,
 * REQ-NTS-003 CnAppRoot's own scoped-render, REQ-NTS-005 graceful absence),
 * as modified by theme-picker-consumes-nldesign.
 *
 * These scenarios drive the buildiq admin builder UI (Theme section + theme
 * picker dialog) and the runtime render root. The builder admin UI is
 * Conduction/buildiq#41-quarantined in this build (no application detail /
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
 * (buildiq-nldesign-theme.postman_collection.json). Backend validation
 * scenarios (REQ-NTS-001) and the Newman/asset-contract scenarios
 * (REQ-NTS-006) are excluded from e2e enforcement in the spec.
 */

import { expect, test } from '@playwright/test'
import { dismissOverlays } from '../support/appFixture.ts'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

// @e2e nldesign-theme-selection::builder-picks-a-theme-from-the-visual-list
// STUB BODY (goto + main-visible only) — needs real assertions written. The old note said "QUARANTINED (buildiq#41): builder UI not functional"; #41 is a PR that MERGED 2026-07-27, and 47 spec files already pass against that builder UI. Enabling this as it stands would pass while asserting nothing. Logic covered by vitest (ThemeSection.spec.js + ThemePickerDialog.spec.js, exercising the real published useScopedTheme.listTokenSets()).
test('REQ-NTS-002 — builder picks a theme from the visual list', async ({
	page,
}) => {
	test.skip(
		true,
		'STUB BODY (goto + main-visible only) — needs real assertions written. The old note said "QUARANTINED (buildiq#41): builder UI not functional"; #41 is a PR that MERGED 2026-07-27, and 47 spec files already pass against that builder UI. Enabling this as it stands would pass while asserting nothing. Logic covered by vitest (ThemeSection.spec.js + ThemePickerDialog.spec.js, exercising the real publ...',
	)
	// @e2e nldesign-theme-selection::builder-picks-a-theme-from-the-visual-list
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::empty-catalogue-renders-the-absence-hint-not-a-free-text-fallback
// STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27 and the builder UI renders. Logic covered by vitest (ThemePickerDialog empty-list → REQ-NTS-005 hint test; no free-text input exists anywhere in the dialog anymore).
test('REQ-NTS-002 — empty catalogue renders the absence hint, not a free-text fallback', async ({
	page,
}) => {
	test.skip(
		true,
		'STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27 and the builder UI renders. Logic covered by vitest (ThemePickerDialog empty-list → REQ-NTS-005 hint test; no free-text input exists anywhere in the dialog anymore).',
	)
	// @e2e nldesign-theme-selection::empty-catalogue-renders-the-absence-hint-not-a-free-text-fallback
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::live-preview-applies-via-the-sandboxed-live-preview-pane-cnapproot-and-reverts-on-cancel
// STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27 and the builder UI renders. Logic covered by vitest (PageDesignerHost.spec.js onThemePreview mutate/revert test + ThemePickerDialog cancel-revert test).
test('REQ-NTS-002 — live preview applies via the sandboxed live-preview-pane CnAppRoot and reverts on cancel', async ({
	page,
}) => {
	test.skip(
		true,
		'STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27 and the builder UI renders. Logic covered by vitest (PageDesignerHost.spec.js onThemePreview mutate/revert test + ThemePickerDialog cancel-revert test).',
	)
	// @e2e nldesign-theme-selection::live-preview-applies-via-the-sandboxed-live-preview-pane-cnapproot-and-reverts-on-cancel
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::themed-app-renders-via-cnapproots-own-applier-no-buildiq-composable-involved
// STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27; the runtime is reachable (buildiq-runtime.spec.ts drives it). Scoped-render + :root-rewrite now live in @conduction/nextcloud-vue (scoped-theme-applier) and are covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js against the REAL published dist; the asset contract by Newman.
test("REQ-NTS-003 — themed app renders via CnAppRoot's own applier, no Buildiq composable involved", async ({
	page,
}) => {
	test.skip(
		true,
		'STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27; the runtime is reachable (buildiq-runtime.spec.ts drives it). Scoped-render + :root-rewrite now live in @conduction/nextcloud-vue (scoped-theme-applier) and are covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js against the REAL published dist; the asset contract by Newman.',
	)
	// @e2e nldesign-theme-selection::themed-app-renders-via-cnapproots-own-applier-no-buildiq-composable-involved
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::leaving-the-app-removes-the-injected-style-via-cnapproots-own-teardown
// STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27; the runtime is reachable. Teardown covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js against the real published useScopedTheme.teardown().
test("REQ-NTS-003 — leaving the app removes the injected style (via CnAppRoot's own teardown)", async ({
	page,
}) => {
	test.skip(
		true,
		'STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27; the runtime is reachable. Teardown covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js against the real published useScopedTheme.teardown().',
	)
	// @e2e nldesign-theme-selection::leaving-the-app-removes-the-injected-style-via-cnapproots-own-teardown
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e nldesign-theme-selection::designer-degrades-when-nldesign-is-missing
test('REQ-NTS-005 — designer degrades when nldesign is missing', async ({
	page,
}) => {
	// @e2e nldesign-theme-selection::designer-degrades-when-nldesign-is-missing
	//
	// REAL BODY. This was `goto('/applications')` +
	// `expect(main).toBeVisible()`, which asserts nothing about themes and would
	// pass on any page that renders.
	//
	// The quarantine it carried — "buildiq#41: builder UI not functional" — cites
	// a PR that MERGED on 2026-07-27, and 47 spec files in this suite already
	// pass against that same builder UI.
	//
	// Deterministic here: this app's CI installs openregister and docudesk and
	// NOT nldesign (see code-quality.yml `additional-apps`), so
	// `nldesignAvailable` is false and the degraded branch is the one under test.
	await page.goto(
		`${BASE}/apps/buildiq/builder/hello-world/pages?_version=production`,
		{ waitUntil: 'domcontentloaded' },
	)
	await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
	await dismissOverlays(page).catch(() => {})

	const section = page.locator('.ob-theme-section')
	await section.scrollIntoViewIfNeeded()
	await expect(section).toBeVisible({ timeout: 30_000 })

	// Degrading means two things, and both are asserted: the absence is
	// EXPLAINED, and the control that cannot work is not merely inert.
	await expect(
		section.locator('.ob-theme-section__hint'),
		'the designer must say why the theme cannot be changed',
	).toBeVisible()
	await expect(
		section.getByRole('button', { name: 'Change' }),
		'the Change control must be disabled, not silently non-functional',
	).toBeDisabled()
})

// @e2e nldesign-theme-selection::themed-app-still-renders-without-nldesign
// STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27; the runtime is reachable. Degrade-to-default covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js (real published apply() on a fetch failure).
test('REQ-NTS-005 — a themed app renders default styling without nldesign', async ({
	page,
}) => {
	test.skip(
		true,
		'STUB BODY — needs real assertions. The buildiq#41 quarantine is stale: #41 MERGED 2026-07-27; the runtime is reachable. Degrade-to-default covered by tests/composables/nextcloud-vue-useScopedTheme.spec.js (real published apply() on a fetch failure).',
	)
	// @e2e nldesign-theme-selection::themed-app-still-renders-without-nldesign
	await page.goto(`${BASE}/apps/buildiq/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})
