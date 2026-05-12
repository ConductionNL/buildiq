## 1. Implementation Tasks — openbuilt-version-snapshots

- [ ] 1.1 **Declare `ApplicationVersion` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-OBV-001
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `uuid` (UUID-format), `applicationUuid` (UUID-format, required), `version` (semver pattern, required), `manifest` (object, required — references the canonical app-manifest schema), `publishedAt` (ISO 8601 date-time, required), `publishedBy` (string, required), `notes` (string, optional). Validates against OpenAPI 3.0.0. Lives in the `openbuilt` register namespace alongside `Application` and `BuiltAppRoute`.
  - Implement: declarative — no PHP service class.
  - Test: integration test creates an `ApplicationVersion` row via OR REST, asserts validation rejects a payload missing `applicationUuid`.

- [ ] 1.2 **Extend the `Application` schema with `currentVersion`**
  - spec_ref: REQ-OBA-006, REQ-OBV-006
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: `Application` schema gains a `currentVersion` property (string, UUID-format, optional). Existing seeded Applications from spec #1 remain valid (no required-field upgrade).
  - Implement: declarative schema patch — no PHP migration code.
  - Test: integration test loads the seeded `hello-world` Application post-upgrade and asserts it still parses; asserts `currentVersion` field is present and absent / null.

- [ ] 1.3 **Declare the snapshot-on-publish action on the Application schema (declarative path)**
  - spec_ref: REQ-OBV-002, REQ-OBA-007
  - files: `lib/Settings/openbuilt_register.json` (NOT a new PHP service class)
  - acceptance_criteria: `x-openregister-lifecycle.on_transition` action on the `draft → published` edge declares: (a) create a sibling `ApplicationVersion` record copying `manifest`, `version`, and metadata; (b) update Application's `currentVersion`; (c) reset Application's `status` to `draft` per design.md Decision 3.
  - Implement: declarative-first per ADR-031. NO `VersioningService` / `SnapshotService` / `ApplicationVersionManager` class.
  - Test: integration test publishes a draft Application, asserts a fresh `ApplicationVersion` row exists with byte-equal manifest and correct metadata, asserts `currentVersion` points at it, asserts Application status is `draft` post-action.

