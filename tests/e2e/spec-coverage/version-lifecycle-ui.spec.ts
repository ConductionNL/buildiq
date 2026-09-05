// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the `version-lifecycle-ui` spec — the maintainer cockpit for
 * Buildiq's two-object version model.
 *
 * NEW FILE 2026-08-11. Every one of this spec's 17 scenarios was uncovered by
 * gate-19: no Playwright test anywhere in the suite referenced a single one of
 * them, even though `src/views/VersionHistory.vue` and
 * `src/views/ManifestLayersDetail.vue` implement the whole surface.
 *
 * The surface, read from source rather than guessed:
 *
 *   route      /apps/buildiq/applications/{objectId}/manifest
 *              (manifest.json page `ApplicationManifestDetail`)
 *   list       GET  /apps/buildiq/api/applications/{slug}/versions
 *              (VersionHistory.vue — the SLUG-based endpoint, which is the
 *              whole point of REQ-OBV-VLU-001)
 *   row        .version-history__row, production row also --current
 *   marker     .version-history__badge--production
 *   open       openVersion() -> /apps/buildiq/builder/{slug}
 *                               (+ '?_version=' + slug when not production)
 *   edit       editVersion() -> /apps/buildiq/builder/{slug}/pages
 *                               (+ '?_version=' + slug when not production)
 *   new draft  ManifestLayersDetail.createDraft() ->
 *              POST /apps/buildiq/api/applications/{slug}/versions
 *
 * WHAT IS NOT HERE, AND WHY. REQ-OBV-VLU-007 (the "Open app" split button in
 * ApplicationDetailActions.vue) and REQ-OBV-VLU-008 (the NL catalogue) are a
 * different component and a build-time artefact respectively; they stay
 * uncovered and counted rather than being claimed by a test that does not drive
 * them. Nothing in this file is annotated for a scenario it does not exercise.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { suppressSetupWizard, suppressSupportDialog } from '../support/appFixture.ts'
import { E2E_BASE_URL as BASE } from '../support/baseUrl.ts'
import { ensureVersionChain, listVersions } from '../support/versionChain.ts'

/**
 * A dedicated fixture app.
 *
 * `ensureVersionChain` provisions `development` -> `staging` -> `production`,
 * so the list has a production row AND non-production rows — without both,
 * half of these scenarios cannot be distinguished from each other.
 *
 * Deliberately not `hello-world`: the New-draft scenario WRITES a version, and
 * hello-world's single-version shape is asserted by other suites.
 */
const SLUG = 'pw-vlu'
const NAME = 'PW Version Lifecycle'

/**
 * Open the Manifest detail page, which is where `VersionHistory` is routed.
 *
 * @param page Playwright page.
 * @param objectId The Application's OR object id.
 * @return {Promise<void>}
 */
