# form-editor-logic Specification

## Purpose
TBD - created by archiving change form-editor-logic. Update Purpose after archive.
## Requirements

### Requirement: Steps manager authors multi-step wizards over the existing field list (REQ-OBFEL-001)

The form page sub-editor (`FormPageEditor.vue`) SHALL present a **Steps**
section (`FormStepsManager.vue`) that authors `config.steps[]` per the
`manifest-form-logic` leaf contract: an ordered array of steps, each with
a required `title`, an optional `description`, a stable unique `id`
(auto-derived kebab-case slug from the title, editable), and an ordered
`fields[]` array of field-key references into `config.fields[].key`. The
manager SHALL support add / reorder (up-down) / delete of steps and
assignment of field keys to steps from an explicit unassigned-fields
pool. Field definitions themselves stay owned by `FormFieldBuilder`; the
steps manager only moves key references. An absent or empty `steps[]`
SHALL render as the single-step state (today's flat form) — backward
compatible — and deleting the last step SHALL remove the `steps` key
entirely rather than writing `steps: []`.

**ID:** REQ-OBFEL-001

#### Scenario: Adding a step groups fields by reference

- **WHEN** the developer adds a step, titles it "Contact", and assigns
  the existing fields `wantsContact` and `email` to it
- **THEN** `config.steps` gains one entry with a kebab-case `id`
  (e.g. `contact`), `title: "Contact"`, and `fields:
  ["wantsContact", "email"]`
- **AND** `config.fields[]` itself is unchanged (no field objects are
  moved or duplicated)

#### Scenario: Reordering steps reorders the wizard

- **WHEN** the developer moves the second of three steps up
- **THEN** `config.steps` is rewritten with that step first-swapped into
  position one and all step contents unchanged

#### Scenario: Deleting a step returns its fields to the unassigned pool

- **WHEN** the developer deletes a step whose `fields` lists `email`
- **THEN** the step entry is removed from `config.steps`
- **AND** `email` reappears in the unassigned-fields pool with its field
  definition in `config.fields[]` intact
- **AND** deleting the last remaining step removes the `steps` key from
  `config` entirely
- **AND** saving while `steps` is non-empty and `email` is still
  unassigned auto-assigns `email` to the final step with a warning mark,
  so the written manifest satisfies the leaf validator's
  complete-partition rule (every declared field in exactly one step)

#### Scenario: Absent steps renders the single-step state

- **WHEN** the developer opens a form page whose `config` has no `steps`
  key
- **THEN** the Steps section shows the single-step state (all fields,
  an "add step" affordance, no error)
- **AND** the config round-trips without a `steps` key until a step is
  added

### Requirement: Conditions builder authors per-field visibleWhen from the form's own fields (REQ-OBFEL-002)

Each field row in `FormFieldBuilder.vue` SHALL expose a **Conditions**
section (`VisibleWhenBuilder.vue`) that authors the field's `visibleWhen`
in the existing `$defs/visibleWhen` LOCAL shape — `{ field, op?, value }`
— with a **field picker** sourced from the form's own schema fields (the
sibling `config.fields[].key` values, excluding the field being edited),
an **op picker** over the schema enum `eq | neq | gt | gte | lt | lte`,
and a **value** input whose `true` / `false` / numeric literals are
coerced to boolean / number on write. When `op` is the default `eq` it
SHALL be omitted from the written object. Clearing the builder SHALL
delete the `visibleWhen` key. A `visibleWhen` carrying the advanced
`endpoint` or `source` modes SHALL render as a read-only summary
("Advanced condition — edit in Raw JSON") and SHALL never be rewritten
by the builder.

**ID:** REQ-OBFEL-002

#### Scenario: Authoring a condition with the field, op and value pickers

- **WHEN** the developer opens the Conditions section on the `email`
  field, picks the sibling field `wantsContact`, keeps op `eq`, and
  enters the value `true`
- **THEN** the `email` field is written with
  `visibleWhen: { "field": "wantsContact", "value": true }` (boolean
  `true`, `op` omitted because it is the default `eq`)

#### Scenario: Ordering op coerces the value to a number

