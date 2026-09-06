// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The single source of truth for the absolute base URL of the instance under
 * test.
 *
 * WHY THIS EXISTS — read before changing the precedence below.
 *
 * Ten specs used to compute their own base URL as
 * `process.env.NC_BASE_URL ?? 'http://localhost:8080'` (or the NEXTCLOUD_URL
 * variant), which ignored `PLAYWRIGHT_BASE_URL` entirely. The e2e suite is
 * driven with `PLAYWRIGHT_BASE_URL=http://localhost:8099` (the disposable
 * `ob-vue3-e2e` container) and NC_BASE_URL unset, so every one of those specs
 * silently fell through to the hardcoded `:8080` default — which on a dev box
 * is the SHARED `nextcloud` container, a different instance holding other
 * people's work.
 *
 * Two things went wrong as a result:
 *
 *   1. Specs tested the wrong server. schema-access-scopes creates its fixture
 *      app through `ensureApp()` (relative URL, so the config baseURL = :8099)
 *      and then opened the schema list at `${BASE_URL}/apps/buildiq/...`
 *      (= :8080). The app existed on one instance and the page was opened on
 *      the other, so `.buildiq-schema-list` was never found and the whole
 *      describe failed on a fixture that was actually fine.
 *   2. Worse, the WRITE paths in those specs pointed at the shared instance,
 *      so a run could create fixture apps and schemas on somebody else's
 *      environment.
 *
 * `PLAYWRIGHT_BASE_URL` therefore wins: it is what the config's `baseURL` (and
 * hence every relative `page.goto`, and the stored auth state) already uses, so
 * absolute and relative navigation in the same spec can no longer disagree.
 * NC_BASE_URL / NEXTCLOUD_URL are kept as explicit overrides for anyone driving
 * a spec directly, and the :8080 default is kept only as a last resort.
 *
 * Prefer a RELATIVE `page.goto('/apps/buildiq/...')` in new code — it uses the
 * config baseURL and cannot drift from it at all. Import this only where an
 * absolute URL is genuinely required (e.g. building a `page.request` URL).
 *
 * ⚠️ `BASE_URL` IS IN THE LIST ON PURPOSE.
 * The shared quality workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * exports the target of its `E2E Tests (Playwright)` job as **`BASE_URL`** —
 * alongside NEXTCLOUD_URL / NC_BASE_URL, but NOT as PLAYWRIGHT_BASE_URL. A
 * resolver that omits `BASE_URL` therefore either hard-fails on CI (openconnector:
 * "Error: PLAYWRIGHT_BASE_URL is not set" on every run since its Vue 3 migration)
 * or falls through to a literal and happens to be right for the wrong reason.
 *
 * ⚠️ THERE IS NO LONGER A `|| 'http://localhost:8080'` DEFAULT OFF CI.
 * That literal is the SHARED `nextcloud` dev container on this box. It
 * bind-mounts real host checkouts, and this suite performs WRITES (it creates
 * applications, schemas, pages and RBAC users). A silent fallback onto it
 * corrupts other people's environments, and the failure is invisible: the suite
 * goes green, against the wrong instance. So off CI an unset target is a hard
 * error naming the fix. On a GitHub runner there is no shared instance — the
 * workflow starts a throwaway Nextcloud on the runner's own `localhost:8080` via
 * `php -S` — so falling back there is safe and is kept.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

const CI_DEFAULT_BASE_URL = 'http://localhost:8080'

/**
 * Resolve the Nextcloud base URL for this run.
 *
 * @return {string} The base URL, without a trailing slash.
 * @throws {Error} When no target is configured and this is not a CI runner.
 */
export function resolveE2EBaseURL(): string {
	const explicit =
		process.env.PLAYWRIGHT_BASE_URL
		|| process.env.NC_BASE_URL
		|| process.env.NEXTCLOUD_URL
		// Exported by the shared ConductionNL/.github quality workflow.
		|| process.env.BASE_URL

	if (explicit) {
		return explicit.replace(/\/+$/, '')
	}

	if (process.env.CI || process.env.GITHUB_ACTIONS) {
		console.warn(
			'[buildiq e2e] no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set; '
				+ `using the CI-local default ${CI_DEFAULT_BASE_URL}.`,
		)
		return CI_DEFAULT_BASE_URL
	}

	throw new Error(
		'[buildiq e2e] No target Nextcloud configured. Set PLAYWRIGHT_BASE_URL (preferred), '
			+ 'NC_BASE_URL, NEXTCLOUD_URL or BASE_URL to the instance you want to test, e.g.\n\n'
			+ '    PLAYWRIGHT_BASE_URL=http://localhost:8099 npx playwright test\n\n'
			+ 'There is deliberately no default off CI: the historic one was http://localhost:8080, '
			+ 'the SHARED development container, and this suite WRITES (applications, schemas, users) — '
			+ "running it there corrupts other people's environments while reporting green.",
	)
}

/** The resolved base URL for this run. */
export const E2E_BASE_URL: string = resolveE2EBaseURL()
