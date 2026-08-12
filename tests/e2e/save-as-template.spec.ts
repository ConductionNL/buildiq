/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end test for the OpenBuild "save as template" flow
 * (openspec/changes/save-as-template) — the authoring half of the template
 * marketplace loop. Drives the UI through the application-detail surface,
 * the gallery, and the clone round-trip.
 *
 * Covers (gate-19 scenario references):
 *   - REQ-SAT-001 "Saving captures the app as an org-local template"
 *   - REQ-SAT-001 "Viewer cannot save a template"
 *   - REQ-SAT-002 "Round-trip is a clean rename"
 *   - REQ-SAT-004 "Update-in-place bumps the version"
 *   - REQ-SAT-005 "Org-local template appears with badge and clones normally"
 *   - REQ-SAT-005 "Delete leaves clones and the source app intact"
 *   - REQ-SAT-005 "Seeded cards remain read-only"
 *
 * API-shape assertions (OR RBAC, create/update/delete contracts) live in the
 * Newman collection, not here (Playwright drives the UI only).
 *
 * QUARANTINED (Conduction/openbuild#41): the openbuild admin UI does not
 * render the application-detail surface / template-clone dialog in this build,
 * so the flow cannot be driven end-to-end yet. This file is the canonical UI
 * coverage and re-enables once #41 is fixed (same deferred-bootstrap pattern as
 * tests/e2e/template-gallery.spec.ts).
 */

import { test, expect } from '@playwright/test'
import { ensureApp, dismissOverlays, suppressSupportDialog } from './support/appFixture'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'

const SOURCE_APP = 'pw-sat-source'
const TEMPLATE_SLUG = 'pw-sat-template'
const OR_TEMPLATES = '/index.php/apps/openregister/api/objects/openbuild/application-template'

