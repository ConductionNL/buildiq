# openbuilt-version-snapshots Specification

## Purpose
TBD - created by archiving change openbuilt-versioning. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBV-002 Snapshot is created on draft-to-published transition

The system SHALL NOT spawn sibling `ApplicationVersion` rows on
`draft → published` transitions. Snapshot-on-publish writeback is retired under
ADR-002; the versioned model treats every `ApplicationVersion` row as a long-lived
first-class object, not an append-only snapshot. History on a version is captured
by OR's object-time-travel on the `ApplicationVersion` row itself.

The system SHALL NOT subscribe any PHP listener
(`ApplicationVersionSnapshotListener` or any successor) to OR's
`ObjectLifecycleTransitionedEvent` for the purpose of creating sibling
ApplicationVersion rows. The `Application.x-openregister-lifecycle` block SHALL NOT
declare a `create_relation(ApplicationVersion)` action on any transition.

The publish transition lives on `ApplicationVersion` itself (per
`application-versions`/REQ-OBV-106) — moving an existing `ApplicationVersion` from
`draft` to `published` MUST upsert the `BuiltAppRoute` slug index, and nothing
else.

#### Scenario: Publishing does NOT create a sibling ApplicationVersion

- **GIVEN** an Application X with one `ApplicationVersion` V in `draft`
- **WHEN** V transitions from `draft` to `published`
- **THEN** only V exists in the ApplicationVersion collection for X
- **AND** no sibling ApplicationVersion row is created
- **AND** OR's audit trail records the lifecycle transition on V (not a new row)

#### Scenario: No snapshot listener subscribed

- **WHEN** the OpenBuilt app boots
- **THEN** no `ApplicationVersionSnapshotListener` (or successor) is registered as
  an event listener for `ObjectLifecycleTransitionedEvent`

### Requirement: REQ-OBV-003 Rollback restores a previous snapshot as the draft manifest

The system SHALL support rolling back any `ApplicationVersion` to a prior point in
its OR object-history via OR's time-travel API on the version row itself —
restoring a previous state of an `ApplicationVersion` MUST NOT be implemented by
copying from a sibling snapshot row (the append-only snapshot model is retired
under ADR-002).

The rollback action SHALL restore the chosen historical state of the row's
`manifest` (and any other fields captured by OR's time-travel), SHALL leave the
version's `status` at whatever the historical state recorded, and SHALL trigger
the manifest-hash semver bump (per `application-versions`/REQ-OBV-103) only when
the restored `manifest` differs from the immediately-prior saved state.

#### Scenario: Rollback uses OR object-time-travel on the version row

- **GIVEN** an ApplicationVersion V with three historical states recorded by OR
  object-history (states t0, t1, t2)
- **WHEN** an authorised user rolls V back to state t1
- **THEN** OR's time-travel API is called on V to restore t1
- **AND** no sibling `ApplicationVersion` row is created
- **AND** V's `manifest` matches t1's `manifest`

### Requirement: REQ-OBV-005 Diff endpoint returns two manifest blobs in one call

The system SHALL expose
`GET /index.php/apps/openbuilt/api/applications/{slug}/versions/diff?from={fromRef}&to={toRef}`

The diff endpoint changes shape under the versioned model: diffing two
`ApplicationVersion` rows is the canonical case; comparing two historical states
of one ApplicationVersion (time-travel diff on a single row) is the second
supported case.

The endpoint URL parameters work as follows:
where `{fromRef}` and `{toRef}` are either:

- An ApplicationVersion `slug` (e.g. `staging`) — diff is against the current saved
  state of that version's manifest.
- The literal `current:<versionSlug>` (e.g. `current:staging`) — equivalent to
  the bare slug above; reserved syntax for forward compatibility.
- A version-history reference `history:<versionSlug>:<revisionId>` — diff is against
  the named OR object-history revision of that version.

The endpoint SHALL return a JSON body `{ from: { manifest, semver, savedAt }, to:
{ manifest, semver, savedAt } }`. The endpoint SHALL carry `#[NoAdminRequired]` and
respect the parent Application's `permissions` RBAC block (viewers may diff).
Missing references SHALL return `404`.

#### Scenario: Diff two ApplicationVersions by slug

- **WHEN** an authorised viewer GETs the diff endpoint with `from=development` and
  `to=production` for an Application `<slug>`
- **THEN** the response is `200 application/json`
- **AND** the body contains the development version's manifest under `from` and the
  production version's manifest under `to`

#### Scenario: Diff two historical revisions of one version

- **WHEN** an authorised viewer GETs the diff endpoint with
  `from=history:staging:r5` and `to=history:staging:r9`
- **THEN** the response is `200`
- **AND** `from.manifest` is the manifest captured at revision r5 of the staging
  version; `to.manifest` is the manifest at revision r9

#### Scenario: Missing version returns 404

- **WHEN** an authorised viewer GETs the diff endpoint with `from=<slug>` for a
  version slug that does not exist
- **THEN** the response is `404` with a JSON error body
- **AND** no partial data is leaked

