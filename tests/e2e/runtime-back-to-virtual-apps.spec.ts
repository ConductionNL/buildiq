/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the running app's way back to Buildiq's app list
 * (openspec change `runtime-back-to-virtual-apps`).
 *
 * Tutorial 06 (step 5) sends a builder from a preview "Back to virtual apps",
 * into the designer and out to the preview again. These tests hold the first
 * step of that loop: the link is there for a builder, points at the app
 * list, is absent for a viewer, and never ends up in the manifest.
 */

import { expect, test } from '@playwright/test'
import { suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { dismissFirstVisitOverlays } from './support/overlays.ts'
import { ensureVersionChain } from './support/versionChain.ts'

const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'pw-verchain'
const ADMIN_STATE = 'tests/e2e/.auth/admin.json'
const LINK_NAME = /back to virtual apps/i

test.describe('the running app offers builders a way back', () => {
	test.beforeAll(async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
			await suppressSetupWizard(page)
			await page.goto(`${BASE_URL}/apps/buildiq/`, {
				waitUntil: 'domcontentloaded',
			})
			await ensureVersionChain(page, TEST_SLUG, 'PW Version Chain')
			await grantAppRoles(page, TEST_SLUG, {
				editors: ['group:rbac-editors'],
				viewers: ['group:rbac-viewers'],
			})
		} finally {
			await context.close()
		}
	})

	// @e2e openbuild-runtime::a-version-preview-shows-the-way-back
	test('a version preview links back to the app list', async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
			await page.goto(
				`${BASE_URL}/apps/buildiq/builder/${TEST_SLUG}?_version=development`,
				{ waitUntil: 'domcontentloaded' },
			)
			await dismissFirstVisitOverlays(page)
			const link = page
				.locator('[data-testid="cn-nav"]')
				.getByRole('link', { name: LINK_NAME })
			await expect(link).toBeVisible({ timeout: 30_000 })
			await expect(link).toHaveAttribute(
				'href',
				/\/apps\/buildiq\/applications$/,
			)
		} finally {
			await context.close()
		}
	})

	// @e2e openbuild-runtime::other-users-of-a-published-app-see-no-link
	test('a viewer on the production URL sees no link', async ({ browser }) => {
		const context = await browser.newContext({
			storageState: 'tests/e2e/.auth/rbac-viewer.json',
		})
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
			await page.goto(`${BASE_URL}/apps/buildiq/builder/${TEST_SLUG}`, {
				waitUntil: 'domcontentloaded',
			})
			await dismissFirstVisitOverlays(page)
			// Wait for the shell before asserting an absence, or a blank page
			// would pass.
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
				timeout: 30_000,
			})
			await expect(page.getByRole('link', { name: LINK_NAME })).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	// @e2e openbuild-runtime::the-link-never-lands-in-the-manifest
	test('the link is not part of the stored manifest', async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
			await page.goto(
				`${BASE_URL}/apps/buildiq/builder/${TEST_SLUG}?_version=development`,
				{ waitUntil: 'domcontentloaded' },
			)
			await dismissFirstVisitOverlays(page)
			await expect(page.getByRole('link', { name: LINK_NAME })).toBeVisible({
				timeout: 30_000,
			})
			const stored = await page.evaluate(async (slug) => {
				const resp = await fetch(
					`/index.php/apps/buildiq/api/applications/${slug}/manifest?_version=development`,
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				return resp.text()
			}, TEST_SLUG)
			expect(stored).toContain('"pages"')
			expect(stored.toLowerCase()).not.toContain('back to virtual apps')
			expect(stored).not.toContain('/apps/buildiq/applications')
		} finally {
			await context.close()
		}
	})
})
