/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: named field types and choice lists in the schema designer
 * (change data-field-types-and-choice-lists, task T05, REQ-BQFT-001 to
 * REQ-BQFT-004).
 *
 * 1. A maker adds a schema `taak` with a date field `decidedOn`, a choice field
 *    `status` with two inline options, and a user field `assignee`, picking
 *    each by its named type, and saves. The stored properties are read back
 *    from OpenRegister: `format: date`, `enum` with `x-enum-labels`, and
 *    `referenceType: nextcloud-user`.
 * 2. Properties written by another tool (a date `startsOn` and an imported
 *    `priority` with a three-value `enum`, a `title` and an `x-` key) are put
 *    on the schema through OpenRegister's API. Reopened in the designer,
 *    `startsOn` shows the type "Date"; after the maker renames it and saves,
 *    `priority` still carries its enum and its other keys.
 * 3. "Use a shared list" on a choice field writes `conceptScheme` and no
 *    `enum`. This needs a concept scheme in OpenRegister's bundled vocabulary
 *    register; the test uses `FIELD_TYPES_CONCEPT_SCHEME` when set, else the
 *    first scheme listed, and skips naming the reason when there is none. The
 *    spec's "a concept added later is accepted" half is OpenRegister's
 *    validation and is not driven here.
 * 4. The app gets an index page on the schema and is published; in the
 *    published app the create form shows a date picker for `decidedOn` and a
 *    searchable list of users for `assignee`, where the colleague
 *    `rbac-editor` (provisioned by tests/e2e/global-setup.ts) is picked; the
 *    saved record holds that user id. The form is nextcloud-vue's
 *    schema-driven `CnFormDialog`, which renders `referenceType:
 *    nextcloud-user` as `NcSelectUsers`.
 *
 * The fixture app `pw-field-types` is created in beforeAll; its records, the
 * schema and the app are removed in afterAll.
 *
 * @spec openspec/changes/archive/2026-11-24-data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md
 * @e2e schema-designer-field-types/requirement-the-designer-offers-named-field-types-req-bqft-001/a-maker-adds-a-date-field
 * @e2e schema-designer-field-types/requirement-the-designer-offers-named-field-types-req-bqft-001/an-existing-date-field-opens-as-a-date
 * @e2e schema-designer-field-types/requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002/a-maker-points-a-field-at-the-shared-list-of-municipalities
 * @e2e schema-designer-field-types/requirement-a-user-field-points-at-a-nextcloud-user-req-bqft-003/a-record-is-assigned-to-a-colleague
 * @e2e schema-designer-field-types/requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004/an-imported-enum-survives-a-save
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
const COLLEAGUE_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const CONCEPT_SCHEME = process.env.FIELD_TYPES_CONCEPT_SCHEME ?? ''

const APP_SLUG = 'pw-field-types'
const VERSION = 'production'
const SCHEMA_SLUG = 'taak'
// SchemaDesigner namespaces a new schema to `{app}-{version}-{slug}`.
const NAMESPACED_SLUG = `${APP_SLUG}-${VERSION}-${SCHEMA_SLUG}`
const INDEX_PAGE = { id: 'taken', title: 'Tasks', addLabel: 'New task' }
const IMPORTED_ENUM = ['low', 'normal', 'high']

const created: string[] = []
let register = ''

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
 * The field row at a position in the field editor.
 *
 * @param page  The page with the designer open.
 * @param index The row's position.
 * @return The row.
 */
function fieldRow(page: Page, index: number): Locator {
	return page.locator('.buildiq-field-editor__row').nth(index)
}

/**
 * Add a field: name it and pick its named type.
 *
 * @param page The page with the designer open.
 * @param name The property name.
 * @param type The named type's label, as the picker shows it.
 * @return The new row.
 */
