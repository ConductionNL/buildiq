## ADDED Requirements

### Requirement: REQ-OBV-101 ApplicationVersion schema declared in OpenRegister

The system SHALL declare an `ApplicationVersion` schema in
`lib/Settings/openbuilt_register.json` under the `openbuilt` register namespace
(Schema.org analogue: `SoftwareApplication`). The schema SHALL define properties:

- `name` (string, required) — human-readable display label set by the admin
  (e.g. `"Production"`, `"Staging"`, `"Development"`).
- `slug` (string, required, kebab-case pattern `^[a-z0-9][a-z0-9-]*[a-z0-9]$`,
  min 2, max 48) — URL-safe form (e.g. `"production"`, `"staging"`, `"development"`).
- `manifest` (object, required) — the JSON manifest blob, validated against
  `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` v1.4.0+.
- `register` (string, required) — name of the per-version OR register that holds
  this version's schemas and objects. Convention:
  `openbuilt-{appSlug}-{versionSlug}` (e.g. `openbuilt-hello-world-production`).
  Actual register provisioning is out of scope for this spec and owned by the
  creation-wizard capability.
- `semver` (string, required, pattern
  `^[0-9]+\\.[0-9]+\\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\\+[0-9A-Za-z.-]+)?$`) —
  this version's semantic version. Initial value `0.1.0`.
- `status` (string, enum `draft | published | archived`, default `draft`) — lifecycle
  state, per-version.
- `application` (relation → Application, required) — back-reference to the parent
  Application.
- `promotesTo` (relation → ApplicationVersion, optional) — single optional downstream
  in the linear promotion chain. Terminal versions have `promotesTo: null`.

The schema SHALL be imported into OpenRegister at app install / post-migration time
via `ConfigurationService::importFromApp()` in the existing repair step.

#### Scenario: Schema is available after install

- **WHEN** the OpenBuilt repair step runs on a fresh install
- **THEN** OpenRegister exposes the `openbuilt/applicationVersion` schema with the
  declared properties
- **AND** the schema appears in OR's standard schema listing for the `openbuilt`
  register namespace

#### Scenario: ApplicationVersion row is created via OR REST

- **WHEN** a client POSTs a payload (carrying `name`, `slug`, `manifest`, `register`,
  `semver`, `status`, and an `application` relation) to OR's REST endpoint for the
  `openbuilt/applicationVersion` namespace
- **THEN** OR persists the object, returns 201, and the returned object carries an
  OR-assigned `uuid` and the submitted fields

### Requirement: REQ-OBV-102 Initial semver is 0.1.0 on creation

The system SHALL set `semver` to the plain string `0.1.0` on every newly created
`ApplicationVersion` row that does not supply a `semver` value at creation time. No
prerelease tag, no build metadata. The default applies whether the row is created by
the creation wizard, by the API directly, or by any other consumer.

#### Scenario: Fresh ApplicationVersion defaults to 0.1.0

- **GIVEN** a client creates a new ApplicationVersion without supplying `semver`
- **WHEN** the create succeeds
- **THEN** the persisted row's `semver` is the literal string `0.1.0`

#### Scenario: Explicit semver at creation is honoured

- **WHEN** a client creates an ApplicationVersion with `semver: 2.5.0`
- **THEN** the persisted row's `semver` is `2.5.0` (the auto-bump does not override
  an explicitly-supplied value at creation)

### Requirement: REQ-OBV-103 Manifest content change auto-bumps the patch component

The system SHALL maintain `ApplicationVersion.semver` by patch-bumping it whenever
the saved `manifest` content differs from the previously-saved `manifest` content.
The comparison SHALL be a stable SHA-256 hash over the canonicalised JSON of
`manifest` (recursively sorted keys, no whitespace, normalised number/string
encoding). The previous hash SHALL be persisted on the row in a mapper-internal
`manifestHash` field. Saves that change ONLY metadata (`name`, `description`,
`register`, `permissions`, or any non-`manifest` property) SHALL NOT bump `semver`.
The bump increments the patch component (e.g. `0.1.0 → 0.1.1`, `1.4.7 → 1.4.8`).
Minor and major bumps remain manual (explicit `semver` value on the save payload).

The hash-diff logic SHALL live in `ApplicationVersionService::onSave()`, called on
the existing OR save path (per ADR-031 §Exceptions(2) — stateful diff is outside
declarative calc vocabulary).

#### Scenario: Manifest content change bumps patch

- **GIVEN** an ApplicationVersion with `semver: 0.1.0` and a stored `manifestHash`
- **WHEN** a client saves the same row with a manifest whose canonical-JSON hash
  differs from the stored hash
