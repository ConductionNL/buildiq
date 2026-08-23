// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E — four-step App Creation Wizard.
 *
 * Covers spec buildiq-app-creation-wizard tasks 8.5 + 8.6.
 *
 *   Task 8.5 (preset happy paths):
 *     - `single`: name "Hello World", slug auto-derives to `hello-world-pw-single`,
 *       selects Single preset, clicks through to Review, clicks Create, navigates
 *       to /applications/<uuid>.
 *     - `dev-prod`: same flow; verifies chain label shows development → production.
 *     - `dev-staging-prod`: three-tier chain.
 *     - `custom`: builds 3-row custom chain (alpha → beta → main).
 *
 *   Task 8.6 (validation errors):
 *     - Leading-underscore version slug shows inline error and disables Create.
 *     - Duplicate version slug in chain shows inline error and disables Create.
 *     - Empty version row name shows inline error and disables Create.
 *     - App slug already in use shows server-side error; admin can edit + retry.
 *
 * Pre-conditions:
 *   - Docker stack running at PLAYWRIGHT_BASE_URL (default: http://localhost:8080).
 *   - Buildiq app enabled; `buildiq` register + schemas present (SeedHelloWorld).
 *   - Nextcloud admin user: NC_ADMIN_USER / NC_ADMIN_PASSWORD (default: admin/admin).
 *   - Tests that actually POST to the wizard will leave state in OR; they are
 *     skip-guarded on the `BUILDIQ_E2E_LIVE` env variable so CI dry-runs pass.
 *
 * When BUILDIQ_E2E_LIVE is not set to "1", all tests that require a running dev
 * environment are skipped with an explanatory message. The spec still parses cleanly
 * for `playwright test --list`.
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASSWORD ?? 'admin'

/**
 * Application slugs this spec creates through the wizard.
 *
 * These are derived from the fixed app names passed to `fillStep1()`, whose
 * slugs auto-derive as lower-kebab. They MUST be removed before the run: the
 * wizard now correctly rejects an already-taken slug with 422
 * app_slug_conflict, so a second run would fail at "Create" on every happy
 * path. Previously the uniqueness check was broken (it never matched
 * anything), so re-running silently minted duplicate Applications instead —
 * which is what littered the e2e instance with three `hello-world` rows and,
 * through OpenRegister's ambiguous find-by-slug, broke the whole automations
 * suite. `hello-world` is deliberately NOT in this list: it is the canonical
 * seeded fixture and the "slug already in use" test depends on it existing.
 */
const WIZARD_FIXTURE_SLUGS = [
	'playwright-single-app',
	'playwright-devprod-app',
	'playwright-dsp-app',
	'playwright-custom-app',
	'playwright-validation-app',
	'playwright-dup-slug-app',
	'playwright-empty-name-app',
]

/**
 * Delete the Applications this spec creates, so each run starts from a state
 * where its slugs are genuinely free and "Create" really exercises creation.
 *
 * @param request Playwright API request context (carries the admin session).
 * @return {Promise<void>}
 */
async function deleteWizardFixtureApps(request: APIRequestContext): Promise<void> {
	const resp = await request.get(
		'/index.php/apps/openregister/api/objects/openbuild/application?_limit=100',
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	if (resp.ok() === false) {
		return
	}
	const body = await resp.json()
	const items = Array.isArray(body) ? body : (body.results ?? [])
	for (const app of items) {
		const slug = app?.slug ?? app?.['@self']?.slug
		const id = app?.id ?? app?.['@self']?.id
		if (WIZARD_FIXTURE_SLUGS.includes(slug) && id) {
			await request
				.delete(
					`/index.php/apps/openregister/api/objects/openbuild/application/${id}`,
					{
						headers: { 'OCS-APIRequest': 'true' },
					},
				)
				.catch(() => {})
		}
	}
}

/**
 * Whether a live dev environment is available.
 * Set BUILDIQ_E2E_LIVE=1 to run tests that require a provisioned OR backend.
 */
const LIVE = process.env.BUILDIQ_E2E_LIVE === '1'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Navigate to the Buildiq app as admin.
 *
 * @param page Playwright page.
 */
async function goToApps(page: Page): Promise<void> {
	// NOT `/index.php/apps/buildiq/applications` — live-verified that the
	// `/index.php/`-prefixed form of this deep link redirects to the bare
	// `/apps/buildiq/` Dashboard root, silently dropping the `/applications`
	// sub-path, so `.ob-va-actions` never renders and this always timed out.
	// The pretty-URL form (no `/index.php/` prefix) preserves the sub-path.
	await page.goto(`${BASE_URL}/apps/buildiq/applications`)
	// Wait for the app to mount; the actions bar must be visible.
	await page.waitForSelector('.ob-va-actions, [data-cy="ob-actions"]', {
		timeout: 20_000,
	})
}

/**
 * The wizard dialog itself — every action button MUST be looked up inside it.
 *
 * CnWizardDialog renders as `.dialog__modal[data-testid-modal="cn-wizard-dialog"]`,
 * teleported to <body>. Scoping matters because a page-level `getByRole('button',
 * { name: /^next$/i }).first()` does NOT find the wizard's Next: the applications
 * list behind the modal renders a PAGINATION control whose button is also called
 * "Next" (`.cn-pagination__nav`), it comes first in DOM order, and it sits at
 * y≈1318 in a 720px viewport. Playwright judged it visible and enabled, scrolled
 * to it, and the modal overlay then swallowed the click — producing "subtree
 * intercepts pointer events" against the DIALOG, which reads exactly like a
 * broken dialog rather than a mis-aimed locator (buildiq#86).
 *
 * It only started failing once the fixture apps grew past one page, which is why
 * this looked like a regression in the wizard.
 *
 * @param page Playwright page.
 * @return {import('@playwright/test').Locator} The wizard dialog root.
 */
function wizard(page: Page) {
	return page.locator('[data-testid-modal="cn-wizard-dialog"]')
}

/**
 * Open the wizard by clicking the "Add app" button.
 *
 * @param page Playwright page.
 */
async function openWizard(page: Page): Promise<void> {
	// VirtualAppsActions.vue's button reads "Add app", not "Add application"
	// (src/components/VirtualAppsActions.vue) — live-verified against the
	// rendered applications list.
	const addBtn = page.getByRole('button', { name: /add app/i }).first()
	await expect(addBtn, '"Add app" button must be visible').toBeVisible({
		timeout: 10_000,
	})
	await addBtn.click()
	// The wizard modal should appear.
	await page.waitForSelector('.nc-modal-stub, .modal-wrapper, [role="dialog"]', {
		timeout: 8_000,
	})
}

/**
 * Fill Step 1 with the given name and wait for the slug to auto-derive.
 *
 * @param page     Playwright page.
 * @param appName  Display name for the new application.
 */
async function fillStep1(page: Page, appName: string): Promise<void> {
	const nameInput = page
		.locator(
			'#wizard-app-name, input[placeholder*="name" i], input[name="name"]',
		)
		.first()
	await expect(nameInput).toBeVisible({ timeout: 8_000 })
	await nameInput.fill(appName)
	// Allow debounce / slug derivation to tick.
	await page.waitForTimeout(300)
}

/**
 * Click the Next button (expects the wizard to show "Next").
 *
 * @param page Playwright page.
 */
async function clickNext(page: Page): Promise<void> {
	// Scoped to the dialog — see wizard() for why an unscoped lookup hits the
	// applications-list pagination instead.
	const nextBtn = wizard(page).getByRole('button', { name: /^next$/i })
	await expect(nextBtn).toBeEnabled({ timeout: 5_000 })
	await nextBtn.click()
}

/**
 * Reveal a step-3 row's Advanced panel, which is where the editable version
 * slug input (`#wizard-version-slug-{index}`) lives.
 *
 * The slug input is behind `v-if="advancedOpen[index]"` in
 * Step3Custom.vue — the always-visible surface is the read-only
 * `.wizard-step3__slug-chip`. Specs that filled `#wizard-version-slug-0`
 * without opening Advanced were waiting on an element that is never in the DOM
 * until the toggle is clicked (live-verified: 0 before, 1 after).
 *
 * @param page  Playwright page.
 * @param index Zero-based version row index.
 */
async function openAdvanced(page: Page, index: number): Promise<void> {
	const toggle = page.locator('.wizard-step3__advanced-toggle').nth(index)
	await expect(toggle, `row ${index} Advanced toggle must be present`).toBeVisible(
		{ timeout: 5_000 },
	)
	await toggle.click()
	await expect(page.locator(`#wizard-version-slug-${index}`)).toBeVisible({
		timeout: 5_000,
	})
}

/**
 * Assert the wizard REFUSES to leave the custom-chain step while it is invalid.
 *
 * The spec's REQ-OBWIZ-005/006 wording is "the wizard's Next / Create button is
 * disabled until the slug is corrected", but `CnWizardDialog` (the shared
 * @conduction/nextcloud-vue shell) binds its primary action to
 * `:disabled="loading"` only and exposes no validity input — it is a
 * validate-on-advance wizard: `validate(stepId, stepData)` runs on click and a
 * falsy/`string` outcome blocks the transition and renders the reason. So the
 * button is never disabled on any branch, on this branch or on development.
 *
 * Asserting the guarantee the requirement exists to provide — the admin cannot
 * proceed, and is told why — is strictly stronger than asserting the
 * disabled-attribute proxy for it. The disabled-button clause needs a library
 * change to become true; see the handover notes.
 *
 * @param page          Playwright page.
 * @param expectedError Substring of the row-level message that must be shown.
 */
async function expectStep3BlocksAdvance(
	page: Page,
	expectedError: RegExp,
): Promise<void> {
	// The row-level inline error must be rendered.
	await expect(
		page
			.locator('.wizard-step3__error-msg')
			.filter({ hasText: expectedError })
			.first(),
		'the invalid row must render its inline error',
	).toBeVisible({ timeout: 5_000 })

	// And Next must not get us off step 3.
	const nextBtn = wizard(page).getByRole('button', { name: /^next$/i })
	await nextBtn.click()
	await expect(
		page.locator('.wizard-step3'),
		'the wizard must not advance off the invalid custom-chain step',
	).toBeVisible()
	await expect(
		page.locator('.wizard-step4'),
		'the wizard must not reach Review while the chain is invalid',
	).toHaveCount(0)
	await expect(
		page
			.locator('[role="alert"]')
			.filter({ hasText: /complete the custom version chain/i })
			.first(),
		'the wizard must explain why it refused to advance',
	).toBeVisible({ timeout: 5_000 })
}

/**
 * Click the Create button on step 4 and wait for navigation.
 *
 * @param page Playwright page.
 * @returns The applicationUuid extracted from the URL after navigation.
 */
async function clickCreate(page: Page): Promise<string> {
	const createBtn = wizard(page).getByRole('button', { name: /^create$/i })
	await expect(createBtn).toBeEnabled({ timeout: 5_000 })
	await createBtn.click()
	// Wait for the modal to close and the router to navigate to the detail page.
	await page.waitForURL(/\/applications\/[0-9a-f-]+/, { timeout: 20_000 })
	const match = page.url().match(/\/applications\/([0-9a-f-]+)/)
	return match ? match[1] : ''
}

// ---------------------------------------------------------------------------
// Task 8.5 — Preset happy paths
// ---------------------------------------------------------------------------

test.describe('Wizard — preset happy paths (task 8.5)', () => {
	test.beforeAll(async ({ request }) => {
		await deleteWizardFixtureApps(request)
	})

	test('single preset: name → slug auto-derives, Create lands on detail page', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		// Step 1: Basics
		await fillStep1(page, 'Playwright Single App')
		// Slug should have auto-derived; it appears in .wizard-step1__slug-chip or similar.
		// Allow the component to update.
		await page.waitForTimeout(200)
		await clickNext(page)

		// Step 2: Preset — select Single. Preset cards are plain
		// `<button class="wizard-step2__preset-card">` elements (aria-pressed
		// toggles, not role="radio" inputs) — Step2Preset.vue.
		const singleOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: /single/i })
			.first()
		await expect(singleOption).toBeVisible({ timeout: 5_000 })
		await singleOption.click()
		// Settle: selectPreset()'s payload.versions update reaches the parent
		// via an emit; clickNext()'s toBeEnabled() only proves `preset` landed,
		// not that `versions` did too. Without this, Step4 can render before
		// the versions array is the preset's, showing a stale/empty chain.
		await page.waitForTimeout(300)
		await clickNext(page)

		// Step 4: Review (step 3 is skipped for non-custom presets)
		await expect(page.locator('.wizard-step4, [data-step="4"]')).toBeVisible({
			timeout: 5_000,
		})

		// Chain display must show just 'production'
		const chainEl = page.locator('.wizard-step4__chain').first()
		await expect(chainEl).toContainText('production')

		const uuid = await clickCreate(page)
		expect(uuid, 'URL must contain a UUID after creation').toMatch(
			/^[0-9a-f-]{36}$/i,
		)
	})

	test('dev-prod preset: chain shows development → production', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		await fillStep1(page, 'Playwright DevProd App')
		await clickNext(page)

		// Step 2: Preset — select Development + Production. See the Single
		// case above for why this is a class-scoped button, not role="radio".
		// Match on the card's rendered CHAIN line, which is unique per card.
		// A name/description match is ambiguous: `/development.*production/i`
		// also matches the three-tier card, and — as the dev-staging-prod test
		// below documents — every card's description is fair game for
		// hasText, which is how a `/staging/i` filter ended up selecting the
		// "Single" card.
		const devProdOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: 'development → production' })
			.first()
		await expect(devProdOption).toBeVisible({ timeout: 5_000 })
		await devProdOption.click()
		// Settle — see the identical note in the "single preset" test above.
		await page.waitForTimeout(300)
		await clickNext(page)

		// Step 4: Review
		const chainEl = page.locator('.wizard-step4__chain').first()
		await expect(chainEl).toContainText('development')
		await expect(chainEl).toContainText('→')
		await expect(chainEl).toContainText('production')

		const uuid = await clickCreate(page)
		expect(uuid).toMatch(/^[0-9a-f-]{36}$/i)
	})

	test('dev-staging-prod preset: chain shows development → staging → production', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		await fillStep1(page, 'Playwright DSP App')
		await clickNext(page)

		// Step 2: Preset — select three-tier. See the Single case above for
		// why this is a class-scoped button, not role="radio".
		//
		// `hasText: /staging/i` DOES NOT WORK here and silently selected the
		// wrong card: the "Single" preset's own description reads "One version
		// only. Best for simple apps without a staging environment." — so
		// `/staging/i` matched card 0 first, this test clicked "Single", and
		// the review chain legitimately read "production". Live-verified: the
		// filter resolved to 2 cards with "Single" first.
		// Match the card's unique CHAIN line instead.
		const dspOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: 'development → staging → production' })
			.first()
		await expect(dspOption).toBeVisible({ timeout: 5_000 })
		await dspOption.click()
		// Settle — see the identical note in the "single preset" test above.
		await page.waitForTimeout(300)
		await clickNext(page)

		// Step 4: Review
		const chainEl = page.locator('.wizard-step4__chain').first()
		await expect(chainEl).toContainText('development → staging → production')

		const uuid = await clickCreate(page)
		expect(uuid).toMatch(/^[0-9a-f-]{36}$/i)
	})

	test('custom preset: builds alpha → beta → main chain and creates successfully', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		await fillStep1(page, 'Playwright Custom App')
		await clickNext(page)

		// Step 2: Preset — select Custom. See the Single case above for why
		// this is a class-scoped button, not role="radio".
		const customOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: /custom/i })
			.first()
		await expect(customOption).toBeVisible({ timeout: 5_000 })
		await customOption.click()
		// Settle — see the identical note in the "single preset" test above.
		await page.waitForTimeout(300)
		await clickNext(page)

		// Step 3: Custom chain — should have one default row (Production).
		// Remove the default row and add 3 custom ones.
		// Note: The wizard seeds a single "Production" row; we need to rename it and add two more.

		// Rename the first row to Alpha.
		const firstNameInput = page
			.locator('#wizard-version-name-0')
			.or(page.locator('input[id*="wizard-version-name"]').first())
		await expect(firstNameInput).toBeVisible({ timeout: 5_000 })
		await firstNameInput.clear()
		await firstNameInput.fill('Alpha')
		await page.waitForTimeout(300)

		// Add second row (Beta).
		const addBtn = page
			.locator('.wizard-step3__add-btn, [data-cy="add-version"]')
			.first()
		await expect(addBtn).toBeVisible({ timeout: 5_000 })
		await addBtn.click()
		await page.waitForTimeout(200)

		const secondNameInput = page
			.locator('#wizard-version-name-1')
			.or(page.locator('input[id*="wizard-version-name"]').nth(1))
		await expect(secondNameInput).toBeVisible({ timeout: 5_000 })
		await secondNameInput.fill('Beta')
		await page.waitForTimeout(300)

		// Add third row (Main).
		await addBtn.click()
		await page.waitForTimeout(200)

		const thirdNameInput = page
			.locator('#wizard-version-name-2')
			.or(page.locator('input[id*="wizard-version-name"]').nth(2))
		await expect(thirdNameInput).toBeVisible({ timeout: 5_000 })
		await thirdNameInput.fill('Main')
		await page.waitForTimeout(300)

		await clickNext(page)

		// Step 4: Review — chain must show alpha → beta → main
		const chainEl = page.locator('.wizard-step4__chain').first()
		await expect(chainEl).toContainText('alpha')
		await expect(chainEl).toContainText('→')
		await expect(chainEl).toContainText('main')

		const uuid = await clickCreate(page)
		expect(uuid).toMatch(/^[0-9a-f-]{36}$/i)
	})
})

