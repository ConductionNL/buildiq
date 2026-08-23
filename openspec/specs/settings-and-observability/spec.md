---
retrofit: true
---

# settings-and-observability Specification

**OpenSpec changes**: [harden-xss-dos-csrf](../../changes/harden-xss-dos-csrf/)

## Purpose

Buildiq exposes a small administrative surface that lets the frontend
read and write app-level configuration (the OpenRegister register key),
discover whether OpenRegister is installed, and learn whether the
current user is an admin. The same surface re-imports the bundled
`openbuild_register.json` configuration into OpenRegister — both
lazily (idempotent first-boot import) and forcibly (admin "Reload"
action / repair step). Two lightweight probe endpoints expose liveness
and a placeholder metrics payload for container orchestrators and
load balancers.

This capability is observed behaviour of the `SettingsService`,
`SettingsController`, `InitializeSettings` repair step, and the
`HealthController` / `MetricsController` probe endpoints.

## Requirements

### REQ-OBS-001: Settings read returns config plus environment metadata

`SettingsService::getSettings()` SHALL return a flat array containing
every key in `CONFIG_KEYS` (currently `register`), read from
`IAppConfig` for the `buildiq` app with an empty-string default,
merged with two computed metadata fields: `openregisters` (true when
the `openregister` app is installed, via
`isOpenRegisterAvailable()`) and `isAdmin` (true when a user is signed
in and is a member of the admin group). `SettingsController::index()`
SHALL return this array as a `JSONResponse`, rejecting unauthenticated
callers with HTTP 401.

#### Scenario: Authenticated read

- **WHEN** a signed-in user calls the settings index endpoint
- **THEN** the response contains the `register` config value plus
  `openregisters` and `isAdmin` booleans
- **AND** the HTTP status is 200

#### Scenario: Unauthenticated read

- **WHEN** an unauthenticated request hits the settings index endpoint
- **THEN** the response is `{"error":"Unauthenticated."}` with HTTP 401

### REQ-OBS-002: Settings update persists only whitelisted keys

`SettingsService::updateSettings(array $data)` SHALL iterate over
`CONFIG_KEYS` and persist via `IAppConfig::setValueString` only those
keys present in the supplied data — any other input keys are ignored.
It SHALL return the freshly re-read settings array (REQ-OBS-001 shape).
`SettingsController::create()` SHALL read the request params, call
`updateSettings`, and return `{"success":true,"config":<settings>}`,
rejecting unauthenticated callers with HTTP 401.

#### Scenario: Persist a known key

- **WHEN** an authenticated caller POSTs `{"register":"openbuild"}`
- **THEN** the `register` app-config value is set to `buildiq`
- **AND** the response echoes `success:true` and the updated config

#### Scenario: Unknown key ignored

- **WHEN** the payload contains a key not in `CONFIG_KEYS`
- **THEN** that key is not written to app config

### REQ-OBS-003: Configuration import is idempotent and force-reloadable

`SettingsService::loadConfiguration()` SHALL import the bundled
`lib/Settings/openbuild_register.json` into OpenRegister via
`ConfigurationService::importFromApp` with `force: false`, relying on
OR to short-circuit an already-imported configuration.
`reloadConfiguration()` SHALL perform the same import with
`force: true`. The shared private `doLoadConfiguration(bool $force)`
SHALL return `{success:false,message}` when OpenRegister is absent, the
config file is missing, unreadable, or unparseable, and
`{success:true,message,version}` on a non-empty import result — the
version being taken from the import result or falling back to the
config file's `info.version`. Any thrown error SHALL be logged and
returned as `{success:false,message:<error>}`.
`SettingsController::load()` SHALL invoke `reloadConfiguration()` and
return its result, rejecting unauthenticated callers with HTTP 401.

#### Scenario: First import succeeds

- **WHEN** `loadConfiguration()` runs with OpenRegister installed and a
  valid config file
- **THEN** `importFromApp` is called with `force:false`
- **AND** a `{success:true, version}` result is returned

#### Scenario: OpenRegister absent

- **WHEN** the import runs and OpenRegister is not installed
- **THEN** the result is `{success:false}` with an explanatory message
- **AND** no import is attempted

#### Scenario: Forced reload

- **WHEN** an admin triggers the reload endpoint
- **THEN** `importFromApp` is called with `force:true`

### REQ-OBS-004: Repair step bootstraps configuration on install/upgrade

The `InitializeSettings` repair step SHALL run during app
install/upgrade, calling `SettingsService::reloadConfiguration()` to
force-import the bundled register configuration so a freshly installed
Buildiq has its registers and schemas provisioned without a manual
admin action. `getName()` SHALL return a human-readable step name and
`run(IOutput $output)` SHALL execute the import and surface its outcome
through the repair output / logger.

@e2e exclude pure-backend repair-step: PHPUnit tests verify IRepairStep invokes reloadConfiguration(); no Playwright-testable UI surface for OCC install/upgrade hooks

#### Scenario: Install triggers import

- **WHEN** the Buildiq app is installed or upgraded
- **THEN** the `InitializeSettings` repair step runs
  `reloadConfiguration()` to provision registers

### REQ-OBS-005: Liveness and metrics probe endpoints

Both probe endpoints are served by OpenRegister's AppHost observability
engine (ADR-040 adoption, ADR-006 contract), driven by the declarative
`observability` block of `src/manifest.json`. Buildiq no longer owns a
concrete `HealthController` / `MetricsController`; `AppInfo\Application`
binds the leaf controller names to `GenericHealthController` /
`GenericMetricsController` via lazy service factories. Those factories are
themselves registered by `Bootstrap::register()`, which only runs once
OpenRegister's PSR-4 prefix is on the autoloader — see REQ-OBS-006.

