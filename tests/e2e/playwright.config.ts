/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
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
 * Note what is missing: `--project`. Whichever config it picks, EVERY project in
 * it runs. The ROOT playwright.config.ts declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot into docs/static/screenshots/…, and is
 *                  driven deliberately by the dedicated `Journeydoc Capture`
 *                  job, which passes `--project docs-capture` explicitly.
 *   visual       — pixel-diff baselines. Its own note in the root config says
 *                  the PNG baselines are host-font/GPU specific, so a CI Linux
 *                  runner cannot byte-match a dev-container baseline. It is
 *                  explicitly non-gating and opt-in.
 *
 * Letting the root config be picked would therefore make every PR re-shoot the
 * documentation screenshots AND fail on baselines that cannot match. Rather
 * than delete or weaken either project — the shared workflow's `Journeydoc
 * Capture` job hard-fails unless the ROOT config still declares a project named
 * `docs-capture` — `playwright-test-path: tests/e2e` in the caller makes the
 * workflow's FIRST lookup hit this file, which declares only the regression
 * project. The root config is untouched and stays the entry point for local
 * runs, `--project docs-capture` and `--project visual`.
 *
 * ⚠️ `testIgnore` HAS TO BE REPEATED AT PROJECT LEVEL.
 * A project-level `testIgnore` REPLACES the top-level one, it does not merge
 * with it. The list below is therefore stated twice on purpose; deleting either
 * copy silently changes what is collected.
 *
 * ── DURATION IS THE REAL RISK HERE, NOT CORRECTNESS ─────────────────────────
 * openbuild is the largest e2e suite in the fleet: 48 in-scope spec files
 * carrying ~327 `test()` declarations, several with raised budgets
 * (hydra-console 180s, version-rollback 150s, six files at 90s). The shared
 * job's `timeout-minutes: 45` CANCELS the job when exceeded, and a cancelled
 * job is NO verdict at all — strictly worse than a red one.
 *
 * The obvious lever, `fullyParallel: true` + `workers: 4`, is NOT available to
 * this suite, and that is a measured conclusion rather than a cautious one. A
 * file-by-file survey found four independent classes of cross-talk:
 *
 *   1. `support/appFixture.ts`'s `ensureApp()` takes a CALLER-SUPPLIED slug with
 *      no per-worker uniquifier, and decides whether to create by list-probe —
 *      a textbook TOCTOU. Twelve fixture slugs are hardcoded, and `hello-world`
 *      alone is shared by nine spec files while three of them WRITE to it
 *      (page-editor-coverage and openbuild-runtime replace its manifest;
 *      iconUpload persists an icon on it). `pw-verchain` is shared by three.
 *      version-rollback.spec.ts already carries a comment recording that this
 *      exact collision corrupted versionRouting.spec.ts once, SERIALLY.
 *   2. Read-then-delta assertions. builder-undo-redo and page-designer snapshot
 *      a count and then assert `initial ± 1`; openbuild-runtime asserts there is
 *      exactly ONE hello-world Application and exactly THREE hello-message
 *      objects; hydra-console reads a total from the API and then counts DOM
 *      rows. Every one of these breaks the moment another worker writes.
 *   3. automations-rbac.spec.ts performs three UNSPACED form logins per run.
 *      Nextcloud's brute-force throttle fires on consecutive logins from one IP
 *      — which is precisely why global-setup mints the four role sessions once,
 *      sequentially, with a 1.5s spacer. Multiply those three by four workers
 *      and the throttle is guaranteed.
 *   4. The backend is already the bottleneck at ONE worker: `workflows/
 *      fixtures.ts` resolves ids by pulling `_limit=5000` and filtering client
 *      side, on nearly every operation. Four workers would add load, not
 *      throughput, and would push the very timeouts that are already raised.
 *
 * So this config stays serial, and the duration risk is managed by making it
 * IMPOSSIBLE for the run to be silently cancelled instead:
 *
 *   `globalTimeout` is set BELOW the job's own 45-minute cap. Playwright then
 *   aborts the run itself, with a non-zero exit, a written HTML report and a
 *   per-spec duration breakdown — i.e. a legible verdict naming what was still
 *   running — instead of GitHub killing the runner and leaving `cancelled`,
 *   which reads as neither pass nor fail.
 *
 * Making the suite genuinely parallel-safe means giving `ensureApp()` a
 * per-worker slug suffix (the `RUN_ID` pattern in `workflows/fixtures.ts` is
 * already the right shape and should be ported, not reinvented) and quarantining
 * the `hello-world` writers. That is a real change to the fixtures with its own
 * blast radius, and it does not belong in the commit that merely turns the job
 * on.
 *
 * ARTIFACT PATHS
 * --------------
 * Report and traces are written under `tests/e2e/`. The shared workflow's upload
 * steps list `server/apps/<app>/tests/e2e/playwright-report/` and
 * `.../tests/e2e/test-results/` alongside the app-root paths, so both are
 * collected. `tests/e2e/.gitignore` carries the matching ignore rules — the
 * repo-root .gitignore's `/playwright-report/` and `/test-results/` are
 * root-anchored and do NOT match these locations.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { E2E_BASE_URL } from './support/baseUrl'

// Roughly 30 tests across nine files self-skip behind
// `process.env.OPENBUILD_E2E_LIVE === '1'`. The root config defaults it ON and
// documents why: every spec in this suite already requires a live Nextcloud at
// `baseURL`, so the guard never distinguished "can run" from "cannot run" — it
// only let a slice of the suite report green while asserting nothing. CI has a
// live instance by construction, so the same default applies here. Weakening it
// would be trading real assertions for a shorter run.
process.env.OPENBUILD_E2E_LIVE ??= '1'

/**
 * Files under `tests/e2e` that are NOT part of the CI regression run.
 *
 * - `visual/**` — pixel baselines a CI runner cannot byte-match (see header).
 * - `docs-screenshots.spec.ts` — the journeydoc capture project's only spec.
 * - `global-setup.ts`, `support/**`, `workflows/fixtures.ts` — helper modules.
 *   None of them match Playwright's default `testMatch`, but they are listed so
 *   a future `testMatch` widening cannot start collecting them as specs
 *   ("no tests found in file").
 */
