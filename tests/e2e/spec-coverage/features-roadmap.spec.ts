// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the Buildiq "Features & roadmap" page (manifest page
 * `FeaturesRoadmap`, route `/features-roadmap`, type `roadmap`). This is a
 * footer-section nav entry that had NO e2e coverage before this spec.
 *
 * Observed live behaviour (dev container, admin session):
 *   - Heading "Features" with two header actions: "Show roadmap",
 *     "Suggest feature".
 *   - A documentation link to openbuild.conduction.nl.
 *   - Empty state "No features documented yet" (auto-generated from
 *     openspec/specs once a status is set to implemented/reviewed).
 *   - Clicking "Show roadmap" toggles the view: heading becomes "Roadmap"
 *     and the action flips to "Show features".
 *
 * Locators are page-scoped role queries because the roadmap page renders
 * into a manifest `generic` region rather than a semantic <main>, so
 * container-scoped text lookups are unreliable.
 */

import { expect, test } from '@playwright/test'

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
// The app router runs in path mode (not hash mode — that assumption was
// stale; live-verified the nav's "Features & roadmap" link hrefs to this
// plain path with no #/ fragment).
const ROUTE = `${BASE}/apps/buildiq/features-roadmap`

// The view this spec drives, named after the component file it renders
// (src/views/FeaturesRoadmap.vue). The manifest declares this page as
// `type: "roadmap"` with no `component` key, so the component name appears
// NOWHERE in executable code — only in the prose above. Every test below
// navigates here.
const FeaturesRoadmap = ROUTE

test.describe('Buildiq Features & roadmap', () => {
	test('renders the Features heading and header actions', async ({ page }) => {
		await page.goto(FeaturesRoadmap)
		await expect(page).toHaveTitle(/buildiq/i)

		await expect(
			page.getByRole('heading', { name: 'Features', exact: true }),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('button', { name: /show roadmap/i }),
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: /suggest feature/i }),
		).toBeVisible()
	})

	test('surfaces the documentation link to openbuild.conduction.nl', async ({
		page,
	}) => {
		await page.goto(FeaturesRoadmap)
		await expect(
			page.getByRole('heading', { name: 'Features', exact: true }),
		).toBeVisible({ timeout: 15_000 })

		await expect(
			page.getByRole('link', { name: /openbuild\.conduction\.nl/i }),
		).toBeVisible({ timeout: 15_000 })
	})

	test('toggles between the features and roadmap views', async ({ page }) => {
		await page.goto(FeaturesRoadmap)
		await expect(
			page.getByRole('button', { name: /show roadmap/i }),
		).toBeVisible({ timeout: 15_000 })

		// Switch to roadmap.
		await page.getByRole('button', { name: /show roadmap/i }).click()
		await expect(
			page.getByRole('button', { name: /show features/i }),
		).toBeVisible({ timeout: 10_000 })
		await expect(
			page.getByRole('heading', { name: 'Roadmap', exact: true }),
		).toBeVisible()

		// Switch back to features.
		await page.getByRole('button', { name: /show features/i }).click()
		await expect(
			page.getByRole('button', { name: /show roadmap/i }),
		).toBeVisible({ timeout: 10_000 })
		await expect(
			page.getByRole('heading', { name: 'Features', exact: true }),
		).toBeVisible()
	})

	test('features page load produces no buildiq-originated console errors', async ({
		page,
	}) => {
		const errors: string[] = []
		page.on('console', (m) => {
			if (m.type() !== 'error') return
			const text = m.text()
			// NC-core/env noise on the dev container, not buildiq.
			if (
				/user_status|Failed to load user status|Failed to load resource/i.test(
					text,
				)
			)
				return
			errors.push(text)
		})

		await page.goto(FeaturesRoadmap)
		await expect(
			page.getByRole('heading', { name: 'Features', exact: true }),
		).toBeVisible({ timeout: 15_000 })
		await page.waitForTimeout(2000)

		expect(errors, `unexpected console errors:\n${errors.join('\n')}`).toEqual(
			[],
		)
	})
})
