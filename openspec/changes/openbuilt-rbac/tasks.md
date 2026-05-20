## 1. Implementation Tasks — openbuilt-application-register (modified)

- [ ] 1.1 **Add `permissions` property to the `Application` schema**
  - spec_ref: REQ-OBA-006
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares optional `permissions` object
    with three required-when-present `string[]` arrays — `owners`,
    `editors`, `viewers`. `additionalProperties: false` on the
    `permissions` object. Existing Applications remain schema-valid
    (property is optional). Validates against OpenAPI 3.0.0.
  - Implement: declarative — JSON Schema patch in the register file.
    No PHP service class. No new state machine.
  - Test: integration test creates an Application with `permissions`
    via OR REST and asserts round-trip equality; creates another with
    an unknown sub-key (`admins`) and asserts `4xx`.

- [ ] 1.2 **(Conditional) Declare `x-openregister-authorization` read rule on Application**
  - spec_ref: REQ-OBRBAC-003, REQ-OBR-007
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: If OR's `x-openregister-authorization`
    vocabulary supports a `groupIn-pointer` predicate, declare the
    read rule `anyOf: [{ groupIn: "permissions.owners" }, { groupIn:
    "permissions.editors" }, { groupIn: "permissions.viewers" }]`. If
    not supported, skip this task, record the decision in `hydra.json`
    under `decisions[]`, and file an OR-side issue requesting the
    predicate and link it here.
  - Implement: declarative-preferred per design.md Decision 1; the
    apply agent decides at apply time based on OR's current
    `x-openregister-authorization` capability.
  - Test: integration test as user-A (no role on Application X) lists
    Applications via OR REST and asserts X is absent; as user-B (with
    viewer role on X) asserts X is present.

- [ ] 1.3 **Ship the permissions-population migration repair step**
  - spec_ref: REQ-OBA-007
  - files: `lib/Repair/PopulateApplicationPermissions.php`,
    `appinfo/info.xml` (register as `<post-migration>` step after the
    existing `InitializeSettings` and `SeedHelloWorld` steps)
  - acceptance_criteria: For every existing `Application` whose
    `permissions` is missing/null or whose `permissions.owners` is
    empty, patches `permissions = { owners: ["admin"], editors: [],
    viewers: [] }`. Idempotent: skips Applications whose
    `permissions.owners` is already non-empty. One OR REST round-trip
    per Application. PHP file carries SPDX + EUPL-1.2 docblock
    (ADR-014); no scripting (sed/awk) used to modify the file.
  - Implement: PHP repair step; uses
    `OCA\OpenRegister\Service\ObjectService::saveObject($entityOrArray)`
    (first arg is entity/array, not type string).
  - Test: PHPUnit runs the repair step twice against a fixture with
    one Application without permissions and one with; asserts first
    run patches only the first, second run patches nothing, and the
    patched permissions match the default.

## 2. Implementation Tasks — openbuilt-runtime (modified)

- [ ] 2.1 **Add the permissions check to `ApplicationsController::getManifest`**
  - spec_ref: REQ-OBR-006, REQ-OBRBAC-002
  - files: `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: After org-scope resolution and Application
    lookup, compute caller's group set via
    `\OCP\IGroupManager::getUserGroups()`; intersect with
    `permissions.owners ∪ editors ∪ viewers`. If empty and caller is
    not in the `admin` group, return
    `JSONResponse({ error: 'forbidden', code: 'openbuilt.rbac.no_role' }, 403)`.
    The 403 branch SHALL appear before any code path that touches the
    manifest payload. If caller IS in `admin` group and is bypassing,
    write a `rbac.admin_bypass` audit entry to the OR audit trail
    before returning 200. ~12 LOC added; existing SPDX + EUPL-1.2
    docblock preserved; `#[NoAdminRequired]` attribute preserved.
  - Implement: in-controller; no new service class (ADR-022
    §Exceptions(1)).
  - Test: PHPUnit covers (a) member-of-owners → 200, (b)
    member-of-editors → 200, (c) member-of-viewers → 200, (d) no
    role → 403, (e) admin bypass → 200 + audit entry written, (f)
    cross-org → 404 (org check still wins over RBAC).