`health#index` SHALL return JSON `{status, app, version, checks}` where
`status` is `ok` when every manifest health check passes, with the HTTP
code chosen by the manifest's `statusCodePolicy` (`adr006`).

`metrics#index` SHALL return a Prometheus text exposition (format 0.0.4,
`Content-Type: text/plain; version=0.0.4`) rendering each gauge declared
in `observability.metrics`, with HTTP 200 for an authorised caller. The
engine namespaces every series with the app id, so the manifest's
`applications_total` is exposed as `buildiq_applications_total`, and it
adds the implicit `buildiq_info` and `buildiq_up` series.

#### Scenario: Health probe

- **WHEN** an authenticated caller hits the health endpoint
- **THEN** the response is JSON with `status: "ok"` and HTTP 200

#### Scenario: Metrics probe

- **WHEN** an authenticated caller hits the metrics endpoint
- **THEN** the response is a `text/plain` Prometheus exposition with HTTP
  200, containing `# HELP` / `# TYPE` lines for `buildiq_export_jobs_total`,
  `buildiq_applications_total` and `buildiq_application_versions_total`,
  plus the implicit `buildiq_up` series

**Notes:** This requirement previously described Buildiq's own probe
controllers returning a placeholder `{"metrics":[]}` JSON payload. That
implementation was removed by the ADR-040 AppHost adoption but the
requirement text was not updated with it, leaving the spec — and the e2e
tests written against it — asserting a contract the code had stopped
serving. The text above now tracks the AppHost engine's actual behaviour.

### REQ-OBS-006: The AppHost adoption registers OpenRegister's autoloader first

`AppInfo\Application::register()` SHALL put OpenRegister's PSR-4 prefix on
the composer autoloader — via `OpenRegisterAutoloader::register()`, which
calls `OC_App::registerAutoloading('openregister', …)` — BEFORE it
references any `OCA\OpenRegister\AppHost\…` name, including the
`class_exists()` guard around `Bootstrap::register()`.

Nextcloud registers apps in sorted order: `OC_App::getEnabledApps()` does
`sort($apps)` and `Coordinator::registerApps()` walks that list calling
`OC_App::registerAutoloading($appId, $path)` and then `$app->register()`
for one app at a time. `buildiq` sorts before `openregister`, so every
`OCA\OpenRegister\…` name is unresolvable inside Buildiq's `register()`
on a perfectly healthy instance with OpenRegister enabled.

`OC_App::registerAutoloading()` is idempotent and touches only the
autoloader. `IAppManager::loadApp('openregister')` MUST NOT be used
instead: it marks OpenRegister loaded and calls `Coordinator::bootApp()`,
booting OpenRegister before its own `register()` has run.

The prelude MUST NOT throw under any instance state — an exception escaping
it would abort the whole of `register()`, which is a strictly worse failure
than the one it exists to prevent.

#### Scenario: The AppHost-bound observability routes actually dispatch

- **GIVEN** an instance with OpenRegister enabled, and Buildiq's
  `register()` running at its sorted position ahead of `openregister`
- **WHEN** `GET /apps/buildiq/api/health` is called
- **THEN** the response MUST be HTTP 200 with the engine's canonical
  `{status, app, version, checks}` shape and `app = "buildiq"` — which is
  only possible if `class_exists(Bootstrap::class)` answered `true` and
  `Bootstrap::register()` ran, because Buildiq ships no concrete
  `HealthController` and `health#index` exists ONLY as a Bootstrap DI alias
- **AND** `GET /apps/buildiq/api/metrics` MUST NOT return a 5xx: an
  anonymous caller must be turned away by the auth middleware, which only
  runs once the route resolves to a bound controller
- **AND** the log line `OpenRegister AppHost\Bootstrap is not autoloadable`
  MUST NOT be emitted

The absent-OpenRegister path has no scenario of its own on purpose: it is not
reachable from a browser or an HTTP client, because an instance without
OpenRegister cannot serve Buildiq's OpenRegister-backed surface at all. It is
asserted directly at the unit level, in
`tests/Unit/AppInfo/OpenRegisterAutoloaderTest.php`, which runs the prelude in
an environment where `\OCP\Server::get()` resolves nothing and requires that
control still returns to the caller.

**Notes:** Measured 2026-08-06 against the E2E workflow, and the measurement
is narrower than the load-order argument alone predicts — both halves are
recorded here because the difference is not yet explained.

Before the prelude (run 31081906401, job 92555103075): `Buildiq:
OpenRegister AppHost\Bootstrap is not autoloadable` was logged **3 times**,
once for each `occ` invocation in `tests/e2e/ci-seed.sh`, while
`lib/AppHost/Bootstrap.php` existed on OpenRegister the whole time. So under
the CLI SAPI the guard was answering `false` on a healthy instance and the
generic plumbing was silently skipped — for every `occ` command, background
job and repair step.

In the *same* run, `GET /api/health` returned 200 with `status: ok` and
`GET /api/metrics` rendered the manifest's gauges. Buildiq ships no
concrete `HealthController`/`MetricsController`, and `health#index` exists
only as a `Bootstrap::register()` DI alias — so under the **web** SAPI the
guard was answering `true` and the plumbing *was* registered. The mechanism
behind the CLI/web divergence has not been established, so no claim is made
here about web requests being degraded; what is claimed, and measured, is
that the CLI path was.

After the prelude (run 31085597692): the log line appears **0 times**, and
both observability routes still answer 200.
