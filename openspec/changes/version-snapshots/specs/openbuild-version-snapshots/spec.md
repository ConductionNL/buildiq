## ADDED Requirements

### Requirement: A user can take a named snapshot of a version

Owners and editors of an Application SHALL be able to take a snapshot of one of its
versions. A snapshot SHALL be a `version-snapshot` object in the buildiq register, not
an ApplicationVersion row, holding the version's manifest as it was, a label, the
checksum of that manifest, the user id of who took it and when. The label SHALL be
required and at most 120 characters. When no version is named, the production version
SHALL be used. Viewers and users without a role SHALL be refused; Nextcloud admins pass
through the audited admin bypass used by the other version endpoints.

@e2e exclude snapshot endpoints and tab wiring are covered by VersionSnapshotServiceTest, VersionSnapshotsControllerTest and the VersionSnapshots vitest spec; the tutorial capture run exercises the flow end to end

**ID:** REQ-OBV-201

#### Scenario: Take a snapshot with a label

- **GIVEN** an owner of app `shop` whose production version has manifest M
- **WHEN** they POST `/api/applications/shop/snapshots` with `{label: "before tasks page"}`
- **THEN** the response is `201` with the snapshot
- **AND** the snapshot has label "before tasks page", manifest M, the checksum of M,
  `takenBy` the owner's user id and `kind` `manual`

#### Scenario: A viewer cannot take a snapshot

- **GIVEN** a viewer of app `shop`
- **WHEN** they POST a snapshot
- **THEN** the response is `403` and no snapshot is stored

#### Scenario: A label is required

- **WHEN** an owner POSTs a snapshot with an empty label
- **THEN** the response is `400`

### Requirement: The Version history tab lists snapshots

The Version history tab SHALL offer **Take snapshot** to owners and editors, and SHALL
list the snapshots of the selected version, newest first, each with its label, who took
it, when, and a short checksum. Listing SHALL be allowed for owners, editors and viewers.

**ID:** REQ-OBV-202

#### Scenario: A new snapshot appears at the top

- **GIVEN** the Version history tab is open
- **WHEN** the user takes a snapshot labelled "v1"
- **THEN** "v1" is the first snapshot in the list, with the user's name

### Requirement: Rolling back to a snapshot keeps the replaced state

Owners and editors SHALL be able to roll a version back to one of its snapshots. Before
the snapshot's manifest is written onto the version, the version's current manifest
SHALL be saved as a new snapshot with `kind` `previous-draft` and label "Previous
draft", so the rollback can itself be undone. A snapshot of another Application SHALL
be treated as not found.

**ID:** REQ-OBV-203

#### Scenario: Rollback keeps a Previous draft snapshot

- **GIVEN** a version with manifest M2 and a snapshot S holding manifest M1
- **WHEN** an owner restores S
- **THEN** the version's manifest is M1
- **AND** a new snapshot labelled "Previous draft" holds M2

#### Scenario: A snapshot of another app cannot be restored

- **GIVEN** snapshot S belongs to app `other`
- **WHEN** an owner of `shop` restores S through `/api/applications/shop/snapshots/{S}/restore`
- **THEN** the response is `404` and nothing changes
