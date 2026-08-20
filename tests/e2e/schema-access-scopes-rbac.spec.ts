/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end spec for the data-scopes-authoring change's
 * multi-user / multi-version scenarios (REQ-OBDSA-004, REQ-OBDSA-006,
 * REQ-OBDSA-007).
 *
 * Reuses the RBAC test-user + version-chain fixtures documented in
 * `rbac-403.spec.ts` and `versionRouting.spec.ts` (verified against
 * HEAD): those specs assume Newman's RBAC setup collection has created
 * dedicated test users and group memberships, and that a
 * development → staging → production ApplicationVersion chain exists
 * for the target app. This spec adds two more test users (an editor who
 * is NOT in the `vets` group, and an editor who IS in `vets`) — these
 * must be provisioned by the same Newman setup collection before this
 * spec can run; each test skips gracefully when a required user/version
 * is missing, exactly like its siblings.
 *
 * Covers:
 *   1. REQ-OBDSA-004 — a non-admin editor who is not a member of `vets`
 *      sees the lock-out warning after scoping `read` to `vets`; Save
 *      stays enabled. A `vets` member editor sees no warning for the
 *      same scope.
 *   2. REQ-OBDSA-006 — a scope change saved under `?_version=staging`
 *      leaves the production version's schema (a distinct OR schema
 *      object, per the app+version slug-prefix convention documented in
 *      `src/store/schemas.js` / `SchemaDesigner.vue#refreshList`)
 *      untouched.
 *   3. REQ-OBDSA-007 — an editor sees the Access sub-editor disabled
 *      with an owner-only note on the production version; an owner
 *      sees it enabled.
 */

import { test, expect, type Page } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'
import { saveSchemaAndAwait } from './support/schemaSave'
const APP_SLUG = process.env.NC_ACCESS_RBAC_SLUG ?? 'hello-world'
const STAGING_VERSION = process.env.NC_STAGING_VERSION ?? 'staging'
const PRODUCTION_VERSION = process.env.NC_PRODUCTION_VERSION ?? 'production'
const SCHEMA_SLUG = process.env.NC_ACCESS_RBAC_SCHEMA ?? 'hello'

const EDITOR_NONVET = {
	user: process.env.NC_EDITOR_NONVET_USER ?? 'access-editor-nonvet',
	pass: process.env.NC_EDITOR_NONVET_PASS ?? 'AccessEditorNonvet-1!',
}
const EDITOR_VET = {
	user: process.env.NC_EDITOR_VET_USER ?? 'access-editor-vet',
	pass: process.env.NC_EDITOR_VET_PASS ?? 'AccessEditorVet-1!',
}
const OWNER = {
	user: process.env.NC_ACCESS_OWNER_USER ?? 'admin',
	pass: process.env.NC_ACCESS_OWNER_PASS ?? 'admin',
}

