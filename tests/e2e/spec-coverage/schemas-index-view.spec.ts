// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the Buildiq top-level Schemas index page (manifest
 * page `Schemas`, route `/schemas`, type `custom`). This is distinct from
 * the per-virtual-app schema designer (`/builder/:slug/schemas`, covered by
 * buildiq-schema-designer.spec.ts). The top-level Schemas index had no
 * real-UI test before this spec — only a docs-screenshot pass.
 *
 * Observed live behaviour (dev container, admin session):
 *   - Heading "Schemas".
 *   - A primary action button labelled "Add schema".
 *
 * FIXED (2026-06-09): previously the Schemas index fell through to the
 * generic CnIndexPage with `config.schema: "application"`, so its primary
 * action was mislabelled "Add Application" (CnIndexPage derives the Add
 * label from the schema title). The manifest `/schemas` page now renders
 * the SchemaDesignerView (list mode), whose SchemaListPanel exposes the
 * native "Add schema" flow (AddSchemaDialog). The first test asserts the
 * heading; the second now requires the corrected "Add schema" label and
 * fails if the application-create button regresses back.
 */

import { test, expect } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
// The app router runs in path mode (not hash mode — that assumption was
// stale; live-verified http://localhost:8099/apps/buildiq/schemas renders
// the Schemas index directly, no #/ fragment).
const ROUTE = `${BASE}/apps/buildiq/schemas`

test.describe('Buildiq Schemas index view', () => {
	test('renders the Schemas heading without white-screening', async ({ page }) => {
		await page.goto(ROUTE)
		await expect(page).toHaveTitle(/buildiq/i)

		await expect(
			page.getByRole('heading', { name: 'Schemas', exact: true }).first(),
		).toBeVisible({ timeout: 15_000 })
	})

	test('exposes a primary create action labelled "Add schema"', async ({
		page,
	}) => {
		await page.goto(ROUTE)
		await expect(
			page.getByRole('heading', { name: 'Schemas', exact: true }).first(),
		).toBeVisible({ timeout: 15_000 })

		// The primary action must read "Add schema" (the native schema-create
		// flow), NOT "Add Application". This locks in the fix: if the page
		// regresses to the generic application index, the assertion fails.
		const addSchemaBtn = page
			.getByRole('button', { name: /add schema/i })
			.first()
		await expect(
			addSchemaBtn,
			'Schemas index must expose an "Add schema" action',
		).toBeVisible({ timeout: 10_000 })

		// Guard against regression to the application-create button.
		await expect(
			page.getByRole('button', { name: /add application/i }),
		).toHaveCount(0)
	})
})
