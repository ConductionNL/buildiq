// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot; the dedicated `Journeydoc Capture`
 *                  job runs it explicitly with `--project docs-capture`.
 *   visual       — pixel-diff baselines. The root config's own header says
 *                  the PNGs are host-font/GPU specific and that "a CI Linux
 *                  runner will not byte-match a dev-container baseline".
 *
 * Letting the root config be picked therefore runs two projects that are
 * documented as unable to pass on a CI runner, on top of the one that can.
 * Rather than delete or weaken them, `playwright-test-path: tests/e2e` in
 * `.github/workflows/code-quality.yml` makes the workflow's FIRST lookup hit
 * this file, which declares only the regression project. The root config is
 * untouched and stays the entry point for local runs, `--project docs-capture`
 * and `--project visual`.
 *
 * The report/output paths also differ deliberately: the workflow uploads
 * `server/apps/openbuild/playwright-report/` and
 * `server/apps/openbuild/test-results/`, so on CI the artifacts must land at
 * the APP ROOT, not under `tests/e2e/`. With a config whose paths sit
 * elsewhere the upload steps match nothing and, because they carry
 * `if-no-files-found: ignore`, say so quietly — a red run with no report and
 * no traces to read, which is exactly when you need them.
 *
 * Everything else — the live-mode default, the serial execution, the shared
 * globalSetup session, the httpCredentials, the deliberate absence of a
 * `webServer` — is kept identical to the root config on purpose. This file is
 * a PROJECT filter and an artifact-path fix, not a second set of rules.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { E2E_BASE_URL } from './support/baseUrl'

// Roughly 26 specs self-skip behind `process.env.OPENBUILD_E2E_LIVE === '1'`.
// Every spec in this suite already requires a live Nextcloud at `baseURL`, so
// the guard never distinguished "can run" from "cannot run" — it only let a
// quarter of the suite report green while asserting nothing. The root config
// defaults it ON for the same reason; without this line CI would collect those
// specs and skip them, which is the green-but-dead shape this job exists to
// end. Set OPENBUILD_E2E_LIVE=0 to opt out deliberately.
process.env.OPENBUILD_E2E_LIVE ??= '1'

// `__dirname` is `<app root>/tests/e2e`; the app root is two levels up. The
// workflow runs `npx playwright test` with the app root as CWD, but paths are
// resolved from `__dirname` rather than the CWD so a run started from anywhere
// writes its report and its auth state to the same places.
const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	// CI runs Nextcloud behind PHP's built-in server on a shared runner, which
	// is markedly slower than the dev container the root config's 30s was
	// measured against. A generous budget here turns "the runner was slow" into
	// a pass instead of a flake; it does not make any assertion weaker.
	timeout: 60_000,
	expect: { timeout: 15_000 },
	// Don't run specs in parallel: Nextcloud's brute-force throttle fires after
	// a handful of near-simultaneous form logins from the same IP and every
	// subsequent spec falls back to the /login page. Serial execution with one
	// shared storageState (via globalSetup) avoids it.
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI
		? [
			['github'],
			['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
			['list'],
		]
		: [['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }], ['list']],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		// Same resolution order the specs themselves use (tests/e2e/support/
		// baseUrl.ts), so the config and the specs can never point at two
		// different instances. CI exports NC_BASE_URL / NEXTCLOUD_URL.
		baseURL: E2E_BASE_URL,
		// Authenticated browser context populated by globalSetup. Empty when
		// login fails — specs then surface the actual login page in their
		// failure snapshots.
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		httpCredentials: {
			username: process.env.NC_ADMIN_USER || 'admin',
			password: process.env.NC_ADMIN_PASSWORD || process.env.NC_ADMIN_PASS || 'admin',
		},
		// Note: do NOT set `OCS-APIRequest: true` here globally. The header
		// makes Nextcloud treat every request as an API call — including the
		// HTML page loads — which breaks the browser-based login redirect (no
		// Location header is emitted, the page stays on /login). Specs that
		// need it set it on their explicit `request` calls.
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		headless: true,
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
	],

	// Assume the instance under test is already up; do NOT spin our own
	// webServer. On CI the shared workflow starts `php -S` before this runs;
	// locally it is clean-env + docker-compose up.
})