- [ ] 2.2 **Provide caller's group set via `IInitialState`**
  - spec_ref: REQ-OBR-009
  - files: `lib/AppInfo/Application.php` (register
    `InitialStateProvider`) OR `lib/Controller/PageController.php`
    (existing page render path)
  - acceptance_criteria: On every OpenBuilt page render,
    `IInitialState::provideInitialState('openbuilt', 'currentUserGroups',
    $gids)` is called with the caller's group IDs
    (`IGroupManager::getUserGroups()->map(getGID)`). Namespace:
    `openbuilt`, key: `currentUserGroups`. No DOM data-attribute
    alternative is shipped — ADR-004 hard rule (enforced by
    `hydra-gate-initial-state`).
  - Implement: PHP, ~5 LOC added to the existing render path.
  - Test: Playwright asserts
    `window.OCP.InitialState.loadState('openbuilt', 'currentUserGroups')`
    returns the user's gid array on shell boot.

- [ ] 2.3 **Filter the Application list view by role**
  - spec_ref: REQ-OBR-007, REQ-OBRBAC-003
  - files: `src/views/ApplicationEditor.vue` (list mode),
    `src/composables/useRole.js` (new)
  - acceptance_criteria: If OR returned a pre-filtered list (task
    1.2 path taken), render as-is. Otherwise, filter in JS using
    `loadState('openbuilt', 'currentUserGroups')` and the
    Application's `permissions`. Empty-state UI copy:
    "Geen applicaties beschikbaar — vraag een eigenaar om toegang te
    verlenen." (NL) / "No applications available — ask an owner to
    grant you access." (EN). Frontend uses `loadState` from
    `@nextcloud/initial-state`; no `document.getElementById().dataset`
    reads (ADR-004 / `hydra-gate-initial-state`).
  - Implement: Options API; use `createObjectStore` if list state is
    needed beyond view-local.
  - Test: Playwright as user with no role asserts empty list + empty-
    state copy; as user with one viewer role asserts list of exactly
    one Application; as user with multiple roles asserts correct
    cardinality.

- [ ] 2.4 **Gate destructive editor actions via `useRole`**
  - spec_ref: REQ-OBR-008, REQ-OBRBAC-004
  - files: `src/composables/useRole.js` (extends the one created in
    2.3), `src/views/ApplicationEditor.vue` (consume in template)
  - acceptance_criteria: `useRole(application)` is a pure function
    returning `'owner' | 'editor' | 'viewer' | 'none'` from the
    Application's `permissions` and
    `loadState('openbuilt', 'currentUserGroups')`. Template uses
    `v-if="role === 'owner'"` on Publish / Archive / Re-open / Delete
    / Transfer / Permissions panel; Save button hidden via
    `v-if="role !== 'viewer'"` or `v-if="role !== 'none'"`; viewer
    sees the textarea with the `readonly` attribute set.
  - Implement: ~25 LOC pure composable + ~10 LOC template guards.
  - Test: Playwright covers viewer (textarea read-only, no
    Save/Publish), editor (Save visible, Publish hidden), owner (all
    controls visible).

- [ ] 2.5 **Build the Permissions panel (owner-only)**
  - spec_ref: REQ-OBRBAC-005, REQ-OBRBAC-007, REQ-OBR-008
  - files: `src/modals/PermissionsModal.vue` (new, per ADR-004
    modal-isolation rule)
  - acceptance_criteria: Owner-only (`v-if="role === 'owner'"`) panel
    showing three group pickers (owners, editors, viewers) bound to
    the Application's `permissions` arrays. Save PUTs the updated
    `permissions` block via OR REST. Frontend-side guard rejects an
    `owners = []` PUT before sending; OR REST returns `4xx` if the
    guard is bypassed. Modal lives in `src/modals/` per ADR-004
    (`hydra-gate-modal-isolation` — no inline `<NcModal>` inside
    `ApplicationEditor.vue`). Group pickers are `<NcSelect>` with the
    required `inputLabel` (or `ariaLabelCombobox`) prop per ADR-004
    (`hydra-gate-nc-input-labels`).
  - Implement: Vue 2 + `@conduction/nextcloud-vue` `<NcSelect>` for
    group pickers; fetch Nextcloud groups via OR REST or a thin proxy
    if no public Nextcloud groups endpoint is available to the user.
  - Test: Playwright as owner: opens modal, transfers ownership from
    `team-alpha` to `team-beta`, saves; asserts subsequent list-view
    as the old-owner user is empty (access revoked); asserts orphan-
    check rejects an `owners = []` save.

