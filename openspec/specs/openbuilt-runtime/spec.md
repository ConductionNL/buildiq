# openbuilt-runtime Specification

## Purpose
TBD - created by archiving change bootstrap-openbuilt. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBR-001 Manifest endpoint per virtual-app slug

The system SHALL expose
`GET /index.php/apps/openbuilt/api/applications/{slug}/manifest`
backed by `ApplicationsController::getManifest`. The endpoint SHALL
resolve `{slug}` to an `Application` via the `BuiltAppRoute` index,
return the stored `manifest` JSON blob with `Content-Type:
application/json`, and respond `200` on success or `404` when no
matching published Application exists in the caller's organisation
scope. The endpoint SHALL be registered via `appinfo/routes.php`
(ADR-016) with `#[NoAdminRequired]` and a route-auth posture that
treats it as authenticated-user-readable.

#### Scenario: Endpoint returns the stored manifest

- **WHEN** an authenticated user requests
  `/index.php/apps/openbuilt/api/applications/hello-world/manifest`
- **AND** a published `Application` with `slug: hello-world` exists
  in their organisation
- **THEN** the response is `200 application/json` and the body is the
  exact `manifest` blob persisted on the Application

#### Scenario: Unknown slug returns 404

- **WHEN** an authenticated user requests the manifest for a slug
  that has no matching `BuiltAppRoute`
- **THEN** the response is `404` with a JSON error body

### Requirement: REQ-OBR-002 OpenBuilt shell mounts a nested CnAppRoot per virtual app

The OpenBuilt frontend SHALL register a route `/builder/:slug/*` whose
view (`BuilderHost.vue`) mounts a **nested** `CnAppRoot` instance.
The nested mount SHALL be supplied with `appId = openbuilt-{slug}`
and a `bundledManifest` value, so that
`useAppManifest(appId, bundledManifest)` deep-merges the per-slug
endpoint response over the bundled placeholder and renders the virtual
app inside the OpenBuilt shell. The outer OpenBuilt shell's
`CnAppNav`, header, and chrome SHALL remain visible; the inner
`CnAppRoot` SHALL render only into the OpenBuilt page area.

#### Scenario: Navigating into a virtual app renders its manifest pages

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the outer OpenBuilt shell stays mounted
- **AND** a nested `CnAppRoot` mounts inside the page area with
  `appId = openbuilt-hello-world`
- **AND** the index page declared in the `hello-world` manifest
  renders

### Requirement: REQ-OBR-003 Path segments after the slug forward to the inner router

For routes matching `/builder/:slug/*`, the system SHALL forward the
path segments after `/{slug}` to the **inner** manifest's vue-router
so that detail, form, and dashboard pages inside the virtual app
resolve correctly. The outer OpenBuilt router SHALL treat everything
after `/{slug}/` as opaque to the inner router; the inner router
MUST match its own routes against that suffix.

