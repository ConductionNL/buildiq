// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the `app-icon-management` spec — the remove half of
 * REQ-OBICON-004.
 *
 * UN-QUARANTINED 2026-08-11. This file used to hold three unconditional
 * `test.skip`s citing "Conduction/openbuild#41: openbuild admin UI not
 * functional in this build — no application detail / icon / template-clone UI
 * renders". That reason was stale on both counts, and each half was checked
 * against a live instance before this rewrite:
 *
 *   1. The detail page renders. `src/manifest.json` declares page
 *      `VirtualAppDetail` at `/applications/:objectId` with a sidebar tab
 *      `{ id: "icons", label: "Icons", component: "ApplicationIconTab" }`.
 *   2. The icon UI exists in full — `src/dialogs/IconUploadSection.vue` ships
 *      both slots, both `accept=".svg"` inputs, the previews, the Remove
 *      buttons and the client-side extension check.
 *
 * The old bodies asserted `expect(page.locator('main')).toBeVisible()` and
 * wrapped their only real assertion in `if (await x.count() > 0)`, so simply
 * removing `.skip` would have produced three green tests that drove nothing.
 *
 * Two of the three scenarios were ALREADY covered by real, running tests that
 * merely lacked the annotation — `tests/e2e/iconUpload.spec.ts` proves
 * `user-uploads-a-light-icon` and `non-svg-file-is-rejected-client-side`
 * against this same surface. They are annotated there rather than duplicated
 * here. What no test anywhere exercised is the REMOVE path, so that is what
 * this file now contains.
 *
 * Backend REQ-OBICON-001/002/003/005 are excluded in the spec itself (PHPUnit +
 * Newman) and are not repeated here.
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import { E2E_BASE_URL as BASE } from '../support/baseUrl'
import { suppressSupportDialog, suppressSetupWizard } from '../support/appFixture'

/** The seeded fixture app both icon suites drive. */
const HELLO_WORLD_SLUG = 'hello-world'

/** OR register/schema the Application record lives in (IconUploadSection.vue). */
const OR_OBJECT_PATH = 'apps/openregister/api/objects/openbuild/application'

/** A minimal but genuinely valid SVG — OR writes the content verbatim. */
const MINIMAL_SVG =
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">'
	+ '<rect width="16" height="16" fill="#0b5fff"/></svg>'

/**
 * Resolve the seeded Application and its OR object id.
 *
 * Every step is an assertion, not a skip: the fixture is seeded by globalSetup,
 * so "not found" means the seeding broke and skipping would hide it.
 *
 * @param request Playwright API request context.
 * @return {Promise<{objectId: string, app: Record<string, any>}>} The record.
 */
