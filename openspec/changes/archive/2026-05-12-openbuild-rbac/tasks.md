## 1. Implementation Tasks — openbuilt-application-register (modified)

- [ ] 1.1 **Add `permissions` property to the `Application` schema**
  - spec_ref: REQ-OBA-006
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares optional `permissions` object with three required-when-present `string[]` arrays — `owners`, `editors`, `viewers`. `additionalProperties: false` on the `permissions` object. Existing Applications remain schema-valid (property is optional). Validates against OpenAPI 3.0.0.
  - Implement: declarative — JSON Schema patch. No PHP service class.
  - Test: integration test creates an Application with `permissions` via OR REST, asserts round-trip equality; creates another with an unknown sub-key (`admins`) and asserts 4xx.

- [ ] 1.2 **(Conditional) Declare `x-openregister-authorization` read rule on Application**
  - spec_ref: REQ-OBRBAC-003, REQ-OBR-007
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: If OR's `x-openregister-authorization` vocabulary supports a `groupIn-pointer` predicate, declare the read rule `anyOf: [{ groupIn: "permissions.owners" }, { groupIn: "permissions.editors" }, { groupIn: "permissions.viewers" }]`. If not supported, skip this task and record the decision in `hydra.json` under `decisions[]`; file an OR-side issue requesting the predicate and link it here.
  - Implement: declarative-preferred per design.md Decision 1; the apply agent decides at apply time based on OR's current capability.
  - Test: integration test as user-A (no role on Application X) lists Applications via OR REST and asserts X is absent; as user-B (with viewer role on X) asserts X is present.

- [ ] 1.3 **Ship the permissions-population migration repair step**
  - spec_ref: REQ-OBA-007
  - files: `lib/Repair/PopulateApplicationPermissions.php`, `appinfo/info.xml` (add as `<post-migration>` step after the existing `InitializeSettings` and `SeedHelloWorld` steps)
  - acceptance_criteria: For every existing `Application` whose `permissions` is missing/null, patches `permissions = { owners: ["admin"], editors: [], viewers: [] }`. Idempotent: skips Applications whose `permissions.owners` is already non-empty. One OR REST round-trip per Application. PHP file carries SPDX + EUPL-1.2 docblock (memory rule); no scripting (sed/awk/python) used to modify the file.
  - Implement: PHP repair step; uses `OCA\OpenRegister\Service\ObjectService::saveObject($entityOrArray)` (memory rule — first arg is entity/array, NOT type string).
  - Test: PHPUnit runs the repair step twice against a fixture that has one Application without permissions and one with; asserts first run patches only the first, second run patches nothing, and the patched permissions match the default.

## 2. Implementation Tasks — openbuilt-runtime (modified)

- [ ] 2.1 **Add the permissions check to `ApplicationsController::getManifest`**
  - spec_ref: REQ-OBR-006, REQ-OBRBAC-002
  - files: `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: After org-scope resolution and Application lookup, compute caller's group set via `\OCP\IGroupManager::getUserGroups()`; intersect with `permissions.owners ∪ editors ∪ viewers`. If empty and caller is not in `admin` group, return `JSONResponse({ error: 'forbidden', code: 'openbuilt.rbac.no_role' }, 403)`. The 403 branch SHALL appear before any code path that touches the manifest payload. If caller IS in `admin` group and is bypassing, write a `rbac.admin_bypass` audit entry to the OR audit trail before returning 200. ~12 LOC added; existing SPDX + EUPL-1.2 docblock preserved; `#[NoAdminRequired]` attribute preserved.
  - Implement: in-controller; no new service class (ADR-022 §Exceptions(1)).
  - Test: PHPUnit covers (a) member-of-owners → 200, (b) member-of-editors → 200, (c) member-of-viewers → 200, (d) no role → 403, (e) admin bypass → 200 + audit entry written, (f) cross-org → 404 (org check still wins).

