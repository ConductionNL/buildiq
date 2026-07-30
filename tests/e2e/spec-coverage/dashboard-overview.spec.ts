// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the OpenBuild Dashboard landing page (manifest page
 * `Dashboard`, route `/`). This is the default landing surface and was
 * previously only smoke-touched by the rbac / chat-companion specs.
 *
 * The Dashboard is a `type: "custom"` manifest page rendering the
 * self-contained `DashboardIndex` view — one `CnDashboardPage` with three
 * KPI cards plus a "Recent apps" table:
 *   - Apps
 *   - Hybrid apps
 *   - Published versions
 *   - Recent apps (table widget)
 *
 * The numeric values are data-dependent, so these tests assert the widget
 * *titles* and the in-app navigation, which are static, plus a console
 * hygiene check scoped to the openbuild surface.
 *
 * ROUTING FORM (read before touching a locator here)
 * --------------------------------------------------
 * The openbuild admin surface runs a HISTORY-mode router, so vue-router
 * emits plain path hrefs (`/apps/openbuild/applications`). An earlier
 * revision of this spec matched `a[href$="/apps/openbuild/#/applications"]`
 * on the assumption of a hash router; that selector can never match, which
 * is why all four tests here failed. Live-verified against the running
 * instance before this was changed.
 *
 * MENU CONTENT
 * ------------
 * The in-app menu is driven by the manifest `menu` block, which declares
 * exactly four routed entries (Dashboard, Apps, Store, Features & roadmap)
 * plus one external Documentation link. `/schemas` and `/exports` are
 * routable pages but are deliberately NOT menu entries — asserting them
 * here previously encoded a menu that the manifest does not describe.
 */

import { test, expect } from '@playwright/test'
import { dismissWalkthrough } from '../support/overlays'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

// In-app nav link scoped by its openbuild href (avoids the NC top-bar).
// History-mode router => plain path hrefs, no `#` segment.
const navLink = (page: import('@playwright/test').Page, path: string) => {
	const suffix = path === '/' ? '/apps/openbuild/' : `/apps/openbuild${path}`
	return page.locator(`a[href$="${suffix}"]`).first()
}

test.describe('OpenBuild Dashboard', () => {
	test('renders the KPI and table widget titles', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/`)
		await expect(page).toHaveTitle(/openbuild/i)

		// Widget titles come from DashboardIndex's `widgets` definition and are
		// independent of the data. They render as headings, which distinguishes
		// the "Apps" KPI card from the same word in the nav entry / app rows.
		for (const title of ['Apps', 'Hybrid apps', 'Published versions', 'Recent apps']) {
			await expect(
				page.getByRole('heading', { name: title, exact: true }),
				`dashboard must render the "${title}" widget title`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('exposes the in-app navigation entries (Dashboard, Apps, Store, Features & roadmap)', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/`)

		for (const [label, path] of [
			['Dashboard', '/'],
			['Apps', '/applications'],
			['Store', '/templates'],
			['Features & roadmap', '/features-roadmap'],
		] as const) {
			const link = navLink(page, path)
			await expect(
				link,
				`in-app nav must contain "${label}" -> ${path}`,
			).toBeVisible({ timeout: 15_000 })
			// The href alone could match an unrelated link; pin the label too so a
			// renamed menu entry fails here instead of passing silently.
			await expect(link, `nav entry for ${path} must be labelled "${label}"`).toHaveText(label)
		}
	})

	test('clicking the Apps nav entry routes to the applications index', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/`)
		await expect(navLink(page, '/applications')).toBeVisible({ timeout: 15_000 })
		// The first-visit tour's full-viewport dim swallows this click.
		await dismissWalkthrough(page)

		await navLink(page, '/applications').click()

		await expect(page).toHaveURL(/\/applications\b/, { timeout: 15_000 })
		// Identify the applications index by its own visible primary action.
		// Do NOT assert the "Apps" <h2>: CnIndexPage renders an index page's
		// title into the app-sidebar header, which is collapsed by default, so
		// that heading is present in the DOM at 0x0 and never visible (verified
		// live on both /applications and /exports).
		await expect(
			page.getByRole('button', { name: 'Add app', exact: true }),
		).toBeVisible({ timeout: 15_000 })
	})

	test('dashboard load produces no openbuild-originated console errors', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (m) => {
			if (m.type() !== 'error') return
			const text = m.text()
			// NC-core/env noise on the dev container, not openbuild.
			if (/user_status|Failed to load user status|Failed to load resource/i.test(text)) return
			errors.push(text)
		})

		await page.goto(`${BASE}/apps/openbuild/`)
		await expect(page.getByRole('heading', { name: 'Apps', exact: true })).toBeVisible({ timeout: 15_000 })
		await page.waitForTimeout(2000)

		expect(errors, `unexpected console errors:\n${errors.join('\n')}`).toEqual([])
	})
})
