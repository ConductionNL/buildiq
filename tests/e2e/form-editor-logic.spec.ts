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
 * These specs were authored and NOT run against the shared dev instance
 * (per task instructions); they follow the same structure/selectors as
 * `page-designer.spec.ts` and mirror the same #41 quarantine.
 */

import { test, expect } from '@playwright/test'

// STILL QUARANTINED — #41's blockers are gone, but this suite's seeding is
// stale: it navigates to `/apps/openbuild/applications/{slug}/design`, which is
// not a route in the manifest (the page designer lives at
// `/builder/:slug/pages`), and it drives a "Raw JSON" tab plus an
// `.application-editor__textarea` that do not exist anywhere in src. Its own
// header records that it was authored without ever being run. Re-seeding the
// form page through the manifest API and driving the real Page Designer is a
// rewrite of its harness, not a locator fix.
test.describe.skip('openbuild form-editor-logic', () => {
	const APP_SLUG = 'hello-world'
	const FORM_PAGE_ID = 'e2e-form-logic'

	/**
	 * Seed a `type: "form"` page (three fields: `wantsContact` boolean,
	 * `email` string, `phone` string) via the Raw JSON tab, then switch back
	 * to Design and select it. Every test starts from this state so each
	 * scenario authors its own steps/conditions/validation from a known,
	 * field-only (no steps yet) baseline.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<void>}
	 */
	async function seedFormPageAndSelect(page) {
		await page.goto(`/apps/openbuild/applications/${APP_SLUG}/design`)
		await page.waitForSelector('.page-designer__left', { timeout: 20_000 })

		await page.getByRole('button', { name: /raw json/i }).click()
		const textarea = page.locator('.application-editor__textarea')
		await expect(textarea).toBeVisible()

		const raw = await textarea.inputValue()
		const manifest = JSON.parse(raw)
		manifest.pages = Array.isArray(manifest.pages) ? manifest.pages : []
		manifest.pages = manifest.pages.filter((p) => p.id !== FORM_PAGE_ID)
		manifest.pages.push({
			id: FORM_PAGE_ID,
			type: 'form',
			route: `/${FORM_PAGE_ID}`,
			config: {
				fields: [
					{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
					{ key: 'email', label: 'Email', type: 'string' },
					{ key: 'phone', label: 'Phone', type: 'string' },
				],
			},
		})
		await textarea.fill(JSON.stringify(manifest, null, 2))

		await page.getByRole('button', { name: /^design$/i }).click()
		await page.waitForSelector('.page-designer__left', { timeout: 10_000 })
		await page.getByText(FORM_PAGE_ID).first().click()
	}

	/**
	 * Read the manifest back out via the Raw JSON tab.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<object>}
	 */
	async function readManifest(page) {
		await page.getByRole('button', { name: /raw json/i }).click()
		const textarea = page.locator('.application-editor__textarea')
		const raw = await textarea.inputValue()
		await page.getByRole('button', { name: /^design$/i }).click()
		return JSON.parse(raw)
	}

	test('REQ-OBFEL-001: add/assign/reorder/delete steps', async ({ page }) => {
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
	})

	test('REQ-OBFEL-002: condition builder writes LOCAL visibleWhen', async ({ page }) => {
		await seedFormPageAndSelect(page)

		const fieldRows = page.locator('.form-field-builder__row')
		const emailRow = fieldRows.filter({ hasText: 'email' })
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

		// Advanced endpoint condition authored in Raw JSON passes through
		// untouched — Design shows the read-only summary and an unrelated
		// field edit leaves it byte-for-byte unchanged.
		await page.getByRole('button', { name: /raw json/i }).click()
		const textarea = page.locator('.application-editor__textarea')
		manifest = JSON.parse(await textarea.inputValue())
		const formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		formPage.config.fields.find((f) => f.key === 'email').visibleWhen = { endpoint: '/api/status', field: 'ready' }
		await textarea.fill(JSON.stringify(manifest, null, 2))
		await page.getByRole('button', { name: /^design$/i }).click()

		await emailRow.locator('.form-field-builder__disclosure').click()
		await expect(emailRow.getByText(/advanced condition/i)).toBeVisible()

		await page.getByLabel(/^label$/i).first().fill('Email address')
		manifest = await readManifest(page)
		email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.visibleWhen).toEqual({ endpoint: '/api/status', field: 'ready' })
	})

	test('REQ-OBFEL-003: validation builder writes the structured object', async ({ page }) => {
		await seedFormPageAndSelect(page)

		const fieldRows = page.locator('.form-field-builder__row')
		const emailRow = fieldRows.filter({ hasText: 'email' })
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

		// Seed a field with legacy flat required/pattern via Raw JSON, edit
		// its validation, assert normalisation.
		await page.getByRole('button', { name: /raw json/i }).click()
		const textarea = page.locator('.application-editor__textarea')
		manifest = JSON.parse(await textarea.inputValue())
		const formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		formPage.config.fields.find((f) => f.key === 'phone').required = true
		formPage.config.fields.find((f) => f.key === 'phone').pattern = '^\\d+$'
		await textarea.fill(JSON.stringify(manifest, null, 2))
		await page.getByRole('button', { name: /^design$/i }).click()

		const phoneRow = fieldRows.filter({ hasText: 'phone' })
		await phoneRow.locator('.form-field-builder__disclosure').click()
		await expect(phoneRow.locator('input[type="checkbox"]')).toBeChecked()
		await phoneRow.getByLabel(/^message$/i).fill('i18n.phone-invalid')

		manifest = await readManifest(page)
		const phone = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'phone')
		expect(phone.validation).toEqual({ required: true, pattern: '^\\d+$', message: 'i18n.phone-invalid' })
		expect(phone).not.toHaveProperty('required')
		expect(phone).not.toHaveProperty('pattern')
		// The untouched sibling (email) keeps its structured object as authored above.
		email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.validation.pattern).toBe('^[^@]+@[^@]+$')

		// A non-compiling pattern is rejected inline and never written.
		await emailRow.getByLabel(/^pattern$/i).fill('[a-')
		await expect(emailRow.locator('.field-validation-builder__pattern-error')).toBeVisible()
		manifest = await readManifest(page)
		email = manifest.pages.find((p) => p.id === FORM_PAGE_ID).config.fields.find((f) => f.key === 'email')
		expect(email.validation.pattern).toBe('^[^@]+@[^@]+$') // unchanged
	})

	test('REQ-OBFEL-004: dangling references warn live without deletion', async ({ page }) => {
		await seedFormPageAndSelect(page)

		const fieldRows = page.locator('.form-field-builder__row')
		const emailRow = fieldRows.filter({ hasText: 'email' })
		await emailRow.locator('.form-field-builder__disclosure').click()
		await emailRow.getByLabel(/condition field/i).selectOption('wantsContact')

		await page.locator('.form-steps-manager__add').click()
		const stepRow = page.locator('.form-steps-manager__step').first()
		await stepRow.locator('.form-steps-manager__select').selectOption('wantsContact')
		await stepRow.locator('.form-steps-manager__assign button').click()

		// Remove the wantsContact field.
		const wantsContactRow = fieldRows.filter({ hasText: 'wantsContact' })
		await wantsContactRow.locator('.form-field-builder__remove').click()

		// Both warnings appear immediately.
		await expect(emailRow.locator('[role="alert"]')).toBeVisible()
		await expect(stepRow.locator('[role="alert"]')).toBeVisible()

		// Raw JSON still carries the stale visibleWhen and step entry.
		const manifest = await readManifest(page)
		const formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(formPage.config.fields.find((f) => f.key === 'email').visibleWhen.field).toBe('wantsContact')
		expect(formPage.config.steps[0].fields).toContain('wantsContact')
	})

	test('REQ-OBFEL-006: raw JSON round-trip + save', async ({ page }) => {
		await seedFormPageAndSelect(page)

		await page.getByRole('button', { name: /raw json/i }).click()
		const textarea = page.locator('.application-editor__textarea')
		const manifest = JSON.parse(await textarea.inputValue())
		const formPage = manifest.pages.find((p) => p.id === FORM_PAGE_ID)
		formPage.config.steps = [{ id: 'contact', title: 'Contact', fields: ['wantsContact', 'email', 'phone'] }]
		formPage.config.fields.find((f) => f.key === 'email').visibleWhen = { endpoint: '/api/status', field: 'ready' }
		formPage.config.fields.find((f) => f.key === 'phone').validation = { required: true }
		formPage.config.customUnknownKey = 'preserved'
		formPage.config.submitLabel = 'form.submit'
		const beforeSnapshot = JSON.stringify(manifest)
		await textarea.fill(JSON.stringify(manifest, null, 2))

		// Edit the submit label in Design — byte-for-byte survival of
		// everything else in Raw JSON afterwards.
		await page.getByRole('button', { name: /^design$/i }).click()
		await page.getByLabel(/submit label/i).fill('form.submit.updated')

		const raw2 = await (async () => {
			await page.getByRole('button', { name: /raw json/i }).click()
			return textarea.inputValue()
		})()
		const after = JSON.parse(raw2)
		const afterFormPage = after.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(afterFormPage.config.steps).toEqual(formPage.config.steps)
		expect(afterFormPage.config.fields.find((f) => f.key === 'email').visibleWhen).toEqual({ endpoint: '/api/status', field: 'ready' })
		expect(afterFormPage.config.fields.find((f) => f.key === 'phone').validation).toEqual({ required: true })
		expect(afterFormPage.config.customUnknownKey).toBe('preserved')
		expect(afterFormPage.config.submitLabel).toBe('form.submit.updated')
		void beforeSnapshot // (kept for readability of the "before" intent above)

		// Save and assert the ApplicationVersion PUT/PATCH payload carries the
		// new shapes with every other top-level manifest key unchanged.
		const beforeOtherKeys = Object.keys(after).filter((k) => k !== 'pages')
		const [request] = await Promise.all([
			page.waitForRequest((req) => /applicationVersion/.test(req.url()) && ['PUT', 'PATCH'].includes(req.method())),
			page.getByRole('button', { name: /^save/i }).click(),
		])
		const payload = request.postDataJSON()
		const savedManifest = payload.manifest ?? payload
		const savedFormPage = savedManifest.pages.find((p) => p.id === FORM_PAGE_ID)
		expect(savedFormPage.config.steps).toEqual(formPage.config.steps)
		const afterOtherKeys = Object.keys(savedManifest).filter((k) => k !== 'pages')
		expect(afterOtherKeys.sort()).toEqual(beforeOtherKeys.sort())
	})
})