const IGNORED = [
	'**/visual/**',
	'**/docs-screenshots.spec.ts',
	'**/global-setup.ts',
	'**/support/**',
	'**/workflows/fixtures.ts',
]

export default defineConfig({
	testDir: __dirname,
	// See the header: repeated on the project below, because a project-level
	// testIgnore REPLACES this list rather than extending it.
	testIgnore: IGNORED,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	// Deliberately serial — see the four cross-talk classes in the header.
	fullyParallel: false,
	workers: 1,
	// Below the shared job's `timeout-minutes: 45`, so Playwright aborts with a
	// real failure + report rather than the job being CANCELLED (no verdict).
	globalTimeout: 38 * 60_000,
	forbidOnly: !!process.env.CI,
	// No retries on CI. With a serial suite this large, a retry of a slow spec
	// costs the shared budget twice over and is the most likely way to convert a
	// legible red into a cancelled run. Flakes are to be fixed at the cause, not
	// absorbed.
	retries: 0,
	reporter: process.env.CI
		? [
			['github'],
			['html', { open: 'never', outputFolder: path.resolve(__dirname, 'playwright-report') }],
			['list'],
		]
		: [['html', { open: 'never', outputFolder: path.resolve(__dirname, 'playwright-report') }], ['list']],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/support/baseUrl.ts. It accepts
		// BASE_URL, which is the name the shared workflow actually exports.
		baseURL: E2E_BASE_URL,
		// Authenticated browser context written by global-setup.ts.
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		httpCredentials: {
			username: process.env.NC_ADMIN_USER || 'admin',
			password: process.env.NC_ADMIN_PASSWORD || process.env.NC_ADMIN_PASS || 'admin',
		},
		// Note: do NOT set `OCS-APIRequest: true` globally here. The header makes
		// Nextcloud treat every request as an API call — including HTML page loads
		// — which breaks the browser login redirect (no Location header, the page
		// stays on /login). Specs that need it set it per `request` call.
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		headless: true,
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: IGNORED,
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
