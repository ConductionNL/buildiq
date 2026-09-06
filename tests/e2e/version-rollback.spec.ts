/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Playwright e2e — version rollback (REQ-OBV-003 / REQ-OBR-009).
 *
 * REWRITTEN. The previous version of this file was skipped for years behind a
 * chain of blocker notes that were each partly wrong; the history is worth
 * keeping because every wrong note cost someone a retarget that could not work:
 *
 *   - "click [data-slug=…] on the list page" — ApplicationCard never rendered
 *     that attribute. True, but not the reason it was stuck.
 *   - "edit the first textarea, then Publish" — the manifest editor is a
 *     sidebar tab on the DETAIL page. Also true, also not the reason.
 *   - "VersionHistory lists publish SNAPSHOTS, not versions, so it needs a
 *     fixture that publishes twice" — WRONG, and mine. VersionHistory loads
 *     `/api/applications/{slug}/versions`: the very rows versionChain.ts
 *     creates.
 *
 * The actual reason the panel was empty was a product bug: that endpoint
 * returns rows with no `applicationUuid`, and VersionHistory filtered on
 * `r.applicationUuid === this.applicationUuid` — a field its own response does
 * not carry — so the "Version history" tab rendered empty for EVERY app,
 * always. Fixed alongside this spec; the filter now applies only to the
 * unscoped endpoint, since the by-slug URL is already app-scoped server-side.
 *
 * What this asserts is the rollback CONTRACT as ApplicationVersionsTab
 * implements it (`onRollback`): confirming a rollback copies that version's
 * manifest onto the Application under a `<version>-rollback-<hex>` label with
 * `status: 'draft'` — so a rollback never silently republishes.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { ensureVersionChain } from './support/versionChain.ts'

// A DEDICATED fixture. This spec plants a manifest and then rolls it back, i.e.
// it mutates the app's active manifest twice per run. versionRouting.spec.ts
// drives `pw-verchain` and reads `/manifest?_version=…` off it, so sharing that
// slug made this spec corrupt that one — observed as an editor getting 404 for
// the staging manifest in a combined run. Destructive specs get their own app.
const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'pw-rollback'

/**
 * Resolve the Application's OR object id — the detail route takes the UUID,
 * not the slug. (The old spec navigated by slug and landed on a not-found page.)
 *
 * @param page Playwright page.
 * @return {Promise<string>} The application uuid.
 */