- **THEN** the persisted `semver` is `0.1.1`
- **AND** the persisted `manifestHash` matches the new manifest's canonical hash

#### Scenario: Metadata-only change does not bump

- **GIVEN** an ApplicationVersion with `semver: 0.2.3` and an unchanged manifest
- **WHEN** a client saves the row with only `name` and `description` modified (the
  `manifest` blob is byte-identical to the previously-saved one)
- **THEN** the persisted `semver` remains `0.2.3`
- **AND** the persisted `manifestHash` is unchanged

#### Scenario: Whitespace-only manifest change does not bump

- **GIVEN** an ApplicationVersion with `semver: 0.1.5`
- **WHEN** a client saves the row with a manifest that re-orders keys but has
  identical semantic content (the canonicalised JSON is byte-equal)
- **THEN** the persisted `semver` remains `0.1.5`

### Requirement: REQ-OBV-104 Cycle prevention on the promotesTo chain

The system SHALL reject any `ApplicationVersion` save where setting `promotesTo`
would create a cycle in the linear promotion chain. The check SHALL walk
`promotesTo` forward from the proposed target up to a hard cap of 100 hops; if the
current row's `uuid` is encountered along the walk, the save is rejected with a
422 response naming the conflict. Self-loops (`promotesTo` equal to the current
row's `uuid`) SHALL be rejected by an `x-openregister-validation` same-row check.
Broader cycle detection SHALL run as
`ApplicationVersionService::guardNoCycle()` (per ADR-031 §Exceptions(1) —
cross-row validation).

#### Scenario: Self-loop is rejected

- **WHEN** a client saves an ApplicationVersion with `promotesTo` pointing at its
  own `uuid`
- **THEN** the save fails with 422 citing the self-reference
- **AND** no row is updated

#### Scenario: Indirect cycle is rejected

- **GIVEN** three ApplicationVersion rows A, B, C with `A → B → C` (chain via
  `promotesTo`)
- **WHEN** a client saves C with `promotesTo = A`
- **THEN** the save fails with 422 citing the cycle through B
- **AND** no row is updated

#### Scenario: Valid linear extension succeeds

- **GIVEN** three ApplicationVersion rows A, B, C where A and B already form
  `A → B` and C has no `promotesTo`
- **WHEN** a client saves B with `promotesTo = C`
- **THEN** the save succeeds (`A → B → C`)

### Requirement: REQ-OBV-105 Production version is set explicitly on Application

The `Application.productionVersion` relation pointer SHALL be set explicitly by the
admin (e.g. via the creation wizard's preset or the detail-page version switcher,
both out of scope for this spec). It SHALL NOT be derived from chain terminality.
This survives chain reshaping — inserting or removing versions in the chain SHALL
NOT cause `productionVersion` to silently change.

On every Application save (or `productionVersion` change), the system SHALL verify
that the referenced ApplicationVersion's `application` relation points back at this
Application. Mismatches SHALL be rejected with a 422 response. Implementation:
`ApplicationVersionService::guardProductionVersionOwnership()`, invoked from the
Application pre-save path (per ADR-031 §Exceptions(1) — cross-row).

#### Scenario: Setting productionVersion to a valid version succeeds

- **GIVEN** an Application X and an ApplicationVersion V whose `application` points
  at X
- **WHEN** a client sets `X.productionVersion = V`
- **THEN** the save succeeds

#### Scenario: Setting productionVersion to a foreign version is rejected

- **GIVEN** an Application X and an ApplicationVersion V whose `application` points
  at a different Application Y
- **WHEN** a client sets `X.productionVersion = V`
- **THEN** the save fails with 422 citing the back-reference mismatch
- **AND** X's `productionVersion` is unchanged

#### Scenario: Inserting an intermediate version does not change productionVersion

- **GIVEN** an Application X with `productionVersion = V_prod` and a chain
  `V_dev → V_prod`
- **WHEN** the admin inserts a new ApplicationVersion `V_stage` with
  `V_dev.promotesTo = V_stage` and `V_stage.promotesTo = V_prod`
- **THEN** `X.productionVersion` is still `V_prod`

### Requirement: REQ-OBV-106 Lifecycle on ApplicationVersion drives BuiltAppRoute upsert

The `ApplicationVersion` schema SHALL declare its state machine via
`x-openregister-lifecycle` with states (`draft`, `published`, `archived`) and
transitions (`draft → published`, `published → archived`, `archived → draft`). The
`on_transition` action on the `draft → published` edge SHALL upsert a
`BuiltAppRoute` row keyed by the parent Application's `slug` and pointing at the
parent Application's `uuid`. This action is **relocated** from the Application
schema (chain spec `openbuilt-application-register` REQ-OBA-004) — published-ness
is per-version under the new model.

#### Scenario: Publishing an ApplicationVersion upserts BuiltAppRoute

- **GIVEN** an Application `<slug>` with at least one ApplicationVersion in
  `draft`
- **WHEN** an authorised user transitions that ApplicationVersion from `draft` to
  `published`
- **THEN** a `BuiltAppRoute` row exists with `slug: <slug>` and `applicationUuid`
  matching the parent Application's `uuid`

#### Scenario: Disallowed transition is rejected

- **WHEN** a client attempts a transition not declared on the lifecycle (e.g.
  `draft → archived` directly)
- **THEN** the system returns a 4xx error
- **AND** the version's `status` is unchanged
- **AND** no audit entry is recorded

### Requirement: REQ-OBV-107 ApplicationVersion CRUD endpoints

The system SHALL expose `ApplicationVersionsController` at
`/index.php/apps/openbuilt/api/applications/{slug}/versions` with the following
methods:

- `GET /` — list ApplicationVersions for the named Application (filtered by the
  parent Application's `slug`).
- `GET /{versionSlug}` — fetch one ApplicationVersion by `slug`.
- `POST /` — create a new ApplicationVersion.
- `PUT /{versionSlug}` — update one ApplicationVersion. Triggers the semver
  auto-bump (REQ-OBV-103) and cycle guard (REQ-OBV-104).
- `DELETE /{versionSlug}?strategy=<delete-now|orphan-grace|keep-register>` —
  delete one ApplicationVersion using the named strategy (see REQ-OBV-108).

All endpoints SHALL carry `#[NoAdminRequired]` and SHALL respect the parent
Application's `permissions` RBAC block (owners/editors for write, viewers for
read). All endpoints SHALL be registered in `appinfo/routes.php`.

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

### Requirement: REQ-OBV-108 Version-deletion endpoint accepts a strategy

The system SHALL accept the `?strategy=` query parameter on `DELETE` with three
values:

- `delete-now` — drop the per-version register named in
  `ApplicationVersion.register` (and every object inside it) immediately, then
  delete the ApplicationVersion row.
- `orphan-grace` — mark the per-version register as orphaned (set an orphan flag
  on the register row, e.g. `_self.orphanedAt = <ISO timestamp>` or an equivalent
  mechanism exposed by the OR floor), delete the ApplicationVersion row, leave the
  register data intact. A background job (out of scope for this spec) prunes
  orphaned registers after 30 days.
- `keep-register` — delete the ApplicationVersion row only; leave the register
  untouched for manual recovery.

The endpoint SHALL reject deletion of the ApplicationVersion currently pointed at
by `Application.productionVersion` with a 422 response naming the constraint.
Strategy-branching logic lives in
`ApplicationVersionService::deleteVersion($versionUuid, $strategy)`.

#### Scenario: delete-now drops the register and the version row

- **GIVEN** an ApplicationVersion `V` whose `register` is `openbuilt-<slug>-staging`
  and at least one object inside that register
- **WHEN** an authenticated owner sends `DELETE …/versions/staging?strategy=delete-now`
- **THEN** the per-version register `openbuilt-<slug>-staging` no longer exists
- **AND** the ApplicationVersion row `V` no longer exists
- **AND** the response is `204`

#### Scenario: orphan-grace flags the register and drops the version row

- **WHEN** an authenticated owner sends
  `DELETE …/versions/staging?strategy=orphan-grace`
- **THEN** the per-version register row carries an orphan-mark flag with a
  timestamp
- **AND** the ApplicationVersion row no longer exists
- **AND** the register's objects remain intact

#### Scenario: keep-register drops only the version row

- **WHEN** an authenticated owner sends
  `DELETE …/versions/staging?strategy=keep-register`
- **THEN** the per-version register and its objects remain untouched
- **AND** the ApplicationVersion row no longer exists

#### Scenario: Deleting the production version is rejected

- **GIVEN** an Application X with `productionVersion = V_prod`
- **WHEN** a client sends `DELETE …/versions/<V_prod's slug>?strategy=delete-now`
- **THEN** the response is `422` citing the production-version constraint
- **AND** neither `V_prod` nor its register is modified

#### Scenario: Missing or unknown strategy is rejected

- **WHEN** a client sends `DELETE …/versions/staging` without a `strategy` query
  param (or with an unknown value)
- **THEN** the response is `400` citing the missing/invalid strategy
