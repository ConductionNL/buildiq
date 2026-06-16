---
kind: code
---

# Proposal: OpenBuild Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

OpenBuild's observability endpoints are fake, and one of them violates ADR-006:

- **Health is a lie.** `lib/Controller/HealthController.php` checks *nothing* — it returns `{"status":"ok"}` whenever a user session exists and 401 otherwise. A container probe or load balancer hitting `/apps/openbuild/api/health` anonymously gets **401**, which is a direct ADR-006 violation (health MUST be public), and an authenticated caller gets "ok" even when the database is down or OpenRegister (which OpenBuild hard-requires) is disabled.
- **Metrics are an empty placeholder.** `lib/Controller/MetricsController.php` returns `{"metrics": []}` as JSON (not Prometheus text), behind auth, with a docstring promising future "export-job throughput, icon cache hits, and navigation-entry counts" that were never built.
- Beyond observability, OpenBuild carries ~1,170 lines of fleet-standard boilerplate that the AppHost generics now own: `DashboardController` (128), `PreferencesController` (153), `SettingsController` (160), `SettingsService` (312), `AdminSettings` (89), `SettingsSection` (89), `Repair/InitializeSettings` (104), `Listener/DeepLinkRegistrationListener` (67), plus the corresponding `Application.php` registration code and the boilerplate half of `appinfo/routes.php`. Every fleet-wide fix to this plumbing currently needs an OpenBuild-specific PR.

## Proposed Change

Adopt the OpenRegister AppHost (per `apphost-observability-engine` and `apphost-boilerplate-controllers`): declare observability in `src/manifest.json`, wire the generic controllers via `AppHost\Bootstrap::register()` + `AppHost\Routes::standard()`, and delete the local boilerplate copies.

### 1. Real, public health (replaces the fake)

`observability.health.checks` in `src/manifest.json`:

- `{ "id": "database", "type": "database" }` — real `SELECT 1` (severity critical, default).
- `{ "id": "openregister", "type": "orAvailable", "severity": "degraded" }` — OpenBuild is OR-dependent; a missing OR is degraded, reported honestly.

The engine-owned `GenericHealthController` is `#[PublicPage]` — adoption *fixes* the ADR-006 auth violation by construction; leaf apps can no longer drift it. Probe URL stays `/apps/openbuild/api/health`.

### 2. Real metrics descriptors (replaces the placeholder, delivers two of the three promised metrics)

Implicit `openbuild_info` / `openbuild_up` come free. Declared descriptors, with register/schema slugs **pinned explicitly** to the values in `lib/Settings/openbuild_register.json` (register slug `openbuild`; note `applicationVersion` is camelCase while the other schema slugs are kebab-case — and the dev env once accumulated a duplicate empty `openregister_registers` slug row for `openbuild`, so slug resolution must be exact and verified against a single register row):

| Metric | Source | Delivers promised |
|---|---|---|
| `openbuild_export_jobs_total{status}` | `objectCount`, register `openbuild`, schema `export-job`, `groupBy: ["status"]` (queued/running/succeeded/failed) | export-job throughput |
| `openbuild_applications_total` | `objectCount`, register `openbuild`, schema `application` | navigation-entry counts (one top-bar nav entry per published Application) |
| `openbuild_application_versions_total{status}` | `objectCount`, register `openbuild`, schema `applicationVersion`, `groupBy: ["status"]` (draft/published/archived) | navigation-entry counts (published = nav-visible) |

The third promised metric — **icon cache hits** — has no persisted backing data (`IconService` keeps no counter in appconfig or any table), so it is *not* declared. If it is ever wanted, the path is an `appConfig` counter descriptor or an `IMetricsProvider`; inventing a fake gauge now would repeat the placeholder mistake. Metrics endpoint becomes admin-only Prometheus text 0.0.4 (engine-owned), URL stays `/apps/openbuild/api/metrics`.

### 3. Boilerplate deletion + Bootstrap wiring

**Deleted outright** (replaced by AppHost generics via container aliases):

- `lib/Controller/HealthController.php`
- `lib/Controller/MetricsController.php`
- `lib/Controller/DashboardController.php`
- `lib/Controller/PreferencesController.php`
- `lib/Controller/SettingsController.php`
- `lib/Service/SettingsService.php` (→ `AppHostSettingsService`; the `register` appconfig key keeps resolving — behavioural parity rule 3)
- `lib/Listener/DeepLinkRegistrationListener.php` (→ generic listener; deep-link patterns move to the manifest `deepLinks` block)

**Shrunk to one-line stubs** (NC demands a concrete class in the app namespace — the documented floor):