async function appUuid(page: Page): Promise<string> {
	return page.evaluate(async (slug) => {
		const r = await fetch('/index.php/apps/buildiq/api/applications', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const d = await r.json()
		const rows = Array.isArray(d) ? d : (d?.results ?? [])
		const app = rows.find((x: Record<string, unknown>) => x?.slug === slug)
		return (app?.['@self']?.id ?? app?.id ?? '') as string
	}, TEST_SLUG)
}

/**
 * The Application record as stored, for before/after comparison.
 *
 * @param page Playwright page.
 * @return {Promise<Record<string, unknown>>} The application object.
 */
async function appRecord(page: Page): Promise<Record<string, unknown>> {
	return page.evaluate(async (slug) => {
		const r = await fetch('/index.php/apps/buildiq/api/applications', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const d = await r.json()
		const rows = Array.isArray(d) ? d : (d?.results ?? [])
		return rows.find((x: Record<string, unknown>) => x?.slug === slug) ?? {}
	}, TEST_SLUG)
}

/**
 * The manifest currently served as the application's ACTIVE manifest.
 *
 * Note the endpoint asymmetry ApplicationManifestTab documents: GET returns the
 * manifest bare, PUT expects it wrapped in `{ manifest }`.
 *
 * @param page Playwright page.
 * @return {Promise<unknown>} The active manifest.
 */
async function activeManifest(page: Page): Promise<Record<string, unknown> | null> {
	return page.evaluate(async (slug) => {
		const r = await fetch(
			`/index.php/apps/buildiq/api/applications/${slug}/manifest`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		return r.ok ? await r.json() : null
	}, TEST_SLUG)
}

/**
 * The snapshot a given semver identifies, read from the versions endpoint.
 *
 * Take the semver from the DOM ROW that owns the "Roll back" button being
 * clicked — do NOT infer it from the API ordering. This helper previously
 * picked "the first non-production row" out of the API response, which is a
 * DIFFERENT row than the first button on screen: the restore wrote the empty
 * manifest belonging to the clicked row while the assertion expected the full
 * one belonging to another, so the spec failed against a working fix.
 *
 * @param page Playwright page.
 * @param semver The semver shown on the row being rolled back to.
 * @return {Promise<{version: string, manifest: unknown}>} The snapshot.
 */
async function snapshotBySemver(
	page: Page,
	semver: string,
): Promise<{ version: string; manifest: unknown }> {
	return page.evaluate(
		async ([slug, want]) => {
			const r = await fetch(
				`/index.php/apps/buildiq/api/applications/${slug}/versions`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			const d = await r.json()
			const rows = Array.isArray(d) ? d : (d?.results ?? [])
			const row =
				rows.find(
					(x: Record<string, unknown>) => String(x?.semver ?? '') === want,
				) ?? {}
			return { version: row.semver ?? '', manifest: row.manifest ?? null }
		},
		[TEST_SLUG, semver] as const,
	)
}

/**
 * Overwrite the application's ACTIVE manifest.
 *
 * Note the endpoint asymmetry ApplicationManifestTab documents: GET returns the
 * manifest bare, PUT expects it wrapped in `{ manifest }`.
 *
 * @param page Playwright page.
 * @param manifest The manifest to store.
 * @return {Promise<void>}
 */
async function putActiveManifest(page: Page, manifest: unknown): Promise<void> {
	await page.evaluate(
		async ([slug, m]) => {
			await fetch(
				`/index.php/apps/buildiq/api/applications/${slug}/manifest`,
				{
					method: 'PUT',
					headers: {
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ manifest: m }),
				},
			)
		},
		[TEST_SLUG, manifest] as const,
	)
}

// PREVIOUSLY SKIPPED, AND THE RECORDED REASON WAS WRONG. Both tests now run.
//
// This file used to say: "the sidebar refuses to open — a UI defect to chase on
// its own, not something a selector change fixes", and "getByRole('tab', …)
// finds nothing — these are not ARIA tabs". Neither was true.
//
// What was actually on screen was a MODAL. CnAppRoot offers the first-time-setup
// wizard whenever every REQUIRED step is met but at least one OPTIONAL step is
// not (`optionalSetupGating`, REQ-SETUP-NV-012), and it opens it as a full
// `modal-mask`. Buildiq trips that permanently: its `store` step carries NO
// `required` key — the word "optional" lives only in the title string — so
// `optionalUnmet` is never empty. Real users dismiss it once
// (`cn-setup-wizard-dismissed:{appId}:{version}` in localStorage); every
// Playwright test gets a FRESH context, so it re-opened in every test.
//
// That mask sat over the sidebar toggle (z-index 1001), which is why the toggle
// was "visible but not actionable" — and it marked the background aria-hidden,
// which is why `role=tab` appeared to not exist. Both symptoms, one cause.
// `suppressSetupWizard()` removes it and the sidebar behaves normally.
//
// The measurement that settled it, after three wrong theories:
//     document.elementsFromPoint(<centre of the toggle>)
//       -> div.modal-wrapper--large, div.dialog__modal.modal-mask, …
// Identifying the thing on top costs one probe. Reasoning about why a click
// "should" work costs an afternoon.
/**
 * Open the app detail page and reveal its "Version history" sidebar tab.
 *
 * The sidebar's open/closed state is per-user UI state, so it differs between a
 * freshly seeded fixture box and a long-lived shared one — probe the tab and
 * only open the sidebar when it is actually hidden.
 *
 * Requires `suppressSetupWizard()` to have run: with the setup wizard's
 * modal-mask up, the toggle is visible but not actionable and the tab role is
 * hidden from the a11y tree. See the block comment above.
 *
 * @param page Playwright page.
 * @param uuid The application uuid.
 * @return {Promise<void>}
 */
async function openVersionHistory(
	page: Page,
	uuid: string,
	options: { requireRows?: boolean } = {},
): Promise<void> {
	await page.goto(`${BASE_URL}/apps/buildiq/applications/${uuid}`, {
		waitUntil: 'domcontentloaded',
	})

	// Target the TAB, not its label. `getByText('Version history')` resolves to
	// the `<span class="_sidebarTabsButton__name_…">` inside the tab button, and
	// that span is display:none once the tab strip collapses to icons — so
	// waiting on ITS visibility waits forever. Five nodes carry that exact text;
	// the deepest is the panel's own <h3>.
	const tab = page.getByRole('tab', { name: /version history/i }).first()

	// This used to open with `waitForLoadState('networkidle', 60s).catch(…)`,
	// which can never settle on Nextcloud (ADR-074 rule 4). What it actually
	// bought was a minute of dead time during which the sidebar finished
	// moving — and the sidebar DOES move after first paint. Measured on CI when
	// the wait was replaced by a one-shot "tab is visible, now click it": the
	// click log reads `element is not stable` and then `element is not visible`,
	// i.e. the tab renders, the strip collapses, and the click misses. A single
	// visibility assertion cannot express "and it has stopped moving".
	//
	// So poll the END STATE instead of guessing a moment. Each attempt opens the
	// sidebar if it is closed, clicks the tab, and only succeeds once the PANEL
	// IS ON SCREEN.
	//
	// `aria-selected="true"` alone is not that end state, and measuring it was
	// the second wrong answer here: a run that stopped at the attribute went on
	// to fail the caller's own `.version-history__row` visibility check, which
	// re-polled for a full 20s and saw `hidden` all 38 times. The detail page
	// finishes loading AFTER the first paint and re-mounts the sidebar, which
	// silently undoes an early tab selection — nothing about that is brief, so
	// only re-clicking recovers it. Requiring the rows themselves means an
	// attempt that got undone is retried rather than reported as the app's
	// fault.
	//
	// Accept the empty state too: this helper is also the way in for an app with
	// no history, and asserting WHICH of the two renders is the caller's job.
	//
	// The toggle is a button labelled exactly "Open sidebar". An
	// `[aria-label*="sidebar" i]` match is NOT good enough — "Close sidebar" is
	// also present and matches first.
	//
	// The poll window is 60s, not more: the slowest test here calls this helper
	// TWICE inside a 150s budget, so a larger window could not elapse before the
	// test timeout swallowed its message — the same "guard that cannot fire"
	// trap the describe below records about its own 45s waits.
	const openSidebar = page.getByRole('button', {
		name: 'Open sidebar',
		exact: true,
	})
	// ⚠️ WHEN THE CALLER NEEDS ROWS, REQUIRE THEM *INSIDE* THIS RETRY.
	//
	// The either/or below is right for a caller that only needs the panel open,
	// but it made the row check the CALLER's problem — and a caller asserting
	// `.version-history__row` visibility after this helper returns has no way to
	// re-open anything. Measured on CI at 6349aa6a: the two E2E runs of the SAME
	// sha disagreed. One passed this spec in 49.7s; the other failed it in 36.6s
	// with the row `hidden` on all 36 polls of a 20s wait, the locator resolving
	// to a real `<li class="version-history__row version-history__row--current">`
	// the whole time. That is the sidebar re-mount described above landing AFTER
	// the helper had already succeeded — exactly the case this toPass() exists to
	// absorb, happening one statement too late to be absorbed.
	//
	// Passing `requireRows` moves the same assertion inside the loop, so a
	// re-mount is re-opened and retried instead of being reported as the app
	// hiding its own rows. Nothing is weakened: the condition asserted is
	// identical, and `.version-history__empty` stops counting as success for
	// callers that specifically need a chain.
	const panelBody =
		options.requireRows === true
			? page.locator('.version-history__row').first()
			: page.locator('.version-history__row, .version-history__empty').first()
	await expect(async () => {
		if (!(await tab.isVisible().catch(() => false))) {
			await openSidebar.click({ timeout: 10_000 })
		}
		await tab.click({ timeout: 10_000 })
		await expect(tab).toHaveAttribute('aria-selected', 'true', {
			timeout: 5_000,
		})
		await expect(panelBody).toBeVisible({ timeout: 5_000 })
	}, 'the Version history panel must open and render its body').toPass({
		timeout: 60_000,
		intervals: [500, 1_000, 2_000],
	})
}

test.describe('buildiq-versioning — rollback (REQ-OBV-003)', () => {
	// The default 30s cannot cover this on a loaded instance. Measured on the
	// shared dev box (28 applications, 200+ schemas), a single
	// GET /api/applications takes ~6.9s — against ~0.3s on a disposable
	// fixture instance — and this spec needs several, plus two page loads and
	// a version-chain seed. The budget is set from that measurement rather
	// than raised until it passes.
	test.describe.configure({ timeout: 150_000 })

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
		await page.goto(`${BASE_URL}/apps/buildiq/`, {
			waitUntil: 'domcontentloaded',
		})
		await ensureVersionChain(page, TEST_SLUG, 'PW Rollback Fixture')
	})

	test('the version history tab lists the chain', async ({ page }) => {
		await openVersionHistory(page, await appUuid(page))

		// The regression guard: this panel used to render `.version-history__empty`
		// for every app because of the applicationUuid filter described above.
		const rows = page.locator('.version-history__row')
		await expect(
			rows,
			'the seeded development -> staging -> production chain must be listed',
		).toHaveCount(3, { timeout: 15_000 })
		await expect(page.locator('.version-history__empty')).toHaveCount(0)
	})

	test('rolling back RESTORES the snapshot manifest onto the active version', async ({
		page,
	}) => {
		await openVersionHistory(page, await appUuid(page), {
			requireRows: true,
		})

		// "Roll back" only renders on a NON-production row (`v-if="!isProduction(row)"`),
		// so this also proves the terminal production version offers no rollback.
		const rollbackBtns = page.locator('.version-history__btn--danger')
		const total = await page.locator('.version-history__row').count()
		expect(
			await rollbackBtns.count(),
			'production must not offer Roll back — exactly the non-production rows may',
		).toBe(total - 1)

		// Resolve the target from the row that OWNS the first Roll back button.
		const targetRow = page
			.locator('.version-history__row')
			.filter({ has: page.locator('.version-history__btn--danger') })
			.first()
		const targetSemver = (
			await targetRow.locator('.version-history__semver').innerText()
		).trim()
		const target = await snapshotBySemver(page, targetSemver)
		expect(
			target.manifest,
			`the snapshot under test (${targetSemver}) must carry a stored manifest`,
		).toBeTruthy()

		// POSITIVE CONTROL. The seeded snapshots all carry the SAME (empty
		// menu/pages) manifest, so "restored the snapshot" and "did nothing at
		// all" produce byte-identical results — the assertion below would pass
		// against a rollback that was a complete no-op, which is exactly the bug
		// this spec exists to catch. Plant a manifest that differs from the
		// target FIRST, so the restore has something observable to undo.
		const planted = {
			version: '1.0.0',
			menu: [
				{
					id: 'planted',
					label: 'PLANTED_BEFORE_ROLLBACK',
					icon: 'icon-category-dashboard',
					route: 'Planted',
					order: 10,
				},
			],
			pages: [
				{
					id: 'Planted',
					route: '/planted',
					type: 'dashboard',
					title: 'Planted',
					config: { widgets: [], layout: [] },
				},
			],
		}
		await putActiveManifest(page, planted)
		expect(
			(await activeManifest(page))?.menu,
			'the planted manifest must be live before the rollback — otherwise this test cannot fail',
		).toEqual(planted.menu)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await openVersionHistory(page, await appUuid(page), {
			requireRows: true,
		})
		await page.locator('.version-history__btn--danger').first().click()

		// RollbackConfirmModal — copy is "Roll back" (no ellipsis).
		const confirm = page.getByRole('button', { name: /^roll back$/i }).last()
		await expect(confirm, 'the confirm modal must appear').toBeVisible({
			timeout: 10_000,
		})
		await confirm.click()

		// THE regression guard. Rollback used to PUT `{manifest, version, status}`
		// onto the Application object, whose schema has NEITHER `manifest` NOR
		// `version` — both were dropped, only `status` survived, and the manifest
		// never came back. Compare menu/pages only: the GET returns an EFFECTIVE
		// manifest, with `runtime` injected and `name` added server-side, so a
		// whole-document comparison can never match a stored snapshot.
		await expect
			.poll(
				async () =>
					JSON.stringify((await activeManifest(page))?.menu ?? null),
				{
					message: 'the active menu must become the snapshot menu',
					timeout: 30_000,
				},
			)
			.toBe(
				JSON.stringify(
					(target.manifest as Record<string, unknown>)?.menu ?? null,
				),
			)

		const after = await appRecord(page)
		expect(
			after.status,
			'a rollback must land as a DRAFT — it never silently republishes',
		).toBe('draft')
		expect(
			(await activeManifest(page))?.menu,
			'the planted manifest must be GONE — proves the restore actually wrote',
		).not.toEqual(planted.menu)
	})
})
