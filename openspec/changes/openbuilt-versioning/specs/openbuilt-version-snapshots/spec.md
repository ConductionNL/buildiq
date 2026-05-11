## ADDED Requirements

### Requirement: REQ-OBV-001 ApplicationVersion schema declared in OpenRegister

The system SHALL declare an `ApplicationVersion` schema in
`lib/Settings/openbuilt_register.json` under the `openbuilt` register
namespace. The schema SHALL define properties `uuid` (string,
UUID-format), `applicationUuid` (string, UUID-format, required —
foreign reference to the parent Application), `version` (string,
semver pattern, required), `manifest` (object, required — full
manifest blob copy at snapshot time), `publishedAt` (string, ISO
8601 date-time, required), `publishedBy` (string, NC user id,
required), and `notes` (string, optional). The schema SHALL be
imported into OpenRegister at app install / post-migration time via
the existing repair step. No PHP service class (e.g.
`ApplicationVersionService`) SHALL be written to wrap CRUD over this
schema — clients read and write `ApplicationVersion` rows via OR's
REST API per ADR-022.

#### Scenario: Schema is available after install

- **WHEN** the OpenBuilt app's repair step runs against an install
  that already has the `Application` schema from spec #1
- **THEN** OpenRegister exposes the `openbuilt/application-version`
  schema with the declared properties
- **AND** the schema appears in OR's standard schema listing for the
  `openbuilt` register namespace

#### Scenario: ApplicationVersion row is created via OR REST

- **WHEN** a client POSTs a valid payload (carrying
  `applicationUuid`, `version`, `manifest`, `publishedAt`,
  `publishedBy`) to OR's REST endpoint for the
  `openbuilt/application-version` namespace
- **THEN** OR persists the object, returns 201, and the returned
  object carries an OR-assigned `uuid` and the submitted fields

### Requirement: REQ-OBV-002 Snapshot is created on draft-to-published transition

The system SHALL create an `ApplicationVersion` row every time an
`Application` transitions from `draft` to `published`. The snapshot
SHALL deep-copy the Application's current `manifest` blob, capture
its current `version` string, set `publishedAt` to the transition's
server timestamp, set `publishedBy` to the authenticated actor's NC
user id, and set `applicationUuid` to the Application's UUID. The
snapshot creation SHALL be declared as
`x-openregister-lifecycle.on_transition` action metadata on the
`Application` schema. If OR's lifecycle engine does not yet expose
an action that can create a sibling object on a state transition,
the system MAY ship a single
`ApplicationVersionSnapshotListener` PHP class subscribed to OR's
`ObjectLifecycleTransitionedEvent` as an ADR-031 §Exceptions(1)
fallback — the behaviour observed from outside SHALL be identical
in either case.

#### Scenario: Publishing creates a snapshot

- **WHEN** an authenticated user transitions a `draft` Application
  to `published` via the lifecycle endpoint
- **THEN** a new `ApplicationVersion` row exists in OR with
  `applicationUuid` matching the Application's UUID, `manifest`
  byte-equal to the Application's manifest at transition time,
  `version` matching the Application's `version`, and `publishedAt`
  / `publishedBy` populated

#### Scenario: Failed transition creates no snapshot

- **WHEN** an authenticated user attempts a disallowed transition
  (e.g. `draft → archived` per spec #1's lifecycle declaration)
- **THEN** no `ApplicationVersion` row is created
- **AND** no audit `lifecycle.transition` entry is recorded

### Requirement: REQ-OBV-003 Rollback restores a previous snapshot as the draft manifest

The system SHALL support rolling back an Application to any of its
historical `ApplicationVersion` snapshots. The rollback action
SHALL copy the chosen `ApplicationVersion.manifest` blob onto the
Application's current draft manifest (leaving the Application in
`draft` status, ready for republish), set the Application's
`version` to the chosen snapshot's `version` suffixed with a
rollback marker (e.g. `+rollback`) so that the next publish creates
a new distinct snapshot, and SHALL **not** delete or overwrite any
existing `ApplicationVersion` row — history is append-only.

#### Scenario: Rollback restores the manifest without history rewrite

- **WHEN** an Application has three historical `ApplicationVersion`
  rows (v1.0.0, v1.1.0, v1.2.0)
