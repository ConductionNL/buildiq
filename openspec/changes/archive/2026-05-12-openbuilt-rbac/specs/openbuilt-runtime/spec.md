## ADDED Requirements

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
