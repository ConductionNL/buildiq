# openbuilt-schema-designer Specification

## Purpose
TBD - created by archiving change openbuilt-schema-editor. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBSD-001 Schema list panel scoped to the virtual app's register namespace

The OpenBuilt schema designer SHALL render a list of schemas scoped to
the current virtual app's OpenRegister register namespace. The list
SHALL be reached at `/index.php/apps/openbuilt/builder/{slug}/schemas`
and rendered by `SchemaDesigner.vue` via the
`SchemaListPanel.vue` sub-component. For each schema the panel SHALL
display the slug, title, version, count of properties, and the
declared lifecycle state count (or "none"). The panel SHALL expose
an **Add Schema** action and per-row **Open**, **Rename**, and
**Delete** actions. The list SHALL load via the Pinia store layer
that wraps OR's runtime schema list endpoint (chain spec
`openregister-runtime-schema-api`) and SHALL NOT bypass that endpoint
with direct DB reads.

#### Scenario: Designer lists the schemas of the current virtual app

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world/schemas`
- **AND** the virtual app's register namespace contains a
  `hello-message` schema
- **THEN** the schema list panel renders one row for `hello-message`
- **AND** the row shows the slug, title, version, and property count

#### Scenario: Schemas from other virtual apps are not listed

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world/schemas`
- **AND** a different virtual app's register namespace contains a
  `customer` schema
- **THEN** the schema list panel does NOT render the `customer` row

### Requirement: REQ-OBSD-002 Add Schema flow captures slug, title, description, version

When the user activates the **Add Schema** action, the designer SHALL
render a guided form via `SchemaHeaderForm.vue` capturing:

- `slug` — required, kebab-case, MUST be unique within the virtual
  app's register namespace.
- `title` — required, free-text.
- `description` — optional, free-text.
- `version` — required, semver pattern (MAJOR.MINOR.PATCH), defaulting
  to `0.1.0` on first save.

On submit the designer SHALL POST the new schema to OR's runtime
schema CRUD endpoint (chain spec `openregister-runtime-schema-api`)
and on success SHALL route the user to the schema's detail view
(`/builder/{slug}/schemas/{newSchemaSlug}`). Validation errors
returned by the runtime endpoint SHALL surface inline on the failing
field.

#### Scenario: Valid Add Schema submission persists and routes to the schema

- **WHEN** the user submits the Add Schema form with
  `slug: customer`, `title: "Customer"`, `version: 0.1.0`
- **AND** no `customer` schema exists in the virtual app's register
- **THEN** the designer POSTs to the runtime schema endpoint
- **AND** on `201 Created` the router navigates to
  `/builder/{slug}/schemas/customer`

#### Scenario: Duplicate slug is rejected before route change

- **WHEN** the user submits the Add Schema form with a slug that
  already exists in the namespace
- **THEN** the runtime endpoint returns `409 Conflict`
- **AND** the form surfaces the conflict inline on the `slug` field
- **AND** the router does NOT navigate away from the form

### Requirement: REQ-OBSD-003 Field editor manages property add, remove, reorder, type, and validation

The schema detail view SHALL render the schema's `properties` map as
an ordered list of `FieldRow.vue` rows. For each property the user
SHALL be able to:

- Add a new property (appended; `FieldTypePicker.vue` chooses one of
  `string`, `number`, `integer`, `boolean`, `array`, `object`,
  `relation`).
- Remove a property (after a confirm-before-destructive dialog per
  REQ-OBSD-008).
