# builder-undo-redo Specification

## Purpose
TBD - created by archiving change builder-undo-redo. Update Purpose after archive.
## Requirements
### Requirement: Page-designer session undo/redo runs on the shared manifestEditHistory engine

The page designer (`src/views/PageDesigner.vue`) SHALL provide
editor-level undo/redo over the in-flight (unsaved) draft manifest,
powered by the `manifestEditHistory` utility consumed from
`@conduction/nextcloud-vue` (nc-vue change `manifest-edit-history`) —
replacing the app-local `src/composables/useManifestHistory.js`. The
engine SHALL record each **accepted draft state** (a manifest emitted
through the controlled component's `update:manifest` contract);
structurally-identical re-emissions SHALL NOT create entries. Undo
SHALL restore the previous accepted state and redo SHALL re-apply an
undone state, in both cases re-emitting through `update:manifest` so
the host's draft stays the single source of truth. A new edit after an
undo SHALL truncate the redo tail (classic editor semantics). Undo and
redo SHALL be pure client-side operations: they SHALL NOT issue any
network request (no PUT/PATCH/POST) — persistence remains exclusively
the explicit save action.

**ID:** REQ-BUR-001

#### Scenario: Undo restores the previous draft state

- **WHEN** the user adds a page in the page designer
- **AND** activates undo
- **THEN** the draft manifest SHALL return to its state before the page
  was added (the page list no longer shows the added page)
- **AND** no save/network write SHALL have been issued

#### Scenario: Redo re-applies an undone edit

- **WHEN** the user has undone the page-add edit
- **AND** activates redo
- **THEN** the added page SHALL reappear in the draft exactly as before
  the undo

#### Scenario: A new edit after undo truncates the redo tail

- **WHEN** the user undoes an edit and then makes a different new edit
- **THEN** redo SHALL no longer be available (the undone branch is
  discarded)

### Requirement: Undo/redo toolbar buttons reflect stack state via disabled states

The page designer toolbar SHALL render Undo and Redo buttons whose
`disabled` state tracks the history: Undo SHALL be disabled when no
earlier state exists (fresh session, or fully unwound) and Redo SHALL
be disabled when no undone state exists (fresh session, or after a new
edit truncated the redo tail). The buttons SHALL carry tooltips naming
their keyboard shortcuts.

**ID:** REQ-BUR-002

#### Scenario: Both buttons disabled in a fresh session

- **WHEN** the page designer opens on a loaded app with no edits made
- **THEN** the Undo button SHALL be disabled
- **AND** the Redo button SHALL be disabled

#### Scenario: Buttons enable and disable as the stack moves

- **WHEN** the user makes one edit
- **THEN** Undo SHALL become enabled while Redo stays disabled
- **AND WHEN** the user activates undo
- **THEN** Undo SHALL become disabled and Redo SHALL become enabled

### Requirement: Keyboard shortcuts with an editable-target guard

The designers SHALL bind `Ctrl+Z` to undo and both `Ctrl+Shift+Z` and
`Ctrl+Y` to redo, treating `metaKey` (`Cmd`) as equivalent to `ctrlKey`
for macOS. The handler SHALL ignore these chords when the event target
(or active element) is an editable control — `<input>`, `<textarea>`,
`<select>`, or a `contenteditable` element — so the browser's native
text-field undo wins while typing; draft-level undo/redo SHALL apply
only when focus is outside editable fields. The handler SHALL call
`preventDefault()` only when it actually consumes the chord.

@e2e exclude mixed spec — the Ctrl-chord and editable-guard scenarios are covered by the builder-undo-redo Playwright spec; the Cmd (`metaKey`) equivalence cannot be exercised faithfully on the Linux Chromium CI runner and is verified by Vitest unit tests dispatching synthetic `metaKey` keydown events at the handler

**ID:** REQ-BUR-003

#### Scenario: Ctrl+Z / Ctrl+Shift+Z drive undo and redo outside fields

- **WHEN** focus is outside any editable control after an edit
- **AND** the user presses `Ctrl+Z`
- **THEN** the last edit SHALL be undone
- **AND WHEN** the user presses `Ctrl+Shift+Z` (or `Ctrl+Y`)
- **THEN** the edit SHALL be re-applied

#### Scenario: Ctrl+Z inside a text field leaves draft history untouched

- **WHEN** the user is typing in a sub-editor text input
- **AND** presses `Ctrl+Z`
- **THEN** the draft-level history SHALL NOT move (any earlier
  draft-level edit stays applied)
- **AND** the text field's native undo SHALL behave as the browser
  default

#### Scenario: Cmd equivalents work on macOS

- **WHEN** the platform chord uses `Cmd` instead of `Ctrl`
  (`metaKey` set, `ctrlKey` unset)
- **THEN** `Cmd+Z` SHALL undo and `Cmd+Shift+Z` SHALL redo, identically
  to the Ctrl chords

### Requirement: History is per-editing-session — survives sub-editor switches, resets on save, publish, and version switch

The undo/redo history SHALL be scoped to one editing session. Within a
session, switching the selected page — and therefore the per-page-type
sub-editor dispatched via `SUB_EDITOR_MAP` — or moving focus between
panes SHALL NOT clear or alter the history. The history SHALL reset
(re-seeded with the then-current draft as the new baseline, with both
Undo and Redo disabled) when: a save completes successfully; the
`?_version=` version switches; the app slug changes; or the designer is
re-entered after a publish or version rollback (which arrive through
those same boundaries). The host view SHALL own these boundaries and
signal them to the designer, so a version switch can never leave
another version's manifest reachable via undo (fixing the cross-version
undo-bleed present at HEAD, where `reset()` had no callers).

