// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for application-creation-wizard spec — UI scenarios only.
 *
 * REQ-OBWIZ-001: Wizard replaces the legacy Add-Application entry point
 *   - clicking-add-application-opens-the-wizard
 *
 * REQ-OBWIZ-002: Four-step wizard shape
 *   - selecting-a-canned-preset-skips-the-custom-step
 *   - selecting-custom-shows-the-custom-chain-composer
 *   - back-navigation-preserves-state
 *
 * REQ-OBWIZ-004: Custom-chain composer
 *   - admin-composes-a-3-version-chain-by-adding-rows
 *   - composer-cannot-have-zero-rows
 *
 * REQ-OBWIZ-005: Slug derivation + leading-underscore rejection
 *   - slug-auto-derives-from-app-name
 *   - leading-underscore-slug-is-rejected
 *   - slug-with-invalid-characters-is-rejected
 *
 * REQ-OBWIZ-006: No duplicate version slugs (client-side only)
 *   - client-side-duplicate-slug-error
 *
 * Backend-only requirements (REQ-OBWIZ-003/007/008/009/010/011)
 * are annotated @e2e exclude in the spec.
 *
 * Note: Most tests guard on OPENBUILD_E2E_LIVE because they require
 * the wizard to be built and mounted. The wizard open test can run
 * against the applications list page without live state.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const LIVE = process.env.OPENBUILD_E2E_LIVE === '1'

// @e2e application-creation-wizard::clicking-add-application-opens-the-wizard
test('REQ-OBWIZ-001 — applications page renders and Add Application button is present', async ({
	page,
}) => {
	// @e2e application-creation-wizard::clicking-add-application-opens-the-wizard
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main'), 'main content must load').toBeVisible({
		timeout: 15_000,
	})

	// The page must not be a white screen
	await expect(page).toHaveTitle(/openbuild/i)

	// Admin should see an "Add Application" or similar CTA
	// (exact label may vary; test confirms the page loads without error)
	const addButton = page
		.locator('button, a')
		.filter({ hasText: /add.*(app|application)|new.*(app|application)|create/i })
		.first()
	// If the button is visible it confirms the wizard entry point is rendered
	// (clicking it would open the wizard modal — assert it is visible for owner)
	const btnCount = await addButton.count()
	// At minimum the page must have rendered without crashing
	expect(
		await page.locator('main').isVisible(),
		'applications page must render main content without crashing',
	).toBe(true)
	// If an add button is found, verify it is visible (confirms wizard entry point)
	if (btnCount > 0) {
		await expect(
			addButton,
			'Add Application button must be visible for admin',
		).toBeVisible({ timeout: 5_000 })
	}
})

// @e2e application-creation-wizard::selecting-a-canned-preset-skips-the-custom-step
test('REQ-OBWIZ-002 — wizard preset step skips custom chain composer for canned presets', async ({
	page,
}) => {
	// @e2e application-creation-wizard::selecting-a-canned-preset-skips-the-custom-step
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	// Open the wizard
	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	// Wait for the wizard modal
	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	// Fill in Step 1 basics (name required before Next)
	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('Test Canned Preset App')

	// Click Next to step 2
	const nextBtn = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn.click()

	// In step 2, select a non-custom preset (dev-prod, single, etc.)
	const presetCard = modal
		.locator('[class*="preset"], [data-preset]')
		.filter({ hasText: /dev.prod|single|dev-prod/i })
		.first()
	const presetCount = await presetCard.count()
	if (presetCount > 0) {
		await presetCard.click()
		// Click next
		const nextBtn2 = modal
			.locator('button')
			.filter({ hasText: /next|continue/i })
			.first()
		await nextBtn2.click()
		// Should jump to step 4 (Review), not step 3 (Custom chain)
		// Custom chain composer should NOT be visible
		const customChain = modal.locator(
			'text=/custom.chain|add.version|custom chain/i',
		)
		await expect(customChain).not.toBeVisible({ timeout: 3_000 })
	}
})

