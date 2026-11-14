## ADDED Requirements

### Requirement: Author v2 widget placements from the page designer

The page designer SHALL let an author create, edit and delete entries in the selected
page's `widgets[]` array, the uniform v2 `widgetEntry` shape, without editing raw JSON.

For the selected page the designer SHALL show every placement in `widgets[]` grouped by
its `slot`, and SHALL offer, per placement, an edit action and a delete action, and per
slot an add action. A page whose `widgets[]` is empty or absent SHALL show an empty state
that invites the author to add a placement, not an error.

Creating a placement SHALL produce an entry carrying all six required fields
(`widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth`, `gridHeight`), so a newly added
placement is valid against the canonical manifest schema the moment it is written.

Deleting a placement SHALL remove exactly that entry and SHALL leave every other entry in
`widgets[]` unchanged.

#### Scenario: Adding a placement to an empty page

@e2e tests/e2e/widget-placement-editor.spec.ts::adds a v2 placement to an empty page

- **WHEN** an author opens a page with no `widgets[]` and adds a widget through the
  placement editor
- **THEN** the page's `widgets[]` contains one entry
- **AND** that entry carries `widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth` and
  `gridHeight`
- **AND** saving the page stores it

#### Scenario: Editing an existing placement

@e2e tests/e2e/widget-placement-editor.spec.ts::edits an existing v2 placement

- **WHEN** an author edits a placement produced earlier by a template, a saved block or the
  copilot
- **THEN** the editor opens pre-filled with that placement's current type and settings
- **AND** confirming the edit updates that entry in place
- **AND** the placement keeps the position it had in `widgets[]`

#### Scenario: Deleting a placement leaves its neighbours alone

@e2e tests/e2e/widget-placement-editor.spec.ts::deletes one placement and leaves the rest

- **WHEN** an author deletes one placement from a page carrying several
- **THEN** only that entry is removed from `widgets[]`
- **AND** every remaining entry keeps its own geometry and settings

### Requirement: Drag and resize authoring for the body slot

For the `body` slot the designer SHALL offer direct manipulation: an author drags a
placement to move it and drags its edge to resize it, and the resulting geometry is written
back to `gridX`, `gridY`, `gridWidth` and `gridHeight` on that entry.

A geometry change made this way SHALL update the working manifest immediately and SHALL
mark the designer dirty, exactly as any other page edit does. It SHALL NOT save by itself.

#### Scenario: Dragging a widget updates its grid position

@e2e exclude drag geometry is a component contract, asserted by a vitest spec that feeds the grid's layout-change payload into the editor and diffs the resulting widgets array, because pointer-accurate GridStack drags are not reliably reproducible in Playwright

- **WHEN** an author drags a body-slot placement to a new column and row
- **THEN** that entry's `gridX` and `gridY` match the new position
- **AND** the designer reports unsaved changes

#### Scenario: Resizing a widget updates its span

@e2e exclude drag geometry is a component contract, asserted by the same vitest spec as the drag scenario, for the same reason

- **WHEN** an author resizes a body-slot placement
- **THEN** that entry's `gridWidth` and `gridHeight` match the new span

### Requirement: Placements outside the body slot are authored through position fields

The designer SHALL offer an authoring path for placements in the `sidebar`,
`header-actions`, `footer`, `modal`, `tab:<id>` and `section:<id>` slots that does not
depend on dragging, because direct manipulation is available for the `body` slot only.

That path SHALL expose the placement's slot and its position and span as explicit numeric
fields, and SHALL write the same `widgetEntry` shape the body slot produces. Changing a
placement's slot SHALL be possible from this path, and SHALL re-apply the target slot's
geometry rules to the placement before it is stored.

#### Scenario: Positioning a sidebar placement without dragging

@e2e tests/e2e/widget-placement-editor.spec.ts::positions a sidebar placement through numeric fields

- **WHEN** an author adds a placement to the `sidebar` slot and sets its row and height
- **THEN** the stored entry carries `slot: "sidebar"` with the values entered
- **AND** no drag interaction was required

#### Scenario: Moving a placement to another slot re-applies that slot's rules

@e2e tests/e2e/widget-placement-editor.spec.ts::moves a placement to another slot

- **WHEN** an author moves a body-slot placement into the `header-actions` slot
- **THEN** the stored entry carries `slot: "header-actions"`
- **AND** its geometry satisfies that slot's rules before the page is saved

### Requirement: The editor cannot produce a placement that fails manifest validation

The designer SHALL enforce, while the author is editing, the placement rules that the
manifest schema cannot express as schema and that are otherwise only discovered when a save
is rejected.

The system SHALL keep `gridX + gridWidth` within the slot's resolved column count, which is
the page's `config.slotColumns` value for that slot when one is declared and the slot's
default otherwise, so the post-schema check on the placement can never fail.

The system SHALL keep `gridY` at 0 for every placement in the `header-actions` slot, and
SHALL keep `gridWidth` at 1 for every placement in the `sidebar` slot.

Where a value would break one of these rules, the editor SHALL constrain the value rather
than store it and report a failure later.

One page-level rule cannot be enforced by constraining a value, because it is a property of
the page rather than of the placement: a page of type `dashboard` carrying exactly one
custom widget that fills the body grid is rejected as a custom page in disguise. The
editor SHALL surface that state while the author is in it, and SHALL name the two
documented ways out, declaring the page as `custom` or adding a second widget, rather than
leaving the author to meet the rule as a rejected save.

#### Scenario: A placement cannot be pushed past the slot's last column

