/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for the ZIP export flow of spec #9
 * (buildiq-export-to-real-app).
 *
 * Flow:
 *   1. Authenticate as admin against the local Nextcloud dev instance
 *      (admin:admin per nextcloud-docker-dev defaults).
 *   2. Open the hello-world Application editor.
 *   3. Click the Export action, choose ZIP target, accept the default
 *      version + license.
 *   4. Submit; expect a 202 + ExportJob UUID surfaced in the jobs list.
 *   5. Poll the row until `status=succeeded` (≤ 60 s).
 *   6. Click the download button; assert a ZIP file is received with the
 *      expected filename pattern `<appId>-<version>.zip` (or the job-UUID
 *      fallback the current ExportService emits).
 *
 * NOTE: The Playwright runner is not wired up in Buildiq yet — this file
 * is committed alongside the apply PR per task 7.2 / 8.x of the spec.
 * It runs once the cohort-wide Playwright bootstrap lands and asserts the
 * end-to-end UX contract the controller + background-job tests have
 * already locked at the unit level.
 */

import { test, expect, type Download } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASSWORD = process.env.NC_ADMIN_PASSWORD || 'admin'
const APPLICATION_SLUG = 'hello-world'
const POLL_TIMEOUT_MS = 60_000

// REGRESSION GUARD for a defect this file's quarantine was hiding: the Export
// button did nothing at all.
//
// `ApplicationDetailActions.vue` registered the dialog as
// `const ExportDialog = () => import('…')` — Vue 2 async-component syntax. Vue 3
// accepts a plain function as a FUNCTIONAL component, so it was registered as a
// component whose render function returns a Promise: it rendered nothing, threw
// nothing, and warned nothing. The click set `exportOpen = true`, the `v-if`
// passed, and no dialog ever appeared. It was the last such import in src/.
//
// Fixed with `defineAsyncComponent()`. This block asserts the dialog actually
// mounts, which is the part that silently broke; the ZIP round-trip below stays
// skipped for the unrelated reasons documented there.
test.describe('Buildiq export dialog', () => {
	test('the Export action opens the export dialog with its target picker', async ({
		page,
	}) => {
		await page.goto(
			`${NEXTCLOUD_URL}/apps/buildiq/applications/${APPLICATION_SLUG}`,
			{
				waitUntil: 'domcontentloaded',
			},
		)
		const exportButton = page.getByRole('button', { name: /^export$/i }).first()
		await expect(
			exportButton,
			'the app detail page must offer an Export action',
		).toBeVisible({ timeout: 30_000 })
		await exportButton.click()

		// The regression: this never appeared.
		const dialog = page.locator('.export-dialog')
		await expect(
			dialog,
			'clicking Export must mount the export dialog',
		).toBeVisible({ timeout: 20_000 })

		// Its three pickers are labelled via `<label for>`; the combobox itself
		// carries no accessible name in this @nextcloud/vue version, so a
		// getByRole('combobox', {name}) lookup finds nothing — match the labels.
		await expect(dialog.locator('label', { hasText: 'Version' })).toBeVisible()
		await expect(dialog.locator('label', { hasText: 'Target' })).toBeVisible()
		await expect(dialog.locator('label', { hasText: 'License' })).toBeVisible()

		// ZIP is the default target, and the submit action is reachable.
		await expect(dialog).toContainText('ZIP download')
		await expect(
			page.getByRole('button', { name: /start export/i }),
		).toBeVisible()
	})
})

