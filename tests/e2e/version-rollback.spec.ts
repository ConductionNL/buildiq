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

import { test, expect } from '@playwright/test'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'
import { ensureVersionChain } from './support/versionChain'
import { suppressSupportDialog } from './support/appFixture'

const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'pw-verchain'

/**
 * Resolve the Application's OR object id — the detail route takes the UUID,
 * not the slug. (The old spec navigated by slug and landed on a not-found page.)
 *
 * @param page Playwright page.
 * @return {Promise<string>} The application uuid.
 */
async function appUuid(page: import('@playwright/test').Page): Promise<string> {
	return page.evaluate(async (slug) => {
		const r = await fetch('/index.php/apps/openbuild/api/applications', { headers: { 'OCS-APIRequest': 'true' } })
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
async function appRecord(page: import('@playwright/test').Page): Promise<Record<string, unknown>> {
	return page.evaluate(async (slug) => {
		const r = await fetch('/index.php/apps/openbuild/api/applications', { headers: { 'OCS-APIRequest': 'true' } })
		const d = await r.json()
		const rows = Array.isArray(d) ? d : (d?.results ?? [])
		return rows.find((x: Record<string, unknown>) => x?.slug === slug) ?? {}
	}, TEST_SLUG)
}

// ⚠️ SKIPPED because it has NEVER BEEN EXECUTED — not because of a known blocker.
//
// It is written against a contract that WAS verified live (the VersionHistory
// fix below it was confirmed on a real instance: 3 rows, `.version-history__empty`
// count 0, where the panel had always rendered empty before). But the disposable
// e2e instance was destroyed by a disk-full event before this spec itself could
// be run once, so its selectors, timings and the rollback assertion are unproven.
//
// Enabling it is a one-line change — delete the `.skip` — but do that WITH a run,
// not on the strength of this comment. Shipping an unexecuted spec as if it were
// coverage is the exact failure mode the notes above document three times over.
test.describe.skip('openbuild-versioning — rollback (REQ-OBV-003)', () => {
	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await page.goto(`${BASE_URL}/apps/openbuild/`, { waitUntil: 'domcontentloaded' })
		await ensureVersionChain(page, TEST_SLUG, 'PW Version Chain')
	})

	test('the version history tab lists the chain', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/applications/${await appUuid(page)}`, { waitUntil: 'domcontentloaded' })
		await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {})
		await page.getByText('Version history', { exact: true }).first().click({ timeout: 10_000 })

		// The regression guard: this panel used to render `.version-history__empty`
		// for every app because of the applicationUuid filter described above.
		const rows = page.locator('.version-history__row')
		await expect(rows, 'the seeded development -> staging -> production chain must be listed')
			.toHaveCount(3, { timeout: 15_000 })
		await expect(page.locator('.version-history__empty')).toHaveCount(0)
	})

	test('rolling back copies the snapshot manifest onto the app as a draft', async ({ page }) => {
		const before = await appRecord(page)

		await page.goto(`${BASE_URL}/apps/openbuild/applications/${await appUuid(page)}`, { waitUntil: 'domcontentloaded' })
		await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {})
		await page.getByText('Version history', { exact: true }).first().click({ timeout: 10_000 })
		await expect(page.locator('.version-history__row').first()).toBeVisible({ timeout: 15_000 })

		// "Roll back" only renders on a NON-production row (`v-if="!isProduction(row)"`),
		// so this also proves the terminal production version offers no rollback.
		const rollbackBtns = page.locator('.version-history__btn--danger')
		const total = await page.locator('.version-history__row').count()
		expect(
			await rollbackBtns.count(),
			'production must not offer Roll back — exactly the non-production rows may',
		).toBe(total - 1)

		await rollbackBtns.first().click()

		// RollbackConfirmModal — copy is "Roll back" (no ellipsis).
		const confirm = page.getByRole('button', { name: /^roll back$/i }).last()
		await expect(confirm, 'the confirm modal must appear').toBeVisible({ timeout: 10_000 })
		await confirm.click()

		// The contract (ApplicationVersionsTab.onRollback): manifest copied over,
		// version relabelled `<version>-rollback-<hex>`, status forced to draft.
		await expect.poll(async () => (await appRecord(page)).version, {
			message: 'the application version must be relabelled as a rollback',
			timeout: 20_000,
		}).toMatch(/-rollback-[0-9a-f]+$/i)

		const after = await appRecord(page)
		expect(after.status, 'a rollback must land as a DRAFT — it never silently republishes').toBe('draft')
		expect(after.version, 'the rollback label must differ from the pre-rollback version').not.toBe(before.version)
	})
})
