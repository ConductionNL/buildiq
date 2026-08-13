// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * Playwright e2e — ApplicationCard icon display + productionVersion fields.
 *
 * Covers:
 *   spec A task 7.4  — icon <img> appears on each ApplicationCard
 *   ApplicationCard regression fix — status badge and version chip read from
 *     Application.productionVersion (spec C), not from the (now-removed)
 *     top-level Application.status / Application.version fields.
 *
 * Preconditions:
 *   - Nextcloud reachable with openbuild enabled.
 *   - SeedHelloWorld repair step has produced the hello-world virtual app.
 *   - Playwright auth via httpCredentials (admin:admin) in playwright.config.ts.
 *
 * Limitations:
 *   - The productionVersion badge assertions are "best-effort": if OR does not
 *     return the productionVersion relation inline, the card shows "Draft" + "—"
 *     by design (spec C Decision 4). The test asserts the icon is present and
 *     the badge is NOT the pre-spec-A regression value "Live".
 */
// UN-QUARANTINED 2026-07-30. The old reason (#41, "builder host blank / no
// detail pages") never applied to this file — it only reads the applications
// index — and no longer holds anyway.
//
// PRODUCT DEFECT FOUND AND FIXED WHILE DOING THIS: the status badge and version
// chip on this card were dead. `GET /api/applications` returns
// `productionVersion` as a bare UUID STRING, but `ApplicationCard.vue`'s
// `productionVersion` computed bailed unless `typeof pv === 'object'`. So
// `statusKey` always fell back to `'draft'` and `productionSemver` to `'—'`, for
// every app, whatever its real state — hello-world read "Draft / Version —"
// while its production ApplicationVersion was `{status: 'published',
// semver: '1.0.0'}`. That made REQ-OBR-007b's "newly published Application shows
// published badge" unsatisfiable from the list view.
//
// Fixed by `ApplicationsController::attachProductionVersionDetail()`, which
// resolves the UUID once for the whole list and projects
// `{uuid, slug, name, semver, status}` as `productionVersionDetail`;
// the card prefers that field. `productionVersion` is left a UUID string
// because every detail-side consumer depends on that shape.
//
// Tests 3 and 4 below are now DATA-DRIVEN against that resolved field rather
// than merely checking the badge vocabulary — they compare what the card renders
// against what the API says the production version actually is, so the defect
// cannot regress silently.
/**
 * The resolved production ApplicationVersion the API attaches to the seeded app.
 *
 * Read through the page's own session so the assertion compares the card against
 * exactly the payload that rendered it.
 *
 * @param {import('@playwright/test').Page} page The Playwright page.
 * @return {Promise<object|null>} `{uuid, slug, name, semver, status}` or null.
 */
async function productionVersionDetail(page) {
	const res = await page.request.get(
		'/index.php/apps/openbuild/api/applications',
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(res.ok(), 'the applications API must answer').toBeTruthy()
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body.results ?? [])
	const app = rows.find((a) => (a.slug ?? a['@self']?.slug) === 'hello-world')
	return (app && app.productionVersionDetail) || null
}