- [ ] 2.2 **Provide caller's group set via `IInitialState`**
  - spec_ref: REQ-OBR-009
  - files: `lib/AppInfo/Application.php` (register `InitialStateProvider`) OR add to existing index-action controller; `lib/Controller/PageController.php` (or equivalent) to set the state on page render.
  - acceptance_criteria: On every OpenBuilt page render, `IInitialState::provideInitialState('openbuilt', 'currentUserGroups', $gids)` is called with the caller's group IDs (`IGroupManager::getUserGroups()->map(getGID)`). Initial-state name space `openbuilt`, key `currentUserGroups`. No DOM data-attribute alternative shipped — ADR-004 hard rule (Hydra gate `gate-initial-state`).
  - Implement: PHP, ~5 LOC where the existing render path lives.
  - Test: Playwright asserts `window.OCP.InitialState.loadState('openbuilt', 'currentUserGroups')` returns the user's gid array on shell boot.

- [ ] 2.3 **Filter the Application list view by role**
  - spec_ref: REQ-OBR-007, REQ-OBRBAC-003
  - files: `src/views/ApplicationEditor.vue` (list mode), `src/composables/useRole.js` (new)
  - acceptance_criteria: If OR returned a pre-filtered list (task 1.2 path taken), render as-is. Otherwise, filter in JS using `loadState('openbuilt', 'currentUserGroups')` and the Application's `permissions`. Empty-state UI says "No applications available — ask an owner to grant you access". Frontend uses `loadState` from `@nextcloud/initial-state`; no `document.getElementById().dataset` reads (ADR-004 / `gate-initial-state`).
  - Implement: Options API; no custom Pinia store (memory rule — use `createObjectStore` if list state is needed beyond view-local).
  - Test: Playwright as user with no role asserts empty list + empty-state copy; as user with one viewer role asserts list of exactly one Application; as user with multiple roles asserts correct cardinality.

- [ ] 2.4 **Gate destructive editor actions via `useRole`**
  - spec_ref: REQ-OBR-008, REQ-OBRBAC-004
  - files: `src/composables/useRole.js` (extends the one created in 2.3), `src/views/ApplicationEditor.vue` (consume `useRole` in template)
  - acceptance_criteria: `useRole(application)` is a pure function returning `'owner' | 'editor' | 'viewer' | 'none'` from the Application's `permissions` and `loadState('openbuilt', 'currentUserGroups')`. Template uses `v-if="role === 'owner'"` on Publish / Archive / Delete / Transfer / Permissions panel; `:disabled="role === 'viewer'"` on Save; viewer sees the textarea read-only (`readonly` attribute).
  - Implement: ~25 LOC pure composable + ~10 LOC `<template>` guards.
  - Test: Playwright covers viewer (textarea read-only, no Save/Publish), editor (Save visible, Publish hidden), owner (all controls visible).

- [ ] 2.5 **Build the Permissions panel (owner-only)**
  - spec_ref: REQ-OBRBAC-005, REQ-OBRBAC-007, REQ-OBR-008
  - files: `src/views/ApplicationEditor.vue` (or a new `src/modals/PermissionsModal.vue` per ADR-004 modal-isolation rule)
  - acceptance_criteria: Owner-only (`v-if="role === 'owner'"`) panel that shows three group pickers (owners, editors, viewers) bound to the Application's `permissions` arrays. Save PUTs the updated `permissions` block via OR REST. Frontend-side guard rejects an `owners = []` PUT before sending; OR REST returns 4xx if the guard is bypassed. The modal lives in `src/modals/` per ADR-004 hard rule (Hydra gate `gate-modal-isolation` — no inline `<NcModal>` inside `ApplicationEditor.vue`). Group pickers are `<NcSelect>` with the required `inputLabel` (or `ariaLabelCombobox`) prop per ADR-004 (Hydra gate `gate-nc-input-labels`).
  - Implement: Vue 2 + `@conduction/nextcloud-vue` `<NcSelect>` for group pickers (fetch groups via OR REST or a thin proxy if no public Nextcloud groups endpoint is available to the user).
  - Test: Playwright as owner: opens modal, transfers ownership from `team-alpha` to `team-beta`, saves; asserts subsequent list-view as the old-owner user is empty (access revoked); asserts orphan-check rejects an `owners = []` save.

