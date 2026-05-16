// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright globalSetup — logs in to Nextcloud once and writes the
 * authenticated browser context (cookies + localStorage) to
 * `tests/e2e/.auth/admin.json`. Every spec then reuses that storage
 * state via the project-level `storageState` config, so per-spec
 * `loginAsAdmin` helpers are no longer required.
 *
 * Before this hook existed, specs without an explicit form-login step
 * (applicationCard, builder-host, bootstrap-openbuilt, …) landed on
 * `/login` and every locator timed out. Nextcloud's session is cookie-
 * based; basic auth alone doesn't satisfy the SPA's auth check.
 *
 * The hook is no-op when login fails (e.g. brute-force throttle); the
 * resulting empty storage state lets specs surface real errors with a
 * clear "still on /login" snapshot in the report.
 */

import { chromium, FullConfig } from '@playwright/test'
import { existsSync, mkdirSync } from 'fs'
import { dirname } from 'path'

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0].use.baseURL as string)
		|| process.env.PLAYWRIGHT_BASE_URL
		|| 'http://localhost:8080'
	const adminUser = process.env.NC_ADMIN_USER || 'admin'
	const adminPassword = process.env.NC_ADMIN_PASSWORD || process.env.NC_ADMIN_PASS || 'admin'
	const storagePath = 'tests/e2e/.auth/admin.json'

	if (existsSync(dirname(storagePath)) === false) {
		mkdirSync(dirname(storagePath), { recursive: true })
	}

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	try {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await page.locator('input[name="user"]').fill(adminUser)
		await page.locator('input[name="password"]').fill(adminPassword)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		// Accept both pretty + index.php-prefixed redirects.
		await page.waitForURL(/\/apps\//, { timeout: 20_000 })
		await context.storageState({ path: storagePath })
		// eslint-disable-next-line no-console
		console.log(`[globalSetup] authenticated session stored at ${storagePath}`)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(`[globalSetup] login failed — specs will run unauthenticated: ${(e as Error).message}`)
	} finally {
		await browser.close()
	}
}
