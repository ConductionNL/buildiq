/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change `form-editor-logic`
 * (REQ-OBFEL-001..006) — the manifest-form-logic authoring surface: the
 * Steps manager, the Conditions (visibleWhen) builder, the Validation
 * builder, live dangling-reference warnings, and raw-JSON round-trip +
 * save.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * UN-QUARANTINED 2026-07-29 — green end to end (5/5) against a live instance.
 * The original was written blind against a surface that does not exist: it
 * seeded through a "Raw JSON" tab on `/applications/{slug}/design` (the raw
 * manifest editor is a sidebar tab on the app DETAIL page — the designer lives
 * at `/builder/:slug/pages`), and located field rows by text when a row renders
 * its key in an `<input :value>`. Both are rewritten below; REQ-OBFEL-005 has no
 * UI surface of its own (schema-bound form defaults) and is covered by the
 * FormPageEditor unit specs.
 *
 * Driving it for real also surfaced a product bug, now fixed: FormFieldBuilder
 * tracked open details panels by INDEX, so deleting an earlier row left the
 * panel open on a different field and swallowed its dangling-reference warning.
 */

import { test, expect } from '@playwright/test'
import { ensureApp, dismissOverlays, suppressSupportDialog } from './support/appFixture'
import { readStagedManifest } from './support/stagedManifest'

// Merge note (development -> feat/vue-3-migration, 2026-07-30): arrived as
// `process.env.NC_BASE_URL ?? 'http://localhost:8080'`, which ignores
// PLAYWRIGHT_BASE_URL. With NC_BASE_URL unset — how this suite is driven — that
// resolves to :8080, the SHARED `nextcloud` container. This spec PUTs the app
// manifest, so it would have written fixtures to somebody else's instance while
// `ensureApp()` (relative URL, config baseURL) created the app on :8099.
// See tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'