- [ ] 2.6 **Add the Permission history panel (owner-only, read-only)**
  - spec_ref: REQ-OBRBAC-007
  - files: `src/modals/PermissionHistoryModal.vue` (new, per ADR-004
    modal-isolation)
  - acceptance_criteria: Owner-only read view rendering OR's per-
    object audit trail filtered to `permissions` changes (and
    `rbac.admin_bypass` events). No new audit endpoint; consume OR's
    existing audit REST. Renders before/after `permissions` values,
    actor UID, and timestamp. Modal lives in `src/modals/` per ADR-004.
  - Implement: read-only Vue panel; no PHP additions.
  - Test: Playwright as owner asserts panel renders the permission
    changes made in task 2.5; as editor asserts panel is not visible
    and a direct fetch returns `4xx` (OR's audit endpoint enforces
    this — verify).

## 3. Implementation Tasks — openbuilt-rbac (new) + Nextcloud integration

- [ ] 3.1 **Declare `openbuilt.use` group-permission on the navigation entry**
  - spec_ref: REQ-OBRBAC-006
  - files: `appinfo/info.xml`
  - acceptance_criteria: The existing `<navigations><navigation>`
    block gains `<permission>openbuilt.use</permission>`. Default: no
    restriction → all authenticated users see the entry. An
    administrator can restrict the entry to groups via Nextcloud's
    admin UI ("Apps → OpenBuilt → Restrict to groups"). No new admin-
    settings page is shipped (design.md Decision 4). If the upstream
    `apps/info.xsd` rejects the element (nextcloud/server#60310),
    document the fallback (`occ app:enable openbuilt --groups <group>`)
    and skip the info.xml change until upstream merges it; record the
    decision in `hydra.json`.
  - Implement: `info.xml` patch only.
  - Test: manual smoke — admin restricts the entry to group
    `digital-team`, verifies entry hidden for users outside that
    group, verifies direct URL access returns Nextcloud's standard
    navigation-forbidden response.

- [ ] 3.2 **Set the creator's primary group as `owners` on Application creation**
  - spec_ref: REQ-OBRBAC-001
  - files: Application creation path (frontend create modal in
    `src/views/ApplicationEditor.vue` or server-side in the OR write
    path); or a `BeforeObjectCreated` listener in a single new
    `lib/EventListener/SetDefaultPermissionsListener.php` if OR
    does not support a declarative schema-default expression.
  - acceptance_criteria: A POST to OR REST creating an Application
    without `permissions` ends up with
    `permissions.owners = [<creator's primary gid>]`,
    `permissions.editors = []`, `permissions.viewers = []`. If the
    creator has no groups, falls back to `["admin"]`. The default is
    computed at creation time using `IGroupManager::getUserGroups()`.
    Record the chosen implementation path (declarative schema-default
    vs. listener) in `hydra.json` under `decisions[]`.
  - Implement: prefer the declarative schema `default` route if OR's
    schema engine supports an
    `expression: "$user.groups[0] ?? 'admin'"` evaluator; otherwise
    a single-method listener. No `DefaultPermissionsService` class.
  - Test: integration test — create Application as user in group
    `team-alpha`, assert `permissions.owners = ["team-alpha"]`; create
    as groupless user, assert `permissions.owners = ["admin"]`.

## 4. Deduplication Check

- [ ] 4.1 **Verify no overlap with existing OR abstractions**
  - Search `openspec/specs/` and `openregister/lib/Service/` for any
    existing `AuthorizationService`, `RbacService`, or role-management
    capability that this change would duplicate.
  - Search for any existing `permissions` property on the Application
    schema that pre-dates this change (guard against double-adding).
  - Document findings in `hydra.json` under `deduplication[]`.
  - Expected result: no overlap found — the only permissions metadata
    on Application is the one introduced by this change (per the
    archived spec #7 which is the direct predecessor).

## 5. Verification

- [ ] 5.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)
  — all green; fix any pre-existing issues in touched files.
- [ ] 5.2 Run `npm run lint` / ESLint flat config — clean on new SFCs
  and the `useRole` composable.
- [ ] 5.3 Run `npm run check:manifest` (ADR-024) — passes; no manifest
  changes in this spec but the gate is part of the standard pipeline.
- [ ] 5.4 Confirm no `OpenBuiltAuthorizationService.php` /
  `RbacService.php` / `PermissionService.php` (or similar) under
  `lib/Service/` — ADR-031 review gate.
- [ ] 5.5 Confirm no `<NcModal>` or `<NcDialog>` markup inline inside
  `ApplicationEditor.vue` — `hydra-gate-modal-isolation` (ADR-004
  hard rule); permissions and permission-history modals live in
  `src/modals/`.
- [ ] 5.6 Confirm every new `<NcSelect>` carries an `inputLabel` (or
  `ariaLabelCombobox`) prop — `hydra-gate-nc-input-labels` (ADR-004
  hard rule).
- [ ] 5.7 Confirm no `document.getElementById('...').dataset` reads in
  any new SFC — `hydra-gate-initial-state` (ADR-004 hard rule).
- [ ] 5.8 Run all Hydra gates locally via `bash scripts/run-hydra-gates.sh`.
- [ ] 5.9 Visually verify on a fresh `docker compose up`:
  (a) creating an Application as user `bob` defaults
  `permissions.owners` to `bob`'s primary group;
  (b) user `eve` (not in any of `bob`'s Application's permissions
  groups) cannot see the Application in the list and gets 403 on
  direct URL;
  (c) admin user can read the manifest with a `rbac.admin_bypass`
  audit entry written.

## 6. Tests (ADR-008)

- [ ] 6.1 **PHPUnit** —
  `tests/Unit/Controller/ApplicationsControllerTest.php` extends
  spec #1's tests with the six cases listed in task 2.1: owner/editor/
  viewer pass, no-role 403, admin-bypass writes audit entry, cross-org
  404 wins over RBAC.
- [ ] 6.2 **PHPUnit** —
  `tests/Unit/Repair/PopulateApplicationPermissionsTest.php` runs the
  migration twice over a fixture with one missing-permissions Application
  and one populated Application; asserts idempotence and correct
  defaults.
- [ ] 6.3 **Newman** —
  `tests/api/openbuilt-rbac.postman_collection.json` covers the
  manifest endpoint access matrix (owner/editor/viewer/none/admin) over
  HTTP, plus PUT-to-`permissions` happy path and orphan-rejection path.
- [ ] 6.4 **Playwright** —
  `tests/e2e/openbuilt-rbac.spec.ts` covers:
  (a) list filter visibility (3-of-10 scenario);
  (b) viewer read-only editor (textarea `readonly`, no Save/Publish);
  (c) editor save-but-no-publish (Save enabled, Publish hidden);
  (d) owner full controls + transfer-ownership round-trip (access
  revoked for previous owner on next page load);
  (e) admin bypass triggers `rbac.admin_bypass` audit entry;
  (f) `openbuilt.use` navigation restriction hides the top-bar entry
  for non-permitted users (if info.xml change passed validation).

## 7. Documentation (ADR-009)

- [ ] 7.1 Add `docs/openbuilt-rbac.md` documenting: the three roles,
  default-on-creation behaviour, manifest-endpoint enforcement, list
  filter, `openbuilt.use` navigation gate, admin bypass + audit,
  transfer-ownership flow, operational caveat on group renames (design
  OQ-2), and the post-deploy "ACTION REQUIRED: re-grant access" runbook.
- [ ] 7.2 Update `docs/openbuilt-runtime.md` (from spec #1) with the
  new 403 path on `getManifest`.
- [ ] 7.3 NL Design (ADR-010) — confirm the new Permissions panel and
  Permission history panel use Nextcloud CSS variables only (`var(--color-*)`)
  and meet WCAG AA on the role badges (owner/editor/viewer chips) —
  sufficient contrast ratio against the panel background.
- [ ] 7.4 Update `openspec/app-config.json` to confirm `openbuilt-rbac`
  is listed under `capabilities` (it is already present; verify the
  entry is accurate after this change).

## 8. i18n (ADR-007, ADR-025)

- [ ] 8.1 Add English translations for every new user-visible string in
  `l10n/en.json` — keys under `openbuilt.rbac.*`:
  - Role labels: `openbuilt.rbac.role.owner`, `.editor`, `.viewer`
  - Empty-state copy: `openbuilt.rbac.list.empty`
  - Transfer-ownership modal title and confirmation
  - Orphan-check error: `openbuilt.rbac.error.orphan`
  - Permission history panel headings
  - Admin-bypass tooltip: `openbuilt.rbac.admin_bypass.tooltip`
  - 403 toast: `openbuilt.rbac.error.no_role`
- [ ] 8.2 Add Dutch translations for the same keys in `l10n/nl.json`
  (workspace minimum: nl + en).
- [ ] 8.3 Confirm every user-facing string in the new Permissions panel,
  Permission history panel, and 403 response toast uses translation
  keys via `t(appName, 'key')` — no hardcoded English strings.