@e2e exclude geometry clamping is a pure function, asserted by a vitest spec over the slot-geometry helper across the default column count and a widened config.slotColumns, which covers more cases than a browser run can

- **WHEN** an author moves or widens a placement so that `gridX + gridWidth` would exceed
  the slot's resolved column count
- **THEN** the stored geometry stays within that count
- **AND** the manifest validates

#### Scenario: A widened slot allows a wider placement

@e2e exclude same pure-function vitest spec as above, parameterised on a page declaring config.slotColumns

- **WHEN** a page declares a larger `slotColumns` value for a slot
- **THEN** the editor allows a placement in that slot to span up to the declared count

#### Scenario: Header actions stay on the first row

@e2e tests/e2e/widget-placement-editor.spec.ts::keeps a header-actions placement on row zero

- **WHEN** an author places a widget in the `header-actions` slot
- **THEN** the stored entry has `gridY` equal to 0
- **AND** the editor does not offer a row control for that slot

#### Scenario: A lone full-width custom widget on a dashboard page is flagged where it happens

@e2e tests/e2e/widget-placement-editor.spec.ts::flags a lone full-width custom widget on a dashboard page

- **WHEN** an author adds a single custom widget filling the body grid to a page of type
  `dashboard` and adds nothing else
- **THEN** the editor says the page is a custom page in disguise
- **AND** it names both ways out, declaring the page as `custom` or adding a second widget

### Requirement: A custom page collects the placement note the ratchet requires

When the selected page has `type: "custom"`, the designer SHALL require a note on every
placement it writes, and SHALL store it as the placement's `_note`. The note documents why
a standard page type was not feasible.

The editor SHALL block the placement from being written while the note is empty, and SHALL
say what the note is for, so the author learns the reason at the point of writing rather
than from a rejected save.

On a page of any other type the note SHALL be optional, and an existing `_note` SHALL be
preserved.

#### Scenario: Adding a placement to a custom page requires a note

@e2e tests/e2e/widget-placement-editor.spec.ts::requires a note on a custom page placement

- **WHEN** an author adds a placement to a page of type `custom` and leaves the note empty
- **THEN** the placement cannot be confirmed
- **AND** the editor explains that a custom page must document why a standard page type was
  not feasible

#### Scenario: A non-custom page does not demand a note

@e2e tests/e2e/widget-placement-editor.spec.ts::adds a placement to a dashboard page without a note

- **WHEN** an author adds a placement to a page of type `dashboard`
- **THEN** the placement can be confirmed with no note
- **AND** no `_note` key is written onto the entry

### Requirement: Placement ids are minted once and unique within a page

Every placement the designer creates SHALL be given a kebab-case `id` matching the
manifest's id pattern, unique within that page's `widgets[]`.

The id SHALL be minted by the same generator the block insert path already uses, so one
page cannot end up with two id conventions. An `id` already present on a placement SHALL
NOT be regenerated by an edit, because a stored delta override keys `widgets[]` by id and a
changed id silently stops matching.

#### Scenario: Two placements of the same widget type do not collide

@e2e tests/e2e/widget-placement-editor.spec.ts::mints unique ids for two placements of one type

- **WHEN** an author adds the same widget type to a page twice
- **THEN** both entries carry an `id`
- **AND** the two ids differ

#### Scenario: Editing a placement keeps its id

@e2e tests/e2e/widget-placement-editor.spec.ts::keeps the id when a placement is edited

- **WHEN** an author edits a placement that already carries an `id`
- **THEN** the stored entry carries the same `id` as before

### Requirement: Saving a page preserves keys the placement editor does not surface

Saving a page edited through the placement editor SHALL preserve every key on every
placement and everywhere else in the page that the editor does not itself surface, so a
manifest written by a template, the copilot or a later library version round-trips
unchanged through an unrelated edit.

The editor SHALL NOT rebuild a placement from a fixed list of known keys, and SHALL NOT
write an empty value in place of a key that was absent.

#### Scenario: An unknown placement key survives an unrelated edit

@e2e exclude lossless round-trip is asserted by the existing manifest round-trip vitest suite, extended with a placement carrying keys the editor does not surface, which validates against the installed schema rather than a browser rendering

- **WHEN** a placement carries keys the editor does not surface and an author changes that
  placement's position
- **THEN** the saved entry still carries those keys with their original values

#### Scenario: Opening and saving a page changes nothing by itself

@e2e tests/e2e/widget-placement-editor.spec.ts::opens and saves a page without changing the manifest

- **WHEN** an author opens a page in the placement editor and saves it without editing
  anything
- **THEN** the saved manifest is byte-for-byte the manifest that was loaded

### Requirement: The type picker offers the authoring catalogue for the page's surface

The designer SHALL offer the full catalogue of widget types an administrator may place for
everybody, not the narrower set a user may add to their own dashboard. The designer is an
authoring surface: the author has the register, the schema and the data source in front of
them, which is exactly what the narrower set exists to avoid asking a user for.

The catalogue SHALL be filtered by the surface the selected page represents, so a type
declared for detail pages only is not offered on a dashboard page and the reverse.

#### Scenario: A detail-only type is not offered on a dashboard page

@e2e tests/e2e/widget-placement-editor.spec.ts::hides detail-only widget types on a dashboard page

- **WHEN** an author opens the type picker on a page of type `dashboard`
- **THEN** a type declared for detail pages only is absent from the list

#### Scenario: An administrator sees types a user picking for themselves would not

@e2e tests/e2e/widget-placement-editor.spec.ts::offers the full authoring catalogue

- **WHEN** an author opens the type picker in the page designer
- **THEN** the list includes types that require configuration only an administrator can
  supply