async function openManifestDetail(page: Page, objectId: string): Promise<void> {
	await page.goto(`${BASE}/apps/buildiq/applications/${objectId}/manifest`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(
		page.locator('.version-history'),
		'the Manifest detail page must render the VersionHistory panel',
	).toBeVisible({ timeout: 20_000 })
}

/**
 * Resolve the fixture Application's OR object id.
 *
 * Asserted, never skipped: the fixture is provisioned in `beforeEach`, so a
 * miss means the provisioning broke.
 *
 * @param page Playwright page.
 * @return {Promise<string>} The object id.
 */
async function appObjectId(page: Page): Promise<string> {
	const res = await page.request.get(
		`${BASE}/index.php/apps/openregister/api/objects/buildiq/built-app`
			+ `?slug=${encodeURIComponent(SLUG)}&_limit=1`,
	)
	expect(res.ok(), 'the Application lookup must succeed').toBeTruthy()
	const rows = (await res.json()).results || []
	expect(
		rows.length,
		`the "${SLUG}" fixture Application must exist`,
	).toBeGreaterThan(0)
	const id = rows[0]['@self']?.id || rows[0].uuid || rows[0].id
	expect(id, 'the Application must carry an object id').toBeTruthy()
	return String(id)
}

/** The row locator for a version addressed by its visible name. */
function rowFor(page: Page, versionName: string) {
	return page.locator('.version-history__row').filter({
		has: page.locator('.version-history__row-title', {
			hasText: versionName,
		}),
	})
}

test.describe('version-lifecycle-ui — the version list on the Manifest detail page', () => {
	// The row actions sit in a right-hand column that collapses at the default
	// 1280x720, which puts Open/Edit/Release behind an overflow.
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
		await ensureVersionChain(page, SLUG, NAME)
	})

	// @e2e version-lifecycle-ui::slug-is-passed-to-the-version-list
	// @e2e version-lifecycle-ui::versions-render-for-an-app-with-at-least-one-version
	test('REQ-OBV-VLU-001 — the list is fetched by SLUG and renders one row per version', async ({
		page,
	}) => {
		const objectId = await appObjectId(page)

		// The scenario is specifically that the SLUG-based endpoint is called —
		// the bug it guards is the view falling back to applicationUuid. Arm the
		// wait BEFORE navigating so the request cannot be missed.
		const versionsCall = page.waitForResponse(
			(r) =>
				r.url().includes(`/apps/buildiq/api/applications/${SLUG}/versions`)
				&& r.request().method() === 'GET',
			{ timeout: 30_000 },
		)

		await page.goto(`${BASE}/apps/buildiq/applications/${objectId}/manifest`, {
			waitUntil: 'domcontentloaded',
		})

		const res = await versionsCall
		expect(
			res.status(),
			'the slug-based versions endpoint must answer 2xx',
		).toBeLessThan(300)

		// "the version list renders one row per version (not the empty state)"
		const expected = await listVersions(page, SLUG)
		expect(
			expected.length,
			'the fixture must carry at least one version',
		).toBeGreaterThan(0)

		await expect(
			page.locator('.version-history__empty'),
			'the empty state must NOT be shown for an app that has versions',
		).toHaveCount(0)
		await expect(
			page.locator('.version-history__row'),
			'one row per ApplicationVersion',
		).toHaveCount(expected.length, { timeout: 20_000 })
	})

	// @e2e version-lifecycle-ui::production-version-is-marked
	test('REQ-OBV-VLU-004 — exactly one row carries the production marker', async ({
		page,
	}) => {
		const objectId = await appObjectId(page)
		await openManifestDetail(page, objectId)

		const marker = page.locator('.version-history__badge--production')
		await expect(
			marker,
			'the production version must carry a marker distinct from the other rows',
		).toHaveCount(1, { timeout: 20_000 })
		await expect(marker).toHaveText('Production')

		// The marker must be ON the production row, not merely present somewhere:
		// the row that carries it is also the one flagged `--current`.
		await expect(
			page.locator(
				'.version-history__row--current .version-history__badge--production',
			),
			'the marker must sit on the row the view considers production',
		).toHaveCount(1)

		// And the other rows must NOT carry it — a marker on every row would
		// satisfy a naive "is it visible" check while marking nothing.
		const rows = await page.locator('.version-history__row').count()
		expect(
			rows,
			'the fixture chain must give more than one row',
		).toBeGreaterThan(1)
	})

	// @e2e version-lifecycle-ui::click-a-non-production-version-opens-it-scoped
	test('REQ-OBV-VLU-002 — activating a non-production row opens the builder scoped to it', async ({
		page,
	}) => {
		// THREE page loads: the fixture provisioning in beforeEach, the manifest
		// detail page, and then the builder — which is a SEPARATE webpack entry
		// (`src/builder.js`), so it is a cold bundle fetch, not an SPA route
		// change. The project default of 30s is sized for single-navigation
		// tests and the whole TEST ran out of budget at 39.9s, which surfaced as
		// a `waitForURL` timeout and read like a navigation that never happened.
		// This is the same allowance iconUpload.spec.ts already makes; the
		// assertion itself is unchanged and still exact.
		test.setTimeout(120_000)

		const objectId = await appObjectId(page)
		await openManifestDetail(page, objectId)

		// `staging` is a draft in the fixture chain, so it is never production.
		const staging = rowFor(page, 'staging')
		await expect(staging, 'the fixture must render a "staging" row').toHaveCount(
			1,
			{ timeout: 20_000 },
		)
		await expect(
			staging.locator('.version-history__badge--production'),
			'"staging" must not be the production row, or this scenario tests nothing',
		).toHaveCount(0)

		await staging
			.locator('.version-history__btn', { hasText: 'Open' })
			.first()
			.click()

		await page.waitForURL(
			(url) =>
				url.pathname.endsWith(`/apps/buildiq/builder/${SLUG}`)
				&& url.searchParams.get('_version') === 'staging',
			{ timeout: 30_000 },
		)
	})

	// @e2e version-lifecycle-ui::click-the-production-version-opens-the-canonical-url
	test('REQ-OBV-VLU-002 — activating the production row opens the canonical URL with no _version', async ({
		page,
	}) => {
		// Three page loads, one of them the standalone builder bundle — see the
		// note on the non-production case above.
		test.setTimeout(120_000)

		const objectId = await appObjectId(page)
		await openManifestDetail(page, objectId)

		const production = page.locator('.version-history__row--current')
		await expect(
			production,
			'the production row must be identifiable',
		).toHaveCount(1, { timeout: 20_000 })

		await production
			.locator('.version-history__btn', { hasText: 'Open' })
			.first()
			.click()

		await page.waitForURL(
			(url) =>
				url.pathname.endsWith(`/apps/buildiq/builder/${SLUG}`)
				&& !url.searchParams.has('_version'),
			{ timeout: 30_000 },
		)
	})

	// @e2e version-lifecycle-ui::edit-a-version-opens-the-designer-with-the-version-param
	test('REQ-OBV-VLU-003 — per-row Edit opens the designer carrying ?_version=', async ({
		page,
	}) => {
		// Three page loads, the last being the page designer — see the note on
		// the non-production case above.
		test.setTimeout(120_000)

		const objectId = await appObjectId(page)
		await openManifestDetail(page, objectId)

		const staging = rowFor(page, 'staging')
		await expect(staging).toHaveCount(1, { timeout: 20_000 })

		const edit = staging.locator('.version-history__btn', { hasText: 'Edit' })
		await expect(
			edit,
			'Edit must be offered to an editor+ caller (the run is admin, so canEdit is true)',
		).toHaveCount(1)

		await edit.first().click()

		await page.waitForURL(
			(url) =>
				url.pathname.endsWith(`/apps/buildiq/builder/${SLUG}/pages`)
				&& url.searchParams.get('_version') === 'staging',
			{ timeout: 30_000 },
		)
	})

	// @e2e version-lifecycle-ui::new-draft-clones-production-manifest-and-shares-its-register
	test('REQ-OBV-VLU-005 — New draft posts a draft that clones production manifest and shares its register', async ({
		page,
	}) => {
		// A navigation, a list round-trip and a create round-trip.
		test.setTimeout(120_000)

		const objectId = await appObjectId(page)
		await openManifestDetail(page, objectId)

		const before = await listVersions(page, SLUG)
		const productionRow = before.find((v) => v?.slug === 'production')
		expect(
			productionRow,
			'the fixture must carry a production version',
		).toBeTruthy()

		const newDraft = page.getByRole('button', { name: 'New draft' })
		await expect(
			newDraft,
			'New draft must be offered to an owner/editor caller',
		).toBeVisible({ timeout: 20_000 })

		// Arm the create BEFORE the click. The button also triggers a GET of the
		// version list first, so the predicate pins the method as well as the URL.
		const created = page.waitForResponse(
			(r) =>
				r.url().includes(`/apps/buildiq/api/applications/${SLUG}/versions`)
				&& r.request().method() === 'POST',
			{ timeout: 45_000 },
		)

		await newDraft.click()

		const res = await created
		expect(res.status(), 'the draft create must answer 2xx').toBeLessThan(300)

		const sent = res.request().postDataJSON()
		expect(sent.status, 'the created version must be a draft').toBe('draft')
		expect(
			sent.application,
			'the draft must point at the parent Application uuid',
		).toBe(objectId)
		expect(
			sent.manifest,
			'the draft manifest must be a clone of the production version manifest',
		).toEqual(productionRow?.manifest ?? {})
		expect(
			Object.hasOwn(sent, 'register'),
			"the payload must OMIT register so the backend inherits production's — "
				+ 'sending one is how a per-version register gets minted by accident',
		).toBe(false)

		// "the version list re-renders showing the new draft"
		await expect(
			page.locator('.version-history__row'),
			'the list must gain the new draft row',
		).toHaveCount(before.length + 1, { timeout: 30_000 })

		// The register really is shared, read back from the server rather than
		// inferred from the absent request field.
		const after = await listVersions(page, SLUG)
		const draft = after.find(
			(v) => !before.some((b) => (b?.id ?? b?.uuid) === (v?.id ?? v?.uuid)),
		)
		expect(draft, 'the new draft must be listed').toBeTruthy()
		expect(
			draft?.register,
			'the new draft must SHARE the production register, not mint its own',
		).toEqual(productionRow?.register)
	})
})
