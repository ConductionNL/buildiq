## Deduplication Check

- [ ] **DC-01** Verify no custom Pinia store for Application CRUD exists — `createObjectStore`
  from `@conduction/nextcloud-vue` is the required pattern (ADR-001). Search
  `src/store/modules/` for any hand-rolled CRUD store that wraps OR's REST API. If found,
  remove and replace with `createObjectStore`. *(No overlap expected; document findings.)*

- [ ] **DC-02** Verify no custom file-upload handler or export controller exists in
  `lib/Controller/` beyond `ApplicationsController`. Any file/export concern must use
  `FileService` / `ExportService` from OR. *(No overlap expected.)*

- [ ] **DC-03** Confirm `ManifestDiff.vue` does not duplicate `CnJsonViewer` — that component
  is read-only display, not a diff surface. `jsdiff` + custom coloured-token rendering is
  the correct, non-duplicate path.

---

## 1. Backend — Manifest Endpoint Hardening

- [ ] **1.1 Add 403 permissions guard to `ApplicationsController::getManifest`**
  - spec_ref: REQ-OBR-013
  - files: `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: After resolving `{slug}` to an Application via `BuiltAppRoute`
    (existing) and before returning the manifest body, the method calls
    `IGroupManager::getUserGroups($userId)` and intersects the result with
    `Application.permissions.owners ∪ editors ∪ viewers`. If the intersection is empty
    (and the caller does not hold the audited admin bypass), the method returns
    `new JSONResponse(['error' => 'forbidden', 'code' => 'openbuilt.rbac.no_role'], 403)`.
    Response body MUST NOT contain the Application name, description, or any manifest
    fragment. The existing 404 branch is preserved and ordered before the 403 branch
    (slug not found → 404; slug found but no role → 403). `#[NoAdminRequired]` stays.
    ~10 LOC addition. No new service class (ADR-022 §Exceptions(1)).
  - test: PHPUnit `ApplicationsControllerTest` — assert 403 for caller with no role
    in `permissions`; assert 200 for caller in `permissions.editors`; assert 404 for
    unknown slug; assert 403 body does NOT contain manifest content.

- [ ] **1.2 Register the diff endpoint in `appinfo/routes.php`**
  - spec_ref: REQ-OBR-012
  - files: `appinfo/routes.php`, `lib/Controller/ApplicationsController.php`
  - acceptance_criteria: Route
    `GET /api/applications/{slug}/versions/diff` maps to
    `applications#getManifestDiff` with `#[NoAdminRequired]`. The method accepts
    `from` and `to` query params (ApplicationVersion UUIDs or the literal `draft`),
    fetches both manifest blobs from OR REST, and returns them unwrapped in a single
    JSON envelope `{ "from": { "uuid": "…", "manifest": {…} }, "to": { … } }`.
    ~15 LOC; uses the same org-scope resolution as `getManifest`; applies the same
    403 guard (caller must have at least one role on the Application).
  - test: Newman collection — assert 200 with both blobs for a known pair; assert 404
    for unknown slug; assert 403 for caller with no role.

---

## 2. Backend — Initial State Provider

- [ ] **2.1 Provide caller's group IDs via `IInitialState`**
  - spec_ref: REQ-OBR-016
  - files: `lib/AppInfo/Application.php` (or a new
    `lib/Provider/InitialStateProvider.php` registered there),
    relevant controller `index` action
  - acceptance_criteria: The OpenBuilt shell boot sequence calls
    `IInitialState::provideInitialState('openbuilt', 'currentUserGroups', $groups)`
    where `$groups` is the array of Nextcloud group IDs for the current user, obtained
    via `IGroupManager::getUserGroupIds($userId)`. The call is made on every page load
    that renders the OpenBuilt shell (i.e., the `index` action or a registered
    `InitialStateProvider`). Confirmed via the `gate-initial-state` Hydra gate that no
    DOM data-attribute reads exist anywhere in the frontend. No `document.getElementById`
    pattern introduced.
  - test: Playwright — boot the shell as user `bob` (in groups `team-alpha`, `qa-shared`);
    verify `loadState('openbuilt', 'currentUserGroups')` returns `["team-alpha",
    "qa-shared"]` by inspecting network traffic or Vue devtools store state.