async function addField(page: Page, name: string, type: string): Promise<Locator> {
	const rows = page.locator('.buildiq-field-editor__row')
	const index = await rows.count()
	await page.getByRole('button', { name: /add field/i }).click()
	const row = fieldRow(page, index)
	await row.getByLabel('Name', { exact: false }).first().fill(name)
	await row.getByLabel('Type', { exact: true }).click()
	await page.getByRole('option', { name: new RegExp(`^${type}$`) }).first().click()
	return row
}

/**
 * Open the fixture schema in the designer's detail mode.
 *
 * @param page A signed-in page.
 */
async function openSchema(page: Page): Promise<void> {
	await page.goto(`/apps/buildiq/builder/${APP_SLUG}/schemas/${NAMESPACED_SLUG}?_version=${VERSION}`, { waitUntil: 'domcontentloaded' })
	await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })
	await dismissOverlays(page)
	await expect(page.locator('.buildiq-field-editor__row').first()).toBeVisible({ timeout: 30_000 })
}

/**
 * Write properties onto the stored schema, as an import or another tool
 * would. OpenRegister's schema API reads by slug but writes by id.
 *
 * @param page      A signed-in page.
 * @param additions The properties to add.
 */
async function addPropertiesViaApi(page: Page, additions: Record<string, unknown>): Promise<void> {
	const schema = await storedSchema(page)
	expect(schema, `schema ${NAMESPACED_SLUG} must exist`).not.toBeNull()
	const body = { ...schema, properties: { ...(schema.properties || {}), ...additions } }
	delete body['@self']
	const written = await api(page, 'PUT', `/index.php/apps/openregister/api/schemas/${schema.id}`, body)
	expect(written.status, `writing ${NAMESPACED_SLUG}: ${JSON.stringify(written.data).slice(0, 200)}`).toBeLessThan(300)
}

/**
 * The fixture app's object id.
 *
 * @param page A signed-in page.
 * @return The id, or ''.
 */
async function appUuid(page: Page): Promise<string> {
	const { data } = await api(page, 'GET', '/index.php/apps/buildiq/api/applications')
	const rows = Array.isArray(data) ? data : (data?.results ?? [])
	const app = rows.find((a: any) => (a?.slug ?? a?.['@self']?.slug) === APP_SLUG)
	return String(app?.id ?? app?.uuid ?? app?.['@self']?.id ?? '')
}