- [ ] 1.4 **Fall back to a single listener PHP class IF the declarative path is unavailable (ADR-031 §Exceptions(1))**
  - spec_ref: REQ-OBV-002, REQ-OBA-007
  - files: `lib/Listener/ApplicationVersionSnapshotListener.php` (ONLY if task 1.3's declarative path proves engine-unsupported)
  - acceptance_criteria: Single listener subscribed to OR's `ObjectLifecycleTransitionedEvent`; same observable behaviour as task 1.3; carries SPDX + EUPL-1.2 docblock; no broader business logic. Recorded in `hydra.json` under `decisions[]` with rationale. OR-side issue filed referencing bootstrap-openbuilt's OQ-1.
  - Implement: only if necessary; thin-glue exception per ADR-032.
  - Test: integration test parity with task 1.3 — same publish flow, same assertions, listener path is exercised.

- [ ] 1.5 **Register the diff endpoint route in `appinfo/routes.php`**
  - spec_ref: REQ-OBV-005
  - files: `appinfo/routes.php`
  - acceptance_criteria: Route `GET /api/applications/{slug}/versions/diff` maps to `applications#diffVersions` with `#[NoAdminRequired]`. Registration only in `routes.php` (per ADR-016).
  - Implement: ~3 LOC route declaration.
  - Test: Newman request resolves the route to the controller.

- [ ] 1.6 **Add `ApplicationsController::diffVersions` (thin-glue per ADR-032)**
  - spec_ref: REQ-OBV-005
  - files: `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: `diffVersions(string $slug, string $from, string $to): JSONResponse` resolves slug → Application via `BuiltAppRoute`, accepts `draft` as a literal for either parameter (returns the Application's current draft manifest), looks up both `ApplicationVersion` rows via OR's ObjectService, returns `{ from: { manifest, version, publishedAt }, to: { manifest, version, publishedAt } }`, responds 200 on hit / 404 on miss. ~25 LOC. SPDX + EUPL-1.2 docblock per memory rule. `#[NoAdminRequired]` set so route-auth gate-5 passes.
  - Implement: single method, no service class.
  - Test: PHPUnit asserts 200 with both blobs for valid UUIDs, 404 for unknown UUID, organisation-scope enforcement.

## 2. Implementation Tasks — openbuilt-application-register (modifications)

- [ ] 2.1 **Confirm `currentVersion` is wired by the same lifecycle action as the snapshot**
  - spec_ref: REQ-OBA-006, REQ-OBA-007
  - files: `lib/Settings/openbuilt_register.json` (re-uses the action declared in task 1.3)
  - acceptance_criteria: The single `on_transition` action (or its listener fallback) writes BOTH the new `ApplicationVersion` row AND the Application's `currentVersion` in one logical step. No separate hook / action / listener for `currentVersion` upkeep.
  - Implement: declarative — covered by tasks 1.3 / 1.4.
  - Test: integration test publishes twice and asserts `currentVersion` updates to the second row's `uuid`; asserts the first row remains intact.

## 3. Implementation Tasks — openbuilt-runtime (modifications)

- [ ] 3.1 **Add Publish action and status badge to `ApplicationEditor.vue`**
  - spec_ref: REQ-OBR-006, REQ-OBR-007
  - files: `src/views/ApplicationEditor.vue`, `src/store/applications.js`
  - acceptance_criteria: Editor header carries a `status` badge (draft / published / archived) using NC CSS variables (no hardcoded colours per ADR-010); Publish button is disabled when manifest is invalid or while in-flight; clicking Publish saves the manifest then invokes the `draft → published` lifecycle endpoint; on success a toast names the newly created `ApplicationVersion.uuid`. "Draft modified since last publish" indicator surfaces when textarea diverges from `currentVersion.manifest`.
  - Implement: Options API; reuse `createObjectStore` (memory rule); no custom Pinia layer.
  - Test: Playwright pastes a valid manifest, clicks Publish, asserts the toast surfaces, asserts a new `ApplicationVersion` row is queryable via OR REST.

- [ ] 3.2 **Build `VersionHistory.vue` panel**
  - spec_ref: REQ-OBR-008
  - files: `src/views/VersionHistory.vue`, `src/router/index.js` (sibling tab registration)
  - acceptance_criteria: Panel reads `ApplicationVersion` rows from OR REST filtered by `applicationUuid`, renders newest-first, shows `version`, localised `publishedAt`, `publishedBy`, and `notes`. Empty state for never-published Applications with no console errors. Mounted as a sibling tab inside `ApplicationEditor.vue` per design.md OQ-4 provisional decision.
  - Implement: Options API; no app-local wrapper service.
  - Test: Playwright opens an Application with three historical versions, asserts three rows render in newest-first order; opens an unpublished Application, asserts the empty state renders without console errors.

- [ ] 3.3 **Build rollback action + `RollbackConfirmModal.vue` (modal-isolation per Hydra ADR-004 gate)**
  - spec_ref: REQ-OBR-009, REQ-OBV-003
  - files: `src/modals/RollbackConfirmModal.vue` (own SFC per Hydra modal-isolation gate), `src/views/VersionHistory.vue`
  - acceptance_criteria: Each history row carries "Roll back to this version" button; clicking opens the confirmation modal naming the target `version`; confirming PUTs the chosen snapshot's manifest onto the Application as the new draft, sets the rollback version marker (per design.md OQ-2 provisional `<version>-rollback-<6hex>`), leaves status `draft`, refreshes the textarea. Modal lives in `src/modals/` (NOT inline in parent) per ADR-004 modal-isolation hard rule. Cancel aborts without writes.
  - Implement: NcDialog or NcModal as appropriate; passes `hydra-gate-modal-isolation`.
  - Test: Playwright clicks rollback, confirms, asserts textarea reflects the chosen snapshot's manifest, asserts all historical rows remain present.

- [ ] 3.4 **Build `ManifestDiff.vue` component with client-side diff (per design.md Decision 5)**
  - spec_ref: REQ-OBR-010
  - files: `src/components/ManifestDiff.vue`, `package.json` (add `jsdiff` or equivalent — pending audit per design.md OQ-5)
  - acceptance_criteria: Accepts `from` and `to` props (`ApplicationVersion` UUIDs or the literal `draft`); fetches via the diff endpoint (REQ-OBV-005); JSON-pretty-prints both blobs deterministically (sorted keys, stable indent); runs `jsdiff` (or chosen library) and renders side-by-side with added/removed/unchanged tokens coloured via NC CSS variables. Default load: `from=draft`, `to=currentVersion`. Diff library audit recorded in design.md OQ-5 resolution and `hydra.json`.
  - Implement: client-side only; no server-side diff service.
  - Test: Playwright opens the diff view for an Application with a modified draft vs its published version, asserts the diff visualises the change; selects two arbitrary history rows and asserts a different diff renders.

- [ ] 3.5 **Wire "Compare with current draft" affordance on history rows (per design.md OQ-4)**
  - spec_ref: REQ-OBR-008, REQ-OBR-010
  - files: `src/views/VersionHistory.vue`, `src/views/ApplicationEditor.vue`
  - acceptance_criteria: Each version-history row has a one-click action that switches to the diff sibling tab pre-loaded with `from=draft`, `to=<that row's uuid>`. No new route — uses the existing diff tab.
  - Implement: emit an event from `VersionHistory.vue`, handle it in `ApplicationEditor.vue`.
  - Test: Playwright clicks "Compare with current draft" on a history row, asserts the diff tab activates with the correct pair preloaded.

## 4. Seed Data (ADR-001)

- [ ] 4.1 **Extend `SeedHelloWorld.php` to seed one `ApplicationVersion` and set Application.currentVersion**
  - spec_ref: design.md Seed Data section
  - files: `lib/Repair/SeedHelloWorld.php`
  - acceptance_criteria: Seeds one `ApplicationVersion` row for the seeded `hello-world` Application: `applicationUuid` = the Application's UUID, `version` = `1.0.0`, `manifest` = byte-equal copy, `publishedAt` = install timestamp, `publishedBy` = `system`, `notes` = "Seeded by OpenBuilt install — initial published version". Sets the Application's `currentVersion` to the new row's UUID. Idempotent: re-running the repair on a seeded install does NOT create a duplicate `ApplicationVersion`. No scripting — edit via PHP only (memory rule).
  - Implement: extend the existing repair step; guard on existing-row check.
  - Test: PHPUnit runs the repair step twice, asserts exactly one `ApplicationVersion` exists for `hello-world`; asserts `currentVersion` is set.

## 5. Verification

- [ ] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all green. Fix any pre-existing issues in touched files (memory rule).
- [ ] 5.2 Run `npm run lint` / ESLint — clean on the new SFCs.
- [ ] 5.3 Run `npm run check:manifest` (ADR-024) on the seeded `hello-world` manifest after the snapshot — passes.
- [ ] 5.4 Confirm NO `VersioningService.php` / `SnapshotService.php` / `ApplicationVersionService.php` / `ManifestVersionManager.php` (or similarly-named class) exists under `lib/Service/` — ADR-031 review gate.
- [ ] 5.5 Run Hydra mechanical gates locally (`scripts/run-hydra-gates.sh`): SPDX headers, forbidden-patterns, stub-scan, composer-audit, route-auth, orphan-auth, no-admin-idor, unsafe-auth-resolver, semantic-auth, initial-state, admin-router, nc-input-labels, modal-isolation — all pass. Modal-isolation is the relevant new gate (RollbackConfirmModal in `src/modals/`).
- [ ] 5.6 Visually verify on a fresh `docker compose up` that the seeded `hello-world` Application shows one history row, the Publish button creates a second row, and the diff view renders cleanly between them.
- [ ] 5.7 Resolve design.md OQ-1 (declarative vs listener) — record path chosen in `hydra.json`.
- [ ] 5.8 Resolve design.md OQ-2 (rollback marker form) — record final form in code + `hydra.json`.
- [ ] 5.9 Resolve design.md OQ-5 (diff library choice) — record audit outcome in `hydra.json`; confirm not shadowed by `@conduction/nextcloud-vue`.

## 6. Tests (ADR-008)

- [ ] 6.1 **PHPUnit** — `tests/Unit/Controller/ApplicationsControllerTest.php::diffVersions` covers 200 + 404 + organisation scoping + `draft` literal handling for `from` / `to`.
- [ ] 6.2 **PHPUnit** — `tests/Integration/ApplicationVersioningTest.php` walks `draft → published → rollback → republish` and asserts: snapshot created on first publish, `currentVersion` updated, manifest preserved byte-equal, rollback restores manifest without history rewrite, republish creates a fresh row.
- [ ] 6.3 **Newman** — `tests/api/openbuilt.postman_collection.json` adds requests for the diff endpoint (200 + 404), reading `ApplicationVersion` rows by `applicationUuid` filter, and posting a manual `ApplicationVersion` (smoke test for the schema declaration).
- [ ] 6.4 **Playwright** — `tests/e2e/versioning.spec.ts` covers: navigate to a draft Application, edit and Publish, assert the new history row appears; rollback to the previous row, assert the textarea reflects the restored manifest; open the diff view and assert the visualisation renders without console errors.

## 7. Documentation (ADR-009, ADR-010)

- [ ] 7.1 Extend `docs/openbuilt-runtime.md` with a "Versioning" section covering the snapshot-on-publish flow, rollback semantics (audit-clean per Decision 3), and the diff view's UX contract.
- [ ] 7.2 Add a "How to safely iterate on a published app" walkthrough to `docs/integrator-guide.md` — covers draft / publish / rollback cycle.
- [ ] 7.3 NL Design (ADR-010) — confirm the new status badge, diff tokens, and version-history panel use Nextcloud CSS variables only; document any new variables added.
- [ ] 7.4 Update `openspec/app-config.json` to list `openbuilt-version-snapshots` under capabilities.

## 8. i18n (ADR-005, ADR-007)

- [ ] 8.1 Add English translations in `l10n/en.json` for new keys: `openbuilt.editor.publish`, `openbuilt.editor.publishing`, `openbuilt.editor.status.draft|published|archived`, `openbuilt.editor.draftModified`, `openbuilt.versionHistory.title`, `openbuilt.versionHistory.empty`, `openbuilt.versionHistory.rollback`, `openbuilt.versionHistory.compare`, `openbuilt.rollback.confirm.title`, `openbuilt.rollback.confirm.body`, `openbuilt.rollback.confirm.confirm`, `openbuilt.rollback.confirm.cancel`, `openbuilt.diff.title`, `openbuilt.diff.empty`.
- [ ] 8.2 Add Dutch translations for the same keys in `l10n/nl.json` (memory rule: Dutch + English minimum).
- [ ] 8.3 Confirm no hardcoded UI strings in the new SFCs — every label runs through `t('openbuilt', '<key>')`.