**ID:** REQ-BUR-004

#### Scenario: History survives a sub-editor switch

- **WHEN** the user edits page A, then selects page B (mounting a
  different sub-editor), then activates undo
- **THEN** the edit to page A SHALL be undone
- **AND** the history SHALL NOT have been cleared by the page switch

#### Scenario: Save resets the session history

- **WHEN** the user makes edits and saves successfully
- **THEN** Undo and Redo SHALL both be disabled
- **AND** activating the undo chord SHALL NOT change the draft (the
  saved state is the new baseline; earlier states are reachable only
  via version history)

#### Scenario: Version switch resets the session history

- **WHEN** the user edits version X's draft and then switches to
  version Y via `?_version=`
- **THEN** the history SHALL be reset to version Y's manifest as the
  baseline
- **AND** undo SHALL NOT restore any state of version X's manifest into
  version Y's draft

### Requirement: Schema designer gains staged-model undo/redo with an undoable discard

The schema designer (`src/views/SchemaDesigner.vue`) SHALL provide the
same undo/redo affordance over its staged editor model: toolbar Undo /
Redo buttons (with REQ-BUR-002 disabled-state semantics) rendered
alongside "Discard staged edits" and "Save", the REQ-BUR-003 keyboard
chords and editable-target guard, and per-session scoping per
REQ-BUR-004 with the schema designer's own boundaries — successful save
through the schemas store, a `schemaId` route change, and an
app/version switch each reset the history. Every staged-model mutation
(header, fields, lifecycle states, transitions, relations, widgets)
SHALL be one history entry. "Discard staged edits" SHALL itself be
recorded as one history entry, so a discard can be undone to recover
the discarded staged edits.

**ID:** REQ-BUR-005

#### Scenario: Undo restores a staged field edit

- **WHEN** the user adds a field to a schema in the schema designer
- **AND** activates undo
- **THEN** the staged model SHALL return to its state before the field
  was added
- **AND** no save SHALL have been issued

#### Scenario: Discard staged edits is one undoable entry

- **WHEN** the user has staged edits and clicks "Discard staged edits"
- **THEN** the staged model SHALL revert to the persisted schema
- **AND WHEN** the user activates undo
- **THEN** the discarded staged edits SHALL be restored in full

#### Scenario: Schema save resets the schema session history

- **WHEN** the user saves the schema successfully
- **THEN** Undo and Redo SHALL both be disabled
- **AND** the saved state SHALL be the new history baseline

### Requirement: A raw whole-manifest replacement is exactly one history entry

The history engine SHALL treat any single commit that replaces the
whole draft manifest — the shape produced by a raw-JSON editing surface
(cf. `src/components/tabs/ApplicationManifestTab.vue`'s
parse-validate-apply flow) — as exactly **one** history entry: one undo
SHALL restore the complete pre-edit manifest, however many fields the
raw edit touched. Invalid JSON SHALL never reach the draft or the
history (raw surfaces validate before applying). Raw surfaces that save
directly (rather than editing the designer's draft) remain governed by
the REQ-BUR-004 save boundary.

@e2e exclude engine-seam contract — the raw-JSON tab at HEAD (`ApplicationManifestTab.vue`) is a direct-save sidebar surface on the quarantined VirtualAppDetail page (Conduction/openbuild#41) and does not share the designer's draft session; the one-commit-one-entry semantics are a history-engine contract verified by Vitest unit tests pushing whole-manifest replacements

**ID:** REQ-BUR-006

#### Scenario: One raw edit is one undo step

- **WHEN** a whole-manifest replacement changing multiple pages and
  menu entries is committed to the draft as a single accepted state
- **THEN** the history SHALL record exactly one new entry
- **AND** a single undo SHALL restore the complete pre-edit manifest

#### Scenario: Invalid raw JSON never pollutes history

- **WHEN** a raw-JSON surface rejects unparseable or invalid input
  before applying it
- **THEN** the history SHALL record no entry for the rejected input
- **AND** undo/redo availability SHALL be unchanged

### Requirement: History depth is bounded at 100 entries

The history SHALL be bounded to 100 entries per session. When a push
overflows the bound, the oldest entry SHALL be dropped (the reachable
undo range shortens from the far end); pushing SHALL never fail and
redo semantics SHALL be unaffected by trimming. The bound SHALL be
configured at the OpenBuild integration seam so both designers share
it.

@e2e exclude unit-scale contract — driving 101 distinct UI edits through Playwright proves nothing the engine unit tests do not; the bound, oldest-entry trimming, and never-fail push are verified by Vitest against the manifestEditHistory integration seam

**ID:** REQ-BUR-007

#### Scenario: Overflow drops the oldest entry

- **WHEN** 101 distinct draft states are pushed in one session
- **THEN** the history SHALL retain the most recent 100
- **AND** repeated undo SHALL stop at the oldest retained state without
  error

#### Scenario: Trimming never breaks redo

- **WHEN** the stack is at its bound and the user undoes several steps
  and redoes them
- **THEN** redo SHALL re-apply the undone states exactly as pushed

