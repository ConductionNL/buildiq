---
kind: code
depends_on:
  - bootstrap-openbuilt
  - openbuilt-schema-editor
  - openbuilt-versioning
  - openbuilt-rbac
chain:
  - bootstrap-openbuilt                    # #1 — foundational shell + manifest endpoint
  - nextcloud-vue-in-memory-manifest       # #2 — lives in nextcloud-vue repo
  - openregister-runtime-schema-api        # #3 — lives in openregister repo
  - openbuilt-schema-editor                # #4 — visual schema designer
  - openbuilt-page-editor                  # #5 — visual manifest/page designer
  - openbuilt-versioning                   # #6 — draft / publish / rollback
  - openbuilt-rbac                         # #7 — per-built-app permissions
  - openbuilt-templates-marketplace        # #8 — starter templates
  - openbuilt-export-to-real-app           # #9 — Phase-2 export to a real Nextcloud app
---

## Why

`bootstrap-openbuilt` (spec #1) delivered the minimal viable `openbuilt-runtime`
capability: manifest endpoint, nested `CnAppRoot` host, plain `<textarea>` manifest
editor, and the seeded `hello-world` Application. Three subsequent chain specs layered
ADDED Requirements onto this capability:

- **`openbuilt-schema-editor`** (chain #4) introduced schema-designer routes
  (`/builder/:slug/schemas` and `/builder/:slug/schemas/:schemaId`) rendered by a new
  `SchemaDesigner.vue`, a **Schemas** menu entry in the builder secondary nav, and the
  constraint that those routes render via the **outer** OpenBuilt router — not the nested
  `CnAppRoot` inner router.
- **`openbuilt-versioning`** (chain #6) upgraded the `<textarea>` editor to a
  **tabbed** surface sharing one in-flight manifest state (Design tab + Raw JSON tab),
  added a **Publish** action button and a `draft → published` lifecycle trigger,
  introduced a **draft-vs-published status badge** in the list and editor header, shipped
  `VersionHistory.vue` with a reverse-chronological snapshot list, a "Roll back to this
  version" action (confirmation modal required), and `ManifestDiff.vue` for client-side
  side-by-side diff.
- **`openbuilt-rbac`** (chain #7) added a `403 Forbidden` branch to
  `ApplicationsController::getManifest` when the caller is not in any authorised group,
  a role-filtered Application list view that hides unauthorised Applications (not just
  grey them out), a `useRole(application)` composable gating destructive editor actions
  per effective role (`owner | editor | viewer | none`), and an
  `IInitialState::provideInitialState` call that echoes the caller's Nextcloud group IDs
  to the frontend so that the composable never reads from DOM data-attributes (ADR-004).

Each spec contributed its additions independently; the `openbuilt-runtime` capability
spec accumulated them as "ADDED Requirements" sections. This change is the **canonical
consolidated spec** for `openbuilt-runtime` as it must exist after the full chain
completes. It is the authoritative implementation target and the reference point for
the apply agent.

## What Changes

### Backend

- **MODIFIED** `ApplicationsController::getManifest` — adds a permissions guard
  (REQ-OBR-013) returning `403 Forbidden` when the caller is not in any of the
  Application's `permissions.owners ∪ editors ∪ viewers` groups, ordered before
  the manifest-body emission. Implemented as a single inline group-intersection loop
  using `IGroupManager::getUserGroups()`. No service class (ADR-022 §Exceptions(1)).
  The existing `404` (slug not found) branch is preserved.

- **NEW** `IInitialState::provideInitialState('openbuilt', 'currentUserGroups', string[])`
  call (REQ-OBR-016) in the relevant controller's `index` action or a dedicated
  `InitialStateProvider` registered in `lib/AppInfo/Application.php`. Provides the
  caller's Nextcloud group IDs to the frontend; the frontend consumes them exclusively
  via `loadState('openbuilt', 'currentUserGroups')` from `@nextcloud/initial-state`
  (ADR-004 hard rule — no `document.getElementById` or `dataset` reads).

### Frontend

- **MODIFIED** `BuilderHost.vue` — surfaces a **Schemas** secondary-nav menu entry
  (REQ-OBR-007) routing to `/builder/{slug}/schemas`. Registers two new routes in the
  OpenBuilt **outer** router (not the inner `CnAppRoot` router):
  - `/builder/:slug/schemas` → `SchemaDesigner.vue`
  - `/builder/:slug/schemas/:schemaId` → `SchemaDesigner.vue`

  The existing `/builder/:slug/*` virtual-app preview route continues to mount the
  nested `CnAppRoot` per REQ-OBR-002 and is unaffected by this addition.

- **MODIFIED** `ApplicationEditor.vue` — restructures the manifest editor into a
  **tabbed** surface (REQ-OBR-005): (1) **Design** tab mounting `PageDesigner.vue`
  from the `openbuilt-page-designer` capability (default-selected); (2) **Raw JSON** tab
  containing the original `<textarea>`. Both tabs share one in-flight manifest state —
  edits made in one tab are visible in the other on tab switch without saving. Both tabs
  validate via `validateManifest` from `@conduction/nextcloud-vue` and share one error
  surface. Both PUT to OR on save.

  The editor also gains:
  - **Publish** action button (REQ-OBR-008) — validates manifest, PUTs pending changes,
    calls the `draft → published` lifecycle transition, surfaces a confirmation toast
    with the new `ApplicationVersion.uuid`, disabled while the lifecycle call is in
    flight.
  - **Draft-vs-published badge** (REQ-OBR-009) — appears in the list row and editor
    header; uses Nextcloud CSS variables only (ADR-010); shows a "draft modified since
    last publish" marker when draft manifest diverges from the most recent
    `ApplicationVersion.manifest`.
  - **Role-keyed action gating** (REQ-OBR-015) via the `useRole(application)` composable:
    viewer → read-only textarea, all destructive controls hidden (`v-if`);
    editor → editable textarea, Save enabled, Publish / Archive / Delete / Transfer /
    Permissions hidden; owner → all controls visible and enabled.

- **NEW** `src/views/SchemaDesigner.vue` — schema list / designer view rendered by
  the two outer-router schema routes added above (REQ-OBR-006).

- **NEW** `src/views/VersionHistory.vue` — collapsible panel or sibling tab inside
  `ApplicationEditor.vue` (REQ-OBR-010). Lists every `ApplicationVersion` row for the
  current Application in reverse-chronological order (newest first). Reads from OR REST
  filtered by `applicationUuid` — no app-local wrapper service. Each row shows `version`,
  `publishedAt` (localised), `publishedBy`, and `notes`. Each row carries a "Roll back
  to this version" action.

- **NEW** `src/modals/RollbackConfirmModal.vue` — confirmation modal for the rollback
  action (REQ-OBR-011). Modal lives in its own SFC under `src/modals/` per Hydra
  modal-isolation gate (ADR-004). On confirmation, PUTs the chosen snapshot's `manifest`
  onto the Application as the new draft manifest and leaves status as `draft`. Does not
  delete or mutate existing `ApplicationVersion` rows (audit-clean). Refreshes the editor
  to reflect the restored manifest.

- **NEW** `src/components/ManifestDiff.vue` — side-by-side diff component (REQ-OBR-012).
  Accepts `from` and `to` props (ApplicationVersion UUIDs or the literal `draft`).
  Fetches both manifests via the diff endpoint. Computes diff client-side via `jsdiff`
  (no server-side diff service). Renders added/removed/unchanged lines with Nextcloud CSS
  variable colour tokens. Default preselection when opened from the editor:
  `from=draft`, `to=<currentVersion>`.

- **NEW** `src/composables/useRole.js` — derives the caller's effective role
  (`owner | editor | viewer | none`) from the loaded Application's `permissions` block
  and the caller's group set (consumed from `loadState`). Drives all `v-if` / `:disabled`
  guards on role-restricted controls (REQ-OBR-015).

- **MODIFIED** Application list view (REQ-OBR-014) — renders only Applications on which
  the caller has at least one role. Prefers OR-side `x-openregister-authorization` filtering
  when available; falls back to JS filtering against `loadState('openbuilt',
  'currentUserGroups')`. Applications not visible are absent from the list entirely
  (not greyed out).

### Capabilities

#### New Capabilities

None. All functionality belongs to the `openbuilt-runtime` capability.

#### Modified Capabilities

- `openbuilt-runtime` — receives the full consolidated ADDED Requirements from
  `openbuilt-schema-editor`, `openbuilt-versioning`, and `openbuilt-rbac`. This is
  the canonical runtime spec after the chain completes.

## Impact

- **Backend** — `ApplicationsController.php` gains ~10 LOC for the 403 guard
  (REQ-OBR-013); one new `IInitialState` provision call or a dedicated
  `InitialStateProvider` class (REQ-OBR-016); `appinfo/routes.php` adds the two schema
  routes (REQ-OBR-006).
- **Frontend** — five new SFCs / composables (`SchemaDesigner.vue`, `VersionHistory.vue`,
  `RollbackConfirmModal.vue`, `ManifestDiff.vue`, `useRole.js`); modifications to
  `BuilderHost.vue` and `ApplicationEditor.vue`.
- **i18n** — `openbuilt.builder.menu.schemas` key in `l10n/en.json` and `l10n/nl.json`
  (REQ-OBR-007); any new Publish / badge / diff / rollback UI strings follow the same
  pattern.
- **Dependencies** — `jsdiff` (or equivalent client-side diff library) added to
  `package.json` for `ManifestDiff.vue` (REQ-OBR-012). No server-side PHP diff lib.
- **OpenRegister** — reads `ApplicationVersion` rows via OR REST (no app-local schema
  addition); reads/writes `Application.permissions` via OR REST (schema field added by
  `openbuilt-rbac`). No new OR schemas in this spec.
- **No breaking changes** — the 403 guard is additive; existing Applications without
  a `permissions` field fall through to the implicit "all authenticated users" posture
  until the RBAC migration sets defaults. Existing outer-router routes are unaffected by
  the schema-route additions.
- **Foundational ADRs honoured** — ADR-004 (no DOM data-attribute reads for groups;
  modal-isolation gate for `RollbackConfirmModal.vue`), ADR-005 (deny-by-default on
  manifest endpoint; no metadata leakage in 403 body), ADR-010 (Nextcloud CSS variables
  only — no hardcoded colour literals in badges or diff rendering), ADR-022 (consume OR
  abstractions; thin manifest check is documented §Exceptions(1)), ADR-031
  (schema-declarative — `permissions` is metadata, no `RbacService`).
