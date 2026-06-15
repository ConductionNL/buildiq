// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the OpenBuild Dashboard landing page (manifest page
 * `Dashboard`, route `/`). This is the default landing surface and was
 * previously only smoke-touched by the rbac / chat-companion specs.
 *
 * The dashboard renders four `stats-block` widgets driven by the v2
 * manifest:
 *   - Virtual apps
 *   - Published
 *   - Templates
 *   - Published versions
 *
 * The numeric values are data-dependent (and 0 on an unseeded register —
 * see the seed/env note below), so these tests assert the widget *titles*
 * and the in-app navigation, which are static manifest content, plus a
 * console hygiene check scoped to the openbuild surface.
 *
 * Locator note: the in-app navigation links are disambiguated from the
 * Nextcloud global app menu by their `/apps/openbuild/...` href, because
 * both render as <nav>/<link> roles in the page.
 *
 * Seed/env note: on the dev container the served build's
 * `openregister/api/objects/openbuild/*` collection endpoints 500 because
 * the openbuild register is not seeded, so every count card shows 0. That
 * is an environment fixture gap, not a UI defect, so the assertions here
 * are deliberately data-independent.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

// In-app nav link scoped by its openbuild href (avoids the NC top-bar).
// The app router runs in hash mode, so vue-router emits hrefs of the form
// `/apps/openbuild/#/applications`. Match the hash-suffixed route ('/' maps
// to the bare `/#/`).
const navLink = (page: import('@playwright/test').Page, path: string) => {
	const suffix = path === '/' ? '/apps/openbuild/#/' : `/apps/openbuild/#${path}`
	return page.locator(`a[href$="${suffix}"]`).first()
}

test.describe('OpenBuild Dashboard', () => {
	test('renders the four stats-block widget titles', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/`)
		await expect(page).toHaveTitle(/openbuild/i)

		// Each widget title is static manifest content, independent of data.
		await expect(page.getByText('Virtual apps', { exact: true }).first()).toBeVisible({ timeout: 15_000 })
		await expect(page.getByText('Published', { exact: true }).first()).toBeVisible()
		await expect(page.getByText('Templates', { exact: true }).first()).toBeVisible()
		await expect(page.getByText('Published versions', { exact: true }).first()).toBeVisible()
	})

	test('exposes the in-app navigation entries (Dashboard, Virtual apps, Schemas, Templates, Exports)', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/`)

		for (const [label, path] of [
			['Dashboard', '/'],
			['Virtual apps', '/applications'],
			['Schemas', '/schemas'],
			['Templates', '/templates'],
			['Exports', '/exports'],
		] as const) {
			await expect(
				navLink(page, path),
				`in-app nav must contain "${label}" -> ${path}`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('clicking the Virtual apps nav entry routes to the applications index', async ({ page }) => {
		await page.goto(`${BASE}/apps/openbuild/`)
		await expect(navLink(page, '/applications')).toBeVisible({ timeout: 15_000 })

		await navLink(page, '/applications').click()

		await expect(page).toHaveURL(/\/applications\b/, { timeout: 15_000 })
		await expect(
			page.getByRole('heading', { name: 'Virtual apps', exact: true }),
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
		await expect(page.getByText('Virtual apps', { exact: true }).first()).toBeVisible({ timeout: 15_000 })
		await page.waitForTimeout(2000)

		expect(errors, `unexpected console errors:\n${errors.join('\n')}`).toEqual([])
	})
})