---

## 3. Frontend — Schema Designer Routes and Schemas Menu

- [ ] **3.1 Register schema-designer outer routes**
  - spec_ref: REQ-OBR-006
  - files: `src/router/index.js`, `src/views/SchemaDesigner.vue` (new skeleton)
  - acceptance_criteria: Two new named routes registered in the OpenBuilt **outer**
    Vue router:
    - `{ path: '/builder/:slug/schemas', name: 'SchemaList', component: SchemaDesigner }`
    - `{ path: '/builder/:slug/schemas/:schemaId', name: 'SchemaDetail', component: SchemaDesigner }`
    The existing `/builder/:slug(.*)` catch-all route that mounts `BuilderHost.vue` /
    nested `CnAppRoot` is unaffected — these two routes are more specific and must be
    declared before the catch-all so the Vue router matches them first.
    `SchemaDesigner.vue` is a valid `.vue` SFC (may be a scaffold/placeholder at this
    stage; the full implementation is `openbuilt-schema-editor`'s concern).
  - test: Playwright — navigate to `/builder/hello-world/schemas`; assert `CnAppRoot`
    is NOT mounted; assert `SchemaDesigner.vue`'s root element is present.

- [ ] **3.2 Surface Schemas menu entry in `BuilderHost.vue`**
  - spec_ref: REQ-OBR-007
  - files: `src/views/BuilderHost.vue`, `l10n/en.json`, `l10n/nl.json`
  - acceptance_criteria: `BuilderHost.vue` renders an `NcAppNavigationItem` in the
    secondary navigation with `:to="{ name: 'SchemaList', params: { slug } }"` and
    `:name="t('openbuilt', 'openbuilt.builder.menu.schemas')"`. The entry is visible
    whenever the user is in a `/builder/:slug` context. Translation key
    `openbuilt.builder.menu.schemas` is present in both `l10n/en.json` (value: `"Schemas"`)
    and `l10n/nl.json` (value: `"Schema's"`).
  - test: Playwright — navigate to `/builder/hello-world`; assert "Schemas" entry is
    visible in the secondary nav; click it; assert URL changes to
    `/builder/hello-world/schemas`.

---

## 4. Frontend — Tabbed Manifest Editor

- [ ] **4.1 Refactor `ApplicationEditor.vue` to tabbed structure**
  - spec_ref: REQ-OBR-005
  - files: `src/views/ApplicationEditor.vue`
  - acceptance_criteria: The editor renders two sibling tabs:
    (1) "Design" — mounts `PageDesigner.vue` from `openbuilt-page-designer`; default
    selected. (2) "Raw JSON" — the existing `<textarea>` editor. Both tabs bind to the
    same reactive `inFlightManifest` object in the parent via `v-model` / provide/inject.
    Switching tabs does NOT trigger a save or re-fetch. The `validateManifest` call and
    its error surface are shared between both tabs (single error `ref` shown below the
    tabs). Save button is wired to both tabs' current state. Unsaved edits made in the
    Design tab appear in the Raw JSON textarea after switching (and vice versa).
  - implement: Options API; import and register every component used in `<template>`.
  - test: Playwright — edit a page title in the Design tab; switch to Raw JSON; assert
    the textarea JSON contains the edited title without having saved.

- [ ] **4.2 Add Publish action button**
  - spec_ref: REQ-OBR-008
  - files: `src/views/ApplicationEditor.vue`
  - acceptance_criteria: A "Publish" `NcButton` is rendered alongside Save. Clicking
    it: (a) runs `validateManifest` — aborts with inline error if invalid; (b) PUTs
    pending manifest changes to OR; (c) calls the Application's lifecycle transition
    endpoint to move from `draft → published`; (d) on success, shows an `NcToast` with
    the returned `ApplicationVersion.uuid`; (e) on failure, surfaces the error inline
    and leaves the manifest in draft state. The button has `:disabled="isPublishing"`
    while the lifecycle call is in flight. Hidden (`v-if`) when the caller's role is
    `editor` or `viewer` (per REQ-OBR-015 / `useRole`).
  - test: Playwright — open hello-world editor; click Publish with a valid manifest;
    assert toast appears with a UUID; assert Save and Publish are re-enabled after.
    Separately: paste an invalid manifest; click Publish; assert no network call to the
    lifecycle endpoint.

- [ ] **4.3 Add draft-vs-published status badge**
  - spec_ref: REQ-OBR-009
  - files: `src/views/ApplicationEditor.vue`, `src/views/ApplicationList.vue` (or
    wherever the list view lives)
  - acceptance_criteria: Each Application list row renders a `CnStatusBadge` bound to
    `application.status` (`draft` / `published` / `archived`). The editor header renders
    the same badge. When `application.status === 'draft'` AND the in-editor manifest
    differs from `currentVersion.manifest` (fetched alongside the Application), the
    editor header additionally shows a "modified since last publish" `<span>` or badge
    variant. All badge colours use Nextcloud CSS variables only — no hardcoded hex/rgb
    literals (ADR-010; enforced by `hydra-gate-nl-design` or ESLint).
  - test: Playwright — publish hello-world; assert list row shows `published` badge;
    edit the manifest without publishing; assert editor header shows "modified since
    last publish" marker.

---

## 5. Frontend — Versioning UI

- [ ] **5.1 Build `VersionHistory.vue`**
  - spec_ref: REQ-OBR-010
  - files: `src/views/VersionHistory.vue`
  - acceptance_criteria: Collapsible panel or sibling tab (implementer's choice) inside
    `ApplicationEditor.vue` that fetches OR REST
    `GET /apps/openregister/api/objects/openbuilt/application-version?applicationUuid={uuid}&_order[publishedAt]=desc`
    and renders one row per `ApplicationVersion` in newest-first order. Each row shows
    `version`, `publishedAt` (formatted via `@nextcloud/moment` or equivalent localisation),
    `publishedBy`, and `notes` (empty state when null). An empty-state (`CnEmptyState` or
    equivalent) is shown when the Application has no versions yet — no console error.
    Each row carries a "Roll back to this version" `NcActionButton` that opens
    `RollbackConfirmModal.vue`. No app-local wrapper service — reads OR REST directly.
  - test: Playwright — open an Application with 3 published versions; assert 3 rows in
    newest-first order; assert each row displays all four fields. Open a never-published
    Application; assert empty state is shown.

- [ ] **5.2 Build `RollbackConfirmModal.vue`**
  - spec_ref: REQ-OBR-011
  - files: `src/modals/RollbackConfirmModal.vue`
  - acceptance_criteria: An `NcModal`-based SFC living in `src/modals/` (Hydra
    modal-isolation gate; ADR-004). Props: `version` (the target `ApplicationVersion`
    object). Displays the target version number in the modal title. On confirm, calls
    parent via `@confirm` emit with the chosen version's `uuid`; parent then PUTs the
    snapshot manifest onto the Application (setting `status` to `draft`) and refreshes
    the editor. On cancel, emits `@cancel` — no PUT is sent, textarea unchanged.
    No `ApplicationVersion` row is deleted or mutated during rollback.
  - implement: modal markup lives entirely in this SFC — no inline `NcModal` in parent.
  - test: Playwright — open history panel; click "Roll back to this version" on the
    oldest row; cancel in the modal; assert textarea unchanged and no PUT fired.
    Repeat, this time confirm; assert the textarea manifest matches the snapshot and
    status stays `draft`.

- [ ] **5.3 Build `ManifestDiff.vue`**
  - spec_ref: REQ-OBR-012
  - files: `src/components/ManifestDiff.vue`, `package.json` (`jsdiff` dependency)
  - acceptance_criteria: Component accepts `from` (UUID or `'draft'`) and `to` (UUID)
    as props. On mount, fetches
    `GET /api/applications/{slug}/versions/diff?from={from}&to={to}`. Receives both
    manifest blobs in the response envelope. Canonicalises both blobs with
    `JSON.stringify(JSON.parse(blob), Object.keys(…).sort(), 2)` before passing to
    `jsdiff.diffLines()`. Renders three line classes: added lines (green background via
    `var(--color-success-bg)`), removed lines (red background via `var(--color-error-bg)`),
    unchanged lines (default). Default preselection when opened from the editor:
    `from='draft'`, `to=<application.currentVersion>`. No hardcoded colour literals
    (ADR-010). No second server-side diff round-trip.
  - test: Playwright — publish hello-world; edit the manifest; open diff view; assert
    added/removed lines are rendered; assert the diff was computed client-side (only one
    network call to the diff endpoint, not two separate manifest fetches).

---

## 6. Frontend — Role Enforcement

- [ ] **6.1 Implement `useRole(application)` composable**
  - spec_ref: REQ-OBR-015, REQ-OBR-016
  - files: `src/composables/useRole.js`
  - acceptance_criteria: Vue 2 composable (or `defineStore`-based if reuse is
    beneficial). Accepts an `application` reactive ref or plain object. Reads
    `loadState('openbuilt', 'currentUserGroups')` once on boot (or from a shared Pinia
    state if already loaded). Derives the effective role:
    - `'owner'` if any caller group ∈ `application.permissions.owners`
    - `'editor'` if any caller group ∈ `application.permissions.editors`
    - `'viewer'` if any caller group ∈ `application.permissions.viewers`
    - `'none'` otherwise (owner takes precedence over editor over viewer if multi-role).
    Returns `{ role: Ref<'owner'|'editor'|'viewer'|'none'> }`. Does NOT call
    `document.getElementById`, `OC.getCurrentUser()`, or any custom fetch endpoint.
  - test: Unit test (Jest / Vitest) — mock `loadState` to return `['team-alpha']`;
    pass an Application with `permissions.editors = ['team-alpha']`; assert role is
    `'editor'`. Repeat for owner, viewer, none.

- [ ] **6.2 Wire role-keyed action gating in `ApplicationEditor.vue`**
  - spec_ref: REQ-OBR-015
  - files: `src/views/ApplicationEditor.vue`
  - acceptance_criteria: Import and call `useRole(application)` in the editor.
    - viewer: textarea set to `:readonly="true"`; Save, Publish, Archive, Delete,
      Transfer-ownership, Permissions buttons hidden via `v-if="role !== 'viewer'"`.
    - editor: textarea editable; Save enabled; Publish, Archive, Delete, Transfer,
      Permissions hidden via `v-if="role === 'owner'"`.
    - owner: all controls visible and enabled; Permissions panel and Permission history
      panel are reachable.
    No `v-show` for security-relevant controls — use `v-if` (element is not in the DOM,
    not just invisible).
  - test: Playwright — log in as viewer-role user; open hello-world editor; assert Save
    button is absent from the DOM; assert textarea has `readonly` attribute. Log in as
    editor; assert Save is present but Publish is absent. Log in as owner; assert all
    controls are present.

- [ ] **6.3 Wire role-keyed filtering on the Application list view**
  - spec_ref: REQ-OBR-014
  - files: `src/views/ApplicationList.vue` (or wherever the list is rendered),
    `src/store/modules/applications.js`
  - acceptance_criteria: The list view first checks whether OR returns a pre-filtered
    set (via `x-openregister-authorization` on the Application schema). If yes, renders
    the OR response directly. If no, post-filters the raw OR list in JS:
    `applications.filter(app => intersects(callerGroups, allRoles(app.permissions)))`.
    `callerGroups` comes from `loadState('openbuilt', 'currentUserGroups')`.
    Applications with no intersection are absent from the DOM (not greyed out).
    When the filtered list is empty, renders an `CnEmptyState` with the message
    `t('openbuilt', 'No applications available — ask an owner to grant you access')`.
  - test: Playwright — create Applications A (owners: `['team-alpha']`), B
    (editors: `['other-team']`), C (viewers: `['qa-shared']`). Log in as user in
    `['team-alpha', 'qa-shared']`. Assert only A and C appear in the list.

---

## 7. Seed Data

- [ ] **7.1 Verify or restore `hello-world` seed**
  - spec_ref: REQ-OBR-004
  - files: `lib/Repair/SeedHelloWorld.php` (or `lib/Repair/SeedHelloWorldApp.php`
    under the creation-wizard model from `openbuilt-app-creation-wizard`)
  - acceptance_criteria: The seed creates (idempotently):
    - One `Application` (slug `hello-world`, status `published`, Dutch-valued manifest
      per design.md Seed Data section) with `permissions.owners = ['admin']`.
    - One `BuiltAppRoute` (slug `hello-world`).
    - Three `hello-message` objects with Dutch titles/bodies as specified in design.md.
    Guard: skips if a `hello-world` Application already exists in the system org scope.
    Re-running twice produces no duplicates. Called via
    `ConfigurationService::importFromApp()` for schema registration.
  - test: PHPUnit — run repair step twice; assert exactly one `hello-world` Application
    and three hello-message objects exist after each run.

---

## 8. Tests

- [ ] **8.1 PHPUnit — `ApplicationsControllerTest` (403 guard)**
  - spec_ref: REQ-OBR-013
  - files: `tests/Unit/Controller/ApplicationsControllerTest.php`
  - acceptance_criteria: Covers 200 happy path (caller in `permissions.editors`),
    403 (caller in no group), 404 (unknown slug), and asserts 403 body does not
    contain manifest content. All assertions on status code AND response body shape.

- [ ] **8.2 Newman — manifest endpoint + diff endpoint**
  - spec_ref: REQ-OBR-001, REQ-OBR-012, REQ-OBR-013
  - files: `tests/integration/openbuilt.postman_collection.json`
  - acceptance_criteria: Adds requests: `GET /api/applications/hello-world/manifest`
    (200 + blob shape), `GET /api/applications/unknown/manifest` (404),
    `GET /api/applications/hello-world/manifest` as unauthorised user (403 + error body),
    `GET /api/applications/hello-world/versions/diff?from=draft&to=<uuid>` (200 +
    envelope shape). All with status + JSON-schema assertions.

- [ ] **8.3 Playwright — nested CnAppRoot and manifest editor**
  - spec_ref: REQ-OBR-002, REQ-OBR-003, REQ-OBR-005
  - files: `tests/e2e/builder-host.spec.ts`, `tests/e2e/application-editor.spec.ts`
  - acceptance_criteria: `builder-host.spec.ts` navigates to
    `/builder/hello-world` and asserts the index page renders the three hello-message
    objects; navigates to `/builder/hello-world/berichten/<uuid>` and asserts the detail
    page renders. `application-editor.spec.ts` covers the tabbed editor tab-switch
    scenario, invalid manifest save block, valid save PUT, and Design/Raw JSON
    cross-tab state persistence.

- [ ] **8.4 Playwright — schema routes and schemas menu**
  - spec_ref: REQ-OBR-006, REQ-OBR-007
  - files: `tests/e2e/schema-designer.spec.ts`
  - acceptance_criteria: Navigate to `/builder/hello-world/schemas`; assert
    `SchemaDesigner.vue` root is present and `CnAppRoot` is absent. From
    `/builder/hello-world`, assert "Schemas" nav entry is visible; click it; assert
    URL is `/builder/hello-world/schemas`.

- [ ] **8.5 Playwright — versioning UI (VersionHistory, rollback, diff)**
  - spec_ref: REQ-OBR-010, REQ-OBR-011, REQ-OBR-012
  - files: `tests/e2e/version-history.spec.ts`
  - acceptance_criteria: Publishes hello-world twice to seed two snapshots; asserts
    history panel shows two rows newest-first; asserts rollback to oldest row restores
    manifest and keeps status `draft`; asserts diff view shows added/removed lines
    between draft and latest published.

- [ ] **8.6 Playwright — role enforcement**
  - spec_ref: REQ-OBR-013, REQ-OBR-014, REQ-OBR-015, REQ-OBR-016
  - files: `tests/e2e/rbac.spec.ts`
  - acceptance_criteria: Three test users (owner, editor, viewer) + one user with no
    role. Asserts: owner sees all controls; editor sees Save but not Publish; viewer
    sees read-only textarea; no-role user gets 403 on manifest endpoint; no-role user
    sees empty Application list with correct empty-state message.

---

## 9. Verification Gates

- [ ] **9.1** Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — all green on
  touched PHP files. Fix any pre-existing issues in touched files.

- [ ] **9.2** Run `npm run lint` / ESLint — clean on all new and modified `.vue` / `.js`
  files. Confirm no hardcoded colour literals (ADR-010).

- [ ] **9.3** Run Hydra `gate-initial-state` — confirm no `document.getElementById().dataset`
  reads in any `.vue` / `.js` file in `src/`.

- [ ] **9.4** Run Hydra `gate-modal-isolation` — confirm `RollbackConfirmModal.vue` is the
  only SFC containing `NcModal`; no inline modal markup in `ApplicationEditor.vue` or
  `VersionHistory.vue`.

- [ ] **9.5** Run Hydra `gate-no-admin-idor` — confirm `ApplicationsController::getManifest`
  has `#[NoAdminRequired]` and a permissions check; confirm no controller method with
  `#[NoAdminRequired]` skips an authorization check.

- [ ] **9.6** Run Hydra `gate-route-auth` — confirm all new routes in `appinfo/routes.php`
  (manifest, diff) are declared with `#[NoAdminRequired]` and match the
  authenticated-user-readable posture.

- [ ] **9.7** Visual verify on `docker compose up` — fresh install:
  `/index.php/apps/openbuilt/builder/hello-world` renders the seeded hello-world virtual
  app; the Schemas menu entry is visible; the tabbed editor defaults to the Design tab;
  the version history panel renders an empty state for a never-published Application.

---

## 10. i18n

- [ ] **10.1 Add translation keys for schema designer nav entry**
  - spec_ref: REQ-OBR-007
  - files: `l10n/en.json`, `l10n/nl.json`
  - keys:
    - `openbuilt.builder.menu.schemas`: `"Schemas"` / `"Schema's"`

- [ ] **10.2 Add translation keys for versioning UI**
  - spec_ref: REQ-OBR-008, REQ-OBR-009, REQ-OBR-010, REQ-OBR-011, REQ-OBR-012
  - files: `l10n/en.json`, `l10n/nl.json`
  - keys (representative subset — implementer adds all strings used in templates):
    - `openbuilt.editor.publish`: `"Publish"` / `"Publiceren"`
    - `openbuilt.editor.publishing`: `"Publishing…"` / `"Publiceren…"`
    - `openbuilt.editor.publishSuccess`: `"Published as version {uuid}"` /
      `"Gepubliceerd als versie {uuid}"`
    - `openbuilt.editor.status.draft`: `"Draft"` / `"Concept"`
    - `openbuilt.editor.status.published`: `"Published"` / `"Gepubliceerd"`
    - `openbuilt.editor.status.archived`: `"Archived"` / `"Gearchiveerd"`
    - `openbuilt.editor.modifiedSincePublish`: `"Modified since last publish"` /
      `"Gewijzigd sinds laatste publicatie"`
    - `openbuilt.editor.versionHistory`: `"Version history"` / `"Versiegeschiedenis"`
    - `openbuilt.editor.rollback`: `"Roll back to this version"` /
      `"Terugzetten naar deze versie"`
    - `openbuilt.editor.rollbackConfirm`: `"Roll back to version {version}?"` /
      `"Terugzetten naar versie {version}?"`
    - `openbuilt.editor.diff`: `"Compare versions"` / `"Versies vergelijken"`

- [ ] **10.3 Add translation keys for RBAC UI**
  - spec_ref: REQ-OBR-014, REQ-OBR-015
  - files: `l10n/en.json`, `l10n/nl.json`
  - keys:
    - `openbuilt.list.empty.noRole`:
      `"No applications available — ask an owner to grant you access"` /
      `"Geen applicaties beschikbaar — vraag een eigenaar om toegang"`

- [ ] **10.4 Confirm all new `t()` calls use existing or newly added keys** — no
  hardcoded English strings in Vue templates or JS logic. Checked via `npm run lint`
  and manual inspection of new SFCs.
