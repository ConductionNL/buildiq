---
retrofit: true
---

# settings-and-observability Specification

**OpenSpec changes**: [harden-xss-dos-csrf](../../changes/harden-xss-dos-csrf/)

## Purpose

OpenBuild exposes a small administrative surface that lets the frontend
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
`IAppConfig` for the `openbuild` app with an empty-string default,
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
- **THEN** the `register` app-config value is set to `openbuild`
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
OpenBuild has its registers and schemas provisioned without a manual
admin action. `getName()` SHALL return a human-readable step name and
`run(IOutput $output)` SHALL execute the import and surface its outcome
through the repair output / logger.

@e2e exclude pure-backend repair-step: PHPUnit tests verify IRepairStep invokes reloadConfiguration(); no Playwright-testable UI surface for OCC install/upgrade hooks

#### Scenario: Install triggers import

- **WHEN** the OpenBuild app is installed or upgraded
- **THEN** the `InitializeSettings` repair step runs
  `reloadConfiguration()` to provision registers

### REQ-OBS-005: Liveness and metrics probe endpoints

Both probe endpoints are served by OpenRegister's AppHost observability
engine (ADR-040 adoption, ADR-006 contract), driven by the declarative
`observability` block of `src/manifest.json`. OpenBuild no longer owns a
concrete `HealthController` / `MetricsController`; `AppInfo\Application`
binds the leaf controller names to `GenericHealthController` /
`GenericMetricsController` via lazy service factories, so the binding
survives the app load order in which OpenRegister is not yet autoloadable.

`health#index` SHALL return JSON `{status, app, version, checks}` where
`status` is `ok` when every manifest health check passes, with the HTTP
code chosen by the manifest's `statusCodePolicy` (`adr006`).

`metrics#index` SHALL return a Prometheus text exposition (format 0.0.4,
`Content-Type: text/plain; version=0.0.4`) rendering each gauge declared
in `observability.metrics`, with HTTP 200 for an authorised caller.

#### Scenario: Health probe

- **WHEN** an authenticated caller hits the health endpoint
- **THEN** the response is JSON with `status: "ok"` and HTTP 200

#### Scenario: Metrics probe

- **WHEN** an authenticated caller hits the metrics endpoint
- **THEN** the response is a `text/plain` Prometheus exposition with HTTP
  200, containing `# HELP` / `# TYPE` lines for `export_jobs_total`,
  `applications_total` and `application_versions_total`

**Notes:** This requirement previously described OpenBuild's own probe
controllers returning a placeholder `{"metrics":[]}` JSON payload. That
implementation was removed by the ADR-040 AppHost adoption but the
requirement text was not updated with it, leaving the spec — and the e2e
tests written against it — asserting a contract the code had stopped
serving. The text above now tracks the AppHost engine's actual behaviour.
