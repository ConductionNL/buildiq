## ADDED Requirements

### Requirement: REQ-OBR-006 Application editor exposes a Publish action

`ApplicationEditor.vue` (REQ-OBR-005) SHALL render a "Publish"
action button alongside the existing Save action. Clicking Publish
SHALL: (a) require the textarea manifest to validate cleanly via
`validateManifest`; (b) on validation success, PUT any pending
manifest changes to OR and then call the Application's
`draft → published` lifecycle transition endpoint; (c) on
transition success, surface a confirmation toast naming the new
`ApplicationVersion` `uuid` returned in the response; (d) on
transition failure (e.g. slug-conflict per REQ-OBA-004), surface an
inline error and leave the manifest in draft state. The button
SHALL be disabled while the lifecycle call is in flight.

#### Scenario: Successful publish creates a snapshot

- **WHEN** an integrator opens the editor for a draft Application,
  edits the manifest validly, and clicks Publish
- **THEN** the manifest is saved to OR
- **AND** the lifecycle transition is invoked
- **AND** the confirmation toast surfaces with the newly created
  `ApplicationVersion` `uuid`

#### Scenario: Validation blocks publish

- **WHEN** an integrator clicks Publish while the manifest is
  invalid
- **THEN** no save or lifecycle call is sent
- **AND** the editor surfaces the validation error inline (same
  contract as Save)

### Requirement: REQ-OBR-007 Draft-vs-published indicator surfaces lifecycle state

The OpenBuilt shell SHALL surface the Application's current
`status` (and a marker for "has unpublished draft changes") in two
places: (1) each row of the Application list view carries a small
status badge (`draft` / `published` / `archived`); (2) the editor
header for an open Application carries the same badge plus a
"draft modified since last publish" indicator when the in-textarea
manifest differs from the most recent `ApplicationVersion.manifest`.
The badge SHALL use Nextcloud CSS variables for colour (no
hardcoded colour literals — per ADR-010).

#### Scenario: Newly published Application shows published badge

- **WHEN** an Application has been published and its draft has not
  yet been modified
- **THEN** both the list row and the editor header show a
  `published` badge
- **AND** no "draft modified since last publish" indicator is
  shown

#### Scenario: Edited draft shows modified indicator

- **WHEN** an integrator has edited an Application's manifest after
  the most recent publish but before publishing again
- **THEN** the editor header shows the `draft` badge with a
  "modified since last publish" marker
- **AND** the list row reflects the same state

### Requirement: REQ-OBR-008 VersionHistory.vue lists snapshots for an Application

The OpenBuilt shell SHALL render a `VersionHistory.vue` panel
inside `ApplicationEditor.vue` (collapsible / a sibling tab,
implementer's choice) listing every `ApplicationVersion` row for
the current Application in reverse-chronological order (newest
first). Each row SHALL display `version`, `publishedAt` (localised),
`publishedBy`, and any `notes`. The list SHALL be read from OR REST
filtered by `applicationUuid` — no app-local wrapper service.

#### Scenario: History panel renders snapshots

- **WHEN** an integrator opens an Application that has three
  `ApplicationVersion` rows
- **THEN** the version-history panel renders three rows in
  newest-first order
- **AND** each row shows `version`, `publishedAt`, `publishedBy`,
  and `notes`

#### Scenario: History panel is empty for a never-published Application

- **WHEN** an integrator opens a `draft` Application that has no
  `ApplicationVersion` rows yet
- **THEN** the version-history panel renders an empty state
- **AND** no console error is emitted from the empty-list fetch

### Requirement: REQ-OBR-009 Rollback action restores a chosen snapshot

Each row in the `VersionHistory.vue` panel SHALL carry a "Roll back
to this version" action. Clicking it SHALL: (a) prompt for
confirmation in a modal naming the target `version`; (b) on
confirmation, PUT the chosen snapshot's `manifest` onto the
Application as the new draft manifest, set the Application's
`version` per REQ-OBV-003, and leave the Application's `status` as
`draft`; (c) refresh the editor so the textarea reflects the
restored manifest. Per design.md Decision 3 the rollback is
audit-clean — it does **not** delete or mutate existing
`ApplicationVersion` rows. The confirmation modal SHALL live in
its own SFC under `src/modals/` per Hydra modal-isolation gate
(ADR-004).

#### Scenario: Rollback restores manifest and stays in draft

- **WHEN** an integrator clicks "Roll back to this version" on the
  oldest row in the history panel and confirms in the modal
- **THEN** the Application's draft manifest is byte-equal to the
  chosen snapshot's manifest
- **AND** the Application's status is `draft`
- **AND** no `ApplicationVersion` row has been deleted

#### Scenario: Cancelling the confirmation aborts the rollback

- **WHEN** the integrator opens the confirmation modal and clicks
  Cancel
- **THEN** no PUT is sent to OR
- **AND** the textarea content is unchanged

### Requirement: REQ-OBR-010 ManifestDiff.vue renders a side-by-side diff

The OpenBuilt shell SHALL ship a `ManifestDiff.vue` component
rendering a client-side side-by-side diff between two manifest
blobs. The component SHALL: (a) accept `from` and `to`
`ApplicationVersion` UUIDs (or the literal `draft` for either) as
props; (b) fetch both manifests via the diff endpoint defined in
REQ-OBV-005; (c) compute the diff client-side via `jsdiff` (or an
equivalent library — per design.md Decision 5); (d) render added
lines, removed lines, and unchanged lines with NL Design
colour-coded tokens using Nextcloud CSS variables. By default the
editor SHALL preselect `from=draft` and `to=<currentVersion>` when
the diff view is opened.

#### Scenario: Default diff shows current draft vs latest published

- **WHEN** an integrator opens the diff view from the editor of an
  Application that has been published at least once
- **THEN** the component fetches the diff endpoint with
  `from=draft` and `to=<currentVersion>`
- **AND** the side-by-side rendering shows the diff between the
  two manifests
- **AND** the diff is computed client-side (no second round-trip
  to a server-side diff service)

#### Scenario: Arbitrary snapshot pair can be diffed

- **WHEN** an integrator selects two arbitrary
  `ApplicationVersion` rows from the version-history panel and
  invokes "Compare"
- **THEN** `ManifestDiff.vue` mounts with those two UUIDs
- **AND** the rendered diff matches what the diff endpoint
  returned for that pair