test.describe('ApplicationCard — icon + productionVersion fields (spec A / spec C)', () => {
	test('index page renders ApplicationCards with icon <img> elements', async ({
		page,
	}) => {
		await page.goto('/apps/openbuild/applications')

		// Wait for the SPA to hydrate and the Applications list to appear.
		// The list renders one card per Application. The seeded hello-world
		// entry must be present.
		await expect(
			page.locator('.ob-app-card, [data-testid*="app-card"]').first(),
			'at least one ApplicationCard must be visible on the index',
		).toBeVisible({ timeout: 15_000 })

		// Each card must contain an <img> from the icon-serving endpoint.
		// icon src pattern: /index.php/apps/openbuild/icons/{slug}.svg
		const firstCard = page.locator('.ob-app-card').first()
		const icon = firstCard.locator('img.ob-app-card__icon')
		await expect(
			icon,
			'icon <img> must be visible on ApplicationCard',
		).toBeVisible({ timeout: 10_000 })
		const src = await icon.getAttribute('src')
		expect(src, 'icon src must point to the icon-serving endpoint').toMatch(
			/\/icons\/.+\.svg$/,
		)
	})

	test('hello-world ApplicationCard shows a status badge (not raw "Live" chip)', async ({
		page,
	}) => {
		await page.goto('/apps/openbuild/applications')

		// Wait for at least the seeded hello-world card.
		await expect(page.locator('.ob-app-card').first()).toBeVisible({
			timeout: 15_000,
		})

		// Spec A task 4.2 removed the "Live" chip. The card must never show
		// text "Live" in a chip regardless of Application state.
		const liveChips = page.locator('.ob-app-card__chip--live')
		await expect(
			liveChips,
			'no element with class ob-app-card__chip--live should exist (removed in spec A)',
		).toHaveCount(0)

		const liveText = page
			.locator('.ob-app-card')
			.getByText('Live', { exact: true })
		await expect(
			liveText,
			'no "Live" text should appear in any ApplicationCard',
		).toHaveCount(0)
	})

	test('hello-world ApplicationCard status badge is one of the known values', async ({
		page,
	}) => {
		await page.goto('/apps/openbuild/applications')

		// Target hello-world for real. The previous selector was
		// `[data-slug="hello-world"], .ob-app-card` + `.first()` — ApplicationCard.vue
		// renders no `data-slug` attribute, so that alternation always collapsed to
		// "whatever card happens to be first" while the test name promised
		// hello-world. The slug IS rendered, in the muted chip as `/{slug}`.
		const helloCard = page
			.locator('.ob-app-card')
			.filter({ hasText: '/hello-world' })
			.first()
		await expect(
			helloCard,
			'the seeded hello-world card must be on the index',
		).toBeVisible({ timeout: 15_000 })

		// The badge must show the REAL lifecycle status of the app's production
		// ApplicationVersion — not merely "one of the three words", which the
		// permanently-stuck "Draft" of the pre-fix card also satisfied.
		const badge = helloCard.locator('.ob-app-card__badge')
		await expect(badge).toBeVisible({ timeout: 5_000 })

		const detail = await productionVersionDetail(page)
		expect(
			detail,
			'the seeded app must expose a resolved productionVersionDetail — without it '
				+ 'the card cannot know its status and this assertion is meaningless',
		).toBeTruthy()

		// RETRYING assertion, deliberately. The card first paints its placeholder
		// ("Draft") and swaps to the real status when the shared production-version
		// lookup resolves (src/store/productionVersions.js). A one-shot
		// `textContent()` read races that and reported "draft" while the very
		// snapshot taken at failure showed "Published" — the product was right and
		// the assertion was early. `toHaveText` polls, so it asserts the settled
		// state without weakening what is asserted.
		await expect(
			badge,
			`the badge must settle on the production version's real status ("${detail.status}")`,
		).toHaveText(new RegExp(`^${detail.status}$`, 'i'), { timeout: 15_000 })
	})

	test('hello-world ApplicationCard version chip shows semver or — placeholder', async ({
		page,
	}) => {
		await page.goto('/apps/openbuild/applications')

		const helloCard = page
			.locator('.ob-app-card')
			.filter({ hasText: '/hello-world' })
			.first()
		await expect(
			helloCard,
			'the seeded hello-world card must be on the index',
		).toBeVisible({ timeout: 15_000 })

		// The version chip must render the documented shape exactly: the label
		// followed by a semver, or by the em-dash placeholder spec C Decision 4
		// defines for "no resolved production version".
		//
		// The old body only checked `length > 0` and `not /undefined/`, which the
		// literal string "Version null" would have satisfied. A format assertion
		// pins every failure mode a template hole can produce.
		const versionChip = helloCard.locator('.ob-app-card__chip').first()

		// The real semver, not the em-dash placeholder the pre-fix card was
		// permanently stuck on. Retrying, for the same reason as the badge above.
		const detail = await productionVersionDetail(page)
		expect(
			detail,
			'the seeded app must expose a resolved productionVersionDetail',
		).toBeTruthy()
		await expect(
			versionChip,
			`version chip must settle on the production version's real semver ("${detail.semver}")`,
		).toHaveText(`Version ${detail.semver}`, { timeout: 15_000 })

		// And the slug chip beside it must be the real slug, not a template hole.
		await expect(
			helloCard.locator('.ob-app-card__chip--muted'),
			'the slug chip must render the application slug',
		).toHaveText('/hello-world')
	})
})