- **WHEN** the developer authors a condition with op `gt` and value `3`
- **THEN** the written predicate is
  `visibleWhen: { "field": "<picked>", "op": "gt", "value": 3 }` with a
  numeric `3`, not the string `"3"`

#### Scenario: Clearing the condition removes the key

- **WHEN** the developer clears an existing condition on a field
- **THEN** the field object no longer carries a `visibleWhen` key
- **AND** all other keys on that field are unchanged

#### Scenario: Advanced endpoint or source conditions pass through untouched

- **WHEN** a field's `visibleWhen` was authored in raw JSON with an
  `endpoint` or `source` key
- **THEN** the Conditions section shows a read-only advanced-condition
  summary instead of the pickers
- **AND** editing any other property of that field leaves the advanced
  `visibleWhen` object byte-for-byte identical

### Requirement: Validation builder authors the structured validation object (REQ-OBFEL-003)

Each field row in `FormFieldBuilder.vue` SHALL expose a **Validation**
section (`FieldValidationBuilder.vue`) that authors the field's
`validation` object per the leaf contract —
`{ required?, min?, max?, pattern?, message? }` (`required` boolean;
`min` / `max` numbers acting as value bounds for number fields and
length bounds for string-ish fields; `pattern` a regex string;
`message` a custom i18n message key applied to any failed rule). The
section SHALL prefill from an existing `validation` object, falling back
to the legacy flat `required` / `pattern` field keys, and on its first
write for a field SHALL normalise: values land in `validation` and the
migrated flat `required` / `pattern` keys are removed from that field
only. Fields whose validation section is never edited SHALL keep their
flat keys unchanged. A `pattern` that does not compile via `new RegExp()`
SHALL be marked invalid inline and not written.

**ID:** REQ-OBFEL-003

#### Scenario: Authoring validation writes the structured object

- **WHEN** the developer sets required on, min `5`, max `254`, pattern
  `^[^@]+@[^@]+$`, and message `i18n.email-invalid` on the `email` field
- **THEN** the field is written with `validation: { "required": true,
  "min": 5, "max": 254, "pattern": "^[^@]+@[^@]+$", "message":
  "i18n.email-invalid" }`

#### Scenario: Legacy flat keys prefill and normalise on first edit

- **WHEN** the developer opens the Validation section on a field that
  carries the legacy flat `required: true` and `pattern: "^\\d+$"` and
  saves any validation edit
- **THEN** the section opened prefilled with required on and that pattern
- **AND** the written field carries them inside `validation` and no
  longer carries the flat `required` / `pattern` keys
- **AND** a different field with flat keys that was never edited keeps
  its flat keys byte-for-byte

#### Scenario: A non-compiling pattern is rejected inline

- **WHEN** the developer enters the pattern `[a-` (an unterminated
  character class)
- **THEN** the pattern input is marked invalid with an inline message
- **AND** the invalid pattern is not written to the manifest

### Requirement: Dangling condition and step references warn live and are never auto-deleted (REQ-OBFEL-004)

The editor SHALL detect, on every render against the current
`config.fields[]`, any `visibleWhen.field` (LOCAL mode) or step
`fields[]` entry that references a field key no longer present, and
SHALL paint an immediate inline warning (the `InlineFieldMark` /
ADR-024 affordance, `role="alert"`) next to the stale reference — e.g.
"Condition references removed field 'x'". Removing a field SHALL NOT
cascade-delete conditions or step references that point at it; the stale
reference stays in the manifest (raw JSON preserved) until the developer
resolves it.

**ID:** REQ-OBFEL-004

#### Scenario: Deleting a field warns on the condition that references it

- **WHEN** the `email` field's condition references `wantsContact` and
  the developer removes the `wantsContact` field
- **THEN** a warning appears immediately on the `email` field's
  Conditions section naming the missing key
- **AND** the `email` field's `visibleWhen` object is still present and
  unchanged in the manifest

#### Scenario: A step referencing a removed field warns

- **WHEN** a step's `fields` lists `email` and the developer removes the
  `email` field definition
- **THEN** a warning appears immediately on that step naming the missing
  key
- **AND** the step's `fields` array still contains `"email"` in the
  manifest