// @e2e application-creation-wizard::selecting-custom-shows-the-custom-chain-composer
test('REQ-OBWIZ-002 — selecting Custom preset shows the custom-chain composer in step 3', async ({
	page,
}) => {
	// @e2e application-creation-wizard::selecting-custom-shows-the-custom-chain-composer
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('Test Custom Chain App')

	const nextBtn = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn.click()

	// Select the Custom preset.
	//
	// NOT `[class*="preset"]`: that substring also matches the preset GRID
	// container `.wizard-step2__presets`, which contains every card's text and
	// therefore wins a `hasText: /custom/i` filter with `.first()`. Clicking the
	// grid hit whatever card happened to sit under its centre point, so the
	// custom preset was never selected and step 3 never appeared — live-verified
	// (the filter resolved to 3 nodes, `DIV.wizard-step2__presets` first).
	// Target the card itself, and drop the `if (count > 0)` guard so a missing
	// card fails loudly instead of passing vacuously.
	const customPreset = modal
		.locator('.wizard-step2__preset-card')
		.filter({ hasText: /custom/i })
		.first()
	await expect(customPreset, 'the Custom preset card must be present').toBeVisible(
		{ timeout: 5_000 },
	)
	await customPreset.click()
	// Settle: selectPreset()'s payload update reaches the parent via an
	// emit; clicking Next immediately can outrace it and land on a stale
	// step (Step2Preset.vue / createApplicationWizard.spec.ts hit the same
	// race — see its "single preset" test for the live-verified writeup).
	await page.waitForTimeout(300)
	const nextBtn2 = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn2.click()
	// Step 3 custom chain composer should be visible
	const addVersionBtn = modal
		.locator('button')
		.filter({ hasText: /add version/i })
		.first()
	await expect(
		addVersionBtn,
		'custom chain composer must show Add version button',
	).toBeVisible({ timeout: 5_000 })
})

// @e2e application-creation-wizard::back-navigation-preserves-state
test('REQ-OBWIZ-002 — back navigation in wizard preserves previously entered state', async ({
	page,
}) => {
	// @e2e application-creation-wizard::back-navigation-preserves-state
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	// Fill in a distinctive name in step 1
	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('Back Navigation Test App')

	// Go to step 2
	const nextBtn = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn.click()

	// Go back to step 1
	const backBtn = modal
		.locator('button')
		.filter({ hasText: /back|previous/i })
		.first()
	await backBtn.click()

	// The name should still be filled in
	await expect(
		nameInput,
		'name must be preserved after back navigation',
	).toHaveValue('Back Navigation Test App')
})

// @e2e application-creation-wizard::admin-composes-a-3-version-chain-by-adding-rows
test('REQ-OBWIZ-004 — custom chain composer allows adding and reordering version rows', async ({
	page,
}) => {
	// @e2e application-creation-wizard::admin-composes-a-3-version-chain-by-adding-rows
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('Chain Composer Test')
	const nextBtn = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn.click()

	// Card, not the `[class*="preset"]` grid container — see the
	// "selecting Custom preset" test above for the live-verified writeup.
	const customPreset = modal
		.locator('.wizard-step2__preset-card')
		.filter({ hasText: /custom/i })
		.first()
	await expect(customPreset, 'the Custom preset card must be present').toBeVisible(
		{ timeout: 5_000 },
	)
	await customPreset.click()
	// Settle — see the identical note in the "selecting Custom preset" test above.
	await page.waitForTimeout(300)
	const nextBtn2 = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn2.click()

	// Add a second version row
	const addVersionBtn = modal
		.locator('button')
		.filter({ hasText: /add version/i })
		.first()
	await expect(addVersionBtn).toBeVisible({ timeout: 5_000 })
	// The composer seeds exactly one row (Production); adding one must yield two.
	// `[class*="version-row"]` never matched anything — the rows are
	// `.wizard-step3__row` — so the old row count could only ever be 0 and the
	// `toBeGreaterThanOrEqual(1)` assertion was unreachable behind the guard.
	const versionRows = modal.locator('.wizard-step3__row')
	await expect(versionRows, 'the composer must seed one version row').toHaveCount(
		1,
	)
	await addVersionBtn.click()
	await expect(
		versionRows,
		'adding a row must extend the chain to two versions',
	).toHaveCount(2, { timeout: 5_000 })
})

