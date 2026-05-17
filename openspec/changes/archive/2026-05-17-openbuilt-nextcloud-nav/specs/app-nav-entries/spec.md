## ADDED Requirements

### Requirement: REQ-OBNAV-001 Dynamic per-app top-bar entry for each published Application

The system SHALL register one `INavigationManager` entry per published Application in
`Application::boot()` using `INavigationManager::add()` with a closure factory. Each entry
SHALL carry:

- **id**: `openbuilt-app-{slug}` (e.g. `openbuilt-app-hello-world`).
- **name**: the Application's `name` field value.
- **href**: `/apps/openbuilt/{slug}` (the virtual-app runtime URL).
- **icon**: the URL produced by `IURLGenerator::linkToRouteAbsolute('openbuilt.icon.iconLight',
  ['slug' => $slug])` — pointing at the icon-serving endpoint (REQ-OBICON-002).
- **order**: numeric value placing entries after openbuilt's own static entry, sorted
  alpha-ascending by `name` within the virtual-app group.

The entries SHALL be registered by `AppNavigationService`, which is lazily resolved from the
DI container inside the `boot()` method.

#### Scenario: Published app appears in the Nextcloud top bar

- **WHEN** the Nextcloud request cycle boots after an Application is transitioned to `published`
- **AND** the signed-in user satisfies the visibility predicate for that Application
- **THEN** `INavigationManager::getAll()` includes an entry with
  `id = "openbuilt-app-{slug}"`, `href = "/apps/openbuilt/{slug}"`, and the app's name

#### Scenario: Draft app does not appear in the top bar

- **WHEN** an Application has `status: draft`
- **THEN** no nav entry with `id = "openbuilt-app-{slug}"` appears for any user

#### Scenario: Archived app does not appear in the top bar

- **WHEN** an Application has `status: archived`
- **THEN** no nav entry with `id = "openbuilt-app-{slug}"` appears for any user

### Requirement: REQ-OBNAV-002 Nav entry gated by permissions RBAC

Each nav entry's visibility closure SHALL resolve the signed-in user's UID and group
memberships via `IUserSession` and `IGroupManager` and return `true` only when the user
satisfies at least one of the following:

1. The user's UID matches a `user:<uid>` entry in any of `permissions.owners`,
   `permissions.editors`, or `permissions.viewers`.
2. Any of the user's group GIDs matches a `group:<gid>` entry or a bare group ID in any of
   the three role arrays.
3. The literal `group:*` appears in any of the three role arrays.
4. The user is a Nextcloud admin.

An Application whose `permissions.owners`, `permissions.editors`, and `permissions.viewers`
are all empty (or absent) SHALL NOT be visible to non-admin users, regardless of status.

#### Scenario: Owner-role user sees the nav entry

- **WHEN** user `alice` has UID `alice` and the Application has
  `permissions.owners = ["user:alice"]`
- **THEN** alice's request cycle includes the nav entry for that Application

#### Scenario: Group-eligible viewer sees the nav entry

- **WHEN** user `bob` is a member of group `viewers-alpha`
- **AND** the Application has `permissions.viewers = ["group:viewers-alpha"]`
- **THEN** bob's request cycle includes the nav entry

#### Scenario: Non-member cannot see the nav entry

- **WHEN** user `eve` has no groups matching any role array
- **AND** the Application permissions contain no `group:*` wildcard
- **THEN** eve's request cycle does NOT include the nav entry for that Application

#### Scenario: Nextcloud admin always sees the nav entry

- **WHEN** the signed-in user is a Nextcloud admin
- **AND** the Application is published with empty permissions
- **THEN** the admin's request cycle includes the nav entry

### Requirement: REQ-OBNAV-003 group-wildcard nav-entry visibility SHALL apply to all signed-in users

The system SHALL make the nav entry visible to every signed-in Nextcloud user, regardless of
their group memberships, when the literal string `group:*` appears in any of
`permissions.owners`, `permissions.editors`, or `permissions.viewers` on a published
Application. The wildcard SHALL be detected before the group-intersection check runs.

#### Scenario: `group:*` in owners makes entry universally visible

- **WHEN** the Application has `permissions.owners = ["group:*"]`
- **AND** user `charlie` has no other group memberships
- **THEN** charlie's request cycle includes the nav entry

#### Scenario: `group:*` in viewers makes entry universally visible

- **WHEN** the Application has `permissions.viewers = ["group:*"]`
- **AND** an arbitrary signed-in user with no matching group memberships requests a page
- **THEN** that user's request cycle includes the nav entry

### Requirement: REQ-OBNAV-004 Nav entry list is re-evaluated per request without writeback

The set of published Applications SHALL be read from OR on each boot-cycle evaluation inside
`AppNavigationService`. No writeback to a separate nav-entry table or a cached register SHALL
occur. The update from draft to published (or published to archived) is detected automatically
because the service re-queries the `status == published` filter on every request boot cycle.

#### Scenario: Transitioning an Application to archived removes its nav entry

- **WHEN** an Application is transitioned from `published` to `archived`
- **THEN** on the next Nextcloud request boot cycle, the nav entry for that Application is
  absent from `INavigationManager::getAll()`
- **AND** no separate listener or writeback step is required

#### Scenario: Transitioning an Application to published adds its nav entry

- **WHEN** an Application is transitioned from `draft` to `published`
- **THEN** on the next Nextcloud request boot cycle, the nav entry for that Application is
  present in `INavigationManager::getAll()` for eligible users
