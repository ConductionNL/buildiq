## ADDED Requirements

### Requirement: Admin surface is gated on app-owner group membership

The admin-settings nav entry and the admin dialog SHALL be shown only when the calling user is an **owner** of the app. Ownership SHALL be resolved as a non-empty intersection of the caller's group GIDs — read from the already-published `buildiq.currentUserGroups` initial state via `loadState('buildiq', 'currentUserGroups', [])` — with the app's owner principals parsed from `Application.permissions.owners` using the existing `group:<gid>` / bare-GID grammar, and/or an owner signal carried on the manifest `runtime.user` context. The gate SHALL NOT use `OC.isUserAdmin()`. The system SHALL read owner inputs from initial state (`loadState`), never from DOM data-attributes.

#### Scenario: Owner sees the admin surface

- **WHEN** a user whose `currentUserGroups` intersects the app's `permissions.owners` opens the app
- **THEN** the admin-settings nav entry SHALL be shown
- **AND** the admin dialog SHALL be openable

#### Scenario: Non-owner does not see the admin surface

- **WHEN** a logged-in user whose groups do not intersect `permissions.owners` (and who carries no `runtime.user` owner signal) opens the app
- **THEN** no admin-settings nav entry SHALL be shown
- **AND** the admin dialog SHALL NOT be mountable

#### Scenario: Owner signal via runtime.user context

- **WHEN** the backend surfaces an owner flag/role on the manifest `runtime.user` context for the calling user
- **THEN** the renderer SHALL treat the caller as an owner even when `permissions.owners` is not otherwise present client-side

#### Scenario: NC super-admin flag is not the gate

- **WHEN** a Nextcloud super-admin who is not in the app's owner group opens the app
- **THEN** the admin surface SHALL NOT be shown on the basis of the super-admin flag alone

### Requirement: Owner signal is derived from existing Buildiq primitives

The backend SHALL surface the caller's owner status without introducing a parallel permission model. It SHALL reuse the already-published `buildiq.currentUserGroups`, the `Application.permissions.owners` bucket populated by `PopulateApplicationPermissions` (default group `admin`), and `PermissionResolver::matchesCaller(...)` with the `owners` role bucket. Any owner flag/role added to the manifest `runtime.user` context SHALL be a read-only projection computed via `PermissionResolver`, and SHALL NOT modify `PermissionResolver`'s grammar or the `permissions` block shape.

#### Scenario: Owner projection matches the resolver

- **WHEN** the backend computes the `runtime.user` owner signal for a caller
- **THEN** its value SHALL equal `PermissionResolver::matchesCaller(permissions, caller, callerGroups, allowAdminBypass, ['owners'])` for that app's `permissions`

#### Scenario: No new permission model is introduced

- **WHEN** this capability is implemented
- **THEN** `PermissionResolver`'s grammar and the `Application.permissions` block shape SHALL be unchanged
- **AND** owner status SHALL be derived solely from `currentUserGroups`, `permissions.owners`, and `PermissionResolver`

### Requirement: Per-section permission narrows within the owner gate

An `adminSettings` entry's optional `permission` SHALL further restrict visibility of that section using the existing per-item grammar, but SHALL only narrow — never widen — access. A section's `permission` SHALL NOT make the section visible to a caller who is not an owner of the app.

#### Scenario: Section permission hides a section from some owners

- **WHEN** an owner opens the admin dialog and a section declares a `permission` group the owner is not in
- **THEN** that section SHALL be hidden while other sections remain visible

#### Scenario: Section permission cannot widen to non-owners

- **WHEN** a non-owner would match a section's `permission`
- **THEN** the section SHALL still NOT be shown, because the owner gate on the whole dialog fails first