- `lib/Settings/AdminSettings.php` → `extends GenericAdminSettings` (keeps the IDelegatedSettings #299 pattern)
- `lib/Sections/SettingsSection.php` → `extends GenericSettingsSection`
- `lib/Repair/InitializeSettings.php` → `extends GenericInitializeSettings` (repair step stays a repair step, NOT a migration — install-order constraint; ADR-037 `register.d/` fragment merging is generic-engine behaviour)

**Kept — domain, not boilerplate** (audited per file):

- `lib/Service/AppNavigationService.php` (379 lines) — per-published-app top-bar navigation (REQ-OBNAV-001) is OpenBuild's product, not plumbing.
- `lib/Listener/ProductionVersionGuardListener.php`, `lib/Lifecycle/ApplicationVersionOwnerGuard.php` — cross-row integrity + RBAC lifecycle guards.
- `lib/Repair/MigrateToVersionedModel.php`, `PopulateApplicationPermissions.php`, `SeedApplicationTemplates.php` — domain repair steps.
- All domain controllers/services (Applications, ApplicationVersions, ApplicationCreation, Insights, VersionPromotion, Icon, Exports, Rules, MCP provider, …).

**`Application.php`**: boilerplate registrations replaced by one `Bootstrap::register($context, self::APP_ID)` call; domain registrations (ProductionVersionGuard listeners, MCP provider alias, ApplicationVersionOwnerGuard factory, AppNavigationService boot) stay.

**`appinfo/routes.php`**: becomes `\OCA\OpenRegister\AppHost\Routes::standard($extra)` where `$extra` is OpenBuild's large domain route set (wizard, listMine, manifest, versions CRUD + diff, insights, promotion, icons, exports, rules). **Binding ordering constraint**: all `$extra` routes MUST be merged *before* the SPA catch-all `dashboard#catchAll` (`/{path}`, `.+`), exactly as today — Symfony is order-sensitive and the catch-all would otherwise shadow every `/api/...` route.

## Impact

- **Deleted**: ~1,050 lines of boilerplate PHP; ~150 more shrunk to stubs. `src/manifest.json` gains `observability` (+ `deepLinks`); `Application.php` and `routes.php` rewired; route names/URLs/verbs unchanged (info.xml navigation keeps `dashboard#page`).
- **Behaviour deltas (all intentional, all fixes)**: health goes public + real (was 401-or-fake-ok); metrics go admin-only Prometheus text with real samples (was authed empty JSON). These are the *only* endpoint-contract changes; everything else is bit-parity per the boilerplate design's parity rules.
- **Verification**: OR's AppHost Newman contract collection runs against OpenBuild; the existing 14 OpenBuild Newman collections and the e2e suite stay green — minus the documented issue #41 nested-routing quarantine, which this change neither fixes nor widens.
- **Risk**: behavioural drift between the old local copies and the generics (preferences keys, settings load/reload, chunk-loading order in `templates/index.php`) — mitigated by endpoint-level parity checks before deletion, per the boilerplate change's binding parity rules.

## Implementation deviations (verified engine reality — see design.md)

Three of the planned outright-deletions were NOT possible against OpenRegister
`development` and were kept bespoke (re-aliased to the concrete classes after
`Bootstrap::register()`), per the gate-27 verification:

1. **`PreferencesController`** — kept. There is no `GenericPreferencesController`
   in OR `development`; the Bootstrap alias points at a missing class, so
   deleting the bespoke controller would 500 the preferences routes.
2. **`SettingsController` + `SettingsService`** — kept. The generic
   `AppHostSettingsService::loadConfiguration()` calls
   `ConfigurationService::importFromApp()` with a stale 2-arg signature (OR
   `development` requires 4) and skips the ADR-037 `register.d/` fragment merge
   OpenBuild relies on. `Repair/InitializeSettings` stays bespoke for the same
   dependency.
3. **`DashboardController`** — kept. It publishes `currentUserGroups` to
   `IInitialState` (REQ-OBR-009); the generic dashboard controller does not.

Adopted as planned: observability (Health + Metrics → engine generics + manifest
block), `DeepLinkRegistrationListener` (→ manifest `deepLinks`), `AdminSettings`
+ `SettingsSection` (→ one-line generic stubs), `Bootstrap::register()` +
`Routes::standard($extra)`. The ADR-006 health-public fix and admin-only
Prometheus metrics ship as designed.

## Dependencies

Chained on OpenRegister: `apphost-observability-engine` (engine + generic health/metrics controllers + Newman contract collection), `apphost-boilerplate-controllers` (generics, `Bootstrap`, `Routes::standard()`). ADR-040 (hydra) defines the manifest `observability` contract; ADR-006 is the endpoint contract this change finally satisfies.
