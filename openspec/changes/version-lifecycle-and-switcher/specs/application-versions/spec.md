## MODIFIED Requirements

### Requirement: ApplicationVersion CRUD endpoints

The system SHALL expose `ApplicationVersionsController` at
`/index.php/apps/openbuild/api/applications/{slug}/versions` with the following
methods:

- `GET /` — list ApplicationVersions for the named Application (filtered by the
  parent Application's `slug`).
- `GET /{versionSlug}` — fetch one ApplicationVersion by `slug`.
- `POST /` — create a new ApplicationVersion. When the create payload does NOT supply a
  `register` value, the create path SHALL inherit the `register` of the parent
  Application's current production version (`Application.productionVersion.register`) so
  the new version SHARES production's data register (manifest-only versioning, design.md
  Decision 2). When the payload DOES supply a `register`, that value SHALL be honoured
  unchanged (the per-version register convention remains available for the creation
  wizard / promotion paths). The create path SHALL NOT mint an
  `openbuild-{appSlug}-{versionSlug}` register on this UI's behalf.
- `PUT /{versionSlug}` — update one ApplicationVersion. Triggers the semver
  auto-bump (REQ-OBV-103) and cycle guard (REQ-OBV-104).
- `DELETE /{versionSlug}?strategy=<delete-now|orphan-grace|keep-register>` —
  delete one ApplicationVersion using the named strategy (see REQ-OBV-108).

All endpoints SHALL carry `#[NoAdminRequired]` and SHALL respect the parent
Application's `permissions` RBAC block (owners/editors for write, viewers for
read). All endpoints SHALL be registered in `appinfo/routes.php`.

**ID:** REQ-OBV-107

#### Scenario: List endpoint returns versions for one app

- **WHEN** an authenticated user with viewer access GETs
  `/api/applications/hello-world/versions`
- **THEN** the response is `200 application/json` with an array of ApplicationVersion
  rows whose `application` relation points at the `hello-world` Application

#### Scenario: Cross-app slug returns no versions

- **WHEN** an authenticated user GETs `/api/applications/<slug>/versions` for a
  slug that has no Application
- **THEN** the response is `404`

#### Scenario: Create returns 201 with auto-defaulted semver

- **WHEN** an authenticated owner POSTs a valid ApplicationVersion payload omitting
  `semver`
- **THEN** the response is `201` and the returned row has `semver: "0.1.0"`

#### Scenario: Create without a register inherits the production register

- **GIVEN** an Application `hello-world` whose production version's `register` is
  `openbuild-hello-world-production`
- **WHEN** an authenticated owner POSTs a create payload that omits `register`
- **THEN** the created ApplicationVersion's `register` is
  `openbuild-hello-world-production` (inherited from the production version)
- **AND** no new register is provisioned

#### Scenario: Create with an explicit register honours the supplied value

- **WHEN** an authenticated owner POSTs a create payload carrying
  `register: "openbuild-hello-world-staging"`
- **THEN** the created ApplicationVersion's `register` is
  `openbuild-hello-world-staging` (the supplied value is not overridden)

## ADDED Requirements

### Requirement: Release endpoint sets production, publishes, and demotes the previous production

The system SHALL expose an owner-only release operation on the parent Application that, for
a chosen `ApplicationVersion`, atomically in intent:

1. verifies the caller is an **owner** of the parent Application (reusing the
   `ApplicationPublishController` owner gate / `ApplicationVersionOwnerGuard`); a
   non-owner SHALL receive `403`. Nextcloud admins SHALL NOT be auto-granted.
2. transitions the chosen version `draft → published` via the existing per-version
   `x-openregister-lifecycle` (firing the BuiltAppRoute upsert, REQ-OBV-106). If the
   chosen version is already `published`, this step is a no-op.
3. sets `Application.productionVersion` to the chosen version's uuid, validated by
   `ApplicationVersionService::guardProductionVersionOwnership` (back-reference check;
   mismatch → `422`).
4. demotes the previously production version (if any and if different) by setting its
   `status` to `archived`, so it no longer holds the production role.

After a successful release exactly one version is the Application's production version
(single-production invariant). The endpoint SHALL be registered in `appinfo/routes.php` and
SHALL carry `#[NoAdminRequired]` (the owner check lives in the method body).

The endpoint SHALL NOT drop or mint any register. When the chosen version shares
production's register (the manifest-only case), the demoted previous production keeps its
register row untouched.

**ID:** REQ-OBV-110

#### Scenario: Owner releases a draft to production

- **GIVEN** an Application X with `productionVersion = V_old` (`V_old.status: published`)
  and a draft `V_new` whose `application` points at X
- **WHEN** an owner calls the release endpoint for `V_new`
- **THEN** the response is `200`
- **AND** `X.productionVersion` is `V_new`
- **AND** `V_new.status` is `published`
- **AND** `V_old.status` is `archived`

#### Scenario: Release of a foreign version is rejected

- **GIVEN** a version `V_foreign` whose `application` points at a different Application Y
- **WHEN** an owner of X calls the release endpoint for `V_foreign`
- **THEN** the response is `422` citing the back-reference mismatch
- **AND** `X.productionVersion` is unchanged

#### Scenario: Non-owner is rejected

- **GIVEN** a caller listed in `permissions.editors` (not `owners`) on Application X
- **WHEN** the caller calls the release endpoint
- **THEN** the response is `403`
- **AND** no version status or production pointer is changed

#### Scenario: Nextcloud admin without owner role is rejected

- **GIVEN** a Nextcloud admin who is NOT in X's `permissions.owners`
- **WHEN** the admin calls the release endpoint
- **THEN** the response is `403` (admin power does NOT auto-grant release)

### Requirement: Delete must not drop a register shared with production

The system SHALL NOT drop a register that is shared with production. When an
`ApplicationVersion`'s `register` is the SAME as the parent Application's
production version's `register` (the manifest-only / shared-register case, design.md
Decision 2), the deletion endpoint SHALL NOT execute the `delete-now` strategy's
register-drop step — dropping a register shared by production would destroy production
data. A `delete-now` request against such a version SHALL be treated as `keep-register`
(drop the version row only, leave the register intact), or rejected with a `422` naming
the shared-register constraint. The production version itself remains undeletable
(REQ-OBV-108).

**ID:** REQ-OBV-111

#### Scenario: delete-now on a production-shared draft does not drop the register

- **GIVEN** a draft `V_draft` whose `register` equals the production version's `register`
- **WHEN** an owner sends `DELETE …/versions/<V_draft slug>?strategy=delete-now`
- **THEN** the shared register and its objects remain intact
- **AND** the `V_draft` row is removed (or the request is rejected with `422` naming the
  shared-register constraint, with nothing deleted)

#### Scenario: delete-now on a version with its own register still drops it

- **GIVEN** a version whose `register` is NOT shared with production
- **WHEN** an owner sends `DELETE …/versions/<slug>?strategy=delete-now`
- **THEN** that version's register is dropped and the version row removed (REQ-OBV-108
  behaviour unchanged)