#### Scenario: Detail route inside a virtual app resolves

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world/messages/00000000-0000-0000-0000-000000000000`
- **THEN** the inner `CnAppRoot`'s router matches its `detail` page
  for the `hello-message` schema
- **AND** the detail page renders for the requested object id

### Requirement: REQ-OBR-004 Seeded hello-world Application exercises index, detail, form

The repair step SHALL seed a single Application with `slug:
hello-world`, `status: published`, a `manifest` declaring at least
one `type: index`, one `type: detail`, and one `type: form` page over
a seeded `hello-message` schema in the OpenBuilt register, plus three
sample `hello-message` objects. The seed SHALL be idempotent (safe to
re-run) and SHALL only run when no `Application` with `slug:
hello-world` exists in the system organisation scope.

#### Scenario: Fresh install renders the seeded virtual app

- **WHEN** the OpenBuilt app is installed on a fresh Nextcloud
- **AND** an administrator navigates to
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the seeded index page lists the three sample
  `hello-message` objects
- **AND** opening one of them renders the seeded detail page
- **AND** the seeded form page is reachable from the index actions

#### Scenario: Re-running the repair step is idempotent

- **WHEN** the repair step runs a second time on an already-seeded
  install
- **THEN** no duplicate `hello-world` Application is created
- **AND** no duplicate `hello-message` objects are created

### Requirement: REQ-OBR-005 Textarea manifest editor saves to the Application object

The OpenBuilt shell SHALL render a **tabbed Application editor** for
the `manifest` field of an `Application` object, composed of two
sibling tabs sharing one in-flight manifest state:

1. **"Design"** (default tab) — mounts the visual `PageDesigner.vue`
   shipped by the `openbuilt-page-designer` capability. The designer
   authors the manifest through structured per-page-type sub-editors
   and a menu-tree editor; see the `openbuilt-page-designer`
   capability spec for its full requirements.
2. **"Raw JSON"** — the original JSON `<textarea>`-based editor (the
   integrator-only fallback path).

Both tabs SHALL:
(a) load the current `manifest` blob from OR via the standard OR REST
API at view mount; (b) validate the edited blob client-side using
`validateManifest` from `@conduction/nextcloud-vue` and refuse to save
when validation fails, surfacing the failing JSON path in the
shared error surface; (c) on successful validation, PUT the updated
Application back to OR via the same REST endpoint used by spec #1.
The shared in-flight manifest state SHALL persist across tab switches
without saving, so edits made in one tab are visible in the other on
tab change.

#### Scenario: Invalid edit is blocked before save

- **WHEN** an integrator pastes a manifest blob missing the required
  `pages` array into the Raw JSON tab and clicks Save
- **THEN** the shared error surface cites the missing `pages` field
- **AND** no PUT request is sent to OR
- **AND** the Design tab disables its inputs and surfaces the parse
  / validation error in its side-panel error list

#### Scenario: Valid edit persists and reloads

- **WHEN** an integrator pastes a valid manifest blob in the Raw JSON
  tab and clicks Save
- **THEN** the editor sends a PUT to OR's Application endpoint
- **AND** reloading the editor surfaces the new manifest in both tabs

#### Scenario: Default tab is Design

- **WHEN** the user opens the Application editor view for an existing
  Application
- **THEN** the Design tab is selected by default
- **AND** the Raw JSON tab is accessible as a sibling tab

#### Scenario: Unsaved edits survive a tab switch

- **WHEN** the user edits a page title in the Design tab and switches
  to the Raw JSON tab without saving
- **THEN** the textarea's JSON content reflects the unsaved page title
- **AND** the dirty indicator persists across the tab switch

### Requirement: REQ-OBR-006 Schema designer routes mounted under the builder host

The OpenBuilt frontend router SHALL register two new routes under the
existing `/builder/:slug/*` host (from `bootstrap-openbuilt`
REQ-OBR-002 / REQ-OBR-003):

- `/index.php/apps/openbuilt/builder/:slug/schemas` — schema list.
- `/index.php/apps/openbuilt/builder/:slug/schemas/:schemaId` —
  schema detail / designer.

Both routes SHALL be rendered by `src/views/SchemaDesigner.vue` and
SHALL be registered under the OpenBuilt **outer** router (not the
nested-CnAppRoot inner router). The Schemas surface is a meta-tool
that authors the data model OF a virtual app and SHALL stay scoped to
the OpenBuilt shell so the user can navigate between schema authoring
and the virtual app's runtime preview without re-mounting the nested
CnAppRoot. The existing `/builder/:slug/*` virtual-app preview route
from `bootstrap-openbuilt` SHALL continue to mount the nested
CnAppRoot for the runtime preview and SHALL be unaffected by this
addition.

#### Scenario: Schema list route renders the designer, not the virtual app

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world/schemas`
- **THEN** the OpenBuilt outer shell renders `SchemaDesigner.vue`
- **AND** the nested `CnAppRoot` for `hello-world` is NOT mounted on
  this route

#### Scenario: Virtual-app preview route still mounts the nested CnAppRoot

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the nested `CnAppRoot` for `hello-world` mounts per
  REQ-OBR-002 (bootstrap-openbuilt)
- **AND** the Schemas menu entry is reachable from the outer shell's
  navigation

### Requirement: REQ-OBR-007 Schemas menu entry surfaced in the builder host

`src/views/BuilderHost.vue` SHALL surface a **Schemas** menu entry in
the OpenBuilt outer-shell secondary navigation while the user is in a
virtual app's builder context. Activating the entry SHALL route to
`/builder/{slug}/schemas`. The entry SHALL be visible to any user
authorised to read the virtual app's Application object; chain spec
`openbuilt-rbac` (#7) MAY narrow this visibility further. The menu
entry SHALL use a translation key (`openbuilt.builder.menu.schemas`)
in both `l10n/en.json` and `l10n/nl.json`.

#### Scenario: Schemas entry appears in the builder context

- **WHEN** an authenticated user opens
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the outer shell's secondary navigation includes a
  **Schemas** entry
- **AND** clicking the entry navigates to
  `/builder/hello-world/schemas`

### Requirement: REQ-OBR-006 Application editor exposes a Publish action

`ApplicationEditor.vue` (REQ-OBR-005) SHALL render a "Publish"
action button alongside the existing Save action. Clicking Publish
SHALL: (a) require the textarea manifest to validate cleanly via
`validateManifest`; (b) on validation success, PUT any pending
manifest changes to OR and then call the Application's
`draft → published` lifecycle transition endpoint; (c) on
transition success, surface a confirmation toast naming the new
`ApplicationVersion` `uuid` returned in the response; (d) on
transition failure (e.g. slug-conflict per REQ-OBA-004), surface an
inline error and leave the manifest in draft state. The button
SHALL be disabled while the lifecycle call is in flight.

#### Scenario: Successful publish creates a snapshot

- **WHEN** an integrator opens the editor for a draft Application,
  edits the manifest validly, and clicks Publish
- **THEN** the manifest is saved to OR
- **AND** the lifecycle transition is invoked
- **AND** the confirmation toast surfaces with the newly created
  `ApplicationVersion` `uuid`

#### Scenario: Validation blocks publish

- **WHEN** an integrator clicks Publish while the manifest is
  invalid
- **THEN** no save or lifecycle call is sent
- **AND** the editor surfaces the validation error inline (same
  contract as Save)

### Requirement: REQ-OBR-007 Draft-vs-published indicator surfaces lifecycle state

The OpenBuilt shell SHALL surface the Application's current
`status` (and a marker for "has unpublished draft changes") in two
places: (1) each row of the Application list view carries a small
status badge (`draft` / `published` / `archived`); (2) the editor
header for an open Application carries the same badge plus a
"draft modified since last publish" indicator when the in-textarea
manifest differs from the most recent `ApplicationVersion.manifest`.
The badge SHALL use Nextcloud CSS variables for colour (no
hardcoded colour literals — per ADR-010).

#### Scenario: Newly published Application shows published badge

- **WHEN** an Application has been published and its draft has not
  yet been modified
- **THEN** both the list row and the editor header show a
  `published` badge
- **AND** no "draft modified since last publish" indicator is
  shown

#### Scenario: Edited draft shows modified indicator

- **WHEN** an integrator has edited an Application's manifest after
  the most recent publish but before publishing again
- **THEN** the editor header shows the `draft` badge with a
  "modified since last publish" marker
- **AND** the list row reflects the same state

### Requirement: REQ-OBR-008 VersionHistory.vue lists snapshots for an Application

The OpenBuilt shell SHALL render a `VersionHistory.vue` panel
inside `ApplicationEditor.vue` (collapsible / a sibling tab,
implementer's choice) listing every `ApplicationVersion` row for
the current Application in reverse-chronological order (newest
first). Each row SHALL display `version`, `publishedAt` (localised),
`publishedBy`, and any `notes`. The list SHALL be read from OR REST
filtered by `applicationUuid` — no app-local wrapper service.

#### Scenario: History panel renders snapshots

- **WHEN** an integrator opens an Application that has three
  `ApplicationVersion` rows
- **THEN** the version-history panel renders three rows in
  newest-first order
- **AND** each row shows `version`, `publishedAt`, `publishedBy`,
  and `notes`

#### Scenario: History panel is empty for a never-published Application

- **WHEN** an integrator opens a `draft` Application that has no
  `ApplicationVersion` rows yet
- **THEN** the version-history panel renders an empty state
- **AND** no console error is emitted from the empty-list fetch

### Requirement: REQ-OBR-009 Rollback action restores a chosen snapshot

Each row in the `VersionHistory.vue` panel SHALL carry a "Roll back
to this version" action. Clicking it SHALL: (a) prompt for
confirmation in a modal naming the target `version`; (b) on
confirmation, PUT the chosen snapshot's `manifest` onto the
Application as the new draft manifest, set the Application's
`version` per REQ-OBV-003, and leave the Application's `status` as
`draft`; (c) refresh the editor so the textarea reflects the
restored manifest. Per design.md Decision 3 the rollback is
audit-clean — it does **not** delete or mutate existing
`ApplicationVersion` rows. The confirmation modal SHALL live in
its own SFC under `src/modals/` per Hydra modal-isolation gate
(ADR-004).

#### Scenario: Rollback restores manifest and stays in draft

- **WHEN** an integrator clicks "Roll back to this version" on the
  oldest row in the history panel and confirms in the modal
- **THEN** the Application's draft manifest is byte-equal to the
  chosen snapshot's manifest
- **AND** the Application's status is `draft`
- **AND** no `ApplicationVersion` row has been deleted

#### Scenario: Cancelling the confirmation aborts the rollback

- **WHEN** the integrator opens the confirmation modal and clicks
  Cancel
- **THEN** no PUT is sent to OR
- **AND** the textarea content is unchanged

### Requirement: REQ-OBR-010 ManifestDiff.vue renders a side-by-side diff

The OpenBuilt shell SHALL ship a `ManifestDiff.vue` component
rendering a client-side side-by-side diff between two manifest
blobs. The component SHALL: (a) accept `from` and `to`
`ApplicationVersion` UUIDs (or the literal `draft` for either) as
props; (b) fetch both manifests via the diff endpoint defined in
REQ-OBV-005; (c) compute the diff client-side via `jsdiff` (or an
equivalent library — per design.md Decision 5); (d) render added
lines, removed lines, and unchanged lines with NL Design
colour-coded tokens using Nextcloud CSS variables. By default the
editor SHALL preselect `from=draft` and `to=<currentVersion>` when
the diff view is opened.

#### Scenario: Default diff shows current draft vs latest published

- **WHEN** an integrator opens the diff view from the editor of an
  Application that has been published at least once
- **THEN** the component fetches the diff endpoint with
  `from=draft` and `to=<currentVersion>`
- **AND** the side-by-side rendering shows the diff between the
  two manifests
- **AND** the diff is computed client-side (no second round-trip
  to a server-side diff service)

#### Scenario: Arbitrary snapshot pair can be diffed

- **WHEN** an integrator selects two arbitrary
  `ApplicationVersion` rows from the version-history panel and
  invokes "Compare"
- **THEN** `ManifestDiff.vue` mounts with those two UUIDs
- **AND** the rendered diff matches what the diff endpoint
  returned for that pair

### Requirement: REQ-OBR-006 Manifest endpoint returns 403 for unauthorised callers

`ApplicationsController::getManifest` SHALL be extended with a
permissions check that runs after the organisation-scope resolution
and before any branch that returns the manifest payload. The check
SHALL compute the caller's group set via
`IGroupManager::getUserGroups()` and the Application's authorised
groups as
`permissions.owners ∪ permissions.editors ∪ permissions.viewers`.
If the two sets do not intersect — and the caller is not exercising
the audited admin bypass declared in REQ-OBRBAC-006 — the controller
SHALL respond `403 Forbidden` with a JSON body of shape
`{ "error": "forbidden", "code": "openbuilt.rbac.no_role" }`. The
existing 404 branch (slug not found) is preserved; the 403 branch
SHALL be ordered before the manifest-body emission and SHALL NOT
leak any Application metadata (no name, no description, no manifest
fragment). Implementation is a single in-controller check — no new
service class — per ADR-022 §Exceptions(1).

#### Scenario: Caller without a role gets 403 (not 200, not 404)

- **WHEN** an authenticated user requests
  `/index.php/apps/openbuilt/api/applications/hello-world/manifest`
- **AND** the Application exists in the user's organisation but no
  group the user belongs to appears in its `permissions`
- **THEN** the response is `403`
- **AND** the response body contains only the error envelope above
- **AND** the response body does NOT contain the Application's
  manifest, name, or description

#### Scenario: Caller in any role gets 200

- **WHEN** an authenticated user in group `team-alpha` requests the
  manifest for an Application whose `permissions.editors`
  contains `team-alpha`
- **THEN** the response is `200 application/json` and the body is
  the stored `manifest` blob

### Requirement: REQ-OBR-007 Application list view filters by caller's roles

The system SHALL ensure the frontend Application list (the entry view of the OpenBuilt shell, currently `ApplicationEditor.vue`'s list mode) renders only Applications on which the caller has at least one role.

The list view SHALL prefer OR-side filtering: if the Application
schema declares an `x-openregister-authorization` rule that
expresses the role intersection, the OR REST list endpoint returns
the pre-filtered set and the frontend renders it directly.

If the declarative path is not available, the frontend SHALL filter
in JS using the caller's group set, which is provided to the
frontend via `IInitialState::provideInitialState('openbuilt',
'currentUserGroups', [...])` consumed by `loadState` (per ADR-004 —
no `document.getElementById().dataset` reads).

#### Scenario: User sees only authorised applications

- **WHEN** user `bob` (in groups `team-alpha`, `qa-shared`) opens
  the OpenBuilt shell
- **AND** the organisation contains Applications A (`permissions.owners
  = ["team-alpha"]`), B (`permissions.editors = ["other-team"]`),
  and C (`permissions.viewers = ["qa-shared"]`)
- **THEN** the Application list shows A and C
- **AND** B is absent (not greyed out, not visible)

#### Scenario: Empty list when user has no roles

- **WHEN** an authenticated user with no role on any Application in
  their organisation opens the OpenBuilt shell
- **THEN** the Application list is empty
- **AND** the empty-state UI explains "No applications available —
  ask an owner to grant you access"

### Requirement: REQ-OBR-008 Editor UIs gate destructive actions per role

The system SHALL gate role-restricted actions in the OpenBuilt editor views (currently the textarea editor `ApplicationEditor.vue`; the visual editors arriving in chain specs #5 and #6 when they land) via a shared `useRole(application)` composable that returns the caller's effective role (`owner | editor | viewer | none`). The
mapping in REQ-OBRBAC-004 is the canonical source. UI controls
SHALL be:

- **viewer** — textarea (or visual editor) rendered read-only;
  Save / Publish / Archive / Delete / Transfer / Permissions
  controls are hidden (`v-if`).
- **editor** — textarea (or visual editor) is editable; Save is
  enabled; Publish / Archive / Delete / Transfer / Permissions
  controls are hidden.
- **owner** — all controls visible and enabled, including the
  Permissions panel and the Permission history panel.

A user whose role is `none` cannot reach the editor at all
(REQ-OBR-007 ensures the Application doesn't appear in their
list; REQ-OBR-006 ensures direct-URL access returns 403).

#### Scenario: Editor sees Save but not Publish

- **WHEN** a user with only the `editor` role opens an Application
- **THEN** the manifest textarea is editable
- **AND** the Save button is enabled
- **AND** the Publish, Archive, Delete, Transfer-ownership, and
  Permissions buttons are not rendered

#### Scenario: Owner sees all controls

- **WHEN** a user with the `owner` role opens an Application
- **THEN** every control listed in REQ-OBRBAC-004 is visible and
  enabled
- **AND** the Permission history panel is reachable

### Requirement: REQ-OBR-009 Caller's group set is provided via initial state

The OpenBuilt PHP layer SHALL provide the caller's Nextcloud group
IDs to the frontend via
`IInitialState::provideInitialState('openbuilt',
'currentUserGroups', string[])`, written from the relevant
controller's `index` action (or a dedicated `InitialStateProvider`
service registered in `lib/AppInfo/Application.php`). The frontend
SHALL consume this value through `loadState('openbuilt',
'currentUserGroups')` from `@nextcloud/initial-state`. The
frontend SHALL NOT read group membership from any DOM
data-attribute, fetch endpoint, or `document.getElementById`
pattern (ADR-004 hard rule; enforced by the
`gate-initial-state` Hydra gate).

#### Scenario: Frontend sees the caller's groups

- **WHEN** the OpenBuilt shell boots for user `bob` (in groups
  `team-alpha`, `qa-shared`)
- **THEN** `loadState('openbuilt', 'currentUserGroups')` returns
  `["team-alpha", "qa-shared"]`
- **AND** no DOM data-attribute access is needed to obtain the
  groups

