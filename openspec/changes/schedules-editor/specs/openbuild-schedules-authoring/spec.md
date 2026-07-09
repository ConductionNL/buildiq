## ADDED Requirements

### Requirement: Schedules section renders the app's schedules as a list

The system SHALL render a **Schedules** section in the OpenBuild page designer
(`SchedulesSection.vue`, mounted in `PageDesignerHost.vue` beside the Theme,
Workflow-attachments and Document-attachments sections) that lists every entry
in the current `manifest.schedules[]` array. The section is a controlled
component: it reads its data from the `:manifest` prop and emits all changes
via `@update:manifest`; it never calls a save API of its own.

**ID:** REQ-OBSA-001

#### Scenario: Existing schedules render as a list

- **WHEN** the page designer opens an app whose `manifest.schedules` contains
  one or more entries
- **THEN** the Schedules section lists each entry with its id, cadence summary,
  action and enabled state
- **AND** an app whose `manifest.schedules` is absent or empty renders an empty
  Schedules section with an "add" affordance and no error

#### Scenario: Add opens the edit dialog

- **WHEN** the developer activates "Add schedule"
- **THEN** the standalone `ScheduleEditDialog` (an `NcModal` in
  `src/dialogs/`) opens with default values (enabled = true, no id yet, cadence
  and action unset)

### Requirement: Cadence preset writes interval or validated cron

The edit dialog SHALL present a **Cadence** `NcSelect` with presets Hourly /
Daily / Weekly / Monthly / Custom. Non-custom presets write an `interval` in
seconds (Hourly = 3600, Daily = 86400, Weekly = 604800, Monthly = 2592000);
"Custom" reveals a 5-field `cron` `NcTextField` with live validation. A saved
entry SHALL carry exactly one of `interval` or `cron`, never both.

**ID:** REQ-OBSA-002

#### Scenario: Selecting a non-custom preset writes interval

- **WHEN** the developer selects the "Daily" cadence preset and saves the
  entry
- **THEN** the entry written to `manifest.schedules` has `interval: 86400` and
  no `cron` key

#### Scenario: Custom writes a validated cron and clears interval

- **WHEN** the developer selects "Custom" and enters a valid 5-field cron
  (e.g. `0 2 * * *`) and saves
- **THEN** the entry has `cron: "0 2 * * *"` and no `interval` key

#### Scenario: Switching cadence enforces the one-of invariant

- **WHEN** the developer changes an entry that had a `cron` back to the
  "Weekly" preset
- **THEN** the written entry has `interval: 604800` and the previous `cron`
  key is removed — the manifest never carries both `interval` and `cron`

### Requirement: Action select and synchronization picker write the action arguments

The edit dialog SHALL present an **Action** `NcSelect` (`:input-label`) whose
first and currently only option "Run a synchronization" maps to
`action: "openconnector:synchronization"`, and — for that action — a
**synchronization picker** `NcSelect` that writes the chosen id to
`arguments.synchronizationId`. The action select SHALL remain extensible: new
action types add options without removing the sync action.

**ID:** REQ-OBSA-003

#### Scenario: Choosing the sync action and a synchronization writes both fields

- **WHEN** the developer selects "Run a synchronization" and picks a
  synchronization from the picker and saves
- **THEN** the entry has `action: "openconnector:synchronization"` and
  `arguments.synchronizationId` set to the picked id
  (e.g. `"00000000-0000-0000-0000-000000000000"`)

#### Scenario: Synchronization picker degrades to free text when the list can't load

- **WHEN** the synchronization list cannot be fetched (OpenConnector/OR absent,
  route 404, or network error)
- **THEN** the picker falls back to a plain text field for a raw
  `synchronizationId`
- **AND** any already-stored `arguments.synchronizationId` remains visible and
  is preserved on save

### Requirement: Enabled switch and stable id

The edit dialog SHALL present an **Enabled** `NcCheckboxRadioSwitch`
(`type="switch"`, default true) writing the entry's `enabled` boolean, and a
stable **id** (auto-derived kebab-case slug from a human label, or typed in an
id field) that is unique within `manifest.schedules[]`.

**ID:** REQ-OBSA-004

#### Scenario: Enabled defaults on and toggles off

- **WHEN** a new entry is created and left untouched
- **THEN** it is written with `enabled: true`
- **AND** toggling the switch off writes `enabled: false`

#### Scenario: id is a unique slug

- **WHEN** the developer names a new schedule "Nightly BRP sync"
- **THEN** the entry id is derived as a kebab-case slug (e.g. `nightly-brp-sync`)
- **AND** attempting to save a second entry with an id already used in
  `manifest.schedules[]` is blocked with a uniqueness message

### Requirement: Edit updates in place and remove deletes the entry

The Schedules section SHALL let the developer edit an existing entry (opening
the dialog pre-filled and writing changes back to the same array position,
preserving its `id`) and remove an entry (deleting it from
`manifest.schedules[]`). Both emit `@update:manifest`.

**ID:** REQ-OBSA-005

#### Scenario: Edit updates the same entry in place

- **WHEN** the developer opens an existing schedule, changes its cadence from
  Daily to Weekly, and saves
- **THEN** the same array entry is updated (`interval: 604800`) with its `id`
  unchanged and no duplicate entry is added

#### Scenario: Remove deletes the entry

- **WHEN** the developer removes a schedule entry
- **THEN** that entry is deleted from `manifest.schedules[]` and the section
  emits the updated manifest

### Requirement: Invalid entries are blocked with a message

The system SHALL run `services/manifestValidation/schedules.js` (wired into
`useManifestValidator`) and block an invalid entry with a clear message rather
than writing it. An entry is invalid when it has both or neither of
`interval`/`cron`, a malformed cron, an action not on the allow-list, a missing
`arguments.synchronizationId` for the sync action, or a duplicate/empty id.

**ID:** REQ-OBSA-006

#### Scenario: Both interval and cron is rejected

- **WHEN** an entry would carry both an `interval` and a `cron`
- **THEN** validation reports the one-of violation and the entry cannot be
  saved

#### Scenario: Neither interval nor cron is rejected

- **WHEN** an entry has neither `interval` nor `cron`
- **THEN** validation reports that a cadence is required and the entry cannot
  be saved

#### Scenario: Malformed cron is rejected

- **WHEN** the developer enters a cron that is not a well-formed 5-field
  expression (e.g. `0 2 * *`)
- **THEN** validation reports the cron shape error and the entry cannot be
  saved

#### Scenario: Missing synchronization for the sync action is rejected

- **WHEN** the action is `openconnector:synchronization` and
  `arguments.synchronizationId` is empty
- **THEN** validation reports the missing synchronization and the entry cannot
  be saved

### Requirement: Edits persist via the existing ApplicationVersion save

The system SHALL persist schedule edits through the page designer's existing
save path — `PageDesignerHost.save()` PUTs the whole ApplicationVersion
manifest, and `schedules[]` rides along as a top-level manifest key. This
change SHALL NOT add a new endpoint, store, or save method for schedules.

**ID:** REQ-OBSA-007

#### Scenario: Schedules survive the manifest round-trip

- **WHEN** the developer adds a schedule and triggers the page designer save
- **THEN** the ApplicationVersion PUT payload's `manifest.schedules` contains
  the new entry
- **AND** every other top-level manifest key (pages, theme, workflows,
  documents) is unchanged by the schedules edit
