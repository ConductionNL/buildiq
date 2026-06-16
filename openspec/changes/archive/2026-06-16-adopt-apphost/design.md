# Design: OpenBuild Adopts OpenRegister AppHost

## Engine reality (verified against OpenRegister `development`, 2026-06-16)

This change was implemented after fresh-cloning OpenRegister `development` and
verifying every generic class + method the proposal assumed (the gate-27
check). The full AppHost engine is present
(`OCA\OpenRegister\AppHost\Bootstrap`, `Routes`, `GenericDashboardController`,
`GenericSettingsController`, `GenericHealthController`,
`GenericMetricsController`, the observability engine, repair steps, admin
settings + section, deep-link listener). Three deviations from the proposal's
"delete everything" plan were forced by the actual engine state:

### Deviation 1 — `GenericPreferencesController` does not exist

`Bootstrap::registerControllers()` aliases the leaf `PreferencesController` at
`OCA\OpenRegister\AppHost\Controller\GenericPreferencesController`, but **that
class is not present in OpenRegister `development`** (only Dashboard, Settings,
Health, Metrics generics ship). Deleting OpenBuild's bespoke
`PreferencesController` and relying on the Bootstrap alias would 500 the
`preferences#getPreference` / `preferences#setPreference` routes (the closure
cannot autoload the missing class). **Decision:** keep OpenBuild's
`PreferencesController`; re-register it as a concrete service after
`Bootstrap::register()` so the leaf class name resolves to the real class
(last `registerService` wins in NC's DI container).

### Deviation 2 — generic settings service is incompatible with OR `development`

`AppHostSettingsService::loadConfiguration()` calls
`ConfigurationService::importFromApp(appId, force)` — two named args. OR
`development`'s actual signature is
`importFromApp(string $appId, array $data, string $version, bool $force=false)`
— **four parameters, two of them required**. The generic would throw
`ArgumentCountError` at runtime. The generic also performs **no ADR-037
`register.d/` fragment merge**, which OpenBuild depends on
(`lib/Settings/register.d/10-business-rules.json`). **Decision:** keep
OpenBuild's `SettingsService` (it builds the merged `data` + fragment-aware
`version` and calls `importFromApp` with the correct 4-arg signature) and its
`SettingsController` (body-level admin guards on `create()`/`load()`).
`Repair/InitializeSettings` stays bespoke too because it depends on that
SettingsService for the fragment merge.

### Deviation 3 — dashboard initial-state is domain behaviour

OpenBuild's `DashboardController::page()` publishes `currentUserGroups` to
`IInitialState` (REQ-OBR-009) — the frontend's `useRole(application)`
composable derives per-Application roles from it. The generic dashboard
controller does not. **Decision:** keep OpenBuild's `DashboardController`;
re-alias it to the concrete class after `Bootstrap::register()`.

## What was adopted

- **Observability (full):** `HealthController` + `MetricsController` deleted;
  the engine's `GenericHealthController` (public, ADR-006) + 
  `GenericMetricsController` (admin Prometheus) serve `/api/health` +
  `/api/metrics`, driven by the new `observability` block in
  `src/manifest.json`. This fixes the pre-existing ADR-006 violation
  (auth-gated health → public).
- **Deep links:** `DeepLinkRegistrationListener` deleted; the patterns moved
  to the manifest `deepLinks` block, consumed by the engine's generic listener
  registered by `Bootstrap`.
- **Admin settings + section:** `AdminSettings` and `SettingsSection` shrunk to
  one-line stubs extending `GenericAdminSettings` (IDelegatedSettings #299) /
  `GenericSettingsSection`.
- **Bootstrap + Routes:** `Application::register()` calls
  `Bootstrap::register($context, 'openbuild', ['observability' => true])`;
  `appinfo/routes.php` is `Routes::standard($extra)` with the domain routes.

## Parity

| Endpoint | Before | After | Parity |
|---|---|---|---|
| Routes (names/URLs/verbs) | bespoke table | `Routes::standard` + `$extra` | identical (8 canonical + 19 domain + catch-all last) |
| `settings#index/create/load` | bespoke | kept bespoke (re-aliased) | byte-identical |
| `preferences#get/set` | bespoke | kept bespoke (re-aliased) | byte-identical |
| dashboard page HTML | bespoke | kept bespoke (re-aliased) | byte-identical |
| `GET /api/health` | 401 anon / `{status:ok}` authed | **public** `{status,app,version,checks}` | intentional ADR-006 fix |
| `GET /api/metrics` | authed JSON `{metrics:[]}` | **admin** Prometheus 0.0.4 + 3 descriptors | intentional ADR-040 fix |

## Schema lag (gate-22 / vitest manifest validator)

The canonical `@conduction/nextcloud-vue` app-manifest schema is
`additionalProperties: false` at the root and does not yet describe the
engine-owned `observability` / `deepLinks` blocks. `scripts/check-manifest.js`
and `tests/composables/manifestRoundTrip.spec.js` strip those keys before
renderer-shape validation; OR's `ObservabilityManifest` parser validates their
own shape server-side. An upstream nextcloud-vue PR should add the blocks to
the schema; until then the strip is the documented bridge.

## LOC impact

Deleted: `HealthController` (78), `MetricsController` (79),
`DeepLinkRegistrationListener` (67), `HealthControllerTest`,
`MetricsControllerTest`. `AdminSettings` 89→~45, `SettingsSection` 89→~45
(both now logic-free stubs). Net runtime PHP removed ~224 lines + 2 obsolete
test files; behaviour for the kept-bespoke trio is unchanged.
