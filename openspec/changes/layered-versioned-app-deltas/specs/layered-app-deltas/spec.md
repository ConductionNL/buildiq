## ADDED Requirements

### Requirement: ApplicationVersion carries a scope discriminator and owner

The system SHALL extend the `ApplicationVersion` schema in
`lib/Settings/openbuild_register.json` with two optional, additive properties:

- `scope` (string, enum `admin | user`, default `admin`) — the delta-scope
  discriminator. `admin` is the instance-wide shared delta managed by admins
  (today's behaviour); `user` is a per-user delta owned by a single user. An
  `ApplicationVersion` with no `scope` SHALL read as `admin` (legacy default).
- `owner` (string) — the owning Nextcloud user UID. Set ONLY on a `scope: user`
  row; empty/absent on `scope: admin` rows.

The schema SHALL declare an `x-openregister-validation` same-row rule
`user-scope-requires-owner` asserting that when `scope == 'user'` the `owner` is
non-null. No new OpenRegister schema SHALL be introduced — the user layer reuses
the existing `ApplicationVersion` row (Schema.org analogue: `SoftwareApplication`).

**ID:** REQ-LAD-001

#### Scenario: Admin-scoped version defaults correctly

- **WHEN** an `ApplicationVersion` is created without a `scope` value
- **THEN** the persisted row reads as `scope: admin`
- **AND** no `owner` is required

#### Scenario: User-scoped version without owner is rejected

- **WHEN** a client creates an `ApplicationVersion` with `scope: user` and no
  `owner`
- **THEN** the save fails validation citing `user-scope-requires-owner`
- **AND** no row is persisted

#### Scenario: User-scoped version with owner persists

- **WHEN** a client creates an `ApplicationVersion` with `scope: user` and
  `owner: <uid>`
- **THEN** the row persists carrying `scope: user` and the supplied `owner`

### Requirement: Application carries an allowUserOverrides flag

The system SHALL extend the `Application` schema with an optional
`allowUserOverrides` boolean property defaulting to `false`. The flag SHALL be
purely declarative and read directly by the layered resolver, the RBAC guard, and
the dashboard "create override" affordance. An `Application` with no
`allowUserOverrides` value SHALL read as `false` (default-secure).

**ID:** REQ-LAD-002

#### Scenario: Flag defaults to false

- **WHEN** an `Application` is created without `allowUserOverrides`
- **THEN** the persisted row reads as `allowUserOverrides: false`

#### Scenario: Flag can be enabled by an admin

- **WHEN** an owner of the Application sets `allowUserOverrides: true`
- **THEN** the persisted row reads as `allowUserOverrides: true`

### Requirement: Manifest resolves as base, then admin delta, then caller's user delta

The system SHALL resolve an app's effective manifest as
`base ⊕ admin-delta ⊕ (caller's own) user-delta`, applying each delta BASE-first
via the established `mergeManifestDelta` keyed-delta contract (pages by `page.id`,
widgets by `widget.id`, the `{ "$op": "remove" }` deletion marker, the optional
`__order` reorder key). The chain SHALL be expressed via the existing `baseRef`:
the admin delta's `baseRef` points at the BASE, and the user delta's `baseRef`
points at the admin delta version (`kind: application-version`,
`id: <admin-version-uuid>`). The caller's user delta SHALL be layered ONLY when
the parent `Application.allowUserOverrides` is `true` AND a `scope: user` row
owned by the caller exists; otherwise the result SHALL be exactly
`base ⊕ admin-delta`. For a HYBRID app the BASE is the fleet app's bundled
manifest, which OpenBuild does not hold, so the BASE merge SHALL remain
client-side (the loader merges the served admin+user delta chain over the bundled
base); for a VIRTUAL app the resolution MAY merge server-side via the existing
`ManifestResolverService`. The merge SHALL reuse the existing PHP
`mergeManifestDelta` port from `app-delta-override` — no new merge engine.

@e2e exclude pure-backend resolution contract — the layered merge order is verified by PHPUnit over the existing mergeManifestDelta port; the client-side HYBRID merge is the loader's concern covered by nc-vue

**ID:** REQ-LAD-003

#### Scenario: User delta layered when allowed and present

- **GIVEN** an Application with `allowUserOverrides: true`, an admin delta, and a
  user delta owned by the caller
- **WHEN** the caller resolves the app's manifest
- **THEN** the result is `base ⊕ admin-delta ⊕ user-delta`

#### Scenario: User delta ignored when overrides disabled

- **GIVEN** an Application with `allowUserOverrides: false` and a stray user delta
  owned by the caller
- **WHEN** the caller resolves the app's manifest
- **THEN** the result is `base ⊕ admin-delta` only (the user delta is not applied)

#### Scenario: No user delta resolves to admin layer

- **GIVEN** an Application with `allowUserOverrides: true` and no user delta for
  the caller
- **WHEN** the caller resolves the app's manifest
- **THEN** the result is `base ⊕ admin-delta`

### Requirement: A user delta is owned solely by its creator and gated by the flag

The system SHALL ensure that a `scope: user` `ApplicationVersion` is
created/read/edited/rolled-back ONLY by its `owner` UID (or an audited Nextcloud
admin), and ONLY when the parent `Application.allowUserOverrides` is `true`. The
write path SHALL reject a `scope: user` payload whose `owner` is not the calling
user. Reads and lists of `scope: user` rows SHALL be filtered to
`owner == caller.uid`. The enforcement SHALL be fail-closed (unresolvable owner,
foreign owner, or missing flag denies) and SHALL extend the existing
`ApplicationVersionOwnerGuard` (`lib/Lifecycle/`) and `PermissionResolver`
(`lib/Service/`) — not a new service (ADR-031 §Exceptions(1) cross-row).

**ID:** REQ-LAD-004

#### Scenario: Owner edits their own user delta

- **GIVEN** an Application with `allowUserOverrides: true` and a user delta owned
  by user A
- **WHEN** user A edits or rolls back that delta
- **THEN** the operation succeeds

#### Scenario: Foreign user cannot access another user's delta

- **WHEN** user B attempts to read, edit, or roll back a `scope: user` delta
  owned by user A
- **THEN** the request is denied (403 or filtered to empty)
- **AND** no part of user A's delta appears in the response

#### Scenario: User delta write rejected when flag disabled

- **GIVEN** an Application with `allowUserOverrides: false`
- **WHEN** any user attempts to create or edit a `scope: user` delta for it
- **THEN** the request is denied
- **AND** no `scope: user` row is persisted

### Requirement: Per-layer version history reuses OpenRegister versioning

The system SHALL reuse OpenRegister's native object versioning, rollback, and
time-travel for the edit history of each delta row — admin and user scopes alike.
The system SHALL NOT introduce a new version-storage table, endpoint, or service
for delta history. Rollback of a delta SHALL roll back that single
`ApplicationVersion` row via OR's existing rollback path.

@e2e exclude reuse-of-OR-versioning — OR object versioning/rollback/time-travel is OR's own verified capability; this requirement asserts reuse, not new behaviour; the UI surface is covered by the application-delta-layers-ui spec

**ID:** REQ-LAD-005

#### Scenario: Editing a user delta records an OR version

- **WHEN** the owner saves an edit to their user delta
- **THEN** OpenRegister records a new object version on that `ApplicationVersion`
  row
- **AND** the prior content is recoverable via OR rollback

#### Scenario: Rolling back a delta uses OR time-travel

- **WHEN** the owner rolls a user delta back to an earlier OR version
- **THEN** the delta's `manifestDelta` reverts to that version's content via OR's
  rollback path
- **AND** no OpenBuild-local version store is consulted
