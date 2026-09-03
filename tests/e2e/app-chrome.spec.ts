/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error), an entry whose `route` names
 * a page the app does not host renders a row that goes nowhere, and
 * `nav.includePersonalSettings: false` silently removed the entry that reaches
 * the user's notification preferences.
 *
 * The two reports are declarative `type: "dashboard"` pages over buildiq's own
 * register, which adds a fourth failure mode no manifest gate can see: a widget
 * whose `source` names a schema that does not exist renders its card, its title
 * and no value, silently. In THIS app that is a live risk, because the slugs
 * are not the seed keys AND are not consistent with each other: Application is
 * `built-app` and exportJob is `export-job`, but ApplicationVersion stays
 * `applicationVersion` in camelCase. So the assertions below look for VALUES,
 * not just for cards.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/apps/buildiq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers: ADR-114 fixes the sequence and
		// openregister runs its footer at 1/2 while pipelinq runs 160/200/230.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports lists both reports', async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]')
		await nav.locator('[data-testid="cn-nav-entry-ReportsMenu"]').click()
		await expect(page).toHaveURL(/\/apps\/buildiq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		for (const label of ['Applications', 'Versions and exports']) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('the applications report reads the kebab-case slug and renders numbers', async ({
		page,
	}) => {
		// built-app, NOT Application. The point of this test: naming the seed
		// key yields a card that renders its chrome and no value, silently.
		await page.goto(`${APP_BASE}/reports/applications`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText('Published', { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('main, .app-content').first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		)
	})

	test('the delivery report reads applicationVersion in camelCase, not kebab', async ({
		page,
	}) => {
		// The asymmetry that makes this app's slugs a trap: export-job is
		// kebab-case but applicationVersion is not. "Tidying" it to
		// application-version would blank two cards in place with no error.
		await page.goto(`${APP_BASE}/reports/delivery`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page.getByText('Versions published', { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('main, .app-content').first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		)
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin).toHaveAttribute('href', /\/settings\/admin\/buildiq$/)
	})
})
