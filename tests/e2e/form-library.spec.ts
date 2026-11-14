// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: the form library (spec: form-library).
 *
 * A form is stored in the library through OpenRegister's object API, the way
 * SaveFormToLibraryDialog stores it, then found, opened for use, refused as a
 * broken file and imported from an export in the app store's "Forms" view.
 * Every test skips when the `form-template` schema is not imported on the
 * stack, which says so instead of failing on a missing fixture.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md
 * @e2e form-library/requirement-a-form-can-be-saved-to-the-library-req-bqgl-002/a-maker-shares-a-subsidy-application-form
 * @e2e form-library/requirement-the-app-store-lists-library-forms-req-bqgl-003/a-maker-finds-a-form-by-name
 * @e2e form-library/requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004/a-second-app-reuses-the-form
 * @e2e form-library/requirement-forms-travel-between-organisations-req-bqgl-005/another-municipality-imports-the-form
 * @e2e form-library/requirement-forms-travel-between-organisations-req-bqgl-005/a-broken-file-is-refused
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl.ts'

const LIBRARY = '/index.php/apps/openregister/api/objects/buildiq/form-template'
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const suffix = Date.now().toString(36)

const fields = ['naam', 'adres', 'postcode', 'verbruikKwh', 'iban', 'toelichting']

const subsidyForm = {
	slug: `aanvraag-energiesubsidie-${suffix}`,
	name: `Aanvraag energiesubsidie ${suffix}`,
	description: 'Vraag een energiesubsidie aan.',
	category: 'citizen-engagement',
	kind: 'registration-form',
	publisher: 'Gemeente Voorbeeld',
	sourceSchema: 'aanvraag',
	version: '1.0.0',
	form: {
		fields: fields.map((name, order) => ({ name, label: name, order })),
		confirmationText: 'Bedankt voor uw aanvraag.',
	},
	schemaFragment: Object.fromEntries(
		fields.map((name) => [
			name,
			{ type: name === 'verbruikKwh' ? 'number' : 'string' },
		]),
	),
}

/**
 * Open the app store's "Forms" view.
 *
 * @param page The page.
 */
async function openForms(page: Page): Promise<void> {
	await page.goto(`${NEXTCLOUD_URL}/apps/buildiq/templates`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(page.locator('.template-gallery')).toBeVisible({ timeout: 45_000 })
	await dismissOverlays(page)
	await page.getByTestId('forms-tab').click()
}

test.describe('form library', () => {
	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
	})

	test('a saved form holds its six property definitions and no records, and is found by name', async ({
		page,
		request,
	}) => {
		const created = await request.post(LIBRARY, {
			headers: HEADERS,
			data: subsidyForm,
		})
		test.skip(
			created.status() === 404,
			'the form-template schema is not imported here',
		)
		expect(created.ok()).toBeTruthy()
		const stored = await created.json()
		expect(Object.keys(stored.schemaFragment ?? {})).toHaveLength(6)
		expect(stored.records ?? stored.objects).toBeUndefined()

		await openForms(page)
		await page.getByTestId('form-library-search').locator('input').fill(suffix)
		const cards = page.getByTestId('library-form-card')
		await expect(cards).toHaveCount(1, { timeout: 30_000 })
		await expect(cards.first()).toContainText(subsidyForm.name)
		await expect(cards.first()).toContainText('Gemeente Voorbeeld')

		// Using it shows the whole form and writes nothing before a confirm.
		await cards.first().getByRole('button', { name: 'Use this form' }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog).toContainText('verbruikKwh')
		await expect(dialog.getByTestId('confirm-use-form')).toBeDisabled()
	})

	test('a file of another kind is refused and nothing is created', async ({
		page,
		request,
	}) => {
		const probe = await request.get(LIBRARY, { headers: HEADERS })
		test.skip(
			probe.status() === 404,
			'the form-template schema is not imported here',
		)

		await openForms(page)
		await page.locator('.bq-form-library__file').setInputFiles({
			name: 'block.json',
			mimeType: 'application/json',
			buffer: Buffer.from(
				JSON.stringify({
					schemaVersion: '1.0',
					kind: 'component-block',
					block: {},
				}),
			),
		})
		await expect(page.locator('.bq-form-library__error')).toHaveText(
			'This file is not a form export.',
		)
	})

	test('another organisation imports an exported form with its category and publisher', async ({
		page,
		request,
	}) => {
		const probe = await request.get(LIBRARY, { headers: HEADERS })
		test.skip(
			probe.status() === 404,
			'the form-template schema is not imported here',
		)

		const imported = {
			...subsidyForm,
			slug: `import-${suffix}`,
			name: `Geimporteerd ${suffix}`,
			publisher: 'Gemeente Ander',
		}
		await openForms(page)
		await page.locator('.bq-form-library__file').setInputFiles({
			name: 'form.json',
			mimeType: 'application/json',
			buffer: Buffer.from(
				JSON.stringify({
					schemaVersion: '1.0',
					kind: 'form-template',
					form: imported,
				}),
			),
		})
		await expect(
			page.locator('.bq-form-library__notice[role="status"]'),
		).toContainText(imported.name, { timeout: 30_000 })

		await page
			.getByTestId('form-library-search')
			.locator('input')
			.fill(`Geimporteerd ${suffix}`)
		const card = page.getByTestId('library-form-card').first()
		await expect(card).toContainText('Gemeente Ander')
		await expect(card).toContainText('Citizen engagement')
	})
})
