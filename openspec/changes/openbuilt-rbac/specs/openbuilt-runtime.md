## Purpose

Delta requirements added to the `openbuilt-runtime` capability by the
`openbuilt-rbac` change. These requirements wire RBAC enforcement into
the manifest endpoint, the Application list view, and the editor UIs,
and provide the caller's group set to the frontend via
`IInitialState`.

---

### Requirement: REQ-OBR-006 Manifest endpoint returns 403 for unauthorised callers

`ApplicationsController::getManifest` SHALL verify, after the existing
organisation-scope check passes and the `Application` is resolved, that
the caller is a member of at least one Nextcloud group present in
`permissions.owners ∪ permissions.editors ∪ permissions.viewers`. If
the intersection is empty and the caller is not in the Nextcloud `admin`
group, the controller SHALL return:

```json
{ "error": "forbidden", "code": "openbuilt.rbac.no_role" }
```

with HTTP status `403`. The 403 branch SHALL execute before any code
path that reads or returns the manifest payload (deny-by-default per
ADR-005). When the caller IS in the `admin` group and is thereby
bypassing the permissions check, the controller SHALL write a
`rbac.admin_bypass` audit entry to the OR audit trail before returning
`200`. The existing `#[NoAdminRequired]` attribute on the method SHALL
be preserved; no new service class is introduced.

#### Scenario: Caller with viewer role receives the manifest

- **GIVEN** an Application with `permissions.viewers = ["viewers-alpha"]`
- **WHEN** user `bob` (member of `viewers-alpha`) requests the manifest
  via `GET /index.php/apps/openbuilt/api/applications/{slug}/manifest`
- **THEN** the response status is `200 OK`
- **AND** the response body contains the manifest JSON blob

#### Scenario: Caller with no role is denied

- **GIVEN** an Application with specific `permissions` groups
- **AND** user `eve` whose group membership has no intersection with
  `permissions.owners ∪ editors ∪ viewers`
- **WHEN** `eve` requests the manifest endpoint
- **THEN** the response status is `403 Forbidden`
- **AND** the response body is `{ "error": "forbidden", "code": "openbuilt.rbac.no_role" }`
- **AND** no manifest content appears in the response body

#### Scenario: Admin bypass writes audit entry

- **GIVEN** an Application with `permissions.owners = ["team-alpha"]`
- **AND** the Nextcloud admin user is not a member of `team-alpha`
- **WHEN** the admin requests the manifest endpoint
- **THEN** the response status is `200 OK` with the manifest blob
- **AND** the OR audit trail contains a `rbac.admin_bypass` event with
  the actor UID, Application slug, and timestamp

---

### Requirement: REQ-OBR-007 Application list view filters by caller's roles

The Application list view in the OpenBuilt shell SHALL display only
Applications on which the caller holds at least one role. The
filtering strategy follows the preference order defined in
REQ-OBRBAC-003:

1. **Preferred** — if the `Application` schema declares an
   `x-openregister-authorization` rule (wired by task 1.2), the OR
   REST list endpoint returns only permitted rows; the frontend
   renders the result without further filtering.
2. **Fallback** — if OR does not yet support the
   `groupIn-pointer` predicate, the frontend SHALL filter the list
   using `loadState('openbuilt', 'currentUserGroups')` from
   `@nextcloud/initial-state`. No `document.getElementById().dataset`
   reads are permitted (ADR-004 hard rule; enforced by
   `hydra-gate-initial-state`).

The empty-state UI for a caller with no roles on any Application SHALL
display: "Geen applicaties beschikbaar — vraag een eigenaar om toegang
te verlenen." (NL) / "No applications available — ask an owner to
grant you access." (EN).

#### Scenario: List shows only permitted Applications

- **GIVEN** an organisation with 10 Applications
- **AND** user `bob` has at least one role on exactly 3 of them
- **WHEN** `bob` opens the OpenBuilt Application list
- **THEN** exactly 3 Applications appear in the rendered list
- **AND** the other 7 are absent from the payload consumed by the
  frontend

#### Scenario: Caller with no roles sees empty state

- **GIVEN** an organisation with Applications, none of which grant a
  role to user `eve`
- **WHEN** `eve` opens the OpenBuilt Application list
- **THEN** the list is empty and the empty-state message is displayed
- **AND** no Application data leaks to the frontend

---

### Requirement: REQ-OBR-008 Editor UIs gate destructive actions per role

All OpenBuilt editor UIs SHALL consume the shared `useRole(application)`
composable (located at `src/composables/useRole.js`) to derive the
caller's effective role (`'owner' | 'editor' | 'viewer' | 'none'`)
from the loaded Application's `permissions` block and the caller's
group set from `loadState`. The composable is a pure function — no API
calls, no Pinia store, no side effects.

The composable result SHALL drive the following template bindings:

| Control | viewer | editor | owner |
|---|---|---|---|
| Manifest textarea | `readonly` attribute set | writable | writable |
| Save button | hidden (`v-if="false"`) | visible + enabled | visible + enabled |
| Publish button | hidden | hidden | visible |
| Archive button | hidden | hidden | visible |
| Re-open button | hidden | hidden | visible |
| Delete button | hidden | hidden | visible |
| Transfer ownership | hidden | hidden | visible |
| Permissions panel | hidden | hidden | visible |

All future editor UIs introduced by later chain specs (visual editors
from #5 / #6) SHALL consume the same `useRole(application)` composable
as the single source of truth for role-to-action mapping.

#### Scenario: Viewer sees read-only editor

- **GIVEN** an Application on which the caller has only `viewer` role
- **WHEN** the caller opens the Application in the textarea editor
- **THEN** the textarea is rendered with the `readonly` attribute
- **AND** the Save, Publish, Archive, Delete, and Transfer ownership
  controls are not visible

#### Scenario: Editor can save but not publish

- **GIVEN** an Application on which the caller has only `editor` role
- **WHEN** the caller opens the Application in the textarea editor
- **THEN** the textarea is writable and the Save button is enabled
- **AND** the Publish button is hidden (or disabled with a tooltip
  "Eigenaarrol vereist" / "Owner role required")

#### Scenario: Owner has all controls visible

- **GIVEN** an Application on which the caller has `owner` role
- **WHEN** the caller opens the Application in the editor
- **THEN** all controls — Save, Publish, Archive, Delete, Transfer
  ownership, and the Permissions panel — are visible and enabled

---

### Requirement: REQ-OBR-009 Caller's group set provided via initial state

On every OpenBuilt page render, the PHP controller SHALL provide the
authenticated user's Nextcloud group IDs to the frontend via
`IInitialState::provideInitialState('openbuilt', 'currentUserGroups', $gids)`
where `$gids` is an array of group ID strings obtained via
`IGroupManager::getUserGroups($user)`. The frontend SHALL consume this
value using `loadState('openbuilt', 'currentUserGroups', [])` from
`@nextcloud/initial-state`.

No DOM data-attribute reads are permitted as an alternative (ADR-004
hard rule; enforced by `hydra-gate-initial-state`). The group set is
used by both the list-view fallback filter (REQ-OBR-007) and the
`useRole(application)` composable (REQ-OBR-008).

#### Scenario: Group IDs are available on shell boot

- **GIVEN** a user who is a member of groups `["digitaal-team", "redactie"]`
- **WHEN** the OpenBuilt shell boots in the browser
- **THEN** `loadState('openbuilt', 'currentUserGroups', [])` returns
  `["digitaal-team", "redactie"]`
- **AND** no `document.getElementById` or `dataset` read is present
  in any new SFC or composable
