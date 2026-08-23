// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { defineConfig, devices } from '@playwright/test'

// Roughly 26 specs self-skip behind `process.env.BUILDIQ_E2E_LIVE === '1'`
// with reasons like "Requires live dev environment". But EVERY spec in this
// suite already requires a live Nextcloud at `baseURL` — the config
// deliberately does not spin its own webServer (see the note at the bottom).
// So the guard never distinguished "can run" from "cannot run": it only let a
// quarter of the suite report green while asserting nothing, which is the
// green-but-dead antipattern.
//
// Default it ON so those specs actually assert. Set BUILDIQ_E2E_LIVE=0 to
// opt out deliberately (e.g. a parse-only lint of the suite).
process.env.BUILDIQ_E2E_LIVE ??= '1'

/**
 * Playwright config for Buildiq e2e tests.
 *
 * Targets the local Nextcloud Docker stack at http://localhost:8080
 * (see `.github/docker-compose.yml`). Tests assume the Buildiq app
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
	// Mirror of tests/e2e/playwright.config.ts. CI does not pick this file up
	// (`playwright-test-path: tests/e2e` makes the workflow's first lookup hit
	// the config next to the specs), but it IS the documented fallback the
	// shared workflow uses when that file is absent — and the job it would run
	// under carries `timeout-minutes: 45`. A cancellation at the job cap is not
	// a verdict: no tally, and the report is never written so the artifact
	// upload finds nothing. Stopping ourselves below the cap fails with numbers
	// attached instead.
	globalTimeout: 36 * 60 * 1000,
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
		// `retain-on-failure`, not `on-first-retry`: this config's `retries` is
		// 1 only on CI and 0 locally, so on a developer box the old setting
		// produced no trace for any failure at all — and CI does not use this
		// config (see tests/e2e/playwright.config.ts). Keeping the two files in
		// step so a local reproduction has the same evidence a CI run does.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		headless: true,
	},
	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
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
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI before it can gate.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: 'tests/e2e/.auth/admin.json',
			},
			timeout: 90_000,
		},
	],
	// Assume the Docker stack is already up; do NOT spin our own webServer.
	// (clean-env + docker-compose up is the documented dev path — see
	// `.github/docs/development-environment.md`.)
})