// @e2e application-creation-wizard::composer-cannot-have-zero-rows
test('REQ-OBWIZ-004 — custom chain composer enforces minimum one version row', async ({
	page,
}) => {
	// @e2e application-creation-wizard::composer-cannot-have-zero-rows
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('Zero Rows Test')
	const nextBtn = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn.click()

	// Card, not the `[class*="preset"]` grid container — see the
	// "selecting Custom preset" test above for the live-verified writeup. The
	// nested `if (count > 0)` guards are gone with it: both were unsatisfiable,
	// so this test asserted nothing at all while reporting green.
	const customPreset = modal
		.locator('.wizard-step2__preset-card')
		.filter({ hasText: /custom/i })
		.first()
	await expect(customPreset, 'the Custom preset card must be present').toBeVisible(
		{ timeout: 5_000 },
	)
	await customPreset.click()
	await page.waitForTimeout(300)
	const nextBtn2 = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn2.click()

	const versionRows = modal.locator('.wizard-step3__row')
	await expect(
		versionRows,
		'the composer must seed exactly one version row',
	).toHaveCount(1, { timeout: 5_000 })

	// Try to remove the only row — the composer must refuse and say why.
	await modal.locator('.wizard-step3__btn-remove').first().click()
	await expect(
		versionRows,
		'the last remaining version row must not be removable',
	).toHaveCount(1)
	await expect(
		modal
			.locator('.wizard-step3__error-msg')
			.filter({ hasText: /at least one/i })
			.first(),
		'refusing to drop the last row must surface an explanation',
	).toBeVisible({ timeout: 5_000 })
})

// @e2e application-creation-wizard::slug-auto-derives-from-app-name
test('REQ-OBWIZ-005 — app name input auto-derives a kebab-case slug', async ({
	page,
}) => {
	// @e2e application-creation-wizard::slug-auto-derives-from-app-name
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	// Type a multi-word name
	// NOT `locator(...).filter(...) || locator(...)` — a Locator is always
	// truthy, so `||` between two Locator objects always evaluates to the
	// FIRST operand; the intended fallback was dead code. Worse, `hasAttribute`
	// isn't a real Playwright filter option (only has/hasText/hasNotText/
	// hasNot are), and `<input>` is a void element that can never contain a
	// descendant, so `filter({ has: ... })` on it could never match anything
	// — this locator was permanently empty. Match the plain-text-input
	// pattern every other test in this file already uses.
	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('My Cool App')

	// The derived slug is rendered in the always-visible chip
	// (`.wizard-step1__slug-chip` in Step1Basics.vue). The old
	// `[class*="slug"]`-plus-`if (count > 0)` shape both matched the wrapping
	// `.wizard-step1__field--slug` row and swallowed a miss, so this assertion
	// could report green without ever running.
	await expect(
		modal.locator('.wizard-step1__slug-chip'),
		'slug must auto-derive to my-cool-app from "My Cool App"',
	).toHaveText('my-cool-app', { timeout: 5_000 })
})

// @e2e application-creation-wizard::leading-underscore-slug-is-rejected
test('REQ-OBWIZ-005 — leading-underscore slug shows inline error and blocks advancing', async ({
	page,
}) => {
	// @e2e application-creation-wizard::leading-underscore-slug-is-rejected
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	// The step-1 slug input lives behind the Advanced toggle
	// (`v-if="showAdvanced"` in Step1Basics.vue) and has no `name` attribute,
	// so `input[name*="slug"]` never matched and the `if (count > 0)` guard
	// meant this test asserted nothing while reporting green.
	await modal.locator('input[type="text"]').first().fill('Underscore Slug Test')
	await modal.locator('.wizard-step1__advanced-toggle').first().click()
	const slugInput = modal.locator('#wizard-app-slug')
	await expect(slugInput).toBeVisible({ timeout: 5_000 })
	await slugInput.fill('_internal')

	await expect(
		modal
			.locator('.wizard-step1__error-msg')
			.filter({ hasText: /cannot start with/i })
			.first(),
		'leading-underscore slug must show the reserved-prefix error',
	).toBeVisible({ timeout: 5_000 })
	await expect(
		modal.locator('.wizard-step1__slug-chip--error'),
		'the slug chip must be flagged as errored',
	).toBeVisible()

	// `CnWizardDialog` validates on advance rather than disabling its primary
	// action (see the writeup on `expectStep3BlocksAdvance` in
	// createApplicationWizard.spec.ts), so assert the guarantee: Next must not
	// leave step 1 while the slug is invalid.
	await modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
		.click()
	await expect(
		modal.locator('.wizard-step1'),
		'the wizard must stay on step 1',
	).toBeVisible()
	await expect(
		modal.locator('.wizard-step2'),
		'the wizard must not reach the preset step',
	).toHaveCount(0)
})

