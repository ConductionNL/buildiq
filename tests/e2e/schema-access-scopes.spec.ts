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

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts. This used to be
// `NC_BASE_URL ?? 'http://localhost:8080'`, which pointed at the SHARED dev
// instance while ensureApp() created the fixture on the e2e one.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'
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
 * Locate one per-operation row of the Access sub-editor.
 *
 * The row's own text is its heading PLUS its Scope/Groups controls, so an
 * anchored `filter({ hasText: /^read$/i })` on the row never matches. Filter on
 * the row's heading element instead — AccessEditor renders it as an `<h4>` with
 * the capitalised operation name ("Read", "Create", "Update", "Delete").
 *
 * @param page Playwright page.
 * @param op   The operation: read | create | update | delete.
 * @return {import('@playwright/test').Locator} The matching row.
 */
function accessRow(page: import('@playwright/test').Page, op: 'read' | 'create' | 'update' | 'delete') {
	return page.locator('.openbuild-access-editor .openbuild-access-editor__row')
		.filter({ has: page.getByRole('heading', { name: new RegExp(`^${op}$`, 'i') }) })
}

/**
 * Type a group into a row's taggable Groups select and commit it.
 *
 * The select renders its "create this tag" option asynchronously, so pressing
 * Enter straight after `fill()` commits nothing and the group is silently
 * dropped — the scope then saves with an empty group list. Wait for the option
 * to exist before committing.
 *
 * @param page  Playwright page.
 * @param row   The access row locator (see accessRow()).
 * @param group The group id to tag.
 * @return {Promise<void>}
 */
async function tagGroup(
	page: import('@playwright/test').Page,
	row: import('@playwright/test').Locator,
	group: string,
) {
	const input = row.getByLabel(/groups/i)
	await input.fill(group)
	await expect(
		page.getByRole('option', { name: new RegExp(`^${group}$`) }).first(),
	).toBeVisible({ timeout: 15_000 })
	await input.press('Enter')
	// The row's model updates on the select's input event; give it a tick before
	// Save reads the staged model.
	await expect(row.getByText(group, { exact: false }).first()).toBeVisible({ timeout: 10_000 })
}

/**
 * Click Save and wait until the change is actually visible through the API.
 *
 * `waitForLoadState('networkidle')` does NOT wait for the save's XHR, so a
 * straight read-after-save races it and asserts against the schema's PREVIOUS
 * contents — which is exactly how a working save looked like a failure here.
 * Poll the API for the expected value instead.
 *
 * @param page     Playwright page.
 * @param slug     The schema slug to poll.
 * @param pick     Selects the value to compare from the fetched schema.
 * @param expected The value to wait for.
 * @return {Promise<void>}
 */