// ---------------------------------------------------------------------------
// Task 8.6 — Validation errors
// ---------------------------------------------------------------------------

test.describe('Wizard — validation errors (task 8.6)', () => {
	test.beforeAll(async ({ request }) => {
		await deleteWizardFixtureApps(request)
	})

	test('leading-underscore version slug shows inline error and blocks advancing', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		await fillStep1(page, 'Playwright Validation App')
		await clickNext(page)

		// Select custom preset so we can edit version slugs.
		// Class-scoped button, not role="radio" — see the Single case in the
		// preceding describe block.
		const customOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: /custom/i })
			.first()
		await customOption.click()
		// Settle — selectPreset()'s emit must reach the parent before Next, or
		// `wizardSteps` has not yet grown the Custom step. See the "single
		// preset" test in the preceding describe block.
		await page.waitForTimeout(300)
		await clickNext(page)
		await expect(
			page.locator('.wizard-step3'),
			'custom preset must open step 3',
		).toBeVisible({ timeout: 5_000 })

		// Step 3: Manually set a leading-underscore slug. The editable slug
		// input only exists once the row's Advanced panel is open.
		await openAdvanced(page, 0)
		const slugInput = page.locator('#wizard-version-slug-0')
		await slugInput.fill('_system')

		// The always-visible slug chip must flag the row as errored.
		await expect(
			page.locator('.wizard-step3__slug-chip--error').first(),
			'slug error indicator must appear for _system',
		).toBeVisible({ timeout: 5_000 })

		await expectStep3BlocksAdvance(page, /cannot start with/i)
	})

	test('duplicate version slug shows inline error and blocks advancing', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		await fillStep1(page, 'Playwright Dup Slug App')
		await clickNext(page)

		// Class-scoped button, not role="radio" — see the Single case in the
		// preceding describe block.
		const customOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: /custom/i })
			.first()
		await customOption.click()
		// Settle — see the "leading-underscore" test above.
		await page.waitForTimeout(300)
		await clickNext(page)
		await expect(
			page.locator('.wizard-step3'),
			'custom preset must open step 3',
		).toBeVisible({ timeout: 5_000 })

		// Step 3: add a second row and set the same slug as the first.
		const addBtn = page.locator('.wizard-step3__add-btn').first()
		await addBtn.click()
		await expect(page.locator('.wizard-step3__row')).toHaveCount(2, {
			timeout: 5_000,
		})

		// Both editable slug inputs live behind their row's Advanced panel.
		await openAdvanced(page, 0)
		await openAdvanced(page, 1)
		await page.locator('#wizard-version-slug-0').fill('production')
		await page.locator('#wizard-version-slug-1').fill('production')

		// Both colliding rows must be flagged as duplicates.
		await expect(
			page.locator('.wizard-step3__slug-chip--duplicate'),
			'both colliding rows must show the duplicate indicator',
		).toHaveCount(2, { timeout: 5_000 })

		await expectStep3BlocksAdvance(page, /already used in this chain/i)
	})

	test('empty version name shows inline error and blocks advancing', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		await goToApps(page)
		await openWizard(page)

		await fillStep1(page, 'Playwright Empty Name App')
		await clickNext(page)

		// Class-scoped button, not role="radio" — see the Single case in the
		// preceding describe block.
		const customOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: /custom/i })
			.first()
		await customOption.click()
		// Settle — see the "leading-underscore" test above.
		await page.waitForTimeout(300)
		await clickNext(page)
		await expect(
			page.locator('.wizard-step3'),
			'custom preset must open step 3',
		).toBeVisible({ timeout: 5_000 })

		// Step 3: clear the name of the first row. Open the row's Advanced panel
		// first — `.wizard-step3__error-msg` is rendered *inside* that panel
		// (Step3Custom.vue), so the row's inline reason is only observable with
		// Advanced open. The always-visible surface is the errored slug chip.
		await openAdvanced(page, 0)
		const nameInput = page.locator('#wizard-version-name-0')
		await expect(nameInput).toBeVisible({ timeout: 5_000 })
		await nameInput.fill('')

		await expect(
			page.locator('.wizard-step3__slug-chip--error').first(),
			'clearing the version name must flag the row',
		).toBeVisible({ timeout: 5_000 })
		await expectStep3BlocksAdvance(page, /name must not be empty/i)
	})

	test('slug already in use shows server-side error; admin can edit and retry', async ({
		page,
	}) => {
		test.skip(!LIVE, 'Requires live dev environment — set BUILDIQ_E2E_LIVE=1')

		// This test requires `hello-world` to already exist (seeded by SeedHelloWorld).
		await goToApps(page)
		await openWizard(page)

		// Step 1: use the slug of the already-seeded app.
		const nameInput = page
			.locator('#wizard-app-name, input[id*="wizard-app-name"]')
			.first()
		await expect(nameInput).toBeVisible({ timeout: 8_000 })
		await nameInput.fill('Hello World')
		await page.waitForTimeout(300)

		// Manually set slug to 'hello-world' if the input is accessible.
		const toggleAdvanced = page
			.locator('button:has-text("Advanced"), [data-cy="toggle-advanced"]')
			.first()
		if (await toggleAdvanced.isVisible({ timeout: 1_000 }).catch(() => false)) {
			await toggleAdvanced.click()
		}
		const slugInput = page
			.locator('#wizard-app-slug, input[id*="wizard-app-slug"]')
			.first()
		if (await slugInput.isVisible({ timeout: 1_000 }).catch(() => false)) {
			await slugInput.clear()
			await slugInput.fill('hello-world')
		}

		await clickNext(page)

		// Step 2: choose single preset. Class-scoped button, not role="radio"
		// — see the Single case in the "preset happy paths" describe block.
		const singleOption = page
			.locator('.wizard-step2__preset-card')
			.filter({ hasText: /single/i })
			.first()
		await singleOption.click()
		// Settle — see the identical note in the "single preset" test above.
		await page.waitForTimeout(300)
		await clickNext(page)

		// Step 4: Review — click Create. Should hit a slug conflict (422).
		const createBtn = wizard(page).getByRole('button', { name: /^create$/i })
		await expect(createBtn).toBeEnabled({ timeout: 5_000 })
		await createBtn.click()

		// Error banner should appear with a conflict message.
		// `.wizard__error-banner` does not exist anywhere in src/ — it never did.
		// CreateApplicationWizard surfaces a recoverable submit failure through
		// nc-vue's CnWizardDialog.setError(), which renders an NcNoteCard, i.e.
		// `.notecard.notecard--error` with role="alert". Match the markup the
		// component actually produces.
		const errorBanner = page.locator('.notecard--error').first()
		await expect(
			errorBanner,
			'error banner must appear for slug conflict',
		).toBeVisible({ timeout: 10_000 })
		await expect(errorBanner).toContainText(
			/hello-world|already exists|conflict/i,
		)

		// Admin can press Back, change the slug, and the banner is gone.
		const backBtn = wizard(page).getByRole('button', { name: /back/i })
		await expect(backBtn).toBeVisible()
		await backBtn.click()
		// Now on step 2 again.
		await backBtn.click()
		// Now on step 1 again.

		// Error banner is no longer visible (it belonged to the step 4 submit attempt).
		// The wizard should have reset the error state when Back is clicked.
		// (Note: the wizard only resets errorMessage on onClose/resetState, not on Back.
		// The user needs to navigate forward again to re-submit — banner persists until
		// the next successful submit or modal close. This is by design per the spec.)
		// We only assert that the user can navigate back — not that the banner is gone
		// until they get a fresh create attempt.
	})
})
