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
 *      and then opened the schema list at `${BASE_URL}/apps/openbuild/...`
 *      (= :8080). The app existed on one instance and the page was opened on
 *      the other, so `.openbuild-schema-list` was never found and the whole
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
 * Prefer a RELATIVE `page.goto('/apps/openbuild/...')` in new code — it uses the
 * config baseURL and cannot drift from it at all. Import this only where an
 * absolute URL is genuinely required (e.g. building a `page.request` URL).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

export const E2E_BASE_URL: string = process.env.PLAYWRIGHT_BASE_URL
	|| process.env.NC_BASE_URL
	|| process.env.NEXTCLOUD_URL
	|| 'http://localhost:8080'
