// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright config for OpenBuilt e2e tests.
 *
 * Targets the local Nextcloud Docker stack at http://localhost:8080
 * (see `.github/docker-compose.yml`). Tests assume the OpenBuilt app
 * is enabled and the SeedHelloWorld repair step has populated the
 * canonical hello-world virtual app.
 *
 * One-time setup (CI / new dev):
 *   npx playwright install --with-deps
 */
export default defineConfig({
	testDir: 'tests/e2e',
	timeout: 30_000,
	expect: { timeout: 5_000 },
	// Don't run specs in parallel: Nextcloud's brute-force throttle fires
	// after a handful of near-simultaneous form logins from the same IP
	// and every subsequent spec falls back to the /login page. Serial
	// execution with one shared storageState (via globalSetup) avoids it.
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	globalSetup: './tests/e2e/global-setup.ts',
	reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
	use: {
		baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8080',
		// Authenticated browser context populated by globalSetup. Empty
		// when login fails — specs then surface the actual login page in
		// their failure snapshots.
		storageState: 'tests/e2e/.auth/admin.json',
		httpCredentials: {
			username: process.env.NC_ADMIN_USER || 'admin',
			password: process.env.NC_ADMIN_PASSWORD || 'admin',
		},
		// Note: do NOT set `OCS-APIRequest: true` here globally. The header
		// makes Nextcloud treat every request as an API call — including the
		// HTML page loads — which breaks the browser-based login redirect
		// (no Location header is emitted, the page stays on /login).
		// Specs that need OCS-APIRequest set it on their explicit `request`
		// calls (e.g. versionRouting.spec.ts, applicationDetailOverview.spec.ts).
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		headless: true,
	},
	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Documentation capture project (ADR-030 / journeydoc). Opt-in:
		//   npx playwright test --project docs-capture
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
	],
	// Assume the Docker stack is already up; do NOT spin our own webServer.
	// (clean-env + docker-compose up is the documented dev path — see
	// `.github/docs/development-environment.md`.)
})