// UN-QUARANTINED AND NARROWED 2026-07-31. #41 was not the blocker; the flow this
// file drove is half live and half deliberately removed.
//
// LIVE, and covered below: capturing an app as an org-local ApplicationTemplate
// (ApplicationDetailActions -> "Save as template" -> SaveAsTemplateDialog ->
// an ApplicationTemplate record in OpenRegister).
//
// REMOVED ON PURPOSE, and therefore NOT asserted any more: the clone round-trip.
// This file cloned a seeded "Permit Tracker" card to build its fixture app and
// then re-cloned its own saved template from the gallery. Commit f8e0eec57
// ("keep GitHub-only") made the Templates tab a GitHub search; the gallery's
// CloneTemplateDialog is bound `:github="true"` and installs through
// `/api/shop/github/install`. Nothing in src/ calls
// `POST /api/applications/from-template/{templateSlug}` any more, so an
// org-local template cannot be cloned from the UI at all. The fixture app is
// now created through the wizard fixture instead, and the clone/badge
// assertions are dropped rather than rewritten against a path that does not
// exist. See tests/e2e/template-gallery.spec.ts for the same finding.
test.describe('OpenBuild save as template', () => {
	/**
	 * Delete any template this suite created, so a re-run starts clean and the
	 * slug-collision guard in the dialog does not (correctly) block the save.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<void>}
	 */
	async function resetTemplate(page) {
		await page.evaluate(async ({ api, slug }) => {
			const tok = window.OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const headers = { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
			const listed = await (await fetch(api, { headers })).json().catch(() => null)
			const rows = Array.isArray(listed) ? listed : (listed?.results ?? [])
			for (const row of rows) {
				if (row?.slug !== slug) {
					continue
				}
				const uuid = row?.['@self']?.id ?? row?.id
				if (uuid) {
					await fetch(`${api}/${encodeURIComponent(String(uuid))}`, { method: 'DELETE', headers })
				}
			}
		}, { api: OR_TEMPLATES, slug: TEMPLATE_SLUG })
	}

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureApp(page, SOURCE_APP, 'PW SAT Source')
		await resetTemplate(page)
	})

	// @e2e save-as-template::saving-captures-the-app-as-an-org-local-template
	test('captures an app as an org-local template, config only', async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/applications/${SOURCE_APP}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissOverlays(page)

		// "Save as template" lives in the detail page's overflow Actions menu.
		await page.getByRole('button', { name: /^Actions$/i }).first().click()
		await page.getByRole('menuitem', { name: /Save as template/i })
			.or(page.getByRole('button', { name: /Save as template/i }))
			.first()
			.click()

		const saveDialog = page.locator('.ob-save-template')
		await expect(saveDialog).toBeVisible({ timeout: 20_000 })
		await saveDialog.getByLabel(/Template title/i).fill('PW SAT template')
		await saveDialog.getByLabel(/^Slug/i).fill(TEMPLATE_SLUG)
		await saveDialog.getByLabel(/Use case/i).fill('e2e capture')

		// A category is required (`canSave` gates on `selectedCategory`) and the
		// dialog pre-selects the first one, so it needs no interaction here —
		// asserted rather than assumed.
		await expect(saveDialog.locator('.v-select')).toContainText(/\w/)

		// No validation error may be showing: the capture must be a real manifest.
		// This is the regression that made Save unreachable — the dialog captured
		// `{}` and reported "/version must be a string /menu must be an array
		// /pages must be an array" for every app.
		await expect(saveDialog.locator('.ob-save-template__error')).toHaveCount(0)

		// The capture is config-only — it never carries object rows.
		await expect(saveDialog.locator('.ob-save-template__no-rows')).toBeVisible()

		await page.getByRole('button', { name: /^Save as template$/i }).last().click()

		// The record must actually land in OpenRegister.
		await expect.poll(async () => {
			const rows = await page.evaluate(async (api) => {
				const resp = await fetch(api, { headers: { 'OCS-APIRequest': 'true' } })
				const data = await resp.json().catch(() => null)
				return Array.isArray(data) ? data : (data?.results ?? [])
			}, OR_TEMPLATES)
			return rows.some((r) => r?.slug === TEMPLATE_SLUG)
		}, { timeout: 30_000, message: 'the saved template must exist as an ApplicationTemplate' }).toBe(true)

		const stored = await page.evaluate(async (api) => {
			const resp = await fetch(api, { headers: { 'OCS-APIRequest': 'true' } })
			const data = await resp.json().catch(() => null)
			const rows = Array.isArray(data) ? data : (data?.results ?? [])
			return rows.find((r) => r?.slug === 'pw-sat-template') ?? null
		}, OR_TEMPLATES)

		expect(stored, 'the template record must be readable back').toBeTruthy()
		expect(stored.title).toBe('PW SAT template')
		// Org-local, not one of the bundled Conduction fixtures.
		expect(stored.isSeeded ?? false).toBeFalsy()
		// It captured the app's manifest, and no object data travelled with it.
		expect(stored.manifest, 'the capture must carry the source manifest').toBeTruthy()
		expect(JSON.stringify(stored.manifest)).not.toContain('"results"')
	})

	// ⚠️ TWO `@e2e` ANCHORS WERE REMOVED FROM HERE. DO NOT PUT THEM BACK.
	//
	// This test used to carry
	//     @e2e save-as-template::viewer-cannot-save-a-template
	//     @e2e save-as-template::seeded-cards-remain-read-only
	// and hydra gate-19 counted both scenarios as COVERED — while the comment
	// immediately below, written by the same hand, states in plain words that
	// the test proves neither of them. The gate has no way to notice that: it
	// matches the tag and checks only that the enclosing test runs
	// (ConductionNL/.github#343). A green scenario that nothing exercises is
	// worse than a red one, because nobody looks at it again.
	//
	// Both scenarios are now correctly reported as uncovered. Writing them for
	// real is tracked in Conduction/openbuild#178 — and the "no provisioned
	// user" reason below is STALE: `tests/e2e/global-setup.ts` mints
	// `.auth/rbac-viewer.json` on every run.
	//
	// The capture action is scoped to a single application, never to the list.
	//
	// This test used to be called "a viewer sees neither the save action nor the
	// manage actions", which it did not test: it runs on the shared ADMIN
	// session, so the absent button proves scoping, not rights-gating. Its second
	// half then asserted that a seeded "Permit Tracker" card carries no Edit or
	// Delete button — since the GitHub-only gallery renders no seeded cards at
	// all, those `toHaveCount(0)` assertions passed against an empty locator and
	// proved nothing.
	//
	// Real viewer gating (REQ-SAT-001 / REQ-SAT-005) needs a second, non-owner
	// session, which this instance has no provisioned user for — the same gap
	// that keeps rbac-403.spec.ts and schema-access-scopes-rbac.spec.ts skipped.
	// It is asserted at the unit level in tests/components/... via `obAppRole`,
	// and left out here rather than faked with an admin session.
	test('the capture action is offered per application, not on the index', async ({ page }) => {
		await page.goto(`${NEXTCLOUD_URL}/apps/openbuild`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByRole('button', { name: /Save as template/i })).toHaveCount(0)
	})
})
