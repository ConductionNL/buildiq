## 0. Prerequisites

- [ ] 0.1 Confirm the consumed `@conduction/nextcloud-vue` version exposes the ADR-042 setup renderer (manifest `setup` block → `CnWizardDialog` flow). If absent, implement steps via the `component` escape hatch and file the nc-vue follow-up issue at planning time.
- [ ] 0.2 Confirm no overlap with `openbuild-walkthrough-editor` (that change edits VIRTUAL apps' setup blocks; this one adds OpenBuild's own) — record the coexistence note in the PR description.

## 1. Extract seeding into a service

- [ ] 1.1 Create `lib/Service/TemplateSeedService.php` with `seed(): array` returning `{ seeded: int, skipped: int, errors: string[] }`, moving the fixture-directory loading + create-missing-never-overwrite logic out of `lib/Repair/SeedApplicationTemplates.php:110+`. Idempotent by template slug.
- [ ] 1.2 Rewrite `SeedApplicationTemplates::run()` as a thin wrapper over `TemplateSeedService::seed()` (repair-step behaviour byte-equivalent: same IOutput info/warning lines).
- [ ] 1.3 Unit tests: seed-on-empty creates all fixtures; re-run skips all; partial pre-existing set only creates the missing ones; missing fixtures dir returns an error entry, does not throw.

## 2. Setup action endpoint

- [ ] 2.1 Create `lib/Controller/SetupController.php` with `seedTemplates(): JSONResponse` calling `TemplateSeedService::seed()`; admin-only (explicit `IGroupManager::isAdmin` guard — do NOT rely on the SecurityMiddleware default alone; ADR-005), returns `{ seeded, skipped, errors }` with 200, or 500 with a safe message on failure.
- [ ] 2.2 Register `['name' => 'setup#seedTemplates', 'url' => '/api/setup/seed-templates', 'verb' => 'POST']` in `appinfo/routes.php`, specific-first before the SPA catch-all (ADR-016/ADR-029). CSRF enforced (no `#[NoCSRFRequired]`).
- [ ] 2.3 SPDX headers, full PHPDoc, `@spec` tags; `composer check:strict` clean; hydra route-auth/route-reachability/semantic-auth gates pass.

## 3. Manifest setup block

- [ ] 3.1 Add the `setup` block to `src/manifest.json`: `enabled: true`, `version: 1`, `completionConfigKey: "setup_completed_version"`, steps `info` → `run-action` (seed-templates, required, POST `/apps/openbuild/api/setup/seed-templates`) → `config-fields` (remote store: `registry_url`, `registry_register`, `registry_token`; optional/skippable; written via the existing settings POST) → `summary` (recap + `observability.health.checks`).
- [ ] 3.2 Validate against the canonical v2 schema (`app-manifest-v2.schema.json` referenced at `src/manifest.json:2`).
- [ ] 3.3 English-only step titles/bodies (i18n source keys in English).

## 4. Completion + walkthrough interplay

- [ ] 4.1 Stamp `setup_completed_version` (app config) when the summary step completes; the wizard SHALL NOT re-trigger while the stored version >= manifest `setup.version`.
- [ ] 4.2 Pre-satisfy on already-healthy instances: when templates exist AND (store configured OR consciously skipped), first boot after upgrade stamps completion without showing the wizard.
- [ ] 4.3 Verify the `openbuild:getting-started` walkthrough starts only after setup completion for admins, and that a non-admin on an unconfigured instance gets the standard dependency/not-configured state, not the dead-end tour.

## 5. Verification

- [ ] 5.1 Newman/API: POST seed-templates as admin → 200 `{ seeded > 0 }` on a clean instance; re-POST → `{ seeded: 0, skipped: N }`; POST as non-admin → 403.
- [ ] 5.2 Playwright e2e: fresh instance → admin first visit shows the setup wizard; complete it; Store lists templates; walkthrough tour then reaches `create-app` without a dead end.
- [ ] 5.3 Repair-step regression: `occ maintenance:repair` still seeds identically on a clean install.
