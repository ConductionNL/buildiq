## Purpose

Defines the per-virtual-app role model for OpenBuilt — the `permissions`
block shape, the default-on-creation behaviour, the manifest-endpoint
enforcement contract, the role → action mapping, the transfer-ownership
flow, the permission-change audit trail contract, and the global
`openbuilt.use` navigation-entry gate. This capability is schema-
declarative per ADR-031: no authorization service class is introduced.

---

### Requirement: REQ-OBRBAC-001 Permissions field shape and default on creation

The system SHALL extend the `Application` schema with an optional
`permissions` property of shape
`{ owners: string[], editors: string[], viewers: string[] }` where
each array element is a Nextcloud group ID (`gid`). The arrays MAY be
empty. When a new `Application` is created without an explicit
`permissions` value, the system SHALL default `permissions.owners` to
an array containing the **creator's primary Nextcloud group**
(`IUserSession::getUser()->getUID()`'s first group from
`IGroupManager::getUserGroups()`); `editors` and `viewers` SHALL
default to empty arrays. If the creator has no group membership, the
system SHALL fall back to the `admin` group as the sole owner so the
Application is never created in an unreachable "no owner" state.

#### Scenario: New Application gets creator's primary group as owner

- **GIVEN** a Nextcloud instance with OpenBuilt installed
- **AND** a user whose primary group is `team-alpha` is authenticated
- **WHEN** that user creates a new `Application` via OR REST without
  sending a `permissions` field
- **THEN** the persisted Application has
  `permissions.owners = ["team-alpha"]`, `permissions.editors = []`,
  and `permissions.viewers = []`

#### Scenario: Groupless creator falls back to admin

- **GIVEN** a Nextcloud instance with OpenBuilt installed
- **AND** a user with no Nextcloud group memberships is authenticated
- **WHEN** that user creates a new `Application` without sending a
  `permissions` field
- **THEN** the persisted Application has
  `permissions.owners = ["admin"]`
- **AND** the user is recorded as the actor in the OR audit trail

---

### Requirement: REQ-OBRBAC-002 Manifest endpoint enforces role membership

The system SHALL augment
`GET /index.php/apps/openbuilt/api/applications/{slug}/manifest` so
that, after the existing organisation-scope check passes and the
Application is resolved, the controller SHALL verify the caller is a
member of at least one group present in
`permissions.owners ∪ permissions.editors ∪ permissions.viewers`. If
the caller has no group intersection with the Application's
`permissions` (and is not a Nextcloud admin who has explicitly
elevated via the admin-bypass declared in REQ-OBRBAC-006), the
controller SHALL respond `403 Forbidden` with a JSON error body. The
check SHALL run before any other branch that would return the manifest
payload — deny-by-default per ADR-005.

#### Scenario: Member of viewer group reads the manifest

- **GIVEN** an Application whose `permissions.viewers = ["viewers-alpha"]`
- **WHEN** user `bob` whose groups include `viewers-alpha` requests
  the manifest for that Application
- **THEN** the response is `200 application/json` carrying the
  manifest blob

#### Scenario: Non-member cannot read the manifest

- **GIVEN** an Application with `permissions.owners`, `editors`, and
  `viewers` defined
- **WHEN** user `eve` whose groups do not intersect with any of the
  Application's `permissions.owners`, `permissions.editors`, or
  `permissions.viewers` requests its manifest
- **THEN** the response is `403 Forbidden`
- **AND** no part of the manifest payload appears in the response body

#### Scenario: 403 is returned before 404 disambiguation

- **GIVEN** an Application that exists in the organisation but to
  which the caller has no role
- **WHEN** an unauthorised caller probes the manifest endpoint with
  the Application's slug
- **THEN** the response is `403`, not `404`
- **AND** the response body does not leak the Application's `name`,
  `description`, or any manifest content

---

### Requirement: REQ-OBRBAC-003 Application list filters out unauthorised entries

The OpenBuilt shell's Application list view SHALL display only
Applications on which the caller has at least one role
(`owner | editor | viewer`). The filter SHALL be applied in this
order of preference:

