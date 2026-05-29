## 1. Implementation Tasks — openbuilt-application-register

- [x] 1.1 **Declare `Application` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-OBA-001, REQ-OBA-002
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `uuid`, `slug` (kebab-case pattern), `name` (required), `description`, `manifest` (object, required, with a `$ref` or inline reference to the canonical app-manifest schema), `version` (semver pattern, required), `status` (enum draft|published|archived, default draft, required). Validates against OpenAPI 3.0.0.
  - Implement: declarative — no PHP service class.
  - Test: integration test creates an Application via OR REST, asserts schema validation kicks in on a malformed manifest.

- [x] 1.2 **Add `x-openregister-lifecycle` to the `Application` schema** (canonical ADR-031 example)
  - spec_ref: REQ-OBA-003
  - files: `lib/Settings/openbuilt_register.json` (NOT a new PHP service)
  - acceptance_criteria: Declares states `draft`, `published`, `archived` and transitions `draft → published`, `published → archived`, `archived → draft`. Each transition emits an OR audit event. No `ApplicationLifecycleService.php` file is created.
  - Implement: declarative schema patch only.
  - Test: integration test transitions a seeded Application through every allowed state, asserts audit-trail entries exist, asserts a disallowed transition (`draft → archived`) returns 4xx.

- [x] 1.3 **Declare `BuiltAppRoute` schema and slug uniqueness**
  - spec_ref: REQ-OBA-004
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `slug` (kebab-case, required) and `applicationUuid` (UUID-format, required); slug uniqueness scoped to organisation (declarative if the engine supports it; otherwise documented in design.md OQ-1 as a thin-glue fallback).
  - Implement: declarative schema patch (and, only if necessary per design.md OQ-1, a single `BuiltAppRouteSyncListener.php` subscribed to OR's lifecycle event).
  - Test: integration test publishes two Applications with the same slug in the same organisation, asserts the second is rejected.

- [x] 1.4 **Wire BuiltAppRoute upkeep to the Application lifecycle**
  - spec_ref: REQ-OBA-004
  - files: `lib/Settings/openbuilt_register.json` (preferred); only if OR's engine is missing the hook, `lib/Listener/BuiltAppRouteSyncListener.php`
  - acceptance_criteria: Transitioning an Application to `published` creates / refreshes its BuiltAppRoute; transitioning to `archived` removes (or marks inactive) the BuiltAppRoute. Behaviour is identical whether the action is declarative (`x-openregister-lifecycle.on_published`) or listener-based.
  - Implement: prefer the declarative path; record the chosen path in `hydra.json` under `decisions[]` for self-learning.
  - Test: integration test asserts the BuiltAppRoute row appears on publish and disappears on archive.

- [x] 1.5 **Confirm multi-tenant scoping via OR `organisation`**
  - spec_ref: REQ-OBA-005
  - files: `lib/Settings/openbuilt_register.json` (no changes if OR defaults already apply)
  - acceptance_criteria: Cross-organisation reads return empty / 403 per OR's standard contract. No app-local RBAC code introduced (ADR-022).
  - Implement: rely on OR's existing organisation scoping; no PHP added.
  - Test: integration test runs as user-A in org-A, asserts org-B Applications are not returned.

## 2. Implementation Tasks — openbuilt-runtime

- [x] 2.1 **Register the manifest endpoint route in `appinfo/routes.php`** (ADR-016)
  - spec_ref: REQ-OBR-001
  - files: `appinfo/routes.php`
  - acceptance_criteria: Route `GET /api/applications/{slug}/manifest` maps to `applications#getManifest` with `#[NoAdminRequired]`. Only registration path is `routes.php` — no attribute-only registration.
  - Implement: ~5 LOC route declaration.
  - Test: Newman + Playwright network-request capture verifies the route resolves.

- [x] 2.2 **Add `ApplicationsController::getManifest`** (thin-glue code per ADR-032)
  - spec_ref: REQ-OBR-001
  - files: `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: `getManifest(string $slug): JSONResponse` resolves slug → Application via OR's ObjectService and the `BuiltAppRoute` index, returns the `manifest` blob unwrapped (no OR envelope), 200 on hit, 404 on miss. ~15 LOC; carries SPDX + EUPL-1.2 docblock (per memory rule). `#[NoAdminRequired]` attribute is set so route-auth gate-5 passes.
  - Implement: single method, no service class.
  - Test: PHPUnit asserts 404 on unknown slug + 200+payload on known slug.

- [x] 2.3 **Build `BuilderHost.vue` mounting a nested `CnAppRoot`**
  - spec_ref: REQ-OBR-002, REQ-OBR-003
  - files: `src/views/BuilderHost.vue`, `src/router/index.js` (route registration), `src/manifests/placeholder.json`
  - acceptance_criteria: Vue route `/builder/:slug(.*)` mounts `BuilderHost.vue`; the host renders `<CnAppRoot :app-id="\`openbuilt-${slug}\`" :bundled-manifest="placeholder" :key="slug" :options="{ fetcher: redirectingFetcher }" />`. Inner-router path forwarding is verified by inspecting `$route.params.pathMatch`.
  - Implement: ~25 LOC across the SFC `<script>` + `<template>`.
  - Test: Playwright navigates `/builder/hello-world` and asserts the seeded index page renders; then navigates to `/builder/hello-world/messages/<uuid>` and asserts the detail page renders.

- [x] 2.4 **Build the textarea-based `ApplicationEditor.vue`**
  - spec_ref: REQ-OBR-005
  - files: `src/views/ApplicationEditor.vue`, `src/store/applications.js`
  - acceptance_criteria: Editor lists Applications via OR REST, opens a JSON textarea bound to the `manifest` blob, validates on Save via `validateManifest` from `@conduction/nextcloud-vue`, surfaces the failing JSON path on validation error, PUTs the blob back to OR REST on success.
  - Implement: Options API; no custom Pinia store layered over `useObjectStore` (memory rule: use `createObjectStore`).
  - Test: Playwright pastes a malformed manifest (missing `pages`), asserts the save button stays disabled / surfaces an error; then pastes a valid manifest and asserts the PUT goes through.

## 3. Seed Data (ADR-001)

- [x] 3.1 **Declare `hello-message` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-OBR-004
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `uuid` (UUID-format) plus `title` (required, string) and `body` (string).
  - Implement: declarative schema patch.

- [x] 3.2 **Ship the seed repair step `lib/Repair/SeedHelloWorld.php`**
  - spec_ref: REQ-OBR-004
  - files: `lib/Repair/SeedHelloWorld.php`, `appinfo/info.xml` (`<repair-steps>` already declares `InitializeSettings`; add `SeedHelloWorld` as a `<post-migration>` step)
  - acceptance_criteria: Seeds one `Application` with `slug: hello-world`, `status: published`, the manifest declared in design.md "Seed Data", plus three `hello-message` sample objects. Idempotent: guarded by an existing-slug check; re-running the repair step on a seeded install is a no-op. The seeded manifest validates against the canonical schema; the repair step calls `ConfigurationService::importFromApp()` (memory rule) for schema registration.
  - Implement: PHP repair step; no scripting / sed / awk to modify code.
  - Test: PHPUnit runs the repair step twice, asserts exactly one `hello-world` Application + three sample messages exist after each run.

## 4. Verification

- [x] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all green; fix any pre-existing issues in touched files (memory rule). _(phpunit requires NC bootstrap; runs in container only)_
- [x] 4.2 Run `npm run lint` / ESLint flat config — clean on the new SFC.
- [ ] 4.3 Run `npm run check:manifest` (ADR-024) on the seeded `hello-world` manifest blob in tests — passes against the canonical schema pinned in `package.json`.
- [ ] 4.4 Visually verify on a fresh `docker compose up` that `/index.php/apps/openbuilt/builder/hello-world` renders the seeded virtual app.
- [x] 4.5 Confirm no `ApplicationLifecycleService.php` / `ApplicationStateMachine.php` / similar service class exists under `lib/Service/` — ADR-031 review gate.

## 5. Tests (ADR-008)

- [x] 5.1 **PHPUnit** — `tests/unit/Controller/ApplicationsControllerTest.php` covers `getManifest` (200 happy path + 404 unknown-slug + 500 inconsistent-state). _Organisation scoping requires functional test in container._
- [ ] 5.2 **PHPUnit** — `tests/Integration/ApplicationLifecycleTest.php` walks the Application through `draft → published → archived → draft`, asserts audit entries on each transition, asserts a disallowed transition is rejected, asserts BuiltAppRoute upkeep.
- [x] 5.3 **Newman** — `tests/integration/openbuilt.postman_collection.json` covers `GET /api/applications/{slug}/manifest` (200, 404) plus a full CRUD round-trip on Applications via OR REST (POST/GET/PUT/DELETE) and a manifest-after-publish check. 8 requests, all with status + JSON-shape assertions.
- [x] 5.4 **Playwright** — `playwright.config.ts` + `tests/e2e/{builder-host,application-editor,manifest-endpoint}.spec.ts` cover the seeded builder-host journey (index→detail→form), the textarea editor round-trip, and the public manifest endpoint shape. Requires `npx playwright install --with-deps` once; runs via `npm run test:e2e`.

## 6. Documentation (ADR-009, ADR-010)

- [x] 6.1 Add `docs/openbuilt-runtime.md` describing the nested-`CnAppRoot` mount pattern, the manifest endpoint contract, and the workaround per design.md Decision 4.
- [x] 6.2 Add a "How to author a virtual app" walkthrough in `docs/integrator-guide.md` covering the textarea editor + the seeded `hello-world` example.
- [x] 6.3 NL Design (ADR-010) — confirm the new views use Nextcloud CSS variables only (no hardcoded colours); document any new variables added. _BuilderHost.vue + ApplicationEditor.vue use `var(--color-*)` only._
- [x] 6.4 Update `openspec/app-config.json` to list `openbuilt-application-register` and `openbuilt-runtime` under capabilities.

## 7. i18n (ADR-005, ADR-007)

- [x] 7.1 Add English translations for every new string in `l10n/en.json` (top-bar entry already declared in `info.xml`; add `openbuilt.builder.*`, `openbuilt.editor.*`, `openbuilt.helloworld.*` keys).
- [x] 7.2 Add Dutch translations for the same keys in `l10n/nl.json`.
- [x] 7.3 Confirm the seeded `hello-world` manifest uses translation keys for every `label` and `title` (per ADR-024 §6).
