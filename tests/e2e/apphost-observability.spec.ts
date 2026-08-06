// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect } from '@playwright/test'

/**
 * The ADR-040 AppHost autoload prelude, asserted through its user-visible effect.
 *
 * OpenBuild ships NO concrete HealthController or MetricsController. Their route
 * targets (`health#index`, `metrics#index`, declared by
 * `\OCA\OpenRegister\AppHost\Routes::standard()` in appinfo/routes.php) exist
 * ONLY as DI aliases created by `Bootstrap::register()` inside
 * `Application::register()`.
 *
 * Nextcloud registers apps in sorted order — `OC_App::getEnabledApps()` does
 * `sort($apps)` and `Coordinator::registerApps()` calls
 * `OC_App::registerAutoloading($appId, $path)` and then `$app->register()` one
 * app at a time — and `openbuild` sorts BEFORE `openregister`. So without the
 * prelude, `class_exists(Bootstrap::class)` answered false inside OpenBuild's
 * own `register()`, `Bootstrap::register()` never ran, those aliases were never
 * created, and the routes resolved to a class with no binding: HTTP 500, not
 * 404. Measured on this very workflow — `OpenBuild: OpenRegister
 * AppHost\Bootstrap is not autoloadable` was logged on every occ call in
 * ci-seed.sh while OpenRegister was installed and enabled the whole time.
 *
 * This test is the reason the fix is not a lint change: it FAILS on the code
 * before the prelude and passes after it. It is deliberately an HTTP-level
 * assertion rather than a UI one, because the defect lives in the composition
 * root and its first observable symptom is a route that cannot be dispatched.
 *
 * CI is the environment that can see this. A dev instance where any
 * alphabetically-earlier app already pulls OpenRegister's autoloader in (e.g.
 * `doriath`, which carries the same prelude) registers the PSR-4 prefix
 * process-wide and masks the failure entirely.
 */
// @e2e openspec/specs/settings-and-observability/spec.md#the-apphost-bound-observability-routes-actually-dispatch
test.describe('ADR-040 AppHost adoption', () => {
	test('serves /api/health from the AppHost generic controller', async ({ request }) => {
		// ADR-006: health is public — no auth, no CSRF.
		const response = await request.get('/index.php/apps/openbuild/api/health')

		expect(
			response.status(),
			'health#index is bound only by Bootstrap::register(). A 500 here means '
			+ 'Bootstrap::register() did not run, i.e. OCA\\OpenRegister\\ was not '
			+ 'autoloadable during openbuild\'s register() — the ADR-040 prelude is '
			+ 'missing or ran too late.',
		).toBe(200)

		const body = await response.json()

		// The engine's canonical shape: { status, app, version, checks }.
		expect(body).toHaveProperty('status')
		expect(body).toHaveProperty('checks')
		expect(
			body.app,
			'the generic controller namespaces its report with the app id it was registered for',
		).toBe('openbuild')
	})

	test('serves /api/metrics from the AppHost generic controller', async ({ request }) => {
		// ADR-006: metrics is admin-only, so an anonymous caller must be
		// REJECTED rather than served — but it must be rejected by the auth
		// middleware, which only runs once the route resolves to a bound
		// controller. A 500 would again mean Bootstrap::register() never ran.
		const response = await request.get('/index.php/apps/openbuild/api/metrics')

		expect(
			response.status(),
			'metrics#index is bound only by Bootstrap::register(). Any 5xx here means '
			+ 'the AppHost aliases were never created.',
		).toBeLessThan(500)
	})
})