// STILL SKIPPED, with the true reason replacing the #41 one.
//
//   - It polls `[data-test="export-job-row"]:has-text("succeeded")`. That
//     attribute is emitted nowhere in src/; the jobs surface is
//     src/components/tabs/ExportJobsTab.vue + src/views/ExportJobsList.vue, and
//     the export trigger is now the "Export" button in ApplicationDetailActions
//     opening ExportDialog — not the flow this file drives.
//   - It navigates to `/apps/buildiq/applications/hello-world`, i.e. the slug
//     as the route param. The detail route takes the OR OBJECT ID
//     (`/applications/:objectId`), so this lands on a not-found detail page.
//   - It also re-implements a form login instead of using the shared
//     storageState from globalSetup.
//
// Retarget it at ExportJobsTab/ExportDialog and resolve the object id first;
// the ZIP contract itself is covered at the unit/controller level meanwhile.
test.describe.skip('Buildiq ZIP export', () => {
	test.beforeEach(async ({ page }) => {
		// Login via the Nextcloud login form. CI uses storageState; this
		// fallback keeps the spec runnable in local dev.
		await page.goto(`${NEXTCLOUD_URL}/index.php/login`)
		if (
			await page
				.locator('input[name="user"]')
				.isVisible({ timeout: 5_000 })
				.catch(() => false)
		) {
			await page.fill('input[name="user"]', ADMIN_USER)
			await page.fill('input[name="password"]', ADMIN_PASSWORD)
			await page
				.locator('button[type="submit"], input[type="submit"]')
				.first()
				.click()
			// Pretty URLs in modern NC drop the `/index.php` prefix; accept both.
			await page.waitForURL(/\/apps\//, { timeout: 15_000 })
		}
	})

	test('export a hello-world Application as a ZIP and download it', async ({
		page,
	}) => {
		// 1. Navigate to the hello-world editor.
		await page.goto(
			`${NEXTCLOUD_URL}/apps/buildiq/applications/${APPLICATION_SLUG}`,
		)

		// 2. Open the Export dialog. The button is wired in
		//    src/views/ApplicationDetail.vue per task 8.3.
		const exportButton = page.getByRole('button', { name: /export/i })
		await expect(exportButton).toBeVisible({ timeout: 15_000 })
		await exportButton.click()

		// 3. Choose ZIP target. NcSelect renders a combobox-style trigger;
		//    the inputLabel prop (nc-input-labels gate) gives screen
		//    readers the "Target" label we can locate by.
		const targetSelect = page.getByRole('combobox', { name: /target/i })
		await expect(targetSelect).toBeVisible()
		await targetSelect.click()
		await page.getByRole('option', { name: /^ZIP/i }).click()

		// 4. Submit and capture the UUID surfaced in the row.
		await page.getByRole('button', { name: /^submit|^export$/i }).click()

		// 5. Poll the jobs list until status=succeeded.
		const succeededRow = page
			.locator('[data-test="export-job-row"]:has-text("succeeded")')
			.first()
		await expect(succeededRow).toBeVisible({ timeout: POLL_TIMEOUT_MS })

		// 6. Click the download button and capture the resulting file.
		const downloadPromise: Promise<Download> = page.waitForEvent('download')
		await succeededRow.getByRole('button', { name: /download/i }).click()
		const download = await downloadPromise

		const suggestedFilename = download.suggestedFilename()
		expect(suggestedFilename).toMatch(/\.zip$/i)
		// Filename either carries the app slug, the job UUID, or a generic
		// `export.zip` — all three are documented in the controller +
		// service layer. The .zip extension is the load-bearing assertion.
		expect(suggestedFilename.length).toBeGreaterThan(0)
	})

	test('export dialog rejects submission with invalid target', async ({
		page,
	}) => {
		// Locks the client-side guard mirror of the 422 controller path.
		await page.goto(
			`${NEXTCLOUD_URL}/apps/buildiq/applications/${APPLICATION_SLUG}`,
		)
		const exportButton = page.getByRole('button', { name: /export/i })
		await expect(exportButton).toBeVisible({ timeout: 15_000 })
		await exportButton.click()

		// Submit without choosing a target — NcSelect should remain in its
		// placeholder state and the submit button should be disabled (or
		// produce an inline validation error).
		const submitBtn = page.getByRole('button', { name: /^submit|^export$/i })
		const isDisabled = await submitBtn.isDisabled().catch(() => false)
		if (!isDisabled) {
			await submitBtn.click()
			// Validation error surfaces as a notice element with role=alert.
			await expect(page.getByRole('alert')).toBeVisible({ timeout: 5_000 })
		}
	})
})
