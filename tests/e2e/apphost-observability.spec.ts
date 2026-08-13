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
 * app at a time — and `openbuild` sorts BEFORE `openregister`, so
 * `OCA\OpenRegister\` is not on the autoloader when OpenBuild's own
 * `register()` runs. If `class_exists(Bootstrap::class)` answers false there,
 * `Bootstrap::register()` never runs, these aliases are never created, and both
 * routes resolve to a class with no binding: HTTP 500, not 404.
 *
 * ⚠️ Honest scope of this test. It does NOT reproduce the pre-fix defect: on the
 * run measured before the prelude landed (run 31081906401), both routes already
 * answered 200, so the guard was evidently answering TRUE under the web SAPI.
 * What WAS measured failing on that same run is the CLI SAPI — `OpenBuild:
 * OpenRegister AppHost\Bootstrap is not autoloadable` was logged 3 times, once
 * per `occ` call in ci-seed.sh, with OpenRegister installed and enabled
 * throughout. The CLI/web divergence has not been explained; see REQ-OBS-006's
 * Notes. So treat this file as a REGRESSION GUARD on the aliases, not as
 * evidence that the prelude changed these two responses. The prelude's own
 * contract is asserted where it can be: tests/Unit/AppInfo/OpenRegisterAutoloaderTest.php.
 *
 * A dev instance where any alphabetically-earlier app already pulls
 * OpenRegister's autoloader in (e.g. `doriath`, which carries the same prelude)
 * registers the PSR-4 prefix process-wide and masks the load order entirely.
 */
// @e2e openspec/specs/settings-and-observability/spec.md#the-apphost-bound-observability-routes-actually-dispatch
test.describe('ADR-040 AppHost adoption', () => {
	test('serves /api/health from the AppHost generic controller', async ({
		request,
	}) => {
		// ADR-006: health is public — no auth, no CSRF.
		const response = await request.get('/index.php/apps/openbuild/api/health')

		expect(
			response.status(),
			'health#index is bound only by Bootstrap::register(). A 500 here means '
				+ 'Bootstrap::register() did not run, i.e. OCA\\OpenRegister\\ was not '
				+ "autoloadable during openbuild's register() — the ADR-040 prelude is "
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

	test('serves /api/metrics from the AppHost generic controller', async ({
		request,
	}) => {
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
