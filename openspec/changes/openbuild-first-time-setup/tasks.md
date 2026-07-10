## 0. Prerequisites

- [x] 0.1 Confirm the consumed `@conduction/nextcloud-vue` version exposes the ADR-042 setup renderer (manifest `setup` block → `CnWizardDialog` flow). CONFIRMED — `CnSetupWizard.vue` ships in the installed lib and POSTs `run-action` to `/api/setup/action/{actionId}`, `config-fields` to `/api/setup/config`, and reads `/api/setup/status`. Endpoints implemented to that exact contract (NOT the ad-hoc `/api/setup/seed-templates` the proposal sketched).
- [x] 0.2 Confirm no overlap with `openbuild-walkthrough-editor` (that change edits VIRTUAL apps' setup blocks; this one adds OpenBuild's own) — no file overlap; this change only touches OpenBuild's own manifest + backend.

## 1. Extract seeding into a service

- [x] 1.1 Create `lib/Service/TemplateSeedService.php` with `seed(): array` returning `{ seeded: int, skipped: int, errors: string[] }`, moving the fixture-directory loading + create-missing-never-overwrite logic out of the repair step. Idempotent by template slug. Added `countSeeded()` for side-effect-free status checks.
- [x] 1.2 Rewrite `SeedApplicationTemplates::run()` as a thin wrapper over `TemplateSeedService::seed()`. Behaviour preserved: emits the seeding summary and re-raises `RuntimeException` (loud-fail, REQ-OBTC-009) when the service collects any error. (Per-slug info lines are now summarised into one count line — the loud-fail contract is byte-preserved, the incidental per-slug log lines are not.)
- [x] 1.3 Unit tests (`tests/Unit/Service/TemplateSeedServiceTest.php`): seed-on-empty creates all; re-run skips all; partial pre-existing only creates missing; missing dir returns an error entry, does not throw; missing individual fixture collects error + continues; `countSeeded` reports existing. Repair-step test rewired through the real service.

## 2. Setup action endpoint

- [x] 2.1 Create `lib/Controller/SetupController.php`. Implemented the fleet-wide contract (`status`/`saveConfig`/`runAction`) rather than a bespoke single endpoint, so the shipped `CnSetupWizard` renderer drives it directly. `runAction('seed-templates')` calls `TemplateSeedService::seed()`; admin-only via explicit `IGroupManager::isAdmin` guard (ADR-005), returns `{ success, message, detail:{seeded,skipped,errors} }` (200 / 422 partial / 500 unexpected / 403 non-admin / 401 unauth).
- [x] 2.2 Register the routes in `appinfo/routes.php`, specific-first before the SPA catch-all: `setup#status` (GET `/api/setup/status`), `setup#saveConfig` (POST `/api/setup/config`), `setup#runAction` (POST `/api/setup/action/{actionId}`). CSRF enforced (no `#[NoCSRFRequired]`). (Route path follows the renderer's real convention, not the proposal's `/api/setup/seed-templates`.)
- [x] 2.3 SPDX headers, full PHPDoc, `@spec` tags; PHPCS clean on all three lib files; PHPStan (level 5) `[OK] No errors`. `#[NoAdminRequired]` + explicit in-body admin guard satisfies route-auth/semantic-auth (mirrors the proven ApplicationCreationController pattern; `AuthorizedAdminSetting` + `DataResponse` generics fail PHPStan L5 in this repo's stub set).

## 3. Manifest setup block

- [x] 3.1 Add the `setup` block to `src/manifest.json`: `enabled`, `version:1`, `completionConfigKey:"setup_completed_version"`, steps `welcome` (info) → `seed` (run-action `seed-templates`, required) → `store` (config-fields: `registry_url`/`registry_register`/`registry_token`, optional) → `done` (summary, `healthCheck:true`).
- [x] 3.2 Validated against the canonical v2 schema (`app-manifest-v2.schema.json`) with ajv (draft 2020-12) — MANIFEST VALID.
- [x] 3.3 English-only step titles/bodies.

## 4. Completion + walkthrough interplay

- [x] 4.1 `SetupController::status()` stamps `setup_completed_version` (= `SETUP_VERSION`) once the required seed step is satisfied. The runtime "SHALL NOT re-trigger while stored version >= setup.version" gate is enforced by nc-vue's `CnAppRoot` boot phase (reads `completionConfigKey`), not this repo.
- [x] 4.2 Pre-satisfy on already-healthy instances: `status()` derives `seedDone` from `countSeeded() > 0` (existing templates) and stamps completion without any user action — so an already-seeded instance is pre-satisfied on first boot after upgrade.
- [ ] 4.3 Verify the `openbuild:getting-started` walkthrough starts only after setup completion for admins, and a non-admin on an unconfigured instance gets the standard not-configured state, not the dead-end tour. DEFERRED — the walkthrough-vs-setup phased boot lives in nc-vue's `CnAppRoot`; verifying the interplay needs a live instance.

## 5. Verification

- [ ] 5.1 Newman/API: POST as admin → 200 `{ success, detail.seeded>0 }` on a clean instance; re-POST → skipped; non-admin → 403. DEFERRED — needs a live instance; the seed/skip/partial/403 logic is fully covered by the PHP unit suite (service + controller behaviour), but the true HTTP round-trip needs a running NC + OR.
- [ ] 5.2 Playwright e2e: fresh instance → admin first visit shows the setup wizard; complete it; Store lists templates; tour reaches `create-app`. DEFERRED — live-instance e2e.
- [ ] 5.3 Repair-step regression: `occ maintenance:repair` still seeds identically on a clean install. DEFERRED — needs a live instance; the repair step now delegates to the same unit-tested seeding service, and its wrapper is unit-tested (4 repair tests green).