// Harness rewritten 2026-07-28. The original seeded through a "Raw JSON" tab on
// `/apps/openbuild/applications/{slug}/design` — neither the route nor the
// textarea exists (the designer is `/builder/:slug/pages`, and its host is
// PageDesignerHost). The form page is now seeded through the manifest API and
// every assertion reads the PERSISTED manifest back, which is a truer end-to-end
// check than inspecting an unsaved editor buffer.
test.describe('openbuild form-editor-logic', () => {
	// The page designer is a three-pane desktop surface: at Playwright's default
	// 1280x720 the page-list rows land below the fold (measured y=728), where the
	// click never settles. Give it a desktop viewport.
	test.use({ viewport: { width: 1600, height: 1200 } })

	const APP_SLUG = 'pw-form-logic'
	const FORM_PAGE_ID = 'e2e-form-logic'

	/**
	 * The manifest endpoint reads plain but writes wrapped: GET returns the
	 * manifest object itself, while PUT expects `{ manifest: {...} }` and
	 * rejects a bare body with `bad_request: Missing or invalid manifest`.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<object>} The persisted manifest.
	 */
	async function fetchManifest(page) {
		const resp = await page.request.get(
			`${BASE_URL}/index.php/apps/openbuild/api/applications/${APP_SLUG}/manifest`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(resp.ok(), 'GET manifest must succeed').toBeTruthy()
		return resp.json()
	}

	/**
	 * Replace the app's pages with a known baseline containing exactly one
	 * `type: "form"` page (three fields, no steps yet), so each scenario authors
	 * its own steps/conditions/validation from the same starting point and the
	 * suite is idempotent across runs.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {?Function} mutate - optional hook to shape the seeded page config
	 *   before it is written, for shapes the Design surface cannot author.
	 * @return {Promise<number>} Index of the seeded page in `manifest.pages`.
	 */
	async function seedFormPage(page, mutate = null) {
		const manifest = await fetchManifest(page)
		const pages = (manifest.pages || []).filter((p) => p.id !== FORM_PAGE_ID)
		const config = {
			fields: [
				{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
				{ key: 'email', label: 'Email', type: 'string' },
				{ key: 'phone', label: 'Phone', type: 'string' },
			],
		}
		if (mutate) {
			mutate(config)
		}
		pages.push({
			id: FORM_PAGE_ID,
			type: 'form',
			route: `/${FORM_PAGE_ID}`,
			config,
		})
		const resp = await page.request.put(
			`${BASE_URL}/index.php/apps/openbuild/api/applications/${APP_SLUG}/manifest`,
			{ headers: { 'OCS-APIRequest': 'true' }, data: { manifest: { ...manifest, pages } } },
		)
		expect(resp.ok(), 'seeding the form page via the manifest API must succeed').toBeTruthy()
		return pages.length - 1
	}

	/**
	 * Seed the form page and open it in the real Page Designer.
	 *
	 * Selection is by INDEX, not by text: a page-list row renders the page id in
	 * an `<input value=…>`, and `hasText` matches text content, never input
	 * values — the row's visible text is just its type tag. The seeded page is
	 * appended last, and seedFormPage() returns that index.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {?Function} mutate - optional seeded-config hook, see seedFormPage().
	 * @return {Promise<void>}
	 */
	async function seedFormPageAndSelect(page, mutate = null) {
		const index = await seedFormPage(page, mutate)
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages?_version=production`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		// Select by dispatching the row's own click event rather than a real
		// mouse click. Two things get in the way of clicking it for real:
		// the row's id/route inputs carry `@click.stop` (so a click landing on
		// one never reaches the row's select handler), and further down the list
		// `<section class="page-designer__centre">` overlaps the left pane and
		// intercepts pointer events at the row's position. Selecting a page is
		// setup here, not the behaviour under test, so dispatch it directly.
		const row = page.locator('.page-list-editor__row').nth(index)
		await row.scrollIntoViewIfNeeded()
		await row.dispatchEvent('click')
		await expect(page.locator('.form-page-editor')).toBeVisible({ timeout: 30_000 })
	}

	/**
	 * Read the designer's LIVE (staged) manifest.
	 *
	 * The original read this from a "Raw JSON" tab, which does not exist on the
	 * `/builder/:slug/pages` route. Read it from the designer component instead —
	 * same thing the tab used to show: the in-editor buffer, before any save.
	 * Deliberately NOT read back through the API: the host treats a successful
	 * save as a session boundary and bumps its session key, which resets the
	 * designer (and its selection) mid-scenario.
	 *
	 * The component handle lives in `support/stagedManifest.ts` — see the note
	 * there on why the previous `element.__vue__` read was Vue-2-only and had to
	 * become a component-tree walk.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<object>} The staged manifest.
	 */
	async function readManifest(page) {
		return readStagedManifest(page)
	}

	/**
	 * A field row in the FormFieldBuilder, BY INDEX.
	 *
	 * Not by text: a row renders the field key in an `<input :value>`, which Vue
	 * binds as a DOM property, so neither `hasText` nor an `[value=…]` attribute
	 * selector matches it — `filter({ hasText: 'email' })` resolved to zero rows
	 * and every scenario timed out on the first click. The seeded field order is
	 * fixed (wantsContact, email, phone), so index is both stable and readable.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {number} index - zero-based row index.
	 * @return {import('@playwright/test').Locator} The row locator.
	 */
	function fieldRow(page, index) {
		return page.locator('.form-field-builder__row').nth(index)
	}

	test.beforeEach(async ({ page }) => {
		// The first-open support dialog otherwise mounts a mask over the designer
		// and swallows every click; suppress it before the first navigation.
		await suppressSupportDialog(page)
		// Dedicated fixture app: these scenarios rewrite the app's pages wholesale,
		// which must not happen to the shared hello-world seed other suites rely on.
		await ensureApp(page, APP_SLUG, 'PW Form Logic')
	})

	test('REQ-OBFEL-001: add/assign/reorder/delete steps', async ({ page }) => {
		// @e2e openspec/specs/form-editor-logic/spec.md#adding-a-step-groups-fields-by-reference
		// @e2e openspec/specs/form-editor-logic/spec.md#reordering-steps-reorders-the-wizard
		// @e2e openspec/specs/form-editor-logic/spec.md#absent-steps-renders-the-single-step-state
		// @e2e openspec/specs/form-editor-logic/spec.md#deleting-a-step-returns-its-fields-to-the-unassigned-pool
		await seedFormPageAndSelect(page)

		// Absent-steps single-step state before the first add.
		await expect(page.getByText(/single step/i)).toBeVisible()

		// Add two steps and assign fields to each.
		const addStepBtn = page.locator('.form-steps-manager__add')
		await addStepBtn.click()
		await addStepBtn.click()

		const stepRows = page.locator('.form-steps-manager__step')
		await expect(stepRows).toHaveCount(2)

		await stepRows.nth(0).getByLabel(/step title/i).fill('Contact')
		await stepRows.nth(1).getByLabel(/step title/i).fill('Details')

		// Assign wantsContact + email to step 1, phone to step 2.
		await stepRows.nth(0).locator('.form-steps-manager__select').selectOption('wantsContact')
		await stepRows.nth(0).locator('.form-steps-manager__assign button').click()
		await stepRows.nth(0).locator('.form-steps-manager__select').selectOption('email')
		await stepRows.nth(0).locator('.form-steps-manager__assign button').click()
		await stepRows.nth(1).locator('.form-steps-manager__select').selectOption('phone')
		await stepRows.nth(1).locator('.form-steps-manager__assign button').click()

		let manifest = await readManifest(page)
		let formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(formPage.config.steps).toHaveLength(2)
		expect(formPage.config.steps[0].id).toBe('contact')
		expect(formPage.config.steps[0].fields).toEqual(['wantsContact', 'email'])
		expect(formPage.config.steps[1].fields).toEqual(['phone'])
		expect(formPage.config.fields).toHaveLength(3) // fields[] untouched.

		// Reorder: move step 2 up.
		await stepRows.nth(1).getByTitle(/move step up/i).click()
		manifest = await readManifest(page)
		formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(formPage.config.steps[0].title).toBe('Details')
		expect(formPage.config.steps[1].title).toBe('Contact')

		// Delete one step — its fields return to the pool.
		await page.locator('.form-steps-manager__remove').first().click()
		manifest = await readManifest(page)
		formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(formPage.config.steps).toHaveLength(1)
		await expect(page.locator('.form-steps-manager__pool')).toContainText('phone')

		// Delete the last step — `steps` key is removed entirely.
		await page.locator('.form-steps-manager__remove').first().click()
		manifest = await readManifest(page)
		formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(formPage.config).not.toHaveProperty('steps')

		// The scenario's final AND: saving while `steps` is non-empty and a field
		// is still unassigned auto-assigns that field to the FINAL step with a
		// warning mark, so the written manifest satisfies the leaf validator's
		// complete-partition rule (every declared field in exactly one step).
		//
		// Re-add one step and assign only `wantsContact`, leaving `email` and
		// `phone` in the pool. The warning mark is the pool's own note, which
		// renders only while at least one step exists — exactly the condition
		// under which the save-time normalisation fires (FormStepsManager's
		// `__pool-note` is the live-editor half of the contract,
		// `assignUnassignedFieldsToFinalStep()` in
		// src/services/manifestValidation/formLogic.js the save-time half).
		await addStepBtn.click()
		const finalStep = page.locator('.form-steps-manager__step').first()
		await finalStep.getByLabel(/step title/i).fill('Everything')
		await finalStep.locator('.form-steps-manager__select').selectOption('wantsContact')
		await finalStep.locator('.form-steps-manager__assign button').click()
		await expect(page.locator('.form-steps-manager__pool-note')).toBeVisible()

		// Save LAST. A successful save is a session boundary: the host bumps its
		// session key, which resets the designer and its page selection, so
		// nothing after this may read the staged manifest. Assert against the
		// PERSISTED manifest instead — which is also the stronger check, since the
		// complete-partition rule is a claim about what was WRITTEN.
		await page.getByRole('button', { name: /save pages/i }).click()
		await expect.poll(async () => {
			const persisted = await fetchManifest(page)
			const persistedPage = (persisted.pages || []).find((p) => p.id === FORM_PAGE_ID)
			return persistedPage?.config?.steps?.[0]?.fields ?? null
		}, {
			// The repo's `expect` default (playwright.config.ts). NOT 30_000:
			// that equals the whole per-test budget, so the poll could never
			// actually reach it — the test would die first and report a test
			// timeout instead of this assertion's own message, which is the
			// exact failure mode the config's comment says the shorter expect
			// timeout exists to prevent.
			timeout: 15_000,
			message: 'saving must append the still-unassigned field keys to the final step',
		}).toEqual(['wantsContact', 'email', 'phone'])
	})

	test('REQ-OBFEL-002: condition builder writes LOCAL visibleWhen', async ({ page }) => {
		// @e2e openspec/specs/form-editor-logic/spec.md#authoring-a-condition-with-the-field-op-and-value-pickers
		// @e2e openspec/specs/form-editor-logic/spec.md#ordering-op-coerces-the-value-to-a-number
		// @e2e openspec/specs/form-editor-logic/spec.md#clearing-the-condition-removes-the-key
		// @e2e openspec/specs/form-editor-logic/spec.md#advanced-endpoint-or-source-conditions-pass-through-untouched
		await seedFormPageAndSelect(page)

		const emailRow = fieldRow(page, 1)
		await emailRow.locator('.form-field-builder__disclosure').click()

		// eq (default, omitted) + boolean coercion.
		await emailRow.getByLabel(/condition field/i).selectOption('wantsContact')
		await emailRow.getByLabel(/condition value/i).fill('true')

		let manifest = await readManifest(page)
		let email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.visibleWhen).toEqual({ field: 'wantsContact', value: true })

		// gt + numeric coercion.
		await emailRow.getByLabel(/condition operator/i).selectOption('gt')
		await emailRow.getByLabel(/condition value/i).fill('3')
		manifest = await readManifest(page)
		email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.visibleWhen).toEqual({ field: 'wantsContact', op: 'gt', value: 3 })

		// Clear removes the key.
		await emailRow.getByTitle(/clear condition/i).click()
		manifest = await readManifest(page)
		email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email).not.toHaveProperty('visibleWhen')

		// An advanced (endpoint-shaped) condition authored OUTSIDE the Design
		// surface passes through untouched: Design renders a read-only summary
		// and an unrelated edit to the same field leaves it byte-for-byte intact.
		//
		// The original authored this through a "Raw JSON" tab on the designer.
		// There is no such tab — the raw manifest editor is a separate sidebar
		// tab on the app detail page (ApplicationManifestTab), not part of the
		// page designer — so seed the advanced condition through the manifest
		// API instead. Same contract under test: a shape Design cannot author
		// must survive Design editing the field around it.
		await seedFormPageAndSelect(page, (config) => {
			config.fields[1].visibleWhen = { endpoint: '/api/status', field: 'ready' }
		})
		const advancedRow = fieldRow(page, 1)
		await advancedRow.locator('.form-field-builder__disclosure').click()
		await expect(advancedRow.locator('.visible-when-builder__advanced')).toBeVisible()

		// Edit the same field's label (the row's second input) — an edit Design
		// DOES own, right next to the condition it must not touch.
		await advancedRow.getByPlaceholder('Label').fill('Email address')
		manifest = await readManifest(page)
		const advanced = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(advanced.label).toBe('Email address')
		expect(advanced.visibleWhen).toEqual({ endpoint: '/api/status', field: 'ready' })
	})

	test('REQ-OBFEL-003: validation builder writes the structured object', async ({ page }) => {
		// @e2e openspec/specs/form-editor-logic/spec.md#authoring-validation-writes-the-structured-object
		// @e2e openspec/specs/form-editor-logic/spec.md#legacy-flat-keys-prefill-and-normalise-on-first-edit
		// @e2e openspec/specs/form-editor-logic/spec.md#a-non-compiling-pattern-is-rejected-inline
		await seedFormPageAndSelect(page)

		const emailRow = fieldRow(page, 1)
		await emailRow.locator('.form-field-builder__disclosure').click()

		await emailRow.locator('input[type="checkbox"]').check()
		await emailRow.getByLabel(/minimum/i).fill('5')
		await emailRow.getByLabel(/maximum/i).fill('254')
		await emailRow.getByLabel(/^pattern$/i).fill('^[^@]+@[^@]+$')
		await emailRow.getByLabel(/^message$/i).fill('i18n.email-invalid')

		let manifest = await readManifest(page)
		let email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.validation).toEqual({
			required: true,
			min: 5,
			max: 254,
			pattern: '^[^@]+@[^@]+$',
			message: 'i18n.email-invalid',
		})

		// A non-compiling pattern is rejected inline and is NEVER written — the
		// last known-good pattern stays.
		await emailRow.getByLabel(/^pattern$/i).fill('[a-')
		await expect(emailRow.locator('.field-validation-builder__pattern-error')).toBeVisible()
		manifest = await readManifest(page)
		email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.validation.pattern).toBe('^[^@]+@[^@]+$') // unchanged

		// Legacy flat `required` / `pattern` (the pre-REQ-OBFEL-003 shape) is
		// displayed by the builder and NORMALISED into `validation` the moment
		// the field is edited, with the flat keys dropped. Seeded through the
		// manifest API — the original drove a "Raw JSON" designer tab that does
		// not exist (see REQ-OBFEL-002 for the same substitution).
		await seedFormPageAndSelect(page, (config) => {
			config.fields[2].required = true
			config.fields[2].pattern = '^\\d+$'
		})
		const phoneRow = fieldRow(page, 2)
		await phoneRow.locator('.form-field-builder__disclosure').click()
		await expect(phoneRow.locator('input[type="checkbox"]')).toBeChecked()
		await phoneRow.getByLabel(/^message$/i).fill('i18n.phone-invalid')

		manifest = await readManifest(page)
		const phone = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'phone')
		expect(phone.validation).toEqual({ required: true, pattern: '^\\d+$', message: 'i18n.phone-invalid' })
		expect(phone).not.toHaveProperty('required')
		expect(phone).not.toHaveProperty('pattern')
		// The untouched sibling keeps its own shape — normalisation is per-field.
		const sibling = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(sibling).not.toHaveProperty('validation')
	})

	test('REQ-OBFEL-004: dangling references warn live without deletion', async ({ page }) => {
		// @e2e openspec/specs/form-editor-logic/spec.md#deleting-a-field-warns-on-the-condition-that-references-it
		// @e2e openspec/specs/form-editor-logic/spec.md#a-step-referencing-a-removed-field-warns
		await seedFormPageAndSelect(page)

		await fieldRow(page, 1).locator('.form-field-builder__disclosure').click()
		await fieldRow(page, 1).getByLabel(/condition field/i).selectOption('wantsContact')

		await page.locator('.form-steps-manager__add').click()
		const stepRow = page.locator('.form-steps-manager__step').first()
		await stepRow.locator('.form-steps-manager__select').selectOption('wantsContact')
		await stepRow.locator('.form-steps-manager__assign button').click()

		// Remove the wantsContact field (row 0) — email/phone shift up by one, so
		// email is row 0 from here on.
		await fieldRow(page, 0).locator('.form-field-builder__remove').click()

		// Both warnings appear immediately, without deleting anything.
		await expect(fieldRow(page, 0).locator('[role="alert"]')).toBeVisible()
		await expect(stepRow.locator('[role="alert"]')).toBeVisible()

		// The manifest still carries the stale visibleWhen and step entry — a
		// dangling reference WARNS, it never silently rewrites the author's data.
		const manifest = await readManifest(page)
		const formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(formPage.config.fields.find((f) => f.key === 'email').visibleWhen.field).toBe('wantsContact')
		expect(formPage.config.steps[0].fields).toContain('wantsContact')
	})

	test('REQ-OBFEL-006: externally-authored shapes round-trip through Design and survive save', async ({ page }) => {
		// @e2e openspec/specs/form-editor-logic/spec.md#authored-logic-persists-via-the-existing-applicationversion-save
		//
		// NOT anchored here: REQ-OBFEL-006's sibling scenario
		// `raw-json-authored-logic-survives-unrelated-editor-edits`. That scenario
		// asserts a Design <-> Raw JSON TAB round-trip ("switching back to Raw
		// JSON shows all four byte-for-byte unchanged"), and no such tab exists on
		// this route: the raw-JSON editor is `ApplicationManifestTab`, a sidebar
		// tab on the VirtualAppDetail page (`/applications/:objectId`) that reads
		// and writes the Application object, while the designer here saves onto
		// the ApplicationVersion. PageDesigner.vue records the decision explicitly
		// ("resolved: sidebar, not a designer tab"). The seeding below therefore
		// goes through the manifest API, which exercises externally-authored
		// shapes surviving a Design edit + save — but not the tab round-trip the
		// scenario names.
		//
		// Author every shape Design cannot itself produce — an advanced condition,
		// a pre-built steps array, a structured validation object, and a wholly
		// unknown key — then edit ONE thing in Design and save. Nothing else may
		// move, in the editor or in what lands on the server.
		const steps = [{ id: 'contact', title: 'Contact', fields: ['wantsContact', 'email', 'phone'] }]
		await seedFormPageAndSelect(page, (config) => {
			config.steps = steps
			config.fields[1].visibleWhen = { endpoint: '/api/status', field: 'ready' }
			config.fields[2].validation = { required: true }
			config.customUnknownKey = 'preserved'
			config.submitLabel = 'form.submit'
		})
		const before = await readManifest(page)
		const beforeOtherKeys = Object.keys(before).filter((k) => k !== 'pages').sort()

		await page.getByLabel(/submit label/i).fill('form.submit.updated')

		// In the editor: only submitLabel moved.
		const staged = await readManifest(page)
		const stagedPage = staged.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(stagedPage.config.submitLabel).toBe('form.submit.updated')
		expect(stagedPage.config.steps).toEqual(steps)
		expect(stagedPage.config.fields.find((f) => f.key === 'email').visibleWhen)
			.toEqual({ endpoint: '/api/status', field: 'ready' })
		expect(stagedPage.config.fields.find((f) => f.key === 'phone').validation).toEqual({ required: true })
		expect(stagedPage.config.customUnknownKey).toBe('preserved')

		// After save: the same holds of what the server actually stored. Read it
		// back through the API rather than sniffing the request body — a payload
		// the backend rewrites (or rejects) would still look correct on the wire.
		await page.getByRole('button', { name: /save pages/i }).click()
		await expect.poll(async () => {
			const persisted = await fetchManifest(page)
			const persistedPage = (persisted.pages || []).find((p) => p.id === FORM_PAGE_ID)
			return persistedPage?.config?.submitLabel ?? null
		}, { timeout: 30_000 }).toBe('form.submit.updated')

		const saved = await fetchManifest(page)
		const savedPage = saved.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(savedPage.config.steps).toEqual(steps)
		expect(savedPage.config.fields.find((f) => f.key === 'email').visibleWhen)
			.toEqual({ endpoint: '/api/status', field: 'ready' })
		expect(savedPage.config.fields.find((f) => f.key === 'phone').validation).toEqual({ required: true })
		expect(savedPage.config.customUnknownKey).toBe('preserved')
		// Every other top-level manifest key is untouched by a page-designer save.
		expect(Object.keys(saved).filter((k) => k !== 'pages').sort()).toEqual(beforeOtherKeys)
	})
})