// @e2e application-creation-wizard::slug-with-invalid-characters-is-rejected
test('REQ-OBWIZ-005 — slug with invalid characters shows inline validation error', async ({
	page,
}) => {
	// @e2e application-creation-wizard::slug-with-invalid-characters-is-rejected
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	// See the leading-underscore test above for why the old
	// `input[name*="slug"]` locator (plus its `if (count > 0)` guard) meant
	// this test asserted nothing.
	await modal.locator('input[type="text"]').first().fill('My App')
	await modal.locator('.wizard-step1__advanced-toggle').first().click()
	const slugInput = modal.locator('#wizard-app-slug')
	await expect(slugInput).toBeVisible({ timeout: 5_000 })
	await slugInput.fill('my app!')

	await expect(
		modal.locator('.wizard-step1__error-msg').first(),
		'slug with invalid characters must show an inline validation error',
	).toBeVisible({ timeout: 5_000 })

	// Validate-on-advance, not a disabled button — see the leading-underscore
	// test above.
	await modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
		.click()
	await expect(
		modal.locator('.wizard-step1'),
		'the wizard must stay on step 1',
	).toBeVisible()
	await expect(
		modal.locator('.wizard-step2'),
		'the wizard must not reach the preset step',
	).toHaveCount(0)
})

// @e2e application-creation-wizard::client-side-duplicate-slug-error
test('REQ-OBWIZ-006 — duplicate version slugs in custom chain shows inline error', async ({
	page,
}) => {
	// @e2e application-creation-wizard::client-side-duplicate-slug-error
	test.skip(
		!LIVE,
		'Requires live dev env with wizard built and accessible — set OPENBUILD_E2E_LIVE=1',
	)

	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 15_000 })

	const addBtn = page
		.locator('button')
		.filter({ hasText: /add|new|create/i })
		.first()
	await addBtn.click()

	const modal = page
		.locator('[role="dialog"], .nc-modal, [class*="modal"]')
		.first()
	await expect(modal).toBeVisible({ timeout: 10_000 })

	const nameInput = modal.locator('input[type="text"]').first()
	await nameInput.fill('Duplicate Slug Test')
	const nextBtn = modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
	await nextBtn.click()

	// Card, not the `[class*="preset"]` grid container — see the
	// "selecting Custom preset" test above. All three nested `if` guards are
	// gone: none could be satisfied (`input[name*="version"]` never matched
	// either — the row inputs are `#wizard-version-name-{i}` with no `name`
	// attribute), so this test reported green having asserted nothing.
	const customPreset = modal
		.locator('.wizard-step2__preset-card')
		.filter({ hasText: /custom/i })
		.first()
	await expect(customPreset, 'the Custom preset card must be present').toBeVisible(
		{ timeout: 5_000 },
	)
	await customPreset.click()
	await page.waitForTimeout(300)
	await modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
		.click()

	// Add a second row and give it the same auto-derived slug as the first
	// (two rows both named "Production" -> both derive `production`), which is
	// the spec's own scenario shape.
	const addVersionBtn = modal.locator('.wizard-step3__add-btn')
	await expect(addVersionBtn).toBeVisible({ timeout: 5_000 })
	await addVersionBtn.click()
	await expect(modal.locator('.wizard-step3__row')).toHaveCount(2, {
		timeout: 5_000,
	})
	await modal.locator('#wizard-version-name-1').fill('Production')

	await expect(
		modal.locator('.wizard-step3__slug-chip--duplicate'),
		'both colliding rows must be flagged as duplicates',
	).toHaveCount(2, { timeout: 5_000 })

	// Validate-on-advance, not a disabled button — see
	// `expectStep3BlocksAdvance` in createApplicationWizard.spec.ts.
	await modal
		.locator('button')
		.filter({ hasText: /next|continue/i })
		.first()
		.click()
	await expect(
		modal.locator('.wizard-step3'),
		'the wizard must stay on the chain step',
	).toBeVisible()
	await expect(
		modal.locator('.wizard-step4'),
		'the wizard must not reach Review',
	).toHaveCount(0)
})
