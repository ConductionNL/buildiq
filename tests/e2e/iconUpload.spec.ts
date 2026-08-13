// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — icon upload on the Application detail page (spec A task 7.5).
 *
 * UN-QUARANTINED AND REWRITTEN 2026-07-30. The file sat behind
 * `test.describe.skip` blaming Conduction/openbuild#41 ("no application detail
 * page renders"). The detail page renders fine; what was actually broken was
 * this file's own idea of the surface:
 *
 *   - it looked for `.ob-icon-preview img` / `[data-testid="icon-preview"] img`,
 *     neither of which exists in src/. The real markup is
 *     `src/dialogs/IconUploadSection.vue` — `.ob-icon-section__preview-img`,
 *     `.ob-icon-section__file-input`, `.ob-icon-section__remove-btn`,
 *     `.ob-icon-section__error` — mounted by `ApplicationIconTab.vue`;
 *   - it never opened the sidebar, so the Icons tab could not have been in the
 *     DOM under any circumstances;
 *   - consequently its own `if (!iconUiExists) test.skip(...)` escape hatch
 *     fired every run, and two of its three tests were permanent no-op
 *     `test.skip('…pending deploy')` bodies;
 *   - it clicked `[data-slug="hello-world"]` — an attribute ApplicationCard.vue
 *     does not render — falling back to `.ob-app-card.first()`, i.e. an
 *     arbitrary app.
 *
 * Covers, against the real surface:
 *   - the Icons tab mounts the section with both variants and an SVG-only picker
 *   - a non-SVG pick is rejected inline and sends no upload
 *   - an SVG upload persists on the Application and the preview points at the
 *     icon-serving endpoint
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import { E2E_BASE_URL as BASE } from './support/baseUrl'
import { dismissFirstVisitOverlays } from './support/overlays'

const HELLO_WORLD_SLUG = process.env.NC_TEST_SLUG ?? 'hello-world'

/**
 * Minimal valid SVG used as the upload fixture. Kept inline so the spec needs
 * no on-disk asset.
 */
const MINIMAL_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
  <circle cx="12" cy="12" r="10" fill="#4376fc"/>
</svg>`

/**
 * Resolve the seeded Application and its OR object id.
 *
 * The detail route is `/applications/:objectId` — keyed on the object id, not
 * the slug. Several older specs in this suite navigate with the slug and land
 * on a not-found page; this resolves it properly.
 *
 * @param request Playwright request context carrying the admin session.
 * @return {Promise<object>} `{ objectId, app }` for the seeded application.
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
 * Open the Application detail page, expand the sidebar, and activate the Icons
 * tab so `IconUploadSection` is mounted.
 *
 * CnDetailPage seeds `sidebarOpen: false`, so the manifest-declared tabs are
 * absent from the DOM until NcAppSidebar's own `.app-sidebar__toggle` is
 * clicked — the step the previous version of this file was missing entirely.
 *
 * @param page Playwright page.
 * @param objectId The Application's OR object id.
 * @return {Promise<void>}
 */
async function openIconsTab(page: Page, objectId: string): Promise<void> {
	await page.goto(`/apps/openbuild/applications/${objectId}`, {
		waitUntil: 'domcontentloaded',
	})
	await dismissFirstVisitOverlays(page)
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
}

test.describe('Icon upload on the Application detail page (spec A task 7.5)', () => {
	test('the Icons tab mounts the upload section with light + dark variants and an SVG-only picker', async ({
		page,
		request,
	}) => {
		const { objectId } = await resolveApp(request)
		await openIconsTab(page, objectId)

		await expect(
			page.locator('.ob-icon-section'),
			'ApplicationIconTab must mount IconUploadSection',
		).toBeVisible({ timeout: 15_000 })

		// Both variants are part of the contract — the section writes `icon`
		// and `iconDark` on the Application.
		await expect(page.locator('.ob-icon-section__preview--light')).toBeVisible()
		await expect(page.locator('.ob-icon-section__preview--dark')).toBeVisible()

		// The picker must be constrained to SVG. Unconditional: two inputs,
		// both accepting .svg.
		const inputs = page.locator('.ob-icon-section__file-input')
		await expect(inputs, 'one file input per icon variant').toHaveCount(2)
		await expect(inputs.nth(0)).toHaveAttribute('accept', '.svg')
		await expect(inputs.nth(1)).toHaveAttribute('accept', '.svg')
	})

	test('a non-SVG pick is rejected inline and never reaches the server', async ({
		page,
		request,
	}) => {
		const { objectId } = await resolveApp(request)
		await openIconsTab(page, objectId)
		await expect(page.locator('.ob-icon-section')).toBeVisible({
			timeout: 15_000,
		})

		// The rejection must be client-side: nothing may be POSTed.
		const uploads: string[] = []
		page.on('request', (r) => {
			if (r.method() === 'POST' && /\/files|\/objects\//.test(r.url())) {
				uploads.push(`${r.method()} ${r.url()}`)
			}
		})

		await page
			.locator('.ob-icon-section__file-input')
			.first()
			.setInputFiles({
				name: 'not-an-icon.png',
				mimeType: 'image/png',
				// A PNG magic header is enough — validation is on the extension.
				buffer: Buffer.from('89504e470d0a1a0a', 'hex'),
			})

		const error = page.locator('.ob-icon-section__error').first()
		await expect(
			error,
			'a non-SVG pick must surface the inline rejection',
		).toBeVisible({ timeout: 10_000 })
		await expect(error).toContainText(/only \.svg files are accepted/i)

		await page.waitForTimeout(1_500)
		expect(uploads, 'a rejected file must not be uploaded').toEqual([])
	})

	test('uploading an SVG persists it on the Application and shows it in the preview', async ({
		page,
		request,
	}) => {
		// Two navigations plus an upload round-trip. The 30s project default is
		// sized for single-navigation tests; every assertion below keeps its own
		// tight timeout.
		test.setTimeout(90_000)

		const { objectId } = await resolveApp(request)

		await openIconsTab(page, objectId)
		await expect(page.locator('.ob-icon-section')).toBeVisible({
			timeout: 15_000,
		})

		await page
			.locator('.ob-icon-section__file-input')
			.first()
			.setInputFiles({
				name: 'app-icon.svg',
				mimeType: 'image/svg+xml',
				buffer: Buffer.from(MINIMAL_SVG, 'utf8'),
			})

		// The preview swaps from the em-dash placeholder to an <img> pointed at
		// the icon-serving endpoint.
		const preview = page.locator(
			'.ob-icon-section__preview--light .ob-icon-section__preview-img',
		)
		await expect(
			preview,
			'the light preview must render the uploaded icon',
		).toBeVisible({ timeout: 30_000 })
		const src = await preview.getAttribute('src')
		expect(src, 'the preview must point at the icon-serving endpoint').toMatch(
			/\/apps\/openbuild\/icons\//,
		)

		// No error was raised on the happy path.
		await expect(page.locator('.ob-icon-section__error')).toHaveCount(0)
		await expect(page.locator('.ob-icon-section__global-error')).toHaveCount(0)

		// The write actually persisted — read the Application back independently
		// rather than trusting optimistic UI state.
		await expect
			.poll(
				async () => {
					const { app: reloaded } = await resolveApp(request)
					return reloaded.icon ?? null
				},
				{ timeout: 30_000 },
			)
			.toBeTruthy()

		// A Remove control appears once an icon is stored (`v-if="lightRef"`).
		await expect(
			page.locator('.ob-icon-section__remove-btn').first(),
			'a stored icon must offer a Remove action',
		).toBeVisible({ timeout: 10_000 })
	})
})
