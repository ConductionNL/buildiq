/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end spec for the data-scopes-authoring change
 * (REQ-OBDSA-001, REQ-OBDSA-002, REQ-OBDSA-003, REQ-OBDSA-005).
 *
 * Covers the single-admin-user authoring flow:
 *   1. REQ-OBDSA-001 scenario 1 — set a group `read` scope, save, reload,
 *      assert it persisted via the OR schemas API.
 *   2. REQ-OBDSA-001 scenario 2 — independent per-operation scopes: `read`
 *      scoped to one group, `delete` to another, `create`/`update` left
 *      as everyone.
 *   3. REQ-OBDSA-002 scenario 1 — a schema with an API-seeded
 *      `authorization` block survives an UNRELATED field/title edit +
 *      save (the strip-on-save bug regression, at the UI layer).
 *   4. REQ-OBDSA-002 scenario 2 — an API-seeded `@creator` entry (which
 *      the baseline, non-capability-advertising OR cannot represent as
 *      an editable "own records" row) renders read-only and survives an
 *      unrelated save byte-identical.
 *   5. REQ-OBDSA-003 scenario 1 — the baseline scope-kind picker offers
 *      exactly "Everyone with app access" and "Specific groups" (own
 *      records / condition are hidden because the deployed dev OR does
 *      not advertise `openregister.authorization.scopes`).
 *   6. REQ-OBDSA-005 — the scoped schema shows a "Restricted" badge in
 *      the SchemaListPanel, with `read: vets` in its title; the
 *      unscoped schema shows no badge.
 *
 * Runs against a live Nextcloud at NC_BASE_URL (default
 * http://localhost:8080) with the OpenBuild app installed. Uses the
 * shared admin `storageState` from `tests/e2e/global-setup.ts` — no
 * per-spec login needed.
 *
 * The schema-level `authorization` block is read/seeded directly via
 * OR's schemas REST API (`/apps/openregister/api/schemas/{slug}`,
 * `src/store/schemas.js`) rather than the (aspirational, unshipped)
 * per-register nested endpoint — this mirrors exactly what
 * `useSchemasStore` calls under the hood, so API-level assertions here
 * are checking the same persistence path the UI exercises.
 */

import { test, expect } from '@playwright/test'
import { ensureApp, dismissOverlays } from './support/appFixture'

const BASE_URL = process.env.NC_BASE_URL ?? 'http://localhost:8080'
const APP_SLUG = 'pw-access-scopes'
const SCOPED_SCHEMA_SLUG = 'record'
const UNSCOPED_SCHEMA_SLUG = 'plain-record'

/**
 * The designer namespaces a created schema to `{app}-{version}-{slug}` so it
 * lands in — and is listed from — the app's per-version register. Every API
 * lookup and row match here has to use that full slug.
 *
 * @param slug The user-facing slug typed into the Add-schema dialog.
 * @return {string} The namespaced slug.
 */
function nsSlug(slug: string): string {
	return `${APP_SLUG}-production-${slug}`
}

/**
 * Read a schema's current body via OR's schemas API (the same endpoint
 * `useSchemasStore` PUTs/GETs through).
 *
 * @param page Playwright page (used for its `request` context).
 * @param slug Schema slug.
 */
