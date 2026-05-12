// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * E2E — the textarea-based ApplicationEditor (REQ-OBR-005).
 *
 * Logs in as admin, opens /index.php/apps/openbuilt/applications, edits the
 * hello-world manifest in the JSON textarea, saves, and asserts the manifest
 * endpoint reflects the change. Round-trips through OR's REST API (no
 * app-local CRUD wrapper, per ADR-022).
 *
 * Preconditions: see builder-host.spec.ts.
 *
 * NOTE: the visual editor lands in chain spec #5; this test exercises the
 * stop-gap textarea editor only.
 */
test.describe('ApplicationEditor — textarea round-trip', () => {
	test('loads, edits hello-world manifest, saves successfully', async ({ page, request }) => {
		await page.goto('/index.php/apps/openbuilt/applications')

		// The editor lists Applications down the left rail and selects the
		// first one (hello-world) on mount. The textarea binds to the
		// manifest JSON.
		const textarea = page.locator('textarea.openbuilt-editor__textarea, .openbuilt-editor textarea').first()
		await expect(textarea, 'editor textarea must be visible after mount').toBeVisible({ timeout: 15_000 })

		// The textarea must contain a JSON blob with version/menu/pages.
		const initial = await textarea.inputValue()
		expect(initial.length, 'textarea must be populated with the hello-world manifest').toBeGreaterThan(0)
		const parsed = JSON.parse(initial)
		expect(parsed).toHaveProperty('version')
		expect(parsed).toHaveProperty('pages')

		// Mutate the manifest — bump the version to assert the PUT lands.
		const bumped = { ...parsed, version: '1.0.1' }
		await textarea.fill(JSON.stringify(bumped, null, 2))

		// Save — the button is the first <button> in the editor pane.
		const saveButton = page.getByRole('button', { name: /save/i }).first()
		await expect(saveButton).toBeEnabled()
		await saveButton.click()

		// validateManifest may reject if the placeholder is anything but a
		// strict JSON object; the test asserts that NO error banner appears
		// after the save round-trips.
		const errorBanner = page.locator('.openbuilt-editor__error')
		await expect(
			errorBanner,
			'no validation error banner expected on a benign version bump',
		).toBeHidden({ timeout: 5_000 })

		// Final assertion — the public manifest endpoint reflects the new version.
		// (Hits the API directly so we don't depend on the SPA refresh path.)
		await page.waitForTimeout(1_000)
		const response = await request.get('/index.php/apps/openbuilt/api/applications/hello-world/manifest')
		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body.version, 'manifest endpoint must reflect the bumped version').toBe('1.0.1')
	})
})