- Reorder via drag-handle (the new order MUST be reflected in the
  saved JSON Schema's property order).
- Edit `name` (kebab-case or camelCase, unique within the schema),
  `required` flag, `default`, `description`, and the type-specific
  validation set:
  - `string`: `pattern`, `format`, `minLength`, `maxLength`, `enum`
  - `number` / `integer`: `minimum`, `maximum`, `multipleOf`, `enum`
  - `array`: `items` (recursive schema), `minItems`, `maxItems`
  - `object`: `properties` (recursive)
  - `relation`: target schema slug, cardinality (one / many),
    inverse-of property name (optional)
  - `boolean`: no extra validation beyond `default`

Changes SHALL be staged in the Pinia store and persisted only when the
user activates **Save** (REQ-OBSD-006). Live validation feedback
(REQ-OBSD-006) SHALL apply to each row.

#### Scenario: Adding a string property with a regex validation

- **WHEN** the user adds a new property `email` of type `string`
- **AND** sets `format: email` and `required: true`
- **AND** clicks Save
- **THEN** the persisted JSON Schema contains
  `properties.email = { type: "string", format: "email" }`
- **AND** the schema's top-level `required` array contains `"email"`

#### Scenario: Reordering two properties is reflected on reload

- **WHEN** the user drags property `body` above property `title`
- **AND** clicks Save
- **AND** the user reloads the schema detail view
- **THEN** `body` is rendered above `title`

### Requirement: REQ-OBSD-004 Visual lifecycle editor authors x-openregister-lifecycle declaratively

The schema detail view SHALL render a `LifecycleEditor.vue` panel that
lets the user author the schema's `x-openregister-lifecycle` block in
full per ADR-031. The editor SHALL support:

- Adding / removing **states** (each with a kebab-case `name` and a
  human-readable `label`).
- Designating exactly one state as `initial` (radio-style selection).
- Adding **transitions** (`from` state → `to` state), with an optional
  human-readable `label` and an optional condition expression.
- Authoring `on_transition` **actions** per transition: notification
  send, related-object upsert / archive, audit-event emit, webhook
  dispatch — chosen from a typed list whose options match the
  declarative action vocabulary recognised by OR's declarative engine
  (ADR-031).

The editor SHALL NOT permit authoring imperative code (no free-text
PHP, no script blocks); every action SHALL be a typed declarative
record. The editor's output, when serialised, SHALL match the
`x-openregister-lifecycle` JSON Schema shape consumed by OR's
declarative engine on schema reload (chain spec
`openregister-runtime-schema-api`).

#### Scenario: Author a draft → published → archived lifecycle

- **WHEN** the user adds three states (`draft`, `published`,
  `archived`) and three transitions (`draft → published`,
  `published → archived`, `archived → draft`)
- **AND** marks `draft` as the initial state
- **AND** adds an `on_transition` action of type "audit-event-emit"
  to the `draft → published` transition
- **AND** clicks Save
- **THEN** the persisted schema contains an `x-openregister-lifecycle`
  block declaring three states, three transitions, and one
  `on_transition.audit-event-emit` record on the
  `draft → published` transition
- **AND** the persisted schema contains NO imperative service-class
  reference

#### Scenario: Removing the only initial-state designation surfaces a validation error

- **WHEN** the user removes the only state marked `initial`
- **THEN** the editor surfaces "exactly one initial state is required"
- **AND** the Save button is disabled until the user designates a new
  initial state

### Requirement: REQ-OBSD-005 Sub-editors for aggregations, calculations, notifications, relations, widgets

The schema detail view SHALL render five further declarative
sub-editors, each surfaced under a collapsible section:

- `AggregationEditor.vue` — adds entries to
  `x-openregister-aggregations`. Each entry is a typed record:
  `{ name, operation (count | sum | avg | min | max), source
  (property path or related schema slug), filter (optional) }`.
- `CalculationEditor.vue` — adds entries to
  `x-openregister-calculations`. Each entry is a typed record:
  `{ name, expression (formula DSL or rollup spec), depends_on
  (array of property names) }`. The editor SHALL NOT accept free-text
  PHP or JavaScript; the expression DSL is the declarative
  vocabulary recognised by OR's calculation engine (ADR-031).
- `NotificationEditor.vue` — adds entries to
  `x-openregister-notifications`. Each entry is a typed record:
  `{ event (lifecycle transition or CRUD event), channel
  (email | webhook | in-app), template (named template slug),
  recipient (relation path or fixed role) }`.
- `RelationEditor.vue` — adds entries to `x-openregister-relations`.
  Each entry is a typed record: `{ name, target (schema slug),
  cardinality (one | many), inverse_of (optional property name on the
  target schema) }`.
- `WidgetEditor.vue` — adds entries to `x-openregister-widgets`. Each
  entry is a typed record: `{ slot (named page slot), widget
  (canonical widget id), config (typed map per widget) }` so the
  page editor (chain spec #5) can render the widget without
  re-authoring metadata.

All five editors SHALL produce declarative JSON output and SHALL NOT
accept free-text code in any field that affects runtime behaviour.

#### Scenario: Add a count aggregation over a related collection

- **WHEN** the user opens the `AggregationEditor` on a `customer`
  schema
- **AND** adds an entry `{ name: "open_orders", operation: "count",
  source: "orders", filter: "status = 'open'" }`
- **AND** clicks Save
- **THEN** the persisted schema contains
  `x-openregister-aggregations.open_orders` with the typed record
  exactly as authored
- **AND** OR's declarative engine reloads the schema and exposes
  `open_orders` as a computed property (chain spec contract)

#### Scenario: Calculation editor rejects free-text PHP

- **WHEN** the user types `<?php return ... ?>` into a calculation
  expression field
- **THEN** the editor surfaces "expression must use the declarative
  formula DSL"
- **AND** the Save button is disabled

### Requirement: REQ-OBSD-006 Live validation and explicit Save persist to OR's runtime schema CRUD

The designer SHALL run **live client-side validation** on every edit:
field name uniqueness, slug pattern, semver pattern, required-field
checks, and the typed-record shape of every declarative sub-editor.
Validation results SHALL surface inline per row / section and SHALL
disable the **Save** action until the staged change is valid.

The designer SHALL NOT auto-save. The user SHALL persist staged
changes explicitly via the **Save** action. On Save the designer
SHALL:

1. Compose the schema's JSON Schema body (including every
   `x-openregister-*` block) from the staged store state.
2. PUT the composed body to OR's runtime schema CRUD endpoint
   (chain spec `openregister-runtime-schema-api`).
3. On `200 OK`, refresh the local store from the response and surface
   a success toast.
4. On `4xx` or `5xx`, surface the failing error message inline and
   keep the staged state unchanged so the user can correct and retry.

The runtime endpoint SHALL trigger OR's declarative-engine reload and
cache invalidation (per chain spec #3); the designer SHALL NOT
duplicate that work.

#### Scenario: Invalid staged state disables Save until corrected

- **WHEN** the user removes the only `initial` lifecycle state
- **THEN** the designer's live validator surfaces the error inline
- **AND** the **Save** button is disabled

#### Scenario: Successful save round-trips through the runtime endpoint

- **WHEN** the user clicks Save with a valid staged schema
- **THEN** the designer PUTs the composed JSON Schema body to OR's
  runtime schema CRUD endpoint
- **AND** on `200 OK` the local store is refreshed from the response
- **AND** a success toast is surfaced

### Requirement: REQ-OBSD-007 Designer output is declarative-only (ADR-031 compliance)

The schema designer's serialised output SHALL be valid JSON Schema
with declarative `x-openregister-*` extension blocks only. The
designer SHALL NOT serialise references to PHP service classes,
JavaScript callbacks, file paths, or any other imperative artefact.
Every behaviour-shaping field in every sub-editor SHALL be expressed
as a typed declarative record drawn from OR's declarative vocabulary
(ADR-031). This is the canonical example of the ADR-031 principle
applied to a code-only spec: the editor is code, but its product
is declarative.

#### Scenario: Designer output contains no imperative references

- **WHEN** the user saves any schema authored in the designer
- **THEN** the persisted JSON Schema body contains zero references to
  PHP class names, file paths, or executable code strings
- **AND** every `x-openregister-*` block validates against the
  declarative-vocabulary JSON Schema published by OR (chain spec #3)

### Requirement: REQ-OBSD-008 Confirm-before-destructive on delete-field and delete-schema

The designer SHALL surface a confirmation dialog before performing
either of the following destructive actions:

- **Delete property** — a `FieldRow.vue` remove action. The dialog
  SHALL warn that existing objects of this schema may have data in the
  property that will become unreachable after save, and SHALL require
  an explicit second click to confirm.
- **Delete schema** — a `SchemaListPanel.vue` per-row delete action.
  The dialog SHALL warn that all objects of this schema may be
  affected (the destructive scope is defined by OR's runtime schema
  DELETE endpoint, chain spec #3), and SHALL require the user to type
  the schema slug into a confirmation field before the **Delete**
  button activates.

In both cases, cancelling the dialog SHALL leave the staged store
state unchanged.

#### Scenario: Delete-field requires a confirmation click

- **WHEN** the user clicks the remove action on a `FieldRow.vue`
- **THEN** a confirmation dialog renders
- **AND** the property is NOT removed from the staged store until the
  user clicks the dialog's **Confirm** button

#### Scenario: Delete-schema requires typing the slug

- **WHEN** the user clicks the delete action on a schema row
- **AND** the confirmation dialog renders with a typed-slug
  confirmation field
- **THEN** the dialog's **Delete** button is disabled until the user
  types the schema's slug exactly
- **AND** cancelling the dialog leaves the schema in the list