### Requirement: The formLogic manifest validator reports structural and semantic errors (REQ-OBFEL-005)

The system SHALL provide `src/services/manifestValidation/formLogic.js`
exporting `validateFormLogic(manifest)`, wired into
`src/composables/useManifestValidator.js` beside the existing validators,
returning JSON-Pointer-addressed errors under `/pages/<n>/config/...` so
`FormPageEditor`'s inline marks (its `validatedConfigKeys` gains
`steps`) and the right-pane list agree. It SHALL report: a step without
a non-empty `title` or with a non-array `fields`; duplicate step `id`s;
a field key assigned to more than one step; a step `fields[]` entry not
found in `config.fields[].key`; a LOCAL `visibleWhen` whose `field` names
no existing sibling key or whose `op` is off the
`eq|neq|gt|gte|lt|lte` allow-list; a `validation` with non-boolean
`required`, non-numeric `min` / `max`, `min` greater than `max`, or a
`pattern` that fails `new RegExp()`; and a warning-level entry for a
field carrying both a `validation` object and legacy flat
`required` / `pattern` keys.

@e2e exclude pure validation service — every rule is exercised by Vitest service specs (`tests/services/formLogicValidation.spec.js`) mirroring the sibling `schedulesValidation` specs; the user-visible inline-mark surface these errors feed is Playwright-covered by REQ-OBFEL-004's dangling-reference scenarios, leaving no independent e2e-testable URL surface for the service itself

**ID:** REQ-OBFEL-005

#### Scenario: Dangling and duplicate step references are errors

- **WHEN** `validateFormLogic` runs on a form page whose step lists a
  key absent from `config.fields[].key` and whose two steps both list
  the same key
- **THEN** it returns one error for the unknown reference and one for
  the duplicate assignment, each with a JSON-Pointer under
  `/pages/<n>/config/steps`

#### Scenario: Disallowed op and dangling condition field are errors

- **WHEN** a field's `visibleWhen` is `{ "field": "ghost", "op":
  "contains", "value": "x" }` and no field is keyed `ghost`
- **THEN** validation reports the unknown field reference and the
  off-allow-list op as two errors under that field's pointer

#### Scenario: Incoherent validation objects are errors

- **WHEN** a field's `validation` has `min: 10, max: 3` and another
  field's `validation.pattern` is `[a-`
- **THEN** validation reports the min-greater-than-max error and the
  non-compiling pattern error

#### Scenario: A clean manifest passes

- **WHEN** `validateFormLogic` runs on a form page with well-formed
  steps, conditions and validation objects (and on a manifest with no
  form pages at all)
- **THEN** it returns no errors

### Requirement: Raw JSON round-trip and existing persistence are preserved (REQ-OBFEL-006)

The form sub-editor SHALL preserve externally authored manifest content
across every interaction: unknown keys on the page config, on field
objects, and on step objects survive edits (the existing spread-write
contract of `FormPageEditor.update()` and
`FormFieldBuilder.updateField()` extends to the new sections), and the
Design ↔ Raw JSON tab round-trip loses nothing. Persistence SHALL ride
the existing page-designer save path — `PageDesignerHost.save()`'s
ApplicationVersion PUT — with no new endpoint, store, or save method,
and a save after authoring logic SHALL leave every other top-level
manifest key unchanged.

**ID:** REQ-OBFEL-006

#### Scenario: Raw-JSON-authored logic survives unrelated editor edits

- **WHEN** `steps[]`, an advanced `visibleWhen`, a `validation` object,
  and an unknown custom key were authored via the Raw JSON tab and the
  developer then edits the page's submit label in the Design tab
- **THEN** switching back to Raw JSON shows all four byte-for-byte
  unchanged apart from the edited submit label

#### Scenario: Authored logic persists via the existing ApplicationVersion save

- **WHEN** the developer authors a step and a condition and triggers the
  page designer save
- **THEN** the ApplicationVersion PUT payload's form page config carries
  the new `steps` and `visibleWhen` shapes
- **AND** every other top-level manifest key (pages, theme, workflows,
  documents, schedules) is unchanged by the edit
