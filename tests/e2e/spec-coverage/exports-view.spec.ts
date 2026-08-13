// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the OpenBuild Exports index page (manifest page
 * `Exports`, route `/exports`, type `index`). The exporter-ui spec scopes
 * its scenarios to the export *dialog* component (Vitest) and the export
 * *backend* (Newman); the Exports list *view* itself had no real-UI test
 * before this spec — only a docs-screenshot pass.
 *
 * Observed live DOM (dev container, admin session, sidebar collapsed):
 *   - <main>: a "View mode" group of Cards/Table *buttons* (not radios), a
 *     "Search and columns" button, an "Add Export Job" button, an "Actions"
 *     menu, and a "No items found" note (empty state).
 *   - There is NO page heading. CnIndexPage renders the title into the
 *     app-sidebar header, which is collapsed on load, so the <h2> is absent
 *     from the DOM rather than merely hidden. Same on /applications.
 *
 * Seed/env note: the served build's
 * `openregister/api/objects/openbuild/export-job` collection endpoint 500s
 * on the unseeded dev register, so the list renders its empty state rather
 * than rows. Assertions are therefore data-independent.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
// The app router runs in path mode (not hash mode — that assumption was
// stale; live-verified http://localhost:8099/apps/openbuild/exports renders
// the Exports view directly, no #/ fragment).
const ROUTE = `${BASE}/apps/openbuild/exports`

test.describe('OpenBuild Exports view', () => {
	test('renders the Exports index surface and the Add Export Job action', async ({
		page,
	}) => {
		await page.goto(ROUTE)
		await expect(page).toHaveTitle(/openbuild/i)
		await expect(page).toHaveURL(/\/exports\b/)

		// NOTE: this page renders NO heading in its default state. CnIndexPage
		// puts an index page's title in the app-sidebar header, and that sidebar
		// is collapsed on load — the <h2> is not merely invisible, it is absent
		// from the DOM entirely (confirmed from the failing run's accessibility
		// snapshot: <main> holds only the view-mode group, the search/columns
		// button, "Add Export Job", "Actions" and the empty-state note). The same
		// is true of /applications, so it is the shared CnIndexPage layout rather
		// than an Exports defect, and CnIndexPage is nc-vue's, not OpenBuild's.
		// Identify the page by the surfaces it actually paints instead.
		await expect(
			page.getByRole('button', { name: /add export job/i }),
			'Exports view must expose its primary action',
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('group', { name: /view mode/i }),
			'Exports view must render the index view-mode control',
		).toBeVisible({ timeout: 15_000 })
	})

	test('exposes a Cards / Table view toggle', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(
			page.getByRole('button', { name: /add export job/i }),
		).toBeVisible({ timeout: 15_000 })

		// The list view header offers a Cards/Table presentation toggle. These
		// are rendered as buttons by CnIndexPage, not as radios — an earlier
		// revision asserted `role=radio`, which matches nothing on this surface.
		await expect(page.getByRole('button', { name: /^cards$/i })).toBeVisible()
		await expect(page.getByRole('button', { name: /^table$/i })).toBeVisible()
	})

	test('renders a deterministic list surface (empty state or row list)', async ({
		page,
	}) => {
		await page.goto(ROUTE)
		await expect(
			page.getByRole('button', { name: /add export job/i }),
		).toBeVisible({ timeout: 15_000 })

		// Data-independent: the index must render a coherent list surface and
		// never white-screen — either the empty state (unseeded register) or a
		// populated row count summary (seeded register). Assert that at least
		// one of those deterministic surfaces is present.
		const emptyState = page
			.getByText(/no items found|no exports|nothing/i)
			.first()
		const rowSummary = page.getByText(/showing \d+ of \d+/i).first()
		await expect(emptyState.or(rowSummary)).toBeVisible({ timeout: 15_000 })
	})
})