- **AND** an authenticated user rolls back to v1.0.0
- **THEN** the Application's draft `manifest` is byte-equal to
  v1.0.0's `manifest`
- **AND** all three original `ApplicationVersion` rows remain
  unchanged in OR
- **AND** the Application's `status` is `draft`

#### Scenario: Republish after rollback creates a fresh snapshot

- **WHEN** the user from the previous scenario publishes the
  rolled-back draft
- **THEN** a fourth `ApplicationVersion` row is created with a new
  `uuid`, the restored manifest, and a fresh `publishedAt`
- **AND** the Application's `currentVersion` points at the new row,
  not at v1.0.0

### Requirement: REQ-OBV-004 Version history is retained without retention cap

The system SHALL retain every `ApplicationVersion` row indefinitely
for the foreseeable future of an Application's lifetime. No
automatic deletion, time-based expiry, or "keep last N" trimming
SHALL be applied in v1. Retention policy is explicitly deferred —
storage cost is bounded by manifest blob size (kilobytes per snapshot)
and the expected publish cadence (a handful per day per app at most).
If a future spec introduces a retention cap, it SHALL be opt-in
per Application.

#### Scenario: Old snapshots remain queryable

- **WHEN** an Application has been publishing for an extended period
  and accumulated many `ApplicationVersion` rows
- **THEN** the oldest row remains readable via OR REST
- **AND** no row has been deleted by automatic retention logic

### Requirement: REQ-OBV-005 Diff endpoint returns two manifest blobs in one call

The system SHALL expose
`GET /index.php/apps/openbuilt/api/applications/{slug}/versions/diff?from={uuidA}&to={uuidB}`
backed by `ApplicationsController::diffVersions`. The endpoint
SHALL resolve `{slug}` to an Application via the `BuiltAppRoute`
index (spec #1), look up both referenced `ApplicationVersion` rows
(or accept the literal string `draft` for either parameter to mean
"the current draft manifest on the Application"), and return a JSON
body of shape `{ from: { manifest, version, publishedAt }, to: {
manifest, version, publishedAt } }` so that the client diff
component renders without a second round-trip. The endpoint SHALL
respond `200` on success, `404` if any referenced version row is
missing, and SHALL enforce the same organisation scoping as the
manifest endpoint (spec #1). The endpoint SHALL carry
`#[NoAdminRequired]` and SHALL be registered in
`appinfo/routes.php`.

#### Scenario: Diff endpoint returns both manifests

- **WHEN** an authenticated user GETs the diff endpoint with two
  valid `ApplicationVersion` UUIDs for the same Application
- **THEN** the response is `200 application/json`
- **AND** the body contains both manifests unwrapped under `from`
  and `to`

#### Scenario: Diff against current draft

- **WHEN** an authenticated user GETs the diff endpoint with
  `from=draft` and `to=<latest-published-version-uuid>`
- **THEN** `from.manifest` is the Application's current draft
  manifest and `to.manifest` is the published snapshot's manifest

#### Scenario: Missing version returns 404

- **WHEN** an authenticated user GETs the diff endpoint with a
  `from` UUID that has no matching `ApplicationVersion`
- **THEN** the response is `404` with a JSON error body
- **AND** no partial data is leaked

### Requirement: REQ-OBV-006 Current version reference is maintained on the Application

The `Application` schema (spec #1) SHALL be extended with a
`currentVersion` property (string, UUID-format, optional). The
declarative lifecycle action that creates the snapshot SHALL also
update the Application's `currentVersion` to point at the freshly
created `ApplicationVersion` row's `uuid`. The same listener
fallback path described in REQ-OBV-002 SHALL be used if the
declarative path is unavailable. Reading `Application.currentVersion`
SHALL be the canonical way to identify "the latest published
manifest" without scanning the `ApplicationVersion` collection.

#### Scenario: First publish populates currentVersion

- **WHEN** an Application that has never been published transitions
  from `draft` to `published`
- **THEN** the Application's `currentVersion` is set to the UUID of
  the newly created `ApplicationVersion` row

#### Scenario: Re-publish updates currentVersion

- **WHEN** an Application is published a second time
- **THEN** its `currentVersion` is updated to the UUID of the
  second `ApplicationVersion` row
- **AND** the first row remains intact and discoverable via OR REST
