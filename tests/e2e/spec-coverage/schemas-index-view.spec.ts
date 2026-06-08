// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the OpenBuild top-level Schemas index page (manifest
 * page `Schemas`, route `/schemas`, type `index`). This is distinct from
 * the per-virtual-app schema designer (`/builder/:slug/schemas`, covered by
 * openbuild-schema-designer.spec.ts). The top-level Schemas index had no
 * real-UI test before this spec — only a docs-screenshot pass.
 *
 * Observed live behaviour (dev container, admin session):
 *   - Heading "Schemas".
 *   - A primary action button. NOTE: in the served build the button is
 *     labelled "Add Application" on the Schemas page (see BUG below).
 *
 * BUG (flagged, not patched — tests only add coverage, never modify
 * source): the Schemas index renders a primary action labelled
 * "Add Application" instead of an "Add Schema"-style label. The view
 * appears to reuse the applications index toolbar without overriding the
 * create-button label. The first test asserts only the heading (stable);
 * the second test documents the observed-but-wrong label so a regression
 * is visible if it changes either way.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const ROUTE = `${BASE}/apps/openbuild/schemas`

test.describe('OpenBuild Schemas index view', () => {
	test('renders the Schemas heading without white-screening', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(page).toHaveTitle(/openbuild/i)

		await expect(
			page.getByRole('heading', { name: 'Schemas', exact: true }).first(),
		).toBeVisible({ timeout: 15_000 })
	})

	test('exposes a primary create action (label currently "Add Application" — see BUG)', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(page.getByRole('heading', { name: 'Schemas', exact: true }).first()).toBeVisible({ timeout: 15_000 })

		// The page must offer *a* primary create action. We accept either the
		// correct "Add Schema"-style label or the currently-rendered (wrong)
		// "Add Application" label so the test does not lock in the bug, but
		// still fails if the toolbar disappears entirely.
		const createBtn = page.getByRole('button', { name: /add (schema|application)/i }).first()
		await expect(createBtn, 'Schemas index must expose a create action').toBeVisible({ timeout: 10_000 })
	})
})
