// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the OpenBuild Exports index page (manifest page
 * `Exports`, route `/exports`, type `index`). The exporter-ui spec scopes
 * its scenarios to the export *dialog* component (Vitest) and the export
 * *backend* (Newman); the Exports list *view* itself had no real-UI test
 * before this spec — only a docs-screenshot pass.
 *
 * Observed live DOM (dev container, admin session):
 *   - <main>: Cards/Table radio toggle, "Add Export Job" button, "Actions"
 *     menu, and a "No items found" note (empty state).
 *   - The "Exports" heading + description render in the index details pane
 *     (a <complementary> region), so the heading is asserted page-scoped.
 *
 * Seed/env note: the served build's
 * `openregister/api/objects/openbuild/export-job` collection endpoint 500s
 * on the unseeded dev register, so the list renders its empty state rather
 * than rows. Assertions are therefore data-independent.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
// The app router runs in hash mode — deep-link via the hash fragment so the
// SPA mounts the Exports view instead of booting at the default Dashboard.
const ROUTE = `${BASE}/apps/openbuild/#/exports`

test.describe('OpenBuild Exports view', () => {
	test('renders the Exports heading and the Add Export Job action', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(page).toHaveTitle(/openbuild/i)

		await expect(
			page.getByRole('heading', { name: 'Exports', exact: true }),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('button', { name: /add export job/i }),
			'Exports view must expose its primary action',
		).toBeVisible({ timeout: 15_000 })
	})

	test('exposes a Cards / Table view toggle', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(page.getByRole('button', { name: /add export job/i })).toBeVisible({ timeout: 15_000 })

		// The list view header offers a Cards/Table presentation toggle.
		await expect(page.getByRole('radio', { name: /cards/i })).toBeVisible()
		await expect(page.getByRole('radio', { name: /table/i })).toBeVisible()
	})

	test('renders a deterministic list surface (empty state or row list)', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(page.getByRole('button', { name: /add export job/i })).toBeVisible({ timeout: 15_000 })

		// Data-independent: the index must render a coherent list surface and
		// never white-screen — either the empty state (unseeded register) or a
		// populated row count summary (seeded register). Assert that at least
		// one of those deterministic surfaces is present.
		const emptyState = page.getByText(/no items found|no exports|nothing/i).first()
		const rowSummary = page.getByText(/showing \d+ of \d+/i).first()
		await expect(emptyState.or(rowSummary)).toBeVisible({ timeout: 15_000 })
	})
})