async function getSchema(page: import('@playwright/test').Page, slug: string) {
	// Callers pass the user-facing slug; the stored schema carries the
	// app+version namespace the designer applies on create.
	const stored = slug.startsWith(`${APP_SLUG}-`) ? slug : nsSlug(slug)
	const resp = await page.request.get(
		`${BASE_URL}/index.php/apps/openregister/api/schemas/${stored}`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(resp.ok(), `GET schema ${stored} must succeed`).toBeTruthy()
	return resp.json()
}

/**
 * PUT a schema body directly — used to seed an `authorization` block
 * outside the designer, exactly the "set elsewhere" precondition
 * REQ-OBDSA-002's scenarios describe.
 *
 * @param page Playwright page (used for its `request` context).
 * @param slug Schema slug.
 * @param body Full schema body to PUT.
 */
async function putSchema(page: import('@playwright/test').Page, slug: string, body: Record<string, unknown>) {
	// OpenRegister's schema API is READ-BY-SLUG but WRITE-BY-ID: GET on a slug
	// is 200, while PUT/DELETE on that same slug (or on the uuid) 404 "Schema
	// not found". Resolve the numeric id first, or this seeding silently fails
	// and the scenario below asserts against an unchanged schema.
	const current = await getSchema(page, slug)
	const numericId = current?.id
	expect(numericId, `schema ${slug} must expose a numeric id to write with`).toBeTruthy()
	const resp = await page.request.put(
		`${BASE_URL}/index.php/apps/openregister/api/schemas/${numericId}`,
		{ headers: { 'OCS-APIRequest': 'true' }, data: body },
	)
	expect(resp.ok(), `PUT schema ${slug} (id ${numericId}) must succeed`).toBeTruthy()
	return resp.json()
}

// STILL QUARANTINED — modernised but not yet green. #41's blockers are gone
// (the designer renders, saves/deletes now persist), and this file has been
// brought up to date: ensureApp() fixture, `?_version=production` register
// scoping, namespaced schema slugs, dialog-scoped Add-schema locators, and
// putSchema() writing by numeric id (OpenRegister is read-by-slug/write-by-id,
// so the old slug PUT seeded nothing silently).
//
// REMAINING: (a) the `record` schema is not actually created by the Add-schema
// step in this suite's flow, so the API assertions fetch a missing schema;
// (b) the per-operation rows are matched with /^delete$/i, which does not
// resolve against AccessEditor's row titles. Both need a live debugging pass on
// a quiet instance — every run so far was cut short by the shared instance
// going into another session's maintenance/repair.
test.describe.skip('data-scopes-authoring — Access sub-editor (REQ-OBDSA-001/002/003/005)', () => {
	test.beforeEach(async ({ page }) => {
		// Ensure the pw-access-scopes virtual app exists (idempotent). The flat
		// "Add application" form this used to drive no longer exists — creation
		// moved to a multi-step wizard — and the old `if (isVisible)` guard meant
		// the app was silently never created, so every step below acted on an
		// app that wasn't there.
		await ensureApp(page, APP_SLUG, 'PW Access Scopes')
	})

	/**
	 * Open the app's schema list, ensure the named schema exists, and land on
	 * its detail view with the Access editor rendered.
	 *
	 * @param page       Playwright page.
	 * @param slug       The user-facing schema slug (namespaced on create).
	 * @param title      Title to use when creating it.
	 * @return {Promise<void>}
	 */
	async function openSchemaDetail(page: import('@playwright/test').Page, slug: string, title: string) {
		// `?_version=production` targets the app's per-version register that the
		// wizard creates; without it the designer falls back to the legacy
		// `openbuild-{slug}` register that wizard-made apps do not have.
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.openbuild-schema-list')).toBeVisible({ timeout: 45_000 })
		await dismissOverlays(page)

		const namespaced = nsSlug(slug)
		const existingRow = page.locator('.openbuild-schema-list__row').filter({ hasText: namespaced })
		if ((await existingRow.count()) === 0) {
			await page.getByRole('button', { name: /add schema/i }).first().click()
			// Scope to the dialog: the page is a schema-bound detail page, so an
			// unscoped getByLabel(/slug/i) is ambiguous.
			const addDialog = page.locator('[role="dialog"]').filter({ hasText: /add schema/i }).first()
			await expect(addDialog).toBeVisible({ timeout: 15_000 })
			await addDialog.getByRole('textbox', { name: /schema slug/i }).fill(slug)
			await addDialog.getByRole('textbox', { name: /^title$/i }).fill(title)
			await addDialog.getByRole('button', { name: /^add schema$/i }).click()
		} else {
			await existingRow.click()
		}
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })
		await dismissOverlays(page)
	}

	test('REQ-OBDSA-001 scenario 1: group read scope persists via Save → reload', async ({ page }) => {
		await openSchemaDetail(page, SCOPED_SCHEMA_SLUG, 'Record')

		// Access sub-editor — set the "read" row's scope kind to "Specific
		// groups" and tag the "vets" group.
		const accessSection = page.locator('.openbuild-access-editor')
		await expect(accessSection).toBeVisible({ timeout: 45_000 })
		const readRow = accessSection.locator('.openbuild-access-editor__row').filter({ hasText: /^read$/i })
		await readRow.getByLabel(/scope/i).click()
		await page.getByRole('option', { name: /specific groups/i }).click()
		const groupInput = readRow.getByLabel(/groups/i)
		await groupInput.fill('vets')
		await groupInput.press('Enter')

		await page.getByRole('button', { name: /^save$/i }).click()
		await page.waitForLoadState('networkidle')

		const persisted = await getSchema(page, SCOPED_SCHEMA_SLUG)
		expect(persisted.authorization?.read).toEqual(['vets'])

		// Reload — the scope must still show as persisted.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(readRow.getByText(/specific groups/i)).toBeVisible({ timeout: 45_000 })
	})

	test('REQ-OBDSA-001 scenario 2: independent per-operation scopes', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		const row = page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) })
		await row.click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		const accessSection = page.locator('.openbuild-access-editor')
		const deleteRow = accessSection.locator('.openbuild-access-editor__row').filter({ hasText: /^delete$/i })
		await deleteRow.getByLabel(/scope/i).click()
		await page.getByRole('option', { name: /specific groups/i }).click()
		const groupInput = deleteRow.getByLabel(/groups/i)
		await groupInput.fill('admin')
		await groupInput.press('Enter')

		await page.getByRole('button', { name: /^save$/i }).click()
		await page.waitForLoadState('networkidle')

		const persisted = await getSchema(page, SCOPED_SCHEMA_SLUG)
		// `read` retains the scope set in the previous test; `delete` is now
		// scoped too; `create`/`update` were never touched — everyone.
		expect(Object.keys(persisted.authorization ?? {}).sort()).toEqual(['delete', 'read'])
		expect(persisted.authorization.delete).toEqual(['admin'])
	})

	test('REQ-OBDSA-002 scenario 1: unrelated field edit + save preserves an API-seeded authorization block', async ({ page }) => {
		const current = await getSchema(page, SCOPED_SCHEMA_SLUG)
		await putSchema(page, SCOPED_SCHEMA_SLUG, {
			...current,
			authorization: { read: ['vets'] },
		})

		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) }).click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		// Unrelated edit — change the title only.
		await page.getByLabel(/title/i).first().fill('Record renamed')
		await page.getByRole('button', { name: /^save$/i }).click()
		await page.waitForLoadState('networkidle')

		const persisted = await getSchema(page, SCOPED_SCHEMA_SLUG)
		expect(persisted.authorization?.read, 'authorization.read must survive an unrelated save').toEqual(['vets'])
		expect(persisted.title).toBe('Record renamed')
	})

	test('REQ-OBDSA-002 scenario 2: an API-seeded @creator entry renders read-only and survives byte-identical after save', async ({ page }) => {
		const current = await getSchema(page, SCOPED_SCHEMA_SLUG)
		await putSchema(page, SCOPED_SCHEMA_SLUG, {
			...current,
			authorization: { update: ['@creator'] },
		})

		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) }).click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		// The "update" row must render read-only — no editable scope-kind
		// picker, just the "managed outside the designer" note (the
		// deployed dev OR does not advertise the `creator` capability).
		const accessSection = page.locator('.openbuild-access-editor')
		const updateRow = accessSection.locator('.openbuild-access-editor__row').filter({ hasText: /^update$/i })
		await expect(updateRow.getByText(/managed outside the designer/i)).toBeVisible({ timeout: 45_000 })
		await expect(updateRow.getByLabel(/scope/i)).toHaveCount(0)

		// Unrelated edit + save.
		await page.getByLabel(/title/i).first().fill('Record renamed again')
		await page.getByRole('button', { name: /^save$/i }).click()
		await page.waitForLoadState('networkidle')

		const persisted = await getSchema(page, SCOPED_SCHEMA_SLUG)
		expect(persisted.authorization?.update, 'the unrepresentable @creator entry must survive byte-identical').toEqual(['@creator'])
	})

	test('REQ-OBDSA-003 scenario 1: baseline scope-kind picker offers exactly everyone + groups', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) }).click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		const accessSection = page.locator('.openbuild-access-editor')
		const createRow = accessSection.locator('.openbuild-access-editor__row').filter({ hasText: /^create$/i })
		await createRow.getByLabel(/scope/i).click()

		const options = page.getByRole('option')
		await expect(options).toHaveCount(2)
		await expect(options.filter({ hasText: /everyone with app access/i })).toHaveCount(1)
		await expect(options.filter({ hasText: /specific groups/i })).toHaveCount(1)
		await expect(options.filter({ hasText: /own records/i })).toHaveCount(0)
		await expect(options.filter({ hasText: /condition/i })).toHaveCount(0)

		// Close the picker without changing anything.
		await page.keyboard.press('Escape')
	})

	test('REQ-OBDSA-005: scoped schema shows a "Restricted" badge, unscoped schema shows none', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.openbuild-schema-list')).toBeVisible({ timeout: 45_000 })

		// Ensure an unscoped sibling schema exists for the negative assertion.
		const unscopedRow = page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(UNSCOPED_SCHEMA_SLUG) })
		if ((await unscopedRow.count()) === 0) {
			await page.getByRole('button', { name: /add schema/i }).first().click()
			await page.getByLabel(/slug/i).fill(UNSCOPED_SCHEMA_SLUG)
			await page.getByLabel(/title/i).fill('Plain record')
			await page.getByRole('button', { name: /add schema|save/i }).last().click()
			await page.getByRole('button', { name: /back to schemas/i }).click()
		}

		const scopedRow = page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) })
		const scopedBadge = scopedRow.locator('.openbuild-schema-list__badge')
		await expect(scopedBadge).toBeVisible({ timeout: 45_000 })
		await expect(scopedBadge).toHaveAttribute('title', /read: vets|update: @creator/)

		await expect(
			page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(UNSCOPED_SCHEMA_SLUG) })
				.locator('.openbuild-schema-list__badge'),
		).toHaveCount(0)
	})
})
