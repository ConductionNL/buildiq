/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: a schema field that takes its value from a registry (change
 * data-registry-backed-field-option, task T07, REQ-BQRF-001 and REQ-BQRF-003).
 *
 * A maker adds a schema with a text field `kvkNummer` and a whole-number field
 * `medewerkers` in the schema designer. On `kvkNummer` they choose "From a
 * registry", pick "KvK organisation" and "Look it up every time", and save; the
 * stored property is read back from OpenRegister. After a reload the field
 * shows "Format: KvK organisation, live". Choosing "Typed in" and saving again
 * removes the declaration. The whole-number field never shows the section.
 *
 * DEPENDS ON INTEGRIQ. The provider list comes from integriq's
 * `GET /api/property-sources` (resolved through `fleetAppPath('integriq', …)`,
 * so either `integriq` or its old id `openconnector`). The suite probes both
 * paths in beforeAll and skips, naming the reason, when neither publishes the
 * `kvk` provider: a provider list faked in the browser would let the designer
 * write a declaration OpenRegister may refuse on save, which would test the
 * fake rather than the feature.
 *
 * NOT COVERED HERE:
 * - REQ-BQRF-002 (a declaration kept while integriq is missing) is `@e2e
 *   exclude` in the spec: disabling integriq on the shared instance breaks
 *   other suites; Vitest on FieldEditor covers it with the provider call failing.
 * - REQ-BQRF-003's refusal scenario needs OpenRegister to refuse a provider
 *   the designer can only offer when integriq publishes it; the mapping of a
 *   400/422 naming `properties.kvkNummer` onto the field is asserted in Vitest
 *   (task T05).
 *
 * The fixture app `pw-registry-field` is created in beforeAll; the schema and
 * the app are removed in afterAll.
 *
 * @spec openspec/changes/archive/2026-11-24-data-registry-backed-field-option/specs/schema-designer-ui/spec.md
 * @e2e schema-designer-ui/requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001/a-kvk-backed-field-is-declared
 * @e2e schema-designer-ui/requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001/the-section-is-not-offered-for-a-number
 * @e2e schema-designer-ui/requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001/going-back-to-typed-in-removes-the-declaration
 * @e2e schema-designer-ui/requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003/the-table-shows-the-source
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import path from 'node:path'
import { dismissOverlays, ensureApp, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'
import { saveSchemaAndAwait } from './support/schemaSave.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }

const APP_SLUG = 'pw-registry-field'
const SCHEMA_SLUG = 'organisatie'
// SchemaDesigner namespaces a new schema to `{app}-{version}-{slug}`.
const NAMESPACED_SLUG = `${APP_SLUG}-production-${SCHEMA_SLUG}`
const KVK_FIELD = 'kvkNummer'
const NUMBER_FIELD = 'medewerkers'
const PROVIDER = 'kvk'
const SOURCE_KEY = 'x-openregister-property-source'
/** The integriq app ids `fleetAppPath('integriq', …)` may resolve to. */
const INTEGRIQ_IDS = ['integriq', 'openconnector']

let providerLabel = ''
let skipReason = ''

/**
 * Call a Nextcloud route from inside the page, with its session and request token.
 *
 * @param page   A signed-in page on a Nextcloud route.
 * @param method The HTTP method.
 * @param url    The path from the web root.
 * @param body   The JSON body, if any.
 * @return The status and the parsed answer.
 */
async function api(page: Page, method: string, url: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, url, body }) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(url, {
				method,
				headers: { requesttoken: token, 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
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
		{ method, url, body },
	)
}

/**
 * The stored schema, read by slug from OpenRegister.
 *
 * @param page A signed-in page.
 * @return The schema, or null.
 */
async function storedSchema(page: Page): Promise<any> {
	const { status, data } = await api(page, 'GET', `/index.php/apps/openregister/api/schemas/${NAMESPACED_SLUG}`)
	return status === 200 ? data : null
}

/**
 * The field row at a position in the field editor. Rows keep the order the
 * properties were added in; a name input's typed value is a DOM property, not
 * an attribute, so a row cannot be found by `input[value=…]`.
 *
 * @param page  The page with the designer open.
 * @param index The row's position.
 * @return The row.
 */
function fieldRow(page: Page, index: number): Locator {
	return page.locator('.buildiq-field-editor__row').nth(index)
}

/**
 * Open the schema designer list for the fixture app.
 *
 * @param page A signed-in page.
 */
async function openSchemaList(page: Page): Promise<void> {
	// `?_version=production` targets the per-version register the wizard creates
	// (see schema-designer.spec.ts).
	await page.goto(`/apps/buildiq/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('.buildiq-schema-list')).toBeVisible({ timeout: 45_000 })
	await dismissOverlays(page)
}

/**
 * Open the fixture schema in the designer's detail mode. The designer routes a
 * new schema to `/builder/{app}/schemas/{namespaced slug}` itself, so the same
 * address reopens it.
 *
 * @param page A signed-in page.
 */
async function openSchema(page: Page): Promise<void> {
	await page.goto(`/apps/buildiq/builder/${APP_SLUG}/schemas/${NAMESPACED_SLUG}?_version=production`, { waitUntil: 'domcontentloaded' })
	await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })
	await dismissOverlays(page)
	await expect(page.locator('.buildiq-field-editor__row'), 'both fields load').toHaveCount(2, { timeout: 30_000 })
}

test.describe('data-registry-backed-field-option: a field whose value comes from a registry', () => {
	test.describe.configure({ mode: 'serial', timeout: 150_000 })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Registry field')
			for (const id of INTEGRIQ_IDS) {
				const listed = await api(page, 'GET', `/index.php/apps/${id}/api/property-sources`)
				const results = Array.isArray(listed.data?.results) ? listed.data.results : []
				const kvk = results.find((entry: { id?: string }) => entry?.id === PROVIDER)
				if (listed.status === 200 && kvk) {
					providerLabel = String(kvk.label ?? PROVIDER)
					break
				}
			}
			if (providerLabel === '') {
				skipReason = 'integriq is not installed here, or does not publish the kvk property source'
			}
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			const schema = await storedSchema(page)
			if (schema?.id !== undefined) {
				await api(page, 'DELETE', `/index.php/apps/openregister/api/schemas/${schema.id}`)
			}
			const { data } = await api(page, 'GET', '/index.php/apps/buildiq/api/applications')
			const rows = Array.isArray(data) ? data : (data?.results ?? [])
			const app = rows.find((a: any) => (a?.slug ?? a?.['@self']?.slug) === APP_SLUG)
			const uuid = String(app?.id ?? app?.uuid ?? app?.['@self']?.id ?? '')
			if (uuid !== '') {
				await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}`, { deleteData: true })
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		test.skip(skipReason !== '', skipReason)
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e schema-designer-ui/requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001/a-kvk-backed-field-is-declared
	// @e2e schema-designer-ui/requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001/the-section-is-not-offered-for-a-number
	test('REQ-BQRF-001: a kvk field is declared live, and a whole number gets no value source', async ({ page }) => {
		await openSchemaList(page)

		// Set-up: a new schema with the two fields. Scoped to the dialog; see
		// schema-designer.spec.ts for why an unscoped slug field is ambiguous.
		await page.getByRole('button', { name: /add schema/i }).first().click()
		const addDialog = page.locator('[role="dialog"]').filter({ hasText: /add schema/i }).first()
		await expect(addDialog).toBeVisible({ timeout: 15_000 })
		await addDialog.getByRole('textbox', { name: /schema slug/i }).fill(SCHEMA_SLUG)
		await addDialog.getByRole('textbox', { name: /^title$/i }).fill('Organisatie')
		await addDialog.getByRole('button', { name: /^add schema$/i }).click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })
		await dismissOverlays(page)

		const addField = page.getByRole('button', { name: /add field/i })
		await addField.click()
		await page.getByLabel('Name', { exact: false }).first().fill(KVK_FIELD)
		await addField.click()
		await page.getByLabel('Name', { exact: false }).nth(1).fill(NUMBER_FIELD)

		const numberRow = fieldRow(page, 1)
		await numberRow.getByLabel('Type', { exact: true }).click()
		await page.getByRole('option', { name: /^Whole number$/ }).first().click()
		await expect(page.getByTestId(`field-source-${NUMBER_FIELD}`), 'no "Value source" for a whole number').toHaveCount(0)

		const source = page.getByTestId(`field-source-${KVK_FIELD}`)
		await expect(source, 'a text field offers "Value source"').toBeVisible()
		await source.getByLabel('From a registry').check()
		await source.getByLabel('Registry', { exact: true }).click()
		await page.getByRole('option', { name: new RegExp(providerLabel) }).first().click()
		await source.getByLabel('Look it up every time').check()

		await saveSchemaAndAwait(page)

		await expect
			.poll(async () => (await storedSchema(page))?.properties?.[KVK_FIELD]?.[SOURCE_KEY] ?? null, {
				timeout: 20_000,
				message: 'the property carries the declaration',
			})
			.toEqual({ provider: PROVIDER, mode: 'live', config: {} })
		const stored = await storedSchema(page)
		expect(stored?.properties?.[NUMBER_FIELD]?.[SOURCE_KEY], 'the whole number has none').toBeUndefined()
	})

	// @e2e schema-designer-ui/requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003/the-table-shows-the-source
	test('REQ-BQRF-003: reopening the schema shows the registry source as the field\'s format', async ({ page }) => {
		await openSchema(page)
		await expect(page.getByTestId(`field-source-summary-${KVK_FIELD}`)).toContainText(`${providerLabel}, live`, { timeout: 30_000 })
	})

	// @e2e schema-designer-ui/requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001/going-back-to-typed-in-removes-the-declaration
	test('REQ-BQRF-001: choosing "Typed in" and saving removes the declaration', async ({ page }) => {
		await openSchema(page)
		const source = page.getByTestId(`field-source-${KVK_FIELD}`)
		await expect(source).toBeVisible({ timeout: 30_000 })
		await source.getByLabel('Typed in').check()
		await saveSchemaAndAwait(page)

		await expect
			.poll(async () => {
				const property = (await storedSchema(page))?.properties?.[KVK_FIELD]
				return property ? Object.hasOwn(property, SOURCE_KEY) : null
			}, { timeout: 20_000, message: 'the property no longer carries the declaration' })
			.toBe(false)
		await expect(page.getByTestId(`field-source-summary-${KVK_FIELD}`)).toHaveCount(0)
	})
})
