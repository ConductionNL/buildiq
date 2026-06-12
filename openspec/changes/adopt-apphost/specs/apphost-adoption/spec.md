---
status: proposed
---

# OpenBuild AppHost Adoption

## Purpose

OpenBuild's `/api/health` and `/api/metrics` run on the OpenRegister AppHost declarative engine — replacing a fake auth-gated health check (ADR-006 violation) and an empty metrics placeholder with real, contract-correct endpoints — and the fleet-standard boilerplate controllers/services are deleted in favour of the AppHost generics.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## Requirements

### Requirement: Public Real Health Endpoint

OpenBuild SHALL serve `GET /apps/openbuild/api/health` through the AppHost `GenericHealthController` from manifest descriptors `{type: "database"}` (critical) and `{type: "orAvailable", severity: "degraded"}`, publicly accessible per ADR-006, replacing the session-check-only controller.

#### Scenario: Anonymous probe on a healthy instance

- **GIVEN** a healthy instance with OpenRegister enabled
- **WHEN** `GET /apps/openbuild/api/health` is called with no session
- **THEN** the response MUST be HTTP 200 with `status: "ok"`, `checks.database = "ok"`, and `checks.openregister = "ok"` in the standard AppHost shape — never 401
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: OpenRegister unavailable degrades but does not fail

- **GIVEN** an instance where OpenRegister is disabled
- **WHEN** `GET /apps/openbuild/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status: "degraded"` and `checks.openregister` reporting failure with a generic message (no exception details leaked)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Database failure returns 503

- **GIVEN** an instance whose database check fails
- **WHEN** `GET /apps/openbuild/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status: "error"` per the `adr006` status-code policy
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Real Declarative Metrics

OpenBuild SHALL serve `GET /apps/openbuild/api/metrics` through the AppHost `GenericMetricsController` as admin-only Prometheus text 0.0.4, with the implicit `openbuild_info`/`openbuild_up` plus three declared `objectCount` descriptors pinned to register slug `openbuild` and schema slugs `export-job`, `application`, and `applicationVersion` (camelCase, exactly as in `lib/Settings/openbuild_register.json`), replacing the empty-JSON placeholder. The promised icon-cache-hits metric SHALL NOT be declared while no persisted backing data exists.

#### Scenario: Admin scrape returns real samples

- **GIVEN** a seeded instance with applications, application versions, and export jobs in the `openbuild` register
- **WHEN** an admin calls `GET /apps/openbuild/api/metrics`
- **THEN** the response MUST be Prometheus text 0.0.4 containing `openbuild_info`, `openbuild_up`, `openbuild_export_jobs_total{status}` grouped over queued/running/succeeded/failed, `openbuild_applications_total`, and `openbuild_application_versions_total{status}` grouped over draft/published/archived, with values matching direct object counts
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Non-admin scrape is denied

- **GIVEN** an authenticated non-admin user
- **WHEN** the user calls `GET /apps/openbuild/api/metrics`
- **THEN** the request MUST be rejected by the engine-owned admin posture (no `NoAdminRequired`)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Deletion With Behavioural Parity

OpenBuild SHALL delete `HealthController`, `MetricsController`, `DashboardController`, `PreferencesController`, `SettingsController`, `SettingsService`, and `DeepLinkRegistrationListener`, SHALL shrink `AdminSettings`, `SettingsSection`, and `Repair/InitializeSettings` to one-line stubs extending the AppHost generics, and SHALL wire the replacements via `AppHost\Bootstrap::register()` and `AppHost\Routes::standard($extra)` — with route names, URLs, verbs, settings/preferences response shapes, stored preference keys, and `templates/index.php` chunk-loading order identical to pre-adoption, and with all domain code (AppNavigationService, ProductionVersionGuardListener, ApplicationVersionOwnerGuard, domain repair steps, domain controllers/services) untouched.

#### Scenario: Settings and preferences parity after adoption

- **GIVEN** a deployed instance with a configured `register` appconfig value and stored user preferences
- **WHEN** `GET /api/settings`, `POST /api/settings/load`, and `GET/PUT /api/preferences/{key}` are called after adoption
- **THEN** the responses MUST match the pre-adoption baseline fixtures and previously stored preference keys MUST keep resolving
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: SPA still loads through the generic dashboard controller

- **GIVEN** the boilerplate controllers are deleted and routes come from `Routes::standard($extra)`
- **WHEN** a user opens the OpenBuild app root and deep-links into an app path
- **THEN** the SPA page and catch-all MUST render via the generic dashboard controller with the chunk-loading order preserved, and every domain `/api/...` route MUST still win over the catch-all (catch-all remains last)

#### Scenario: Existing test suites stay green minus the documented quarantine

- **GIVEN** the adoption is complete
- **WHEN** the existing OpenBuild Newman collections and Playwright e2e suite run
- **THEN** all MUST pass with the issue #41 nested-routing quarantine list unchanged (neither fixed nor widened by this change)

### Requirement: Deep Links Declared In Manifest

OpenBuild SHALL move its deep-link patterns from the hardcoded `DeepLinkRegistrationListener` into the manifest `deepLinks` block consumed by the AppHost generic listener, with the pattern set migrated verbatim.

#### Scenario: Deep-link registration survives the listener deletion

- **GIVEN** the local `DeepLinkRegistrationListener` is deleted and the patterns live in `src/manifest.json`
- **WHEN** OpenRegister dispatches `DeepLinkRegistrationEvent`
- **THEN** the same deep-link patterns as pre-adoption MUST be registered with the unified search provider
- @e2e exclude backend event wiring — asserted by PHPUnit, no UI surface
