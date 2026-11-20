/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `forms-multi-product-request`, task T07 (REQ-BQMP-003): a journey with a
 * bundle write and a write that repeats per product is authored, two products
 * are filled in and submitted in the preview, and a real run of the same
 * answers leaves one bundle record and two request records, each pointing at
 * the bundle and each on its own case type.
 *
 * The journey is authored through buildiq's save route (`POST /api/journeys`,
 * the route the Journey Designer's Save calls, which judges every write with
 * the run's own validator), then opened in the designer, where the writes
 * editor must show the bundle, the repetition over `producten` and both
 * targets. The designer's own controls are covered by Vitest
 * (`JourneyWritesEditor.spec.js`, `journeyValidation.spec.js`).
 *
 * THE PREVIEW WRITES NOTHING, ON PURPOSE (JourneyPreviewPane: "The preview
 * saves nothing and writes into no register"). So the preview half proves the
 * resident's side renders: the product list and the per-item review. The
 * records are written by OpenRegister's journey run, driven through its run
 * API (`/apps/openregister/api/journey-runs`, the endpoint CnJourney's run
 * store uses in a portal) with the same answers.
 *
 * GUARDED, because this change is inert until its two siblings land (design
 * "Risks"):
 *
 *   - the run: `forEach`, `targetBy`, `targets` and `bundle` in OpenRegister's
 *     journey run (`or-form-and-journey-registry` REQ-FJR-009 / REQ-FJR-010,
 *     openregister#10598). Without a journey-run API the test skips;
 *   - the widget: nextcloud-vue's `sub-objects` field and the per-item review
 *     in CnJourney (T06). Without the product list in the preview the test
 *     skips.
 *
 * It also needs target schemas, which it does not create. It runs when the
 * instance names them, and skips with that reason otherwise:
 *
 *   JOURNEY_MP_REGISTER        the register holding both schemas
 *   JOURNEY_MP_BUNDLE_SCHEMA   the bundle schema (e.g. `aanvraagbundel`), with a string `referentie`
 *   JOURNEY_MP_REQUEST_SCHEMA  the request schema (e.g. `zaak`), with strings `omschrijving`
 *                              and `bundel`, and the case type property below
 *   JOURNEY_MP_TYPE_PROPERTY   the request schema's case type property (default `zaaktype`)
 *
 * `afterAll` deletes the journey, the library form and every record the run
 * wrote.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md
 * @e2e journey-designer/requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003/a-resident-requests-two-products-at-once
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const REGISTER = process.env.JOURNEY_MP_REGISTER ?? ''
const BUNDLE_SCHEMA = process.env.JOURNEY_MP_BUNDLE_SCHEMA ?? ''
const REQUEST_SCHEMA = process.env.JOURNEY_MP_REQUEST_SCHEMA ?? ''
const TYPE_PROPERTY = process.env.JOURNEY_MP_TYPE_PROPERTY ?? 'zaaktype'

const OR_API = '/index.php/apps/openregister/api'
const FORM_LIBRARY = `${OR_API}/objects/buildiq/form-template`
const JOURNEYS = `${OR_API}/objects/buildiq/journey`
const JOURNEY_RUNS = `${OR_API}/journey-runs`
const RUN_ID = Date.now().toString(36)
const MARKER = `pw-bundle-${RUN_ID}`
const STEP_ID = 'aanvraag'

/** The two products, their case types and what the resident types for each. */
const PRODUCTS = [
	{ product: 'parkeervergunning', typeValue: 'PV', toelichting: 'Kenteken AB-123-C' },
	{ product: 'afvalcontainer', typeValue: 'AC', toelichting: 'Container van 240 liter' },
]

/** What afterAll removes. */
const cleanup = { journey: '', form: '', records: [] as Array<{ schema: string; id: string }> }

/**
 * Call a Nextcloud route from the page, with its session and request token.
 *
 * @param page   A logged-in page.
 * @param method HTTP method.
 * @param path   Path from the web root.
 * @param body   JSON body, if any.
 * @return Status and parsed body.
 */
async function call(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(path, {
				method,
				headers: { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await resp.text()
			let data: unknown = text
			try {
				data = JSON.parse(text)
			} catch {
				// Not JSON; keep the text for the failure message.
			}
			return { status: resp.status, data }
		},
		{ method, path, body },
	)
}

/**
 * The id OpenRegister knows a stored object by.
 *
 * @param body The decoded object.
 * @return The id, or ''.
 */
function idOf(body: Record<string, any>): string {
	return String(body?.['@self']?.id ?? body?.uuid ?? body?.id ?? '')
}

/**
 * The rows of a list answer: a bare array or `{results}`.
 *
 * @param data The decoded body.
 * @return The rows.
 */
function rowsOf(data: any): Array<Record<string, any>> {
	return Array.isArray(data) ? data : (data?.results ?? [])
}

/**
 * The library form the journey's step references: one product list field.
 *
 * @return The form-template record.
 */
function productListForm(): Record<string, unknown> {
	return {
		name: `PW product request ${RUN_ID}`,
		slug: `pw-product-request-${RUN_ID}`,
		kind: 'form-page',
		form: {
			fields: [{
				key: 'producten',
				label: 'Producten',
				type: 'product-list',
				widget: 'sub-objects',
				columns: [
					{ key: 'product', label: 'Product', options: PRODUCTS.map((p) => p.product) },
					{ key: 'toelichting', label: 'toelichting' },
				],
				maxItems: 10,
			}],
		},
	}
}

/**
 * The journey: one form step with the bundle write and the repeating write,
 * then the review.
 *
 * @param formId The library form's id.
 * @return The journey as the designer saves it.
 */
function multiProductJourney(formId: string): Record<string, unknown> {
	return {
		title: `PW multi-product request ${RUN_ID}`,
		description: 'Two products in one submission (e2e).',
		steps: [
			{
				id: STEP_ID,
				type: 'form',
				title: 'Aanvraag',
				formId,
				writes: [
					{
						as: 'bundel',
						register: REGISTER,
						schema: BUNDLE_SCHEMA,
						bundle: true,
						mapping: { fields: [], fixed: { referentie: MARKER } },
					},
					{
						as: 'verzoek',
						register: REGISTER,
						schema: REQUEST_SCHEMA,
						forEach: 'producten',
						targetBy: 'product',
						targets: Object.fromEntries(PRODUCTS.map((p) => [p.product, { register: REGISTER, schema: REQUEST_SCHEMA, typeValue: p.typeValue }])),
						mapping: {
							fields: [{ field: 'item.toelichting', property: 'omschrijving' }],
							fixed: { bundel: { $write: 'bundel' } },
						},
					},
				],
			},
			{ id: 'controle', type: 'review', title: 'Controle' },
		],
	}
}

test.describe('forms-multi-product-request: one submission, several products', () => {
	test.describe.configure({ timeout: 180_000 })
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.afterAll(async ({ browser }) => {
		const context = await browser.newContext({ storageState: 'tests/e2e/.auth/admin.json' })
		const page = await context.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			for (const { schema, id } of cleanup.records) {
				await call(page, 'DELETE', `${OR_API}/objects/${REGISTER}/${schema}/${id}`)
			}
			if (cleanup.journey !== '') {
				await call(page, 'DELETE', `${JOURNEYS}/${cleanup.journey}`)
			}
			if (cleanup.form !== '') {
				await call(page, 'DELETE', `${FORM_LIBRARY}/${cleanup.form}`)
			}
		} finally {
			await context.close()
		}
	})

	test('REQ-BQMP-003: a resident requests two products at once and gets one bundle and two requests', async ({ page }) => {
		test.skip(
			REGISTER === '' || BUNDLE_SCHEMA === '' || REQUEST_SCHEMA === '',
			'set JOURNEY_MP_REGISTER, JOURNEY_MP_BUNDLE_SCHEMA and JOURNEY_MP_REQUEST_SCHEMA to a bundle and a request schema',
		)
		await suppressSupportDialog(page)
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })

		const runApi = await call(page, 'GET', `${JOURNEY_RUNS}?_limit=1`)
		test.skip(
			runApi.status === 404 || runApi.status === 405,
			'OpenRegister has no journey run with repeating writes here yet (or-form-and-journey-registry REQ-FJR-009/010, openregister#10598)',
		)

		// Author: the library form, then the journey through the designer's save route.
		const form = await call(page, 'POST', FORM_LIBRARY, productListForm())
		expect(form.status, `store the library form: ${JSON.stringify(form.data).slice(0, 200)}`).toBeLessThan(300)
		cleanup.form = idOf(form.data)
		const saved = await call(page, 'POST', '/index.php/apps/buildiq/api/journeys', { journey: multiProductJourney(cleanup.form) })
		expect(saved.status, `save the journey: ${JSON.stringify(saved.data).slice(0, 400)}`).toBe(200)
		cleanup.journey = String(saved.data.id)
		expect(cleanup.journey).not.toBe('')

		// The designer shows what was authored.
		await page.goto(`${BASE_URL}/apps/buildiq/journeys/${cleanup.journey}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByTestId('journey-title')).toHaveValue(`PW multi-product request ${RUN_ID}`, { timeout: 45_000 })
		// The first step is selected on load; select it explicitly all the same.
		await page.getByTestId('journey-step-0').locator('.journey-steps__select').click()
		await expect(page.getByTestId('journey-write-0').getByTestId('journey-write-bundle')).toBeChecked()
		const repeating = page.getByTestId('journey-write-1')
		await expect(repeating.getByTestId('journey-write-foreach')).toHaveValue('producten')
		await expect(repeating.getByTestId('journey-write-target')).toHaveCount(PRODUCTS.length)

		// The resident's side, in the preview: two products, reviewed per item.
		const preview = page.locator('.journey-preview')
		const addRow = preview.getByTestId('cn-sub-objects-add')
		const widgetShipped = await addRow.waitFor({ state: 'visible', timeout: 20_000 }).then(() => true, () => false)
		test.skip(!widgetShipped, 'nextcloud-vue does not render the sub-objects product list in CnJourney yet (T06)')
		for (const item of PRODUCTS) {
			await addRow.click()
			const rowDialog = page.getByRole('dialog').last()
			await rowDialog.getByRole('combobox', { name: /product/i }).click()
			await page.getByRole('option', { name: item.product }).click()
			await rowDialog.getByRole('textbox', { name: /toelichting/i }).fill(item.toelichting)
			await rowDialog.getByRole('button', { name: /^(save|add|done|complete|opslaan|toevoegen)$/i }).click()
			await expect(rowDialog).toBeHidden()
		}
		await preview.getByRole('button', { name: /^(review|next)$/i }).click()
		for (const item of PRODUCTS) {
			await expect(preview.getByText(item.product).first(), `the review lists ${item.product}`).toBeVisible()
		}
		await preview.getByRole('button', { name: /^submit$/i }).click()
		await expect(preview.getByTestId('journey-preview-submitted')).toBeVisible()

		// The real run of the same answers writes the records.
		const answers = { [STEP_ID]: { producten: PRODUCTS.map(({ product, toelichting }) => ({ product, toelichting })) } }
		const run = await call(page, 'POST', JOURNEY_RUNS, { journey: cleanup.journey, answers, position: 'controle', status: 'active' })
		expect(run.status, `start the run: ${JSON.stringify(run.data).slice(0, 200)}`).toBeLessThan(300)
		const submitted = await call(page, 'POST', `${JOURNEY_RUNS}/${encodeURIComponent(String(run.data.id))}/submit`, {})
		expect(submitted.status, `submit the run: ${JSON.stringify(submitted.data).slice(0, 400)}`).toBeLessThan(300)

		const bundles = await call(page, 'GET', `${OR_API}/objects/${REGISTER}/${BUNDLE_SCHEMA}?referentie=${encodeURIComponent(MARKER)}`)
		const bundleRows = rowsOf(bundles.data).filter((row) => row?.referentie === MARKER)
		expect(bundleRows, 'one bundle record').toHaveLength(1)
		const bundleId = idOf(bundleRows[0])
		cleanup.records.push({ schema: BUNDLE_SCHEMA, id: bundleId })

		const requests = await call(page, 'GET', `${OR_API}/objects/${REGISTER}/${REQUEST_SCHEMA}?bundel=${encodeURIComponent(bundleId)}`)
		const requestRows = rowsOf(requests.data).filter((row) => row?.bundel === bundleId)
		for (const row of requestRows) {
			cleanup.records.push({ schema: REQUEST_SCHEMA, id: idOf(row) })
		}
		expect(requestRows, 'two request records, each pointing at the bundle').toHaveLength(PRODUCTS.length)
		expect(requestRows.map((row) => row[TYPE_PROPERTY]).sort(), 'each request on its own case type').toEqual(PRODUCTS.map((p) => p.typeValue).sort())
		expect(requestRows.map((row) => row.omschrijving).sort()).toEqual(PRODUCTS.map((p) => p.toelichting).sort())
	})
})
