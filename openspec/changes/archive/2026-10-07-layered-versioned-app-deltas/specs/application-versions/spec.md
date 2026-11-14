## ADDED Requirements

### Requirement: ApplicationVersion declares scope and owner for per-user deltas

The system SHALL extend the `ApplicationVersion` schema in
`lib/Settings/openbuild_register.json` with two optional properties that
distinguish a shared admin delta from a per-user delta WITHOUT introducing a new
OpenRegister schema:

- `scope` (string, enum `admin | user`, default `admin`) — an
  `ApplicationVersion` with no `scope` SHALL be treated as `admin` on read
  (legacy default), preserving today's behaviour for every existing row.
- `owner` (string) — the owning Nextcloud user UID, set ONLY on `scope: user`
  rows.

The schema SHALL declare an `x-openregister-validation` same-row rule asserting
`owner != null` when `scope == 'user'`. The existing lifecycle, semver auto-bump,
cycle guard, and CRUD contract (REQ-OBV-101 … REQ-OBV-108) SHALL be unchanged
except that LIST and GET of `scope: user` rows SHALL be filtered to the owning
caller (cross-user rows are never returned).

@e2e exclude pure-backend schema/CRUD contract — the scope/owner fields, validation rule, and owner-filtered list are OR-REST + service-layer contracts verified by Newman/PHPUnit; consistent with this spec's existing backend-only posture

**ID:** REQ-OBV-109

#### Scenario: Legacy version with no scope reads as admin

- **GIVEN** an existing `ApplicationVersion` row persisted before this change with
  no `scope` field
- **WHEN** the row is read
- **THEN** it is treated as `scope: admin`
- **AND** its lifecycle and semver behaviour are unchanged

#### Scenario: User-scoped row requires an owner

- **WHEN** a client POSTs an `ApplicationVersion` with `scope: user` and no
  `owner`
- **THEN** the create is rejected by the `user-scope-requires-owner` validation
  rule

#### Scenario: List of user-scoped versions is owner-filtered

- **GIVEN** user A and user B each own a `scope: user` `ApplicationVersion` for
  the same Application
- **WHEN** user A lists the Application's versions
- **THEN** user A sees the admin version(s) and their own user version, but NOT
  user B's user version