test.describe('data-field-types-and-choice-lists: named field types in the schema designer', () => {
	test.describe.configure({ mode: 'serial', timeout: 180_000 })
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Field types')
			const { data } = await api(page, 'GET', `/index.php/apps/buildiq/api/applications/${APP_SLUG}/versions`)
			const rows = Array.isArray(data) ? data : (data?.results ?? [])
			const version = rows.find((v: any) => v?.slug === VERSION)
			register = typeof version?.register === 'string' ? version.register : `buildiq-${APP_SLUG}-${VERSION}`
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			for (const id of created) {
				await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/${register}/${NAMESPACED_SLUG}/${id}`).catch(() => undefined)
			}
			const schema = await storedSchema(page)
			if (schema?.id !== undefined) {
				await api(page, 'DELETE', `/index.php/apps/openregister/api/schemas/${schema.id}`)
			}
			const uuid = await appUuid(page)
			if (uuid !== '') {
				await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}`, { deleteData: true })
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e schema-designer-field-types/requirement-the-designer-offers-named-field-types-req-bqft-001/a-maker-adds-a-date-field
	// @e2e schema-designer-field-types/requirement-a-user-field-points-at-a-nextcloud-user-req-bqft-003/a-record-is-assigned-to-a-colleague
	test('REQ-BQFT-001/003: a maker adds a date, a choice and a user field by their named types', async ({ page }) => {
		await page.goto(`/apps/buildiq/builder/${APP_SLUG}/schemas?_version=${VERSION}`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.buildiq-schema-list')).toBeVisible({ timeout: 45_000 })
		await dismissOverlays(page)

		// Scoped to the dialog; see schema-designer.spec.ts for why.
		await page.getByRole('button', { name: /add schema/i }).first().click()
		const addDialog = page.locator('[role="dialog"]').filter({ hasText: /add schema/i }).first()
		await expect(addDialog).toBeVisible({ timeout: 15_000 })
		await addDialog.getByRole('textbox', { name: /schema slug/i }).fill(SCHEMA_SLUG)
		await addDialog.getByRole('textbox', { name: /^title$/i }).fill('Taak')
		await addDialog.getByRole('button', { name: /^add schema$/i }).click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })
		await dismissOverlays(page)

		await addField(page, 'decidedOn', 'Date')

		const status = await addField(page, 'status', 'Choice')
		await expect(status.getByText('Enter the options here')).toBeVisible()
		for (const [position, [value, label]] of [['open', 'Open'], ['closed', 'Closed']].entries()) {
			await status.getByRole('button', { name: /add option/i }).click()
			const option = status.locator('.buildiq-field-editor__option').nth(position)
			await option.getByLabel('Value', { exact: true }).fill(value)
			await option.getByLabel('Label', { exact: true }).fill(label)
		}

		const assignee = await addField(page, 'assignee', 'User')
		await expect(assignee.getByText(/searchable list of users/i), 'the user type explains what it stores').toBeVisible()

		await saveSchemaAndAwait(page)

		const properties = (await storedSchema(page))?.properties ?? {}
		expect(properties.decidedOn, 'a date is a string with format date').toMatchObject({ type: 'string', format: 'date' })
		expect(properties.status, 'a choice stores its options and labels').toMatchObject({
			type: 'string',
			enum: ['open', 'closed'],
			'x-enum-labels': { open: 'Open', closed: 'Closed' },
		})
		expect(properties.status.conceptScheme, 'never both inline options and a shared list').toBeUndefined()
		expect(properties.assignee, 'a user field points at a Nextcloud user').toMatchObject({ type: 'string', referenceType: 'nextcloud-user' })
	})

	// @e2e schema-designer-field-types/requirement-the-designer-offers-named-field-types-req-bqft-001/an-existing-date-field-opens-as-a-date
	// @e2e schema-designer-field-types/requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004/an-imported-enum-survives-a-save
	test('REQ-BQFT-001/004: a stored date opens as "Date", and an imported enum survives a save', async ({ page }) => {
		await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		await addPropertiesViaApi(page, {
			startsOn: { type: 'string', format: 'date' },
			priority: { type: 'string', enum: IMPORTED_ENUM, title: 'Priority', 'x-import-source': 'csv' },
		})
		const order = Object.keys((await storedSchema(page))?.properties ?? {})

		await openSchema(page)
		const startsOn = fieldRow(page, order.indexOf('startsOn'))
		await expect(startsOn.getByLabel('Name', { exact: false }).first()).toHaveValue('startsOn')
		await expect(startsOn.locator('.vs__selected').first(), 'the stored date opens with the named type').toHaveText(/^\s*Date\s*$/)

		await startsOn.getByLabel('Name', { exact: false }).first().fill('beginsOn')
		await saveSchemaAndAwait(page)

		const properties = (await storedSchema(page))?.properties ?? {}
		expect(properties.beginsOn, 'the renamed field is still a date').toMatchObject({ type: 'string', format: 'date' })
		expect(properties.startsOn).toBeUndefined()
		expect(properties.priority, 'the keys the designer does not edit are kept').toMatchObject({
			enum: IMPORTED_ENUM,
			title: 'Priority',
			'x-import-source': 'csv',
		})
	})

	// @e2e schema-designer-field-types/requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002/a-maker-points-a-field-at-the-shared-list-of-municipalities
	test('REQ-BQFT-002: a choice field pointed at a shared list stores the scheme and no enum', async ({ page }) => {
		await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		const listed = await api(page, 'GET', '/index.php/apps/openregister/api/objects/vocabulary/conceptScheme?_limit=500')
		const schemes = (Array.isArray(listed.data) ? listed.data : (listed.data?.results ?? []))
			.map((scheme: any) => String(scheme?.['@self']?.slug ?? scheme?.slug ?? ''))
			.filter((slug: string) => slug !== '')
		const scheme = CONCEPT_SCHEME !== '' ? CONCEPT_SCHEME : (schemes[0] ?? '')
		test.skip(scheme === '' || !schemes.includes(scheme), 'OpenRegister holds no concept scheme here (set FIELD_TYPES_CONCEPT_SCHEME to name one)')

		await openSchema(page)
		const municipality = await addField(page, 'municipality', 'Choice')
		await municipality.getByText('Use a shared list').click()
		await municipality.getByLabel('Shared list', { exact: true }).click()
		await page.getByRole('option').filter({ hasText: new RegExp(scheme, 'i') }).first().click()
		await saveSchemaAndAwait(page)

		const property = (await storedSchema(page))?.properties?.municipality ?? {}
		expect(property.conceptScheme, 'the property names the shared list').toBe(scheme)
		expect(property.enum, 'and carries no inline options').toBeUndefined()
	})

	// @e2e schema-designer-field-types/requirement-a-user-field-points-at-a-nextcloud-user-req-bqft-003/a-record-is-assigned-to-a-colleague
	// @e2e schema-designer-field-types/requirement-the-designer-offers-named-field-types-req-bqft-001/a-maker-adds-a-date-field
	test('REQ-BQFT-003: in the published app a colleague is picked from a list of users and saved by user id', async ({ page }) => {
		await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		expect((await storedSchema(page))?.properties?.assignee, 'needs the schema from the first test').toBeTruthy()

		const manifestUrl = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
		const manifest = (await api(page, 'GET', manifestUrl)).data
		const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== INDEX_PAGE.id)
		pages.push({
			id: INDEX_PAGE.id,
			route: `/${INDEX_PAGE.id}`,
			type: 'index',
			title: INDEX_PAGE.title,
			config: { register, schema: NAMESPACED_SLUG, addLabel: INDEX_PAGE.addLabel, columns: ['status', 'assignee'] },
		})
		const seeded = await api(page, 'PUT', manifestUrl, { manifest: { ...manifest, pages } })
		expect(seeded.status, `seeding the index page: ${JSON.stringify(seeded.data).slice(0, 200)}`).toBeLessThan(300)
		const uuid = await appUuid(page)
		const published = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/${uuid}/publish`)
		expect(published.status, `publishing: ${JSON.stringify(published.data).slice(0, 200)}`).toBeLessThan(300)

		await page.goto(`/index.php/apps/buildiq/builder/${APP_SLUG}/${INDEX_PAGE.id}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await page.getByRole('button', { name: new RegExp(INDEX_PAGE.addLabel, 'i') }).first().click()
		const form = page.getByRole('dialog').last()
		await expect(form).toBeVisible({ timeout: 30_000 })

		await expect(form.locator('input[type="date"]'), 'the date field is a date picker').toHaveCount(1)

		const users = form.getByRole('combobox', { name: /assignee/i }).first()
		await users.click()
		await users.pressSequentially(COLLEAGUE_USER)
		const colleague = page.getByRole('option').filter({ hasText: COLLEAGUE_USER }).first()
		await expect(colleague, 'the user field searches Nextcloud users').toBeVisible({ timeout: 20_000 })
		await colleague.click()

		await form.getByRole('button', { name: /^create$/i }).click()
		await expect(form).toBeHidden({ timeout: 30_000 })

		await expect
			.poll(async () => {
				const { data } = await api(page, 'GET', `/index.php/apps/openregister/api/objects/${register}/${NAMESPACED_SLUG}?_order[created]=desc&_limit=1`)
				const record = (Array.isArray(data) ? data : (data?.results ?? []))[0]
				const id = String(record?.id ?? record?.['@self']?.id ?? '')
				if (id !== '' && !created.includes(id)) {
					created.push(id)
				}
				return record?.assignee ?? null
			}, { timeout: 30_000, message: 'the saved record holds the colleague\'s user id' })
			.toBe(COLLEAGUE_USER)
	})
})