1. **Preferred** — declarative, via OR's authorization extension. If
   OR's schema vocabulary supports an `x-openregister-authorization`
   rule that expresses "caller's group ∈ object's
   `permissions.owners ∪ editors ∪ viewers`", the Application schema
   SHALL declare it and the OR REST list endpoint SHALL return only
   matching rows; the frontend filters nothing.
2. **Fallback** — thin app-local filter. If the declarative path is
   not yet supported, the frontend SHALL filter the list returned by
   OR REST using the caller's group set echoed via `loadState`
   (per ADR-004; no `document.getElementById().dataset` reads).

In both paths, the user-visible behaviour is identical: unauthorised
Applications do not appear in the list.

#### Scenario: List omits Applications without any role

- **GIVEN** an organisation containing 10 Applications
- **AND** only 3 of those Applications grant `bob`'s group at least
  one role
- **WHEN** user `bob` opens the OpenBuilt Application list
- **THEN** the rendered list shows exactly 3 entries
- **AND** the omitted 7 do not appear in the response payload
  consumed by the frontend

---

### Requirement: REQ-OBRBAC-004 Role-to-action mapping in editor UIs

The system SHALL gate destructive and write actions in the OpenBuilt
editor UIs according to the following role → action mapping. Buttons
or controls that would trigger a forbidden action SHALL be hidden
(`v-if`) for `viewer` and rendered disabled (`:disabled="true"`) for
`editor` where the action requires `owner`. The mapping is the
single source of truth — all current and future editor UIs (textarea
editor today; visual editors from chain spec #5/#6 when they land)
SHALL consume the same `useRole(application)` composable.

| Action | viewer | editor | owner |
|---|:---:|:---:|:---:|
| Read manifest / browse Application | yes | yes | yes |
| Save manifest draft | no | yes | yes |
| Publish (`draft → published`) | no | no | yes |
| Archive (`published → archived`) | no | no | yes |
| Re-open (`archived → draft`) | no | no | yes |
| Edit `permissions` | no | no | yes |
| Transfer ownership | no | no | yes |
| Delete Application | no | no | yes |

#### Scenario: Viewer cannot save manifest edits

- **GIVEN** an Application on which the caller has only `viewer` role
- **WHEN** the user opens the Application in the textarea editor
- **THEN** the textarea SHALL be rendered read-only (or the Save
  button SHALL be hidden)
- **AND** any attempted PUT to the OR REST Application endpoint SHALL
  be rejected (REQ-OBRBAC-002 on the manifest endpoint; OR's existing
  write authorization covers the OR REST PUT)

#### Scenario: Editor cannot publish

- **GIVEN** an Application on which the caller has only `editor` role
- **WHEN** the user opens the Application in the editor
- **THEN** the Save button SHALL be enabled
- **AND** the Publish button SHALL be hidden (or disabled with a
  tooltip explaining "owner role required")

---

### Requirement: REQ-OBRBAC-005 Transfer-ownership flow

The system SHALL support an owner replacing the `permissions.owners`
list of an Application. The transfer SHALL be a single declarative
update to the Application's `permissions` property via OR REST — no
dedicated `TransferOwnershipService` or `transfer` endpoint. The
frontend SHALL surface a "Transfer ownership" affordance in the
permissions panel of the editor (`owner`-gated per REQ-OBRBAC-004)
that opens a group picker and PUTs the updated `permissions` block.
The system SHALL reject (`4xx`) any transfer that would result in an
empty `permissions.owners` array, preventing accidental orphaning.

#### Scenario: Owner transfers ownership to a different group

- **GIVEN** an Application with `permissions.owners = ["team-alpha"]`
- **AND** the caller is a member of `team-alpha`
- **WHEN** the caller transfers ownership to `team-beta` via the
  permissions panel
- **THEN** the persisted Application has
  `permissions.owners = ["team-beta"]`
- **AND** the OR audit trail records the permissions change with
  before / after values and the actor identity
- **AND** the actor (who is no longer in `owners`, `editors`, or
  `viewers` of the Application) loses access on the next page load

#### Scenario: Empty owners array is rejected

- **GIVEN** an Application with `permissions.owners = ["team-alpha"]`
- **AND** the caller has `owner` role
- **WHEN** the caller attempts to PUT a `permissions` block with
  `owners: []`
- **THEN** the system returns a `4xx` error citing the orphan-check
- **AND** the Application's `permissions` is unchanged

---

### Requirement: REQ-OBRBAC-006 Global `openbuilt.use` navigation-entry permission

The system SHALL extend `appinfo/info.xml` to declare an
`openbuilt.use` group-permission on the `<navigations>` entry. The
permission SHALL be:

- **Default** — no group restriction (the entry is visible to every
  authenticated user, preserving spec #1's auth-only posture
  documented in its OQ-2).
- **Admin-grantable** — through Nextcloud's standard
  `<navigations>/<permission>` mechanism, an administrator MAY
  restrict the OpenBuilt top-bar entry to one or more Nextcloud
  groups via the Nextcloud admin UI.
- **Independent** — the permission gates only the **navigation
  entry**. It does not replace the per-Application `permissions`
  enforced by REQ-OBRBAC-002 / REQ-OBRBAC-003 / REQ-OBRBAC-004; a
  user with `openbuilt.use` who has no role on any Application sees
  an empty list, not an error.

A Nextcloud administrator MAY also bypass per-Application `permissions`
checks for incident response, but ONLY when explicitly acting in admin
mode (`IUserSession::isLoggedIn()` and the user is in the `admin`
group). The bypass SHALL record a `rbac.admin_bypass` event in the OR
audit trail every time it is exercised so the action is reviewable.

#### Scenario: Admin restricts the navigation entry to one group

- **GIVEN** a Nextcloud instance with OpenBuilt installed
- **WHEN** an administrator restricts the OpenBuilt navigation entry
  to the group `digital-team` via Nextcloud's admin UI
- **AND** a user outside `digital-team` logs in
- **THEN** the OpenBuilt top-bar entry is not visible to that user
- **AND** the user cannot reach the OpenBuilt shell via direct URL
  (Nextcloud's existing navigation-permission middleware blocks it)

#### Scenario: Admin bypass is audited

- **GIVEN** an Application with `permissions.owners = ["team-alpha"]`
  where the Nextcloud admin is not a member of `team-alpha`
- **WHEN** the Nextcloud administrator accesses the Application's
  manifest endpoint
- **THEN** the controller serves the manifest (200)
- **AND** the OR audit trail contains a `rbac.admin_bypass` event
  naming the actor, the slug, and the timestamp

---

### Requirement: REQ-OBRBAC-007 Permission changes are recorded in the OR audit trail

The system SHALL record every change to an Application's `permissions`
property in OpenRegister's standard per-object audit trail, regardless
of whether the change is made through the OpenBuilt frontend permissions
panel, the textarea editor, OR REST directly, or the transfer-ownership
flow. The audit entry SHALL be the OR-native object-change event (no
app-local audit duplication); it SHALL carry the before / after
`permissions` values, the actor's UID, and the timestamp, leveraging
OR's existing change-tracking per ADR-022. The OpenBuilt editor SHALL
expose this audit trail in a "Permission history" panel visible to
`owner` role holders only.

#### Scenario: Permission change appears in the audit trail

- **GIVEN** an Application with `permissions.editors = []`
- **AND** the caller has `owner` role
- **WHEN** the owner adds the group `qa-alpha` to
  `permissions.editors` and saves
- **THEN** the OR audit trail for that Application contains an entry
  showing `permissions.editors` changed from `[]` to `["qa-alpha"]`
- **AND** the entry names the acting user and the timestamp

#### Scenario: Permission history is owner-only

- **GIVEN** an Application on which the caller has `viewer` or
  `editor` role
- **WHEN** the user opens the Application
- **THEN** the "Permission history" panel SHALL NOT be visible
- **AND** any direct API call the panel would make SHALL be gated by
  the same owner-only check
