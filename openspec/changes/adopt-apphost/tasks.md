# Tasks: OpenBuild Adopts OpenRegister AppHost

> **Implementation note (2026-06-16):** verified against a fresh OpenRegister
> `development` clone (gate-27). Three planned deletions were kept bespoke +
> re-aliased because the engine generics are missing/incompatible on
> `development`: `PreferencesController` (no `GenericPreferencesController`),
> `SettingsController`+`SettingsService`+`Repair/InitializeSettings` (generic
> `importFromApp` signature stale + no ADR-037 `register.d/` merge),
> `DashboardController` (REQ-OBR-009 `currentUserGroups`). See design.md.
> Observability + deep-links + AdminSettings/SettingsSection adopted as planned.

## 0. Baseline

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl` (anon + authed) `/apps/openbuild/api/health` and `/apps/openbuild/api/metrics`; store responses as fixtures. Expected today: health 401 anon / fake `{"status":"ok"}` authed; metrics `{"metrics":[]}` JSON. These fixtures document the *broken* contract being replaced — the new output is intentionally different (the only non-parity endpoints in this change).
- [ ] 0.2 Capture parity baselines for the endpoints that MUST NOT change: `GET /api/settings`, `POST /api/settings/load`, `GET/PUT /api/preferences/{key}`, `GET /` (dashboard page HTML incl. chunk-loading order), plus the route list from `occ` route dump.
- [ ] 0.3 Verify exactly ONE `openregister_registers` row resolves for slug `openbuild` on the dev instance (the env historically accumulated a duplicate empty slug row — delete the dup + its magic tables first if present, else metric descriptors resolve against the wrong register).

## 1. Manifest observability block

- [ ] 1.1 Add to `src/manifest.json`:
  ```jsonc
  "observability": {
    "health": {
      "checks": [
        { "id": "database",     "type": "database" },
        { "id": "openregister", "type": "orAvailable", "severity": "degraded" }
      ]
    },
    "metrics": [
      { "name": "export_jobs_total", "type": "gauge", "help": "Export jobs by lifecycle status",
        "source": { "kind": "objectCount", "register": "openbuild", "schema": "export-job", "groupBy": ["status"] } },
      { "name": "applications_total", "type": "gauge", "help": "Virtual applications (one nav entry per published app)",
        "source": { "kind": "objectCount", "register": "openbuild", "schema": "application" } },
      { "name": "application_versions_total", "type": "gauge", "help": "Application versions by lifecycle status",
        "source": { "kind": "objectCount", "register": "openbuild", "schema": "applicationVersion", "groupBy": ["status"] } }
    ]
  }
  ```
  Slugs are pinned to `lib/Settings/openbuild_register.json`: register `openbuild`; schemas `export-job`, `application`, `applicationVersion` (camelCase — do NOT "normalise" it to kebab-case).
- [ ] 1.2 Add the `deepLinks` block to `src/manifest.json` carrying the patterns currently hardcoded in `lib/Listener/DeepLinkRegistrationListener.php` (verbatim migration, no pattern changes).
- [ ] 1.3 Validate via ManifestService diagnostics: no errors, no unknown-kind warnings. Do NOT declare an icon-cache-hits metric — no backing data exists (see proposal).

## 2. Bootstrap/Routes wiring + deletions

- [ ] 2.1 `lib/AppInfo/Application.php`: add `\OCA\OpenRegister\AppHost\Bootstrap::register($context, self::APP_ID)`; remove the registrations the Bootstrap now owns (DeepLinkRegistrationListener event wiring). KEEP domain registrations: ProductionVersionGuardListener (ObjectCreating/ObjectUpdating), the MCP `IMcpToolProvider::openbuild` alias, the ApplicationVersionOwnerGuard factory, and the `boot()` AppNavigationService nav registration.
- [ ] 2.2 `appinfo/routes.php`: `return \OCA\OpenRegister\AppHost\Routes::standard($extra);` with `$extra` = the full domain route set (applicationCreation#wizard, applications#listMine/createFromTemplate/getManifest/diffVersions, applicationVersions CRUD, applicationInsights#getInsights, versionPromotion#promote, icon#iconLight/iconDark, exports#submit/download, rules#evaluate/schema/testAll). Assert the merged order keeps today's specific-first ordering and the SPA catch-all `dashboard#catchAll` LAST (it would otherwise shadow every `/api/...` route).
- [ ] 2.3 Delete: `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`, `lib/Controller/DashboardController.php`, `lib/Controller/PreferencesController.php`, `lib/Controller/SettingsController.php`, `lib/Service/SettingsService.php`, `lib/Listener/DeepLinkRegistrationListener.php`.
- [ ] 2.4 Shrink to one-line generic-extending stubs (concrete app-namespace class required by NC): `lib/Settings/AdminSettings.php` (`extends GenericAdminSettings`, IDelegatedSettings #299 pattern preserved), `lib/Sections/SettingsSection.php`, `lib/Repair/InitializeSettings.php` (`extends GenericInitializeSettings`; stays a repair step in info.xml `<repair-steps>`, never a migration; ADR-037 `register.d/` fragment merge is engine behaviour — confirm `10-business-rules.json` still imports).
- [ ] 2.5 KEEP untouched (domain): `AppNavigationService`, `ProductionVersionGuardListener`, `ApplicationVersionOwnerGuard`, `MigrateToVersionedModel`, `PopulateApplicationPermissions`, `SeedApplicationTemplates`, all domain controllers/services, `OpenBuildToolProvider`.
- [ ] 2.6 Sweep references to the deleted classes: unit tests, psalm/phpstan baselines, `@spec` tags, info.xml `<settings>`/`<repair-steps>` entries (must point at the stubs, which keep their FQCNs), docs.

## 3. Verification

- [ ] 3.1 New health contract: anon `GET /apps/openbuild/api/health` → 200 `{status, app, version, checks:{database:"ok", openregister:"ok"}}`; with OR disabled → 200 `status: "degraded"`; DB check failure path → 503 (ADR-006 `adr006` policy). No more 401.
- [ ] 3.2 New metrics contract: admin `GET /apps/openbuild/api/metrics` → Prometheus text 0.0.4 containing `openbuild_info`, `openbuild_up`, `openbuild_export_jobs_total{status=...}`, `openbuild_applications_total`, `openbuild_application_versions_total{status=...}` with values matching direct object counts on seeded data; non-admin → denied.
- [ ] 3.3 Run OR's AppHost Newman contract collection (from `apphost-observability-engine`) against OpenBuild — green.
- [ ] 3.4 Existing OpenBuild Newman collections (`tests/integration/*.postman_collection.json`, 14 collections via `run-newman.sh`) — green; parity endpoints (settings/preferences/dashboard) byte-match the 0.2 baselines.
- [ ] 3.5 Existing Playwright e2e suite green, EXCLUDING the documented issue #41 nested-routing quarantine (the 12 quarantined specs stay quarantined — this change must neither fix nor widen #41; assert the quarantine list is unchanged). `bootstrap-openbuild.e2e.spec.ts` + `builder-host.spec.ts` are the smoke canaries for the rewired dashboard catch-all.
- [ ] 3.6 PHPUnit suite green; add/adjust unit coverage for the Application.php wiring (Bootstrap call present, domain registrations intact).

## 4. Docs

- [ ] 4.1 Update OpenBuild observability/settings docs: health now public + real (database + orAvailable), metrics now admin Prometheus with the three declared descriptors; note icon-cache-hits was promised-but-unbacked and what it would take (appConfig counter or provider). Link `src/manifest.json` as the living example; note the AppHost adoption in the architecture page.

## 5. Quality gates

- [ ] 5.1 `composer check:strict` green (PHPCS, PHPMD, Psalm, PHPStan) — fix, don't baseline, anything the deletions surface; prune now-dead baseline entries for the deleted classes.
- [ ] 5.2 All 18 hydra gates green (`scripts/run-hydra-gates.sh`), including gate-16 spec-coverage on the touched methods and gate-19 e2e-coverage on the spec delta.
- [ ] 5.3 Gate-22 manifest validation green (`src/manifest.json` is touched — must validate against the canonical app-manifest schema including the `observability` block).
