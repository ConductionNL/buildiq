## ADDED Requirements

### Requirement: User-scope delta ownership is enforced fail-closed

The system SHALL enforce that a `scope: user` `ApplicationVersion` (a per-user
manifest delta) is created, read, edited, and rolled back ONLY by its `owner` UID
or an audited Nextcloud administrator, and ONLY when the parent
`Application.allowUserOverrides` is `true`. The rule SHALL extend the existing
`ApplicationVersionOwnerGuard` (`lib/Lifecycle/`) and `PermissionResolver`
(`lib/Service/`) — NOT a new service — using OCP `IUserSession` / `IGroupManager`
for principal resolution. Enforcement SHALL be default-secure: an unresolvable
owner, a foreign owner, or a missing/false flag SHALL deny. The admin bypass SHALL
reuse the existing audited `PermissionResolver::isAdmin()` path and be recorded in
OR's per-object change trail. A user SHALL NEVER read or write another user's
delta: list/read of `scope: user` rows SHALL be filtered to `owner == caller.uid`,
and a `scope: user` write whose `owner` is not the caller SHALL be rejected.

@e2e exclude backend RBAC contract — the user-scope guard, owner filter, and flag gate are verified by PHPUnit (ApplicationVersionOwnerGuard + PermissionResolver) and a no-admin-idor cross-user test; no standalone Playwright surface

**ID:** REQ-OBRBAC-008

#### Scenario: Owner is authorised on their own user delta

- **GIVEN** an Application with `allowUserOverrides: true` and a `scope: user`
  delta owned by user A
- **WHEN** user A reads, edits, or rolls back that delta
- **THEN** the guard allows the operation

#### Scenario: Foreign user is denied on another's user delta

- **WHEN** user B requests a `scope: user` delta owned by user A
- **THEN** the guard denies the operation (403) or the row is filtered out of B's
  list
- **AND** none of user A's delta content is leaked

#### Scenario: User-scope write is denied when the flag is false

- **GIVEN** an Application with `allowUserOverrides: false`
- **WHEN** any user attempts to write a `scope: user` delta for it
- **THEN** the guard denies the write fail-closed

#### Scenario: Admin bypass on a user delta is audited

- **WHEN** a Nextcloud administrator accesses a `scope: user` delta they do not
  own
- **THEN** the operation is allowed
- **AND** the OR audit trail records the admin action with actor and timestamp
