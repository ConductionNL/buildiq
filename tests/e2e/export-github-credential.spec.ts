/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The GitHub export target offers broker credentials, never a token field
 * (export-github-broker task 7.3).
 *
 * Opens the export dialog on the hello-world app, switches the target to
 * "Push to GitHub" and asserts the dialog shows the "GitHub credential"
 * picker and no input that would take a token: no password field and no
 * field labelled token.
 *
 * @spec openspec/changes/archive/2026-11-24-export-github-broker/specs/openbuild-exporter/spec.md
 * @e2e export-github-broker/requirement-the-github-export-holds-no-token/the-export-dialog-offers-credentials-never-a-token-field
 */

import { expect, test } from '@playwright/test'
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl.ts'

const APPLICATION_SLUG = 'hello-world'

test.describe('Buildiq GitHub export credential picker', () => {
	// @e2e export-github-broker/requirement-the-github-export-holds-no-token/the-export-dialog-offers-credentials-never-a-token-field
	test('the GitHub target shows a credential picker and no token field', async ({
		page,
	}) => {
		await page.goto(
			`${NEXTCLOUD_URL}/apps/buildiq/applications/${APPLICATION_SLUG}`,
			{ waitUntil: 'domcontentloaded' },
		)
		// Export sits in the "Actions" overflow menu (see export-zip.spec.ts).
		await page
			.getByRole('button', { name: /^Actions$/i })
			.first()
			.click()
		const exportButton = page
			.locator('[data-testid="cn-action-app-export"]')
			.first()
		await expect(exportButton).toBeVisible({ timeout: 30_000 })
		await exportButton.click()

		const dialog = page.locator('.export-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		// Switch the target from the default ZIP download to GitHub. The select
		// splits its selected label over two spans ("ZIP do" + "wnload"), so it
		// is found by its combobox name, not by that text, and opened from the
		// keyboard: a click on the field leaves its list closed.
		const target = dialog.getByRole('combobox', { name: 'Target' })
		await target.focus()
		await target.press('ArrowDown')
		await page.getByRole('option', { name: /Push to GitHub/i }).first().click()

		await expect(
			dialog.locator('label', { hasText: 'GitHub credential' }),
			'the GitHub target must offer a broker credential picker',
		).toBeVisible({ timeout: 10_000 })

		// No way to type a secret into the dialog.
		await expect(dialog.locator('input[type="password"]')).toHaveCount(0)
		await expect(dialog.getByLabel(/token/i)).toHaveCount(0)
		// The hint says where the token lives instead.
		await expect(dialog).toContainText(/credential/i)
	})
})