- [ ] 2.6 **Add the Permission history panel (owner-only, read-only)**
  - spec_ref: REQ-OBRBAC-007
  - files: `src/views/ApplicationEditor.vue` or `src/modals/PermissionHistoryModal.vue` (per ADR-004 modal-isolation if rendered as a modal)
  - acceptance_criteria: Owner-only read view rendering OR's per-object audit trail filtered to `permissions` changes (and `rbac.admin_bypass` events). No new audit endpoint; consume OR's existing audit REST. Renders before/after `permissions` values, actor, timestamp.
  - Implement: read-only Vue panel; no PHP additions.
  - Test: Playwright as owner asserts panel renders the permission changes made in task 2.5; as editor asserts panel is not visible and direct fetch returns 4xx (OR's audit endpoint already enforces — verify).

## 3. Implementation Tasks — openbuilt-rbac (new) + Nextcloud integration

- [ ] 3.1 **Declare `openbuilt.use` group-permission on the navigation entry**
  - spec_ref: REQ-OBRBAC-006
  - files: `appinfo/info.xml`
  - acceptance_criteria: The existing `<navigations><navigation>` block gains `<permission>openbuilt.use</permission>` (or whatever Nextcloud info.xml syntax the current `<navigations>` schema supports for group restrictions). Default: no restriction → all authenticated users see the entry. An administrator can restrict the entry to groups via Nextcloud's admin UI ("Apps → OpenBuilt → Restrict to groups"). No new admin-settings page is shipped (the existing Nextcloud mechanism is sufficient — design.md Decision 4).
  - Implement: `info.xml` patch only.
  - Test: manual smoke — admin restricts the entry to group `digital-team`, verifies entry hidden for users outside that group, verifies direct URL access returns Nextcloud's standard "navigation forbidden" response.

- [ ] 3.2 **Set the creator's primary group as `owners` on Application creation**
  - spec_ref: REQ-OBRBAC-001
  - files: Frontend Application-creation flow (`src/views/ApplicationEditor.vue`'s create modal, or wherever spec #1 placed it); if a server-side default is needed (because OR's create path does not have access to the current user's groups), a tiny pre-save hook in the same code path that already exists on the Application object.
  - acceptance_criteria: A POST to OR REST creating an Application without `permissions` ends up with `permissions.owners = [<creator's primary gid>]`, `editors = []`, `viewers = []`. If the creator has no groups, falls back to `["admin"]`. The default is computed once, at creation time, using `IGroupManager::getUserGroups()` server-side OR (if OR allows pre-save defaulting via schema) declaratively in the schema's `default` clause.
  - Implement: prefer the declarative schema-default route if OR's `default` clause supports an `expression: "$user.groups[0] ?? 'admin'"` evaluator; otherwise a tiny `BeforeObjectCreated` listener (single file, single method). Record the chosen path in `hydra.json` decisions.
  - Test: PHPUnit/integration: create Application as user in group `team-alpha`, assert `permissions.owners = ["team-alpha"]`; create as groupless user, assert `permissions.owners = ["admin"]`.

## 4. Verification

- [ ] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all green; fix any pre-existing issues in touched files (memory rule).
- [ ] 4.2 Run `npm run lint` / ESLint flat config — clean on the new SFCs and composable.
- [ ] 4.3 Run `npm run check:manifest` (ADR-024) — passes; no manifest changes in this spec, but the gate is part of the standard pipeline.
- [ ] 4.4 Confirm no `OpenBuiltAuthorizationService.php` / `RbacService.php` / `PermissionService.php` (or similar) under `lib/Service/` — ADR-031 review gate.
- [ ] 4.5 Confirm no `<NcModal>` or `<NcDialog>` markup inline inside `ApplicationEditor.vue` — `gate-modal-isolation` (ADR-004 hard rule); permissions / permission-history modals live in `src/modals/` if rendered as modals.
- [ ] 4.6 Confirm every new `<NcSelect>` carries an `inputLabel` (or `ariaLabelCombobox`) prop — `gate-nc-input-labels` (ADR-004 hard rule).
- [ ] 4.7 Confirm no `document.getElementById('...').dataset` reads in any new SFC — `gate-initial-state` (ADR-004 hard rule).
- [ ] 4.8 Run all 13 Hydra gates locally via `bash scripts/run-hydra-gates.sh`.
- [ ] 4.9 Visually verify on a fresh `docker compose up`: (a) creating an Application as user `bob` defaults `permissions.owners` to `bob`'s primary group; (b) user `eve` (not in any of `bob`'s Application's permissions groups) cannot see the Application in the list and gets 403 on direct URL; (c) admin user can read the manifest with an audit entry written.

## 5. Tests (ADR-008)

- [ ] 5.1 **PHPUnit** — `tests/Unit/Controller/ApplicationsControllerTest.php` extends spec #1's tests with the six cases listed in 2.1 (owner/editor/viewer pass, no-role 403, admin-bypass writes audit, cross-org wins over RBAC).
- [ ] 5.2 **PHPUnit** — `tests/Unit/Repair/PopulateApplicationPermissionsTest.php` runs the migration twice over a fixture with one missing-permissions and one populated Application; asserts idempotence and correct defaults.
- [ ] 5.3 **Newman** — `tests/api/openbuilt-rbac.postman_collection.json` covers the manifest endpoint matrix from 5.1 over HTTP, plus PUT-to-`permissions` happy and orphan-rejection paths.
- [ ] 5.4 **Playwright** — `tests/e2e/openbuilt-rbac.spec.ts` covers: (a) list filter visibility, (b) viewer read-only editor, (c) editor save-but-no-publish, (d) owner full controls + transfer-ownership round-trip, (e) admin bypass triggers audit entry, (f) `openbuilt.use` navigation restriction hides the top-bar entry for non-permitted users.

## 6. Documentation (ADR-009, ADR-010)

- [ ] 6.1 Add `docs/openbuilt-rbac.md` documenting: the three roles, the default-on-creation behaviour, the manifest-endpoint enforcement, the list filter, the `openbuilt.use` navigation gate, the admin bypass + audit, the transfer-ownership flow, the operational caveat on group renames (design.md OQ-2), and the post-deploy "ACTION REQUIRED: re-grant access" runbook.
- [ ] 6.2 Update `docs/openbuilt-runtime.md` (from spec #1) with the new 403 path on `getManifest`.
- [ ] 6.3 NL Design (ADR-010) — confirm the new permissions panel and permission history panel use Nextcloud CSS variables only; WCAG AA on the role badges (owner/editor/viewer chips) — sufficient contrast against the panel background.
- [ ] 6.4 Update `openspec/app-config.json` to list `openbuilt-rbac` under capabilities (alongside the modified `openbuilt-application-register` and `openbuilt-runtime`).

## 7. i18n (ADR-005, ADR-007)

- [ ] 7.1 Add English translations for every new string in `l10n/en.json` — keys under `openbuilt.rbac.*` (role labels, empty-state copy, transfer-ownership modal, orphan-check error, audit-trail panel headings, admin-bypass tooltip).
- [ ] 7.2 Add Dutch translations for the same keys in `l10n/nl.json` (per workspace minimum nl+en).
- [ ] 7.3 Confirm every user-facing string in the new permissions panel, permission-history panel, and 403 response body uses translation keys (no hardcoded English).