async function saveAndAwait(
	page: import('@playwright/test').Page,
	slug: string,
	pick: (schema: Record<string, unknown>) => unknown,
	expected: unknown,
) {
	await page.getByRole('button', { name: /^save$/i }).click()
	await expect.poll(async () => pick(await getSchema(page, slug)), { timeout: 30_000 }).toEqual(expected)
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

// UN-QUARANTINED 2026-07-28 — green 6/6 against a live instance.
//
// Brought up to date with what the designer actually does: ensureApp() fixture
// (the flat "Add application" form it used to drive is gone), ?_version=production
// register scoping, namespaced schema slugs, dialog-scoped Add-schema locators,
// and putSchema() writing by NUMERIC ID (OpenRegister is read-by-slug but
// write-by-id, so the old slug PUT seeded nothing and these scenarios asserted
// against an unchanged schema).
//
// Two traps worth keeping in mind when editing this file:
//   - a per-operation row's text is its heading PLUS its controls, so match the
//     row via its <h4> heading (accessRow), never `hasText: /^read$/`;
//   - `waitForLoadState('networkidle')` does NOT wait for the save XHR — read
//     back through saveAndAwait(), which polls the API.
test.describe('data-scopes-authoring — Access sub-editor (REQ-OBDSA-001/002/003/005)', () => {
	// Whether this run has already reset the scoped schema's authorization.
	let baselineReset = false

	test.beforeEach(async ({ page }) => {
		// Ensure the pw-access-scopes virtual app exists (idempotent). The flat
		// "Add application" form this used to drive no longer exists — creation
		// moved to a multi-step wizard — and the old `if (isVisible)` guard meant
		// the app was silently never created, so every step below acted on an
		// app that wasn't there.
		await ensureApp(page, APP_SLUG, 'PW Access Scopes')

		// Start each RUN from a clean authorization block. The scenarios below
		// build on one another within a run (scenario 2 asserts the exact set of
		// scoped operations), but they also leave scopes behind — scenario 4
		// deliberately seeds `update: ['@creator']`. Without this reset the
		// leftovers from the previous run make scenario 2 fail on every rerun,
		// i.e. the suite passes once and then goes red.
		if (baselineReset === false) {
			const existing = await page.request.get(
				`${BASE_URL}/index.php/apps/openregister/api/schemas/${nsSlug(SCOPED_SCHEMA_SLUG)}`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			if (existing.ok()) {
				const current = await existing.json()
				await putSchema(page, SCOPED_SCHEMA_SLUG, { ...current, authorization: {}, title: 'Record' })
			}
			baselineReset = true
		}
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
		// Decide "does it exist?" from the API, not from the rendered rows: the
		// list can still be loading, and a DOM-based check that guesses "absent"
		// creates a SECOND schema with the same slug (OpenRegister currently
		// accepts that), after which every row lookup hits a strict-mode
		// violation with two identical rows.
		const probe = await page.request.get(
			`${BASE_URL}/index.php/apps/openregister/api/schemas/${namespaced}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		const existingRow = page.locator('.openbuild-schema-list__row').filter({ hasText: namespaced }).first()
		if (probe.status() === 404) {
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
		const readRow = accessRow(page, 'read')
		await readRow.getByLabel(/scope/i).click()
		await page.getByRole('option', { name: /specific groups/i }).click()
		await tagGroup(page, readRow, 'vets')

		await saveAndAwait(page, SCOPED_SCHEMA_SLUG, (s) => (s as any).authorization?.read, ['vets'])

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
		const deleteRow = accessRow(page, 'delete')
		await deleteRow.getByLabel(/scope/i).click()
		await page.getByRole('option', { name: /specific groups/i }).click()
		await tagGroup(page, deleteRow, 'admin')

		// `read` retains the scope set in the previous test; `delete` is now
		// scoped too; `create`/`update` were never touched — everyone.
		await saveAndAwait(page, SCOPED_SCHEMA_SLUG, (s) => (s as any).authorization?.delete, ['admin'])
		const persisted = await getSchema(page, SCOPED_SCHEMA_SLUG)
		expect(Object.keys(persisted.authorization ?? {}).sort()).toEqual(['delete', 'read'])
	})

	test('REQ-OBDSA-002 scenario 1: unrelated field edit + save preserves an API-seeded authorization block', async ({ page }) => {
		const current = await getSchema(page, SCOPED_SCHEMA_SLUG)
		await putSchema(page, SCOPED_SCHEMA_SLUG, {
			...current,
			authorization: { read: ['vets'] },
		})

		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) }).first().click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		// Unrelated edit — change the title only.
		await page.getByLabel(/title/i).first().fill('Record renamed')
		await saveAndAwait(page, SCOPED_SCHEMA_SLUG, (s) => (s as any).title, 'Record renamed')
		const persisted = await getSchema(page, SCOPED_SCHEMA_SLUG)
		expect(persisted.authorization?.read, 'authorization.read must survive an unrelated save').toEqual(['vets'])
	})

	test('REQ-OBDSA-002 scenario 2: an API-seeded @creator entry renders read-only and survives byte-identical after save', async ({ page }) => {
		const current = await getSchema(page, SCOPED_SCHEMA_SLUG)
		await putSchema(page, SCOPED_SCHEMA_SLUG, {
			...current,
			authorization: { update: ['@creator'] },
		})

		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) }).first().click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		// The "update" row must render read-only — no editable scope-kind
		// picker, just the "managed outside the designer" note (the
		// deployed dev OR does not advertise the `creator` capability).
		const accessSection = page.locator('.openbuild-access-editor')
		const updateRow = accessRow(page, 'update')
		await expect(updateRow.getByText(/managed outside the designer/i)).toBeVisible({ timeout: 45_000 })
		await expect(updateRow.getByLabel(/scope/i)).toHaveCount(0)

		// Unrelated edit + save.
		await page.getByLabel(/title/i).first().fill('Record renamed again')
		await saveAndAwait(page, SCOPED_SCHEMA_SLUG, (s) => (s as any).authorization?.update, ['@creator'])
	})

	test('REQ-OBDSA-003 scenario 1: baseline scope-kind picker offers exactly everyone + groups', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.locator('.openbuild-schema-list__row').filter({ hasText: nsSlug(SCOPED_SCHEMA_SLUG) }).first().click()
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 45_000 })

		const accessSection = page.locator('.openbuild-access-editor')
		const createRow = accessRow(page, 'create')
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
