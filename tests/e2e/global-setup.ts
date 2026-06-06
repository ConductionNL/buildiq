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
 * (applicationCard, builder-host, bootstrap-openbuild, …) landed on
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
import { execSync } from 'child_process'

/**
 * Seed the deterministic `hello-world` virtual-app fixture the e2e specs run
 * against. Production no longer ships a hello-world seed (the SeedHelloWorld
 * repair step was retired with the versioned-model migration), so the suite
 * seeds it itself via the test-only occ command. Override the invocation with
 * OPENBUILD_SEED_CMD when occ is reached differently (e.g. a non-docker CI).
 * Non-fatal: a failure is logged and specs surface the missing fixture.
 */
function seedHelloWorldFixture(): void {
	const cmd = process.env.OPENBUILD_SEED_CMD
		|| 'docker exec -u www-data nextcloud php occ openbuild:seed-hello-world-fixture'
	try {
		const out = execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
		// eslint-disable-next-line no-console
		console.log(`[globalSetup] hello-world fixture: ${out.trim().split('\n').pop()}`)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(`[globalSetup] hello-world fixture seed failed (specs needing it will fail): ${(e as Error).message}`)
	}
}

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

	// Seed the hello-world fixture the specs run against (idempotent).
	seedHelloWorldFixture()
}
