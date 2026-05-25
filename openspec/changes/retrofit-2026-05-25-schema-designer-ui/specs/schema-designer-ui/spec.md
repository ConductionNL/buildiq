---
retrofit: true
---

# schema-designer-ui Specification

## Purpose

The Schema Designer is OpenBuilt's visual editor for authoring an
Application version's OpenRegister schemas: their header (slug, title,
description, version), property fields, lifecycle state machine,
relations, widgets, and derived-value blocks (aggregations,
calculations, notifications). The `SchemaDesigner` view orchestrates
list/detail navigation, stages edits in memory, validates them, and
persists via OpenRegister's runtime schema CRUD. Sub-editors emit
their slices upward; modal dialogs gate create/delete actions; the
schemas store resolves the per-app register.

This capability is observed behaviour of the `SchemaDesigner` view,
the `src/components/schema-editor/*` components, the schema modal
dialogs, and the `schemas` store.

## Requirements

### REQ-OBSDUI-001: Schema list panel and create/delete gating

The `SchemaListPanel` SHALL render the version's schemas with a
property count and lifecycle label per row, emit an open event when a
row is activated, and route add/delete intents through confirmation.
`AddSchemaDialog` SHALL validate the new schema's slug/title before
confirming; `DeleteSchemaDialog` SHALL require an explicit confirmation
gate before allowing delete; `DeleteFieldDialog` SHALL confirm
field-level removal. The `schemas` store SHALL resolve the register
slug for a given application (`registerSlugForApp`).

#### Scenario: Open a schema

- **WHEN** the user activates a schema row
- **THEN** the panel emits an open event carrying the schema identity
- **AND** the designer loads that schema's detail

#### Scenario: Guarded delete

- **WHEN** the user requests schema or field deletion
- **THEN** a confirmation dialog gates the destructive action until
  confirmed

### REQ-OBSDUI-002: Field editor authors typed properties

`FieldEditor` SHALL let the user add, remove, reorder (move up/down),
and edit schema fields, exposing type and cardinality option lists,
coercing numeric inputs (`toIntOrNull`, `toNumberOrNull`), validating
field-name uniqueness/presence (`nameError`), and emitting the updated
field array upward. Removal SHALL be guarded by a request/confirm/cancel
flow.

#### Scenario: Add and edit a field

- **WHEN** the user adds a field and sets its type and validation
- **THEN** the editor emits the updated fields array
- **AND** numeric validation inputs are coerced to numbers or null

#### Scenario: Reorder fields

- **WHEN** the user moves a field up or down
- **THEN** the field order in the emitted array changes accordingly

### REQ-OBSDUI-003: Lifecycle editor authors the state machine

`LifecycleEditor` SHALL let the user add/remove/edit states,
transitions, and per-transition actions, expose action/state option
lists, validate state-name correctness (`stateNameValid`), track the
number of initial states (`initialCount`), enforce exactly one initial
state via `setInitial`, and emit updated states and transitions upward.

#### Scenario: Define a state machine

- **WHEN** the user adds states, marks one initial, and adds
  transitions between them
- **THEN** the editor emits the updated states and transitions
- **AND** marking a new initial state clears the previous one

### REQ-OBSDUI-004: Relations, widgets, and derived-value editors

`RelationEditor` SHALL author schema relations (add/remove/update with
cardinality and target-schema option lists, emitting the relations
array). `WidgetEditor` SHALL author `x-openregister-widgets` rows
(add/remove/update, JSON-validating each widget's config and emitting
the widgets array). The `AggregationEditor`, `CalculationEditor`, and
`NotificationEditor` SHALL render a formatted, human-readable summary
of their respective derived-value blocks.

#### Scenario: Author a relation

- **WHEN** the user adds a relation with a target schema and cardinality
- **THEN** the editor emits the updated relations array

#### Scenario: Invalid widget config blocks

- **WHEN** a widget's config JSON is malformed
- **THEN** the widget row surfaces a config error

### REQ-OBSDUI-005: Designer stages, validates, and persists schema edits

The `SchemaDesigner` view SHALL resolve the active ApplicationVersion,
load the schema list and a selected schema's detail, stage edits in
memory, and compose a canonical schema body from the staged slices
(`composeSchemaBody`, `bodyToStaged`). Save SHALL be gated
(`canSave`) by: dirty-state (`hasStagedChanges`), field-name uniqueness
(`fieldNamesUnique`), exactly one initial lifecycle state
(`hasInitialLifecycleState`), and no widget config errors. Save SHALL
persist via the schemas store / OR runtime CRUD; discard SHALL revert
staged edits; add/delete SHALL update the list. Each sub-editor change
handler (`onFieldsChange`, `onStatesChange`, `onTransitionsChange`,
`onRelationsChange`, `onWidgetsChange`, `onHeaderChange`) SHALL update
the staged body.

#### Scenario: Save a valid edit

- **WHEN** the staged schema is dirty and passes all validation gates
- **THEN** Save is enabled and persists the composed body via the store

#### Scenario: Validation blocks save

- **WHEN** field names collide or no initial lifecycle state is set
- **THEN** `canSave` is false and Save is disabled
