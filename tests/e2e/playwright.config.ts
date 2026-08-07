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
	// measured against.
	//
	// 60_000 WAS MEASURED AND IS TOO GENEROUS. From the first run this suite
	// ever had (run 31030663352, job 92390852268), the FOUR tests that PASSED
	// took 0.4s, 0.4s, 2.9s and 3.5s. Passes finish fast; only failures sit on
	// the budget, and six component-blocks failures burned the full 1.0m each.
	// 30_000 is ~8.5x the slowest observed pass, and is still comfortably above
	// the largest wait a spec asks for itself (`waitForSelector(…, 20_000)` in
	// agents/automations) plus its navigation — so a genuine app failure still
	// reports its OWN legible message instead of being masked by a test-timeout.
	// This is a budget cut, not a weakened assertion: nothing that passed at 60s
	// passed anywhere near 30s.
	timeout: 30_000,
	expect: { timeout: 15_000 },
	// Don't run specs in parallel: Nextcloud's brute-force throttle fires after
	// a handful of near-simultaneous form logins from the same IP and every
	// subsequent spec falls back to the /login page. Serial execution with one
	// shared storageState (via globalSetup) avoids it.
	//
	// `workers: 1` is NOT a wall-clock knob to turn when the job runs long.
	// Four independent classes of cross-talk make parallelism unsafe here:
	//   1. the shared `hello-world` fixture slug — nine specs read it and three
	//      write it, so two workers race on the same object;
	//   2. exact-count assertions (read-then-delta) that another worker's create
	//      invalidates between the read and the delta;
	//   3. Nextcloud's brute-force throttle, which fires on near-simultaneous
	//      logins from one IP (see above);
	//   4. the backend itself — `_limit=5000` list reads with client-side
	//      filtering are the bottleneck, so more workers mostly queue on PHP.
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	// NO RETRIES ON CI. A retry can only ever convert red to green, so it buys
	// nothing a real defect needs — and it doubles the cost of every failure,
	// which is precisely what blew the budget: of the 98 test attempts the first
	// run got through in 42.5 minutes, 15 were retries of already-failing tests,
	// and failures accounted for 29.6 of those 42.5 minutes. Flakiness, if any
	// shows up, is a defect to name and fix, not to average away.
	retries: 0,
	workers: 1,
	// EXIT WITH A TALLY RATHER THAN BE KILLED WITH NONE.
	//
	// The shared workflow's job carries `timeout-minutes: 45`. The first run of
	// this suite hit it exactly (job 92390852268 ran 45m16s) and GitHub cancelled
	// the runner mid-test. A cancellation is not a verdict: no summary line, no
	// counts, and — because Playwright writes its HTML report only after the last
	// test — `actions/upload-artifact` found nothing ("No files were found with
	// the provided path: server/apps/openbuild/playwright-report/"), so there was
	// no report and no traces to read either.
	//
	// A `globalTimeout` BELOW the job cap makes Playwright stop itself, print
	// "Timed out waiting … for the entire test run", emit the pass/fail/skip
	// tally for everything it did reach, and flush the report so the artifact
	// uploads. Budget: 45m job cap − ~3m of runner setup before `npx playwright
	// test` starts (measured: job start 17:36:59, first test 17:39:40) − report
	// write + upload ≈ 36m of headroom for the run itself.
	//
	// This does not hide anything. Hitting it is a hard failure and says so; it
	// just fails with numbers attached.
	globalTimeout: 36 * 60 * 1000,
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
		// `on-first-retry` WROTE NOTHING IN THIS JOB, EVER.
		//
		// This config sets `retries: 0` (see above, deliberately). Playwright
		// only records a trace on a RETRY under that mode, and a retry can
		// never happen, so the trace file was never produced — while the
		// workflow's trace-upload step dutifully ran, found nothing, and said
		// so quietly under `if-no-files-found: ignore`. Two settings that are
		// each individually defensible combined into an instrument that is
		// switched off: every red run since this job existed had a screenshot
		// and a video but no trace, which is the one artifact that carries the
		// network log and the DOM at each step.
		//
		// `retain-on-failure` records every test and keeps the trace only for
		// the ones that fail — no dependence on retries at all. The output
		// lands under `outputDir` (APP_ROOT/test-results), which IS globbed by
		// the shared workflow's upload step (it uploads both
		// `server/apps/<app>/test-results/` and
		// `server/apps/<app>/tests/e2e/test-results/`), so the traces actually
		// leave the runner.
		trace: 'retain-on-failure',
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