async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} failed — still on ${page.url()}`)
	}
}

/**
 * Navigate to the given app's Schema Designer detail view for the named
 * schema, optionally under `?_version=`.
 *
 * @param page Playwright page.
 * @param versionSlug Optional `?_version=` value.
 */
async function openSchemaDetail(page: Page, versionSlug?: string): Promise<void> {
	const query = versionSlug ? `?_version=${versionSlug}` : ''
	await page.goto(
		`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas/${SCHEMA_SLUG}${query}`,
		{ waitUntil: 'domcontentloaded' },
	)
}

// STILL SKIPPED. The three blockers recorded here previously are now RESOLVED;
// a fourth, found by measuring rather than reading, is not. Re-measured
// 2026-08-01 — do not re-derive this from the source, it does not read that way.
//
// RESOLVED — Blocker 1 (fixture users). globalSetup provisions
//   rbac-owner/-editor/-viewer/-outsider and mints one storageState each
//   (tests/e2e/.auth/{id}.json). The two scenarios need "an editor IN the
//   selected group" vs "an editor NOT in it" — that is rbac-editor picking
//   `rbac-editors` vs `rbac-viewers`. No `vets` group, and no Newman, needed.
// RESOLVED — Blocker 2 (version chain). tests/e2e/support/versionChain.ts seeds
//   development -> staging -> production on `pw-verchain`.
// RESOLVED — Blocker 3 (dead selector). The feature is real and the copy exists:
//   SchemaDesigner.vue renders `<NcNoteCard type="warning">` gated on
//   `authorLockedOut`, with the text "…invisible to you…". It is a SIBLING of
//   `.openbuild-access-editor`, not a child. Target `.notecard--warning` filtered
//   by that text; `.note-stub` never existed.
//
// RESOLVED — Blocker 4 (designer unreachable for non-admins). A non-admin used
//   to land on the first-time SETUP WIZARD, because /api/setup/status is
//   admin-only, answers 403, and useSetupStatus read that as "nothing done".
//   Fixed in @conduction/nextcloud-vue 2.1.0-vue3.15 (ConductionNL/nextcloud-vue#576;
//   the first attempt, #575, was inert — it short-circuited `completed`, which
//   CnAppRoot never reads). Guarded now by tests/e2e/non-admin-access.spec.ts.
// RESOLVED — Blocker 5 (prefixed gids). `availableGroups` used to feed the
//   dropdown `group:rbac-editors` while everything downstream wants a bare gid,
//   so a configured scope granted NOBODY and the lock-out warning fired for
//   members. Fixed in #83; covered by 4 unit tests in
//   tests/views/SchemaDesigner.access.spec.js.
//
// BLOCKING — no DRAFT-version schema fixture. Every remaining scenario needs a
// schema on a NON-production version: REQ-OBDSA-004/007 need an editable Access
// editor (production is owner-only by design), and REQ-OBDSA-006 needs to prove
// a staging edit leaves the production copy untouched, which requires both
// copies to exist. Measured on the instance, the version chain seeded by
// support/versionChain.ts carries exactly one schema:
//
//     pw-verchain-production-hello-message   (authorization: null)
//
// and no `pw-verchain-staging-*` counterpart. Per SchemaDesigner.vue's
// `refreshList()` the per-version copies are distinct OR schemas distinguished
// by an `{app}-{version}-{schema}` slug prefix, so the fixture has to mint the
// staging copy explicitly — versionChain.ts creates VERSIONS, not their schemas.
//
// Un-skipping before that fixture exists would make every scenario skip itself
// on a missing precondition, which is what this file already did for months
// while reporting as covered.
test.describe
	.skip('data-scopes-authoring — multi-user / multi-version (REQ-OBDSA-004/006/007)', () => {
	// Skip storageState — each test needs a freshly authed, specific-role session.
	test.use({ storageState: { cookies: [], origins: [] } })

	test('REQ-OBDSA-004: non-member editor sees the lock-out warning; a member editor does not', async ({
		page,
	}) => {
		await loginAs(page, EDITOR_NONVET.user, EDITOR_NONVET.pass).catch(() => {
			test.skip(
				true,
				`SKIP: user ${EDITOR_NONVET.user} not found — provision via Newman RBAC setup (editor role, NOT in "vets")`,
			)
		})
		if (/\/login(\?|$|\/)/.test(page.url())) {
			return
		}

		await openSchemaDetail(page)
		const accessSection = page.locator('.openbuild-access-editor')
		await expect(accessSection).toBeVisible({ timeout: 10_000 })

		const readRow = accessSection
			.locator('.openbuild-access-editor__row')
			.filter({ hasText: /^read$/i })
		await readRow.getByLabel(/scope/i).click()
		await page.getByRole('option', { name: /specific groups/i }).click()
		const groupInput = readRow.getByLabel(/groups/i)
		await groupInput.fill('vets')
		await groupInput.press('Enter')

		const warning = page
			.locator('.note-stub, [type="warning"]')
			.filter({ hasText: /invisible to you/i })
		await expect(warning).toBeVisible({ timeout: 10_000 })
		// Save must remain enabled — the warning is advisory only.
		await expect(page.getByRole('button', { name: /^save$/i })).toBeEnabled()

		// Second half — a `vets` member editor sets the SAME scope and sees
		// no warning (their own records remain visible).
		await loginAs(page, EDITOR_VET.user, EDITOR_VET.pass).catch(() => {
			test.skip(
				true,
				`SKIP: user ${EDITOR_VET.user} not found — provision via Newman RBAC setup (editor role, member of "vets")`,
			)
		})
		if (/\/login(\?|$|\/)/.test(page.url())) {
			return
		}
		await openSchemaDetail(page)
		await expect(page.locator('.openbuild-access-editor')).toBeVisible({
			timeout: 10_000,
		})
		await expect(
			page
				.locator('.note-stub, [type="warning"]')
				.filter({ hasText: /invisible to you/i }),
		).toHaveCount(0)
	})

	test('REQ-OBDSA-006: a draft-version scope change leaves the production schema unchanged', async ({
		page,
	}) => {
		await loginAs(page, OWNER.user, OWNER.pass)

		// Verify the staging version exists before proceeding (mirrors the
		// precondition-check-then-skip pattern in versionRouting.spec.ts).
		const stagingCheck = await page.request.get(
			`${BASE_URL}/index.php/apps/openbuild/api/applications/${APP_SLUG}/versions/${STAGING_VERSION}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		if (stagingCheck.status() !== 200) {
			test.skip(
				true,
				`SKIP: ApplicationVersion "${STAGING_VERSION}" not found — seed a version with this slug first`,
			)
			return
		}

		// Per the app+version slug-prefix convention (SchemaDesigner.vue
		// `refreshList()`), the staging and production registers' copies of
		// this schema are distinct OR schema objects with distinct slugs.
		const draftSlug = `${APP_SLUG}-${STAGING_VERSION}-${SCHEMA_SLUG}`
		const prodSlug = `${APP_SLUG}-${PRODUCTION_VERSION}-${SCHEMA_SLUG}`

		const prodBefore = await page.request.get(
			`${BASE_URL}/index.php/apps/openregister/api/schemas/${prodSlug}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		if (!prodBefore.ok()) {
			test.skip(
				true,
				`SKIP: production schema ${prodSlug} not found — seed the version chain's schemas first`,
			)
			return
		}
		const prodBeforeBody = await prodBefore.json()

		await openSchemaDetail(page, STAGING_VERSION)
		const accessSection = page.locator('.openbuild-access-editor')
		await expect(accessSection).toBeVisible({ timeout: 10_000 })
		const readRow = accessSection
			.locator('.openbuild-access-editor__row')
			.filter({ hasText: /^read$/i })
		await readRow.getByLabel(/scope/i).click()
		await page.getByRole('option', { name: /specific groups/i }).click()
		const groupInput = readRow.getByLabel(/groups/i)
		await groupInput.fill('staging-only-group')
		await groupInput.press('Enter')
		// Wait for the schema WRITE, not for `networkidle`: the latter never
		// settles on Nextcloud (ADR-074 rule 4) and, more to the point, does not
		// wait for the save XHR at all — the read-back below would race it and
		// assert against the schema's PREVIOUS contents.
		await saveSchemaAndAwait(page)

		const draftAfter = await page.request.get(
			`${BASE_URL}/index.php/apps/openregister/api/schemas/${draftSlug}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		const draftAfterBody = await draftAfter.json()
		expect(draftAfterBody.authorization?.read).toEqual(['staging-only-group'])

		const prodAfter = await page.request.get(
			`${BASE_URL}/index.php/apps/openregister/api/schemas/${prodSlug}`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		const prodAfterBody = await prodAfter.json()
		expect(
			prodAfterBody.authorization,
			'the production schema authorization must be untouched',
		).toEqual(prodBeforeBody.authorization)
	})

	test('REQ-OBDSA-007: editor sees a disabled Access sub-editor on production; owner sees it enabled', async ({
		page,
	}) => {
		await loginAs(page, EDITOR_NONVET.user, EDITOR_NONVET.pass).catch(() => {
			test.skip(
				true,
				`SKIP: user ${EDITOR_NONVET.user} not found — provision via Newman RBAC setup`,
			)
		})
		if (/\/login(\?|$|\/)/.test(page.url())) {
			return
		}
		await openSchemaDetail(page, PRODUCTION_VERSION)
		const accessSection = page.locator('.openbuild-access-editor')
		await expect(accessSection).toBeVisible({ timeout: 10_000 })
		await expect(
			accessSection.getByText(/only be changed by an owner/i),
		).toBeVisible({ timeout: 10_000 })
		// Every scope-kind picker in the sub-editor must be disabled.
		const pickers = accessSection.getByLabel(/scope/i)
		const count = await pickers.count()
		for (let i = 0; i < count; i++) {
			await expect(pickers.nth(i)).toBeDisabled()
		}

		await loginAs(page, OWNER.user, OWNER.pass)
		await openSchemaDetail(page, PRODUCTION_VERSION)
		const ownerAccessSection = page.locator('.openbuild-access-editor')
		await expect(ownerAccessSection).toBeVisible({ timeout: 10_000 })
		await expect(
			ownerAccessSection.getByText(/only be changed by an owner/i),
		).toHaveCount(0)
	})
})
