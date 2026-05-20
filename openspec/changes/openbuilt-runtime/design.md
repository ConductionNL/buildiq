## Context

The `openbuilt-runtime` capability covers everything a user touches when they navigate
into a virtual app inside the OpenBuilt shell: the manifest endpoint that serves the
JSON blob, the `BuilderHost.vue` that mounts a nested `CnAppRoot`, the manifest editor,
the schema-designer surface, the versioning/history UI, and all role-enforcement layers.

`bootstrap-openbuilt` (chain spec #1) delivered the minimal viable version of this
capability. Three subsequent specs have since layered additions:

- **`openbuilt-schema-editor`** (#4): schema-designer routes in the outer router,
  Schemas menu entry in the builder nav.
- **`openbuilt-versioning`** (#6): tabbed editor (Design + Raw JSON), Publish action,
  draft-vs-published badge, `VersionHistory.vue`, rollback, `ManifestDiff.vue`.
- **`openbuilt-rbac`** (#7): 403 guard on the manifest endpoint, role-filtered list
  view, role-keyed action gating, initial-state group provider.

This design document covers the full consolidated surface and records the key
architectural decisions that govern implementation of the accumulated requirements.

## Goals / Non-Goals

**Goals**

- Consolidate all ADDED Requirements for `openbuilt-runtime` (from chain specs #4, #6,
  #7) into a single coherent implementable spec.
- Deliver the **tabbed manifest editor** — Design tab (mounts `PageDesigner.vue`) +
  Raw JSON tab (`<textarea>`) — sharing one in-flight state and one validation error
  surface.
- Deliver the **schema-designer outer routes** (`/builder/:slug/schemas`,
  `/builder/:slug/schemas/:schemaId`) and the **Schemas** menu entry without
  disturbing the nested `CnAppRoot` route.
- Deliver the **Publish action** and **draft-vs-published badge** so that the
  `draft → published` lifecycle transition is reachable from the editor UI.
- Deliver **`VersionHistory.vue`**, the rollback confirmation modal
  (`RollbackConfirmModal.vue`), and **`ManifestDiff.vue`** for side-by-side diff.
- Deliver the **403 guard** on `getManifest`, the **role-filtered list**, the
  **`useRole` composable**, and the **initial-state group provider** so that
  per-app RBAC is enforced end-to-end without DOM data-attribute reads.

**Non-Goals**

- `PageDesigner.vue` itself — shipped by the `openbuilt-page-designer` capability
  (chain spec #5). This spec mounts it from the Design tab but does not build it.
- `SchemaDesigner.vue`'s internal schema-field editor — shipped by `openbuilt-schema-editor`
  (chain spec #4). This spec registers the route and menu entry only.
- Promotion dialog (the three-option data-copy flow) — `openbuilt-version-promotion`.
- `?version=<slug>` URL routing — `openbuilt-version-routing`.
- App-creation wizard — `openbuilt-app-creation-wizard`.
- Permissions editor UI (group pickers for owner/editor/viewer) — `openbuilt-rbac`
  chain spec (the panel ships there; this spec only gates actions on the loaded
  `permissions` field).
- Transfer-ownership flow — `openbuilt-rbac`.
- Export-to-real-app code generator — chain spec #9.

## Decisions

### Decision 1 — Shared in-flight manifest state across tabs (frontend)

Both the Design tab and the Raw JSON tab bind to the **same reactive manifest object**
in the parent `ApplicationEditor.vue`. Tab switching does not trigger a save or a
re-fetch — the in-flight object is handed to each tab as a prop (or via provide/inject).
The dirty indicator persists across tab switches.

**Why:** A save-on-tab-switch model would generate spurious OR writes and would make
the "Publish after editing" path confusing (is the last save authoritative?). The
single in-flight object is the simplest mental model for the integrator.

**Alternatives considered:**

- *Each tab keeps its own draft; tabs diff-merge on switch.* Rejected: merge conflicts
  between structured (Design) and raw (JSON) edits produce a non-deterministic result
  that would surprise integrators.
- *Raw JSON tab is the canonical source; Design tab regenerates from it on every switch.*
  Possible for v1 but implies `PageDesigner` must be able to cold-parse any valid
  manifest, which is a harder contract than "receives the current in-flight object and
  emits mutations". Deferred if `PageDesigner` requests it.

### Decision 2 — Schema-designer routes in the outer router (not the inner `CnAppRoot` router)

`/builder/:slug/schemas` and `/builder/:slug/schemas/:schemaId` are registered in the
OpenBuilt **outer** Vue router. When the user is on a schema route, `BuilderHost.vue`
renders `SchemaDesigner.vue` directly — the nested `CnAppRoot` is NOT mounted.

**Why:** The schema surface is a meta-tool that authors the data model **of** a virtual
app. It should persist across navigation between schema editing and the runtime preview
(where `CnAppRoot` IS mounted). Putting schema routes inside the inner router would
mean the inner `CnAppRoot` is mounted even while editing schemas — unnecessary register
traffic, confusing nav state, and broken back-button semantics.

**Alternatives considered:**

- *Put schema routes inside the inner router.* Rejected above.
- *Open schema editing in a modal over `BuilderHost.vue`.* Rejected: the schema
  surface can be deep (list → type → field → validation rules); a modal would need
  nested navigation, which `NcModal` does not support well.

### Decision 3 — Rollback is audit-clean (append-only; no history deletion)

The rollback action PUTs the chosen `ApplicationVersion.manifest` onto the Application
as the new draft and leaves the Application's `status` as `draft`. No
`ApplicationVersion` row is deleted or mutated. The rollback is observable in the OR
audit log as a normal Application save.

**Why:** Deleting or mutating historical snapshots would create a gap in the audit
trail that compliance reviewers would flag. The append-only model is also simpler to
implement — a single PUT to OR's Application endpoint.

**Alternatives considered:**

- *Create a new `ApplicationVersion` row pointing at the restored manifest, then mark
  the original as "superseded".* More semantically expressive but creates a new row
  in the history panel that may confuse integrators ("where did this snapshot come
  from?"). Deferred unless a use case surfaces.
- *Delete the snapshots between the rollback target and HEAD.* Rejected: destructive,
  not recoverable, breaks compliance requirements.

### Decision 4 — 403 guard is inline in the controller; no service class

`ApplicationsController::getManifest` performs the permissions check with an inline
group-intersection loop (~10 LOC). No `ApplicationAuthorizationService`, no
`RbacService`, no `PermissionsMiddleware`. The check is the single thin-controller
exception per ADR-022 §Exceptions(1).

**Why:** OR's authorization vocabulary cannot yet express "caller's group is in
Application.permissions.owners ∪ editors ∪ viewers" natively. The fallback is the
thinnest possible in-controller check. Creating a service class for a 10-LOC check
would violate ADR-031's "no bespoke service for declarative-first concerns" rule.

**Alternatives considered:**

- *Register an OR `x-openregister-authorization` rule on the Application schema.*
  The preferred path when OR's authorization extension supports the role-intersection
  expression. If it does, the in-controller check becomes a no-op fallback. The
  implementer SHOULD try this first and document the result.
- *Middleware / Nextcloud middleware layer.* Rejected: middleware runs before slug
  resolution, so the Application object (and its `permissions`) is not yet loaded.

### Decision 5 — Client-side diff via `jsdiff`; diff endpoint returns both blobs

`ManifestDiff.vue` fetches **both** manifest blobs in a single request from the diff
endpoint (`GET /api/applications/{slug}/versions/diff?from={uuidA}&to={uuidB}`),
then computes the line diff entirely client-side using `jsdiff` (or an equivalent
library). No server-side diff computation. No second round-trip.

**Why:** Server-side diff is unnecessary overhead given that JSON blobs are at most a
few KB. `jsdiff` is a well-maintained library with a line-diff algorithm that produces
a clean `{added, removed, value}` array. Client-side computation also means the diff
can be recomputed instantly when the user edits the `from`/`to` selectors without
waiting for a network round-trip.

**Alternatives considered:**

- *Two separate round-trips (one per blob).* Rejected: doubles latency; the diff
  endpoint is thin glue (~15 LOC) and the single-request API is cleaner.
- *Server-side `diff` PHP library.* Rejected: adds a PHP dependency for a problem
  that a 2-KB JS library solves better and without the serialisation overhead.

### Decision 6 — `useRole` composable derives role from `loadState`; no additional fetch

The `useRole(application)` composable computes the caller's effective role entirely
client-side: it intersects the `application.permissions` object (loaded with the
Application) against the group set from `loadState('openbuilt', 'currentUserGroups')`.
No dedicated role-check endpoint; no `IGroupManager` call from the frontend.

**Why:** The group set is already available via `loadState` (provided by the PHP initial-
state call in REQ-OBR-016). Making a round-trip to a `/api/role?slug=…` endpoint would
add latency and a new backend surface for a pure client-side derivation.

**Alternatives considered:**

- *Fetch role from OR's `x-openregister-authorization` response headers.* Possible if
  OR starts returning effective role in list/get responses. Implementer SHOULD check
  whether OR 0.2.x returns this and, if so, prefer it over the local derivation.
- *Derive role from `OC.getCurrentUser()`.* Rejected (ADR-004): `OC.*` is a global
  DOM shim, not a Nextcloud-idiomatic API. `loadState` is the correct pattern.

## Reuse Analysis

Per ADR-001 (org-wide), before proposing new capability the implementer MUST audit
existing OR services and `@conduction/nextcloud-vue` components.

| Concern | Existing provision | Conclusion |
|---|---|---|
| Application CRUD (list, get, put) | OR REST + `createObjectStore` | Reuse. No custom controller beyond `getManifest`. |
| ApplicationVersion list (read) | OR REST filtered by `applicationUuid` | Reuse. `VersionHistory.vue` reads OR REST directly — no wrapper service. |
| Manifest validation | `validateManifest` from `@conduction/nextcloud-vue` | Reuse. Both tabs call the same function. |
| Status badge rendering | `CnStatusBadge` from `@conduction/nextcloud-vue` | Reuse. Draft/published/archived badge uses this component. |
| Modal pattern | `NcModal`-based SFC under `src/modals/` | Follow pattern. `RollbackConfirmModal.vue` is a new SFC; no inline modal markup. |
| Caller group set | `IInitialState::provideInitialState` + `loadState` | Follow platform pattern. No custom fetch. |
| OR-side list filtering | `x-openregister-authorization` extension on schema | Preferred path for list view filtering; JS fallback if OR extension unavailable. |
| JSON viewer / diff | `jsdiff` (npm) + `CnJsonViewer` for display | `jsdiff` for diff computation; rendering via custom coloured token output (not `CnJsonViewer` which is read-only viewer, not diff). |

No duplicate capability found. All new components are domain-specific to the runtime
surface and have no counterpart in OR's service layer or `@conduction/nextcloud-vue`.

## Declarative-vs-Imperative Decision Table

Per ADR-031, every business-logic site is classified before implementation.

| Concern | Declarative attempt | Final decision | Rationale |
|---|---|---|---|
| `draft → published` lifecycle transition | `x-openregister-lifecycle` state machine on Application schema | **Declarative** (already declared in `openbuilt-application-register`) | Transition is a pure state hop declared on the schema; OR engine fires it via REST PATCH. No imperative PHP. |
| Manifest validation (client) | JSON Schema ref on Application schema `manifest` property | **Declarative** (schema-side) + `validateManifest` in the frontend (lib function) | `validateManifest` is a library call, not bespoke validation code. |
| Role derivation from permissions | `x-openregister-authorization` rule on Application schema | **Declarative preferred, imperative fallback** | If OR's auth extension can express group-intersection, declare it. Otherwise `useRole` composable + 403 inline check per ADR-022 §Exceptions(1). |
| 403 guard on manifest endpoint | `x-openregister-authorization` | **Imperative** (inline controller guard) | OR's auth extension cannot yet intercept a custom endpoint's return path. ADR-022 §Exceptions(1) fallback. |
| Application list role-filtering | `x-openregister-authorization` on schema | **Declarative preferred, JS fallback** | Same reasoning as role derivation. Implementer SHOULD try OR-side first. |
| Client-side manifest diff | Calc field / computed view in OR | **Imperative** (`jsdiff` in `ManifestDiff.vue`) | Stateful diff between two arbitrary blobs fetched on demand. Outside OR's calc vocabulary. |
| Rollback (PUT snapshot manifest to draft) | OR lifecycle action | **Imperative** (frontend PUT to OR Application endpoint) | Rollback is not a lifecycle transition — it's a plain manifest write. The lifecycle state stays `draft`. |

## Seed Data

Per ADR-001 (org-wide), every schema-introducing change ships seed data. This
consolidated spec does **not** introduce new schemas — `Application`, `BuiltAppRoute`,
`ApplicationVersion`, and `hello-message` are all declared by prior chain specs. The
seed data below is included to document the full canonical seed state that the runtime
requires for testability, and to provide the `design.md`-mandated Seed Data section.

The implementation MAY re-use the existing `lib/Repair/SeedHelloWorld.php` if it still
exists (see `openbuilt-versioning-model` for notes on its fate under ADR-002), or
reproduce it in the creation wizard.

### Application: `hello-world`

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "application",
    "slug": "hello-world"
  },
  "name": "Hallo Wereld",
  "slug": "hello-world",
  "description": "Een voorbeeldapplicatie die de OpenBuilt runtime demonstreert.",
  "status": "published",
  "version": "1.0.0",
  "permissions": {
    "owners": ["admin"],
    "editors": [],
    "viewers": []
  },
  "manifest": {
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [
      {
        "id": "Berichten",
        "label": "openbuilt.helloworld.menu.berichten",
        "icon": "icon-comment",
        "route": "Berichten"
      }
    ],
    "pages": [
      {
        "id": "Berichten",
        "route": "/",
        "type": "index",
        "title": "openbuilt.helloworld.title.berichten",
        "config": {
          "register": "openbuilt",
          "schema": "hello-message",
          "columns": ["titel", "inhoud", "@self.created"]
        }
      },
      {
        "id": "BerichtDetail",
        "route": "/berichten/:id",
        "type": "detail",
        "title": "openbuilt.helloworld.title.bericht",
        "config": {
          "register": "openbuilt",
          "schema": "hello-message"
        }
      },
      {
        "id": "BerichtAanmaken",
        "route": "/berichten/nieuw",
        "type": "form",
        "title": "openbuilt.helloworld.title.nieuw",
        "config": {
          "register": "openbuilt",
          "schema": "hello-message",
          "mode": "create",
          "submitEndpoint": "/index.php/apps/openregister/api/objects/openbuilt/hello-message"
        }
      }
    ]
  }
}
```

### BuiltAppRoute: `hello-world`

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "built-app-route",
    "slug": "hello-world-route"
  },
  "slug": "hello-world",
  "applicationUuid": "<uuid of the hello-world Application above>"
}
```

### Hello-message schema (in `openbuilt` register)

Properties: `uuid` (UUID-format), `titel` (string, required), `inhoud` (string, optional).

### Sample `hello-message` objects (three, Dutch values)

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "hello-message",
    "slug": "bericht-welkom"
  },
  "titel": "Welkom bij OpenBuilt",
  "inhoud": "Dit bericht wordt weergegeven door uw eerste virtuele applicatie."
}
```

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "hello-message",
    "slug": "bericht-bewerk"
  },
  "titel": "Pas mij aan",
  "inhoud": "Open de OpenBuilt-shell en bewerk het hello-world-manifest om te wijzigen wat u hier ziet."
}
```

```json
{
  "@self": {
    "register": "openbuilt",
    "schema": "hello-message",
    "slug": "bericht-manifest"
  },
  "titel": "Gebouwd vanuit een manifest",
  "inhoud": "Alles wat u ziet, komt uit een JSON-blob opgeslagen in OpenRegister."
}
```

## Risks / Trade-offs

- **Risk — In-flight state divergence between Design and Raw JSON tabs.** If
  `PageDesigner.vue` emits partial mutations (e.g. only the `pages` array) while the
  Raw JSON tab displays the full object, a bug in the merge logic could silently
  truncate fields. → Mitigation: the parent `ApplicationEditor.vue` owns the canonical
  reactive object; Design tab emits full object replacements, not patches. Covered
  by REQ-OBR-005 scenario "Unsaved edits survive a tab switch".

- **Risk — OR's `x-openregister-authorization` extension not yet available.** The JS
  fallback filter may return Applications that OR would have excluded, or vice versa.
  → Mitigation: the JS filter is a client-side safety net only; the 403 guard on
  `getManifest` is the authoritative deny point. Even if the list leaks an app,
  loading it returns 403.

- **Risk — `jsdiff` produces spurious diff lines on JSON key-order changes.** If the
  server serialises the manifest with different key ordering between saves, every
  property appears as changed even when values are identical. → Mitigation: the diff
  endpoint SHOULD return manifests with stable (alphabetical) key ordering. The
  `ManifestDiff.vue` component SHOULD canonicalise both blobs (stable `JSON.stringify`
  with sorted keys) before passing to `jsdiff`. Covered by REQ-OBR-012 scenario
  "Default diff shows current draft vs latest published".

- **Risk — Rollback leaves Application in `draft` with no explicit "rolled back to
  version N" annotation.** Integrators may not notice which snapshot was restored.
  → Mitigation: the confirmation modal names the target version, and the OR audit trail
  records the PUT (before/after snapshot). A future spec MAY add a `notes` field to
  the Application save payload to record the rollback source — not required here.

- **Trade-off — Schema routes in the outer router mean `SchemaDesigner.vue` cannot
  use the inner virtual app's store or routing.** This is intentional (see Decision 2).
  The schema surface operates on OR register/schema objects in the `openbuilt` namespace,
  not on the virtual app's data.

- **Trade-off — `useRole` composable derives role entirely client-side.** A determined
  attacker who can forge the `loadState` value could escalate their apparent role in
  the UI. The 403 guard on the manifest endpoint and OR's own authorization form the
  actual security boundary; the UI gating is defence-in-depth only, not the enforce
  point. Documented, acceptable.

## Migration Plan

This consolidated spec covers incremental additions to an existing capability. Each
prior chain spec already shipped (or is shipping) its backend changes. The apply
agent should:

1. **Verify** the baseline from `bootstrap-openbuilt` is present:
   `ApplicationsController::getManifest`, `BuilderHost.vue`, `ApplicationEditor.vue`
   (textarea), the hello-world seed.

2. **Apply** the schema-editor additions (REQ-OBR-006, REQ-OBR-007):
   - Add two outer-router routes and `SchemaDesigner.vue` skeleton.
   - Add the Schemas `NcAppNavigationItem` to `BuilderHost.vue`.
   - Add `openbuilt.builder.menu.schemas` to both `l10n/` files.

3. **Apply** the versioning additions (REQ-OBR-005 tabbed editor, REQ-OBR-008
   Publish, REQ-OBR-009 badge, REQ-OBR-010 VersionHistory, REQ-OBR-011 rollback
   modal, REQ-OBR-012 ManifestDiff):
   - Refactor `ApplicationEditor.vue` to the tabbed structure.
   - Add `VersionHistory.vue`, `RollbackConfirmModal.vue`, `ManifestDiff.vue`.
   - Add `jsdiff` to `package.json`.
   - Register the diff endpoint in `appinfo/routes.php`.

4. **Apply** the RBAC additions (REQ-OBR-013 403 guard, REQ-OBR-014 list filter,
   REQ-OBR-015 role gating, REQ-OBR-016 initial state):
   - Add the inline permissions check to `getManifest`.
   - Add the `provideInitialState` call.
   - Implement `useRole.js`.
   - Wire `v-if` / `:disabled` / `:readonly` guards in `ApplicationEditor.vue`.

5. **Test** all new paths via PHPUnit (403 guard), Newman (manifest endpoint + diff
   endpoint), and Playwright (tabbed editor, version history, rollback, schema nav).

**Rollback:** Each step is additive. Rolling back means reverting the PHP guard and
the frontend SFCs; no data migration is required and no OR schema change is introduced
by this spec.

## Open Questions

- **OQ-1 — OR `x-openregister-authorization` availability.** Does the current OR
  version support an authorization rule that can express
  "caller's group ∈ Application.permissions.owners ∪ editors ∪ viewers"? If yes,
  prefer the declarative OR-side filter for both the list view and the manifest
  endpoint. *Provisional decision*: ship the JS fallback and the inline controller
  check; document in `hydra.json` whether OR's extension was used or not.

- **OQ-2 — `PageDesigner.vue` mounting contract.** The Design tab mounts
  `PageDesigner.vue` from the `openbuilt-page-designer` capability. Does that component
  accept the in-flight manifest as a prop and emit full-object replacements, or does it
  manage its own copy? *Provisional decision*: the Design tab wraps `PageDesigner.vue`
  in a `<keep-alive>` and passes the in-flight manifest as a `:value` prop, expecting
  an `@update:value` event that replaces the canonical object. Adjust if
  `openbuilt-page-designer` ships a different contract.

- **OQ-3 — `jsdiff` vs alternative.** `jsdiff` is the conventional choice; if the
  apply agent finds a lighter or more actively maintained equivalent (e.g. `diff-match-patch`),
  it MAY substitute, provided the line-diff output shape is compatible with the
  `ManifestDiff.vue` rendering logic.
