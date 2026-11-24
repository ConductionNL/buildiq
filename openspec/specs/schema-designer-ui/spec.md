---
retrofit: true
---

# schema-designer-ui Specification

## Purpose

The Schema Designer is Buildiq's visual editor for authoring an
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

@e2e exclude retrofit component-contract spec — `AddSchemaDialog` slug/title validation, `DeleteSchemaDialog` confirmation gate, `DeleteFieldDialog` field-removal guard, and `registerSlugForApp` store resolution are component-internal contracts verified by Vitest unit tests; schema list navigation and open/delete flows are covered by the buildiq-schema-designer Playwright tests

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

@e2e exclude retrofit component-contract spec — `toIntOrNull`, `toNumberOrNull`, `nameError`, field-array emit, and remove-guard request/confirm/cancel flow are component-internal contracts verified by Vitest unit tests; field-editor interactions are covered by the buildiq-schema-designer Playwright tests

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

@e2e exclude retrofit component-contract spec — `stateNameValid`, `initialCount`, `setInitial`, state/transition emit contracts are component-internal contracts verified by Vitest unit tests; lifecycle editor rendering is covered by the buildiq-schema-designer Playwright tests

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

@e2e exclude retrofit component-contract spec — relation-array emit, widget JSON-config validation per-row, and `AggregationEditor`/`CalculationEditor`/`NotificationEditor` human-readable render are component-internal contracts verified by Vitest unit tests; relations/widgets rendering is covered by the buildiq-schema-designer Playwright tests

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

@e2e exclude retrofit component-contract spec — `composeSchemaBody`, `bodyToStaged`, `canSave` gate (`hasStagedChanges`, `fieldNamesUnique`, `hasInitialLifecycleState`), `onFieldsChange`/`onStatesChange`/`onTransitionsChange` change handlers, and discard/add/delete list mutations are staged-state contracts verified by Vitest unit tests; save/validation integration is covered by the buildiq-schema-designer Playwright tests

#### Scenario: Save a valid edit

- **WHEN** the staged schema is dirty and passes all validation gates
- **THEN** Save is enabled and persists the composed body via the store

#### Scenario: Validation blocks save

- **WHEN** field names collide or no initial lifecycle state is set
- **THEN** `canSave` is false and Save is disabled

### Requirement: A maker declares that a field takes its value from a registry (REQ-BQRF-001)

The field editor SHALL show a section "Value source" for fields of type
`string` and `object`, with the choices "Typed in" and "From a registry". It
MUST NOT add a registry-specific entry to the type picker. "From a registry"
SHALL show a provider picker filled from integriq's `GET /api/property-sources`
(the provider label, with the identifier it keys on as a secondary line) and a
mode choice "Look it up every time" (`live`) or "Use it as a starting value"
(`default`). Saving SHALL write `x-openregister-property-source` with
`provider`, `mode` and `config` on the property. Choosing "Typed in" SHALL
remove the key.

@e2e tests/e2e/schema-designer-registry-field.spec.ts

#### Scenario: A KvK-backed field is declared

- **GIVEN** integriq is installed and publishes the provider `kvk` labelled "KvK organisation", keyed on `kvkNummer`
- **WHEN** a maker edits the field `kvkNummer` of type `string`, chooses "From a registry", picks "KvK organisation" and "Look it up every time", and saves the schema
- **THEN** the property `kvkNummer` carries `x-openregister-property-source` with `provider` "kvk", `mode` "live" and `config` `{}`

#### Scenario: The section is not offered for a number

- **WHEN** a maker edits a field of type `integer`
- **THEN** no "Value source" section is shown

#### Scenario: Going back to typed in removes the declaration

- **GIVEN** a field with a `kvk` declaration
- **WHEN** the maker chooses "Typed in" and saves
- **THEN** the property no longer carries `x-openregister-property-source`

### Requirement: An existing declaration survives a session without integriq (REQ-BQRF-002)

When integriq is not installed or `GET /api/property-sources` fails, the
section SHALL show the note "Registry lookups need the integriq app." For a
field that already carries a declaration, the section SHALL show its provider
and mode as read-only text, and a save MUST write the declaration back
unchanged, including its `config`. The editor MUST resolve integriq's path
through `fleetAppPath('integriq', ...)` and MUST NOT hard-code an app id.

@e2e exclude disabling integriq on the shared e2e instance breaks other suites; covered by Vitest on FieldEditor with the provider call failing

#### Scenario: A declaration is kept when integriq is missing

- **GIVEN** a field `adres` with `x-openregister-property-source` `{"provider": "bag", "mode": "default", "config": {"x": 1}}`
- **AND** integriq is not installed
- **WHEN** a maker changes the description of another field and saves
- **THEN** `adres` still carries the declaration with `config` `{"x": 1}`

### Requirement: The fields table shows the registry source and a refusal lands on its field (REQ-BQRF-003)

The "Format" column of the fields table SHALL show "{provider label}, live" or
"{provider label}, starting value" for a field with a declaration. When
OpenRegister refuses the schema save over a property-source declaration, the
designer SHALL show the refusal on the field it names and open that field.

@e2e tests/e2e/schema-designer-registry-field.spec.ts

#### Scenario: The table shows the source

- **GIVEN** the field `kvkNummer` reads `kvk` live
- **WHEN** a maker opens the schema
- **THEN** its "Format" cell reads "KvK organisation, live"

#### Scenario: An unknown provider is refused on its field

- **GIVEN** a field whose declaration names a provider OpenRegister refuses
- **WHEN** the maker saves the schema
- **THEN** the refusal message shows on that field and the field is opened
- **AND** the schema is not reported as saved