async function resolveApp(
	request: APIRequestContext,
): Promise<{ objectId: string; app: Record<string, any> }> {
	const res = await request.get(
		`${BASE}/index.php/apps/openbuild/api/applications`,
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(res.ok(), 'the applications API must answer').toBeTruthy()
	const body = await res.json()
	const rows: Array<Record<string, any>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	const app = rows.find((a) => (a.slug ?? a['@self']?.slug) === HELLO_WORLD_SLUG)
	expect(
		app,
		`the seeded "${HELLO_WORLD_SLUG}" Application must exist`,
	).toBeTruthy()
	const found = app as Record<string, any>
	const objectId = found['@self']?.id || found.uuid || found.id
	expect(objectId, 'the Application must carry an object id').toBeTruthy()
	return { objectId: String(objectId), app: found }
}

/**
 * Open the Application detail page, expand the sidebar, activate the Icons tab.
 *
 * `CnDetailPage` seeds `sidebarOpen: false`, so the manifest-declared tabs are
 * simply not in the DOM until `NcAppSidebar`'s own `.app-sidebar__toggle` is
 * clicked. Omitting that step is why the first draft of this rewrite timed out
 * looking for a tab that could not exist yet.
 *
 * @param page Playwright page.
 * @param objectId The Application's OR object id.
 * @return {Promise<void>}
 */
async function openIconsTab(page: Page, objectId: string): Promise<void> {
	await page.goto(`/apps/openbuild/applications/${objectId}`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(
		page.locator('.ob-detail-header__name'),
		'the detail header must render before the sidebar is driven',
	).toBeVisible({ timeout: 20_000 })

	const sidebar = page.locator('[data-testid="cn-object-sidebar"]')
	if (!(await sidebar.isVisible().catch(() => false))) {
		await page.locator('.app-sidebar__toggle').first().click()
	}
	await expect(sidebar, 'the object sidebar must open').toBeVisible({
		timeout: 15_000,
	})

	await page
		.getByRole('tab', { name: /^icons$/i })
		.first()
		.click()
	await expect(
		page.locator('[data-testid="cn-object-sidebar-tab-icons"]'),
		'the Icons tab panel must render',
	).toBeVisible({ timeout: 15_000 })
	await expect(
		page.locator('.ob-icon-section'),
		'ApplicationIconTab must mount IconUploadSection',
	).toBeVisible({ timeout: 15_000 })
}

/**
 * The light-icon or dark-icon row of the section.
 *
 * The two rows share every class name; only their label text separates them, so
 * that is what addresses them. Index-based addressing would silently follow a
 * reorder and start asserting about the wrong slot.
 *
 * @param page Playwright page.
 * @param variant Which slot.
 * @return The row locator.
 */
function iconRow(page: Page, variant: 'Light' | 'Dark') {
	return page.locator('.ob-icon-section__row').filter({
		has: page.locator('.ob-icon-section__label', {
			hasText: `${variant} icon`,
		}),
	})
}

test.describe('app-icon-management — removing an icon (REQ-OBICON-004)', () => {
	// The Application detail page is a three-pane desktop surface. At the
	// project default of 1280x720 the right-hand sidebar collapses: the Icons
	// TAB PANEL still mounts (so `cn-object-sidebar-tab-icons` is visible) but
	// `IconUploadSection` inside it is laid out at zero width and reports
	// `hidden`. That is exactly how this failed on CI while passing locally —
	// "Expected: visible / Received: hidden" on `.ob-icon-section`, one
	// assertion after the panel check passed. An earlier draft of this file
	// carried the override and got past this point; the rewrite dropped it.
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e app-icon-management::user-removes-the-dark-icon
	test('Remove in the dark slot deletes the OR attachment and clears iconDark.ref', async ({
		page,
		request,
	}) => {
		// Two navigations plus two write round-trips; the 30s project default is
		// sized for single-navigation tests.
		test.setTimeout(120_000)

		const { objectId } = await resolveApp(request)

		// PRECONDITION, established over the API: a dark icon IS attached. The
		// Remove button renders behind `v-if="darkRef"`, so without this the test
		// would "pass" by never finding a button to click — the exact shape of a
		// test that measures nothing.
		const seedUpload = await request.post(
			`${BASE}/index.php/${OR_OBJECT_PATH}/${objectId}/files`,
			{
				data: { name: 'app-icon-dark.svg', content: MINIMAL_SVG },
			},
		)
		expect(
			seedUpload.ok(),
			'seeding the dark attachment must succeed',
		).toBeTruthy()
		// A LIGHT icon is seeded too, deliberately: the scenario's last clause is
		// that the dark slot falls back to the light icon, and that fallback is
		// only meaningful if a light icon exists. Without this the test would be
		// order-dependent on whichever suite last uploaded one.
		const seedLight = await request.post(
			`${BASE}/index.php/${OR_OBJECT_PATH}/${objectId}/files`,
			{
				data: { name: 'app-icon.svg', content: MINIMAL_SVG },
			},
		)
		expect(
			seedLight.ok(),
			'seeding the light attachment must succeed',
		).toBeTruthy()
		const seedPatch = await request.patch(
			`${BASE}/index.php/${OR_OBJECT_PATH}/${objectId}`,
			{
				data: {
					icon: { ref: 'app-icon.svg' },
					iconDark: { ref: 'app-icon-dark.svg' },
				},
			},
		)
		expect(
			seedPatch.ok(),
			'seeding icon + iconDark refs must succeed',
		).toBeTruthy()

		await openIconsTab(page, objectId)

		const dark = iconRow(page, 'Dark')
		const removeBtn = dark.locator('.ob-icon-section__remove-btn')
		await expect(
			removeBtn,
			'the Remove button must render while a dark icon is attached — its absence would make this test vacuous',
		).toBeVisible({ timeout: 20_000 })

		// Arm both writes BEFORE the click that fires them. Waiting afterwards
		// races the XHR, and `waitForLoadState('networkidle')` is banned by
		// gate-58 precisely because it does not wait for an XHR at all.
		// The DELETE is addressed by the attachment's NUMERIC id, not its
		// filename: OpenRegister's `files#delete` route constrains `fileId` to
		// `\d+`, so a filename does not match the route and Nextcloud answers its
		// HTML 404 page. Asserting the numeric shape here is what stops that
		// regression from coming back looking like a passing test.
		const deleteDone = page.waitForResponse(
			(r) =>
				new RegExp(`${OR_OBJECT_PATH}/${objectId}/files/\\d+$`).test(
					new URL(r.url()).pathname,
				) && r.request().method() === 'DELETE',
			{ timeout: 30_000 },
		)
		const patchDone = page.waitForResponse(
			(r) =>
				r.url().includes(`${OR_OBJECT_PATH}/${objectId}`)
				&& r.request().method() === 'PATCH',
			{ timeout: 30_000 },
		)

		await removeBtn.click()

		// "the frontend calls OR's delete-attachment endpoint for the iconDark file"
		const deleteRes = await deleteDone
		expect(
			deleteRes.status(),
			'the delete-attachment call must answer 2xx',
		).toBeGreaterThanOrEqual(200)
		expect(
			deleteRes.status(),
			'the delete-attachment call must answer 2xx',
		).toBeLessThan(300)

		// "and clears the top-level iconDark.ref from the Application"
		const patchRes = await patchDone
		expect(
			patchRes.request().postDataJSON(),
			'the PATCH must null the top-level iconDark ref, not replace the object',
		).toEqual({ iconDark: null })
		expect(
			patchRes.status(),
			'the ref-clearing PATCH must answer 2xx',
		).toBeLessThan(300)

		// The button is bound to `v-if="darkRef"`, so its disappearance is the
		// component's own statement that the ref is gone.
		await expect(
			removeBtn,
			'the Remove button must disappear once the ref is cleared',
		).toHaveCount(0, { timeout: 15_000 })

		// "the preview area falls back to showing the light icon in the
		// dark-background slot" — i.e. the slot must NOT go blank.
		//
		// The first draft of this assertion demanded the dark preview render no
		// <img> at all, and it failed — correctly. The slot keeps rendering an
		// image and lets `/apps/openbuild/icons/{slug}-dark.svg` serve the
		// fallback; that server-side fallback chain is REQ-OBICON-002, which the
		// spec excludes to PHPUnit. So what belongs here is the DOM-observable
		// half: the slot still shows something rather than collapsing.
		await expect(
			dark.locator(
				'.ob-icon-section__preview--dark img.ob-icon-section__preview-img',
			),
			'the dark slot must keep showing a preview (the light-icon fallback), not go blank',
		).toHaveCount(1, { timeout: 15_000 })
		await expect(
			dark.locator('.ob-icon-section__preview-empty'),
			'the dark slot must not fall back to the em-dash placeholder',
		).toHaveCount(0)

		// And the record really lost it — read it back rather than trusting
		// optimistic UI state.
		await expect
			.poll(
				async () => {
					const { app: reloaded } = await resolveApp(request)
					return reloaded.iconDark ?? null
				},
				{ timeout: 30_000 },
			)
			.toBeFalsy()
	})
})
