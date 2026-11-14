# page-layout-per-type Specification (delta)

## Purpose

An admin decides per schema and per type value which header, tabs and widgets
a detail page shows. Buildiq stores the layout and serves it to the owning
app as a data-provider leaf (ADR-066). Requested by the dossiq competitor
analysis, finding B09.

## ADDED Requirements

### Requirement: A page layout is bound to a schema and an optional type value (REQ-OBPL-001)

Buildiq SHALL declare a `pageLayout` schema with `targetApp`, `register`,
`schema`, optional `typeProperty` and `typeValue`, `header`, `tabs[]`,
`widgets[]` and a `draft`, `published` lifecycle. The tuple `(targetApp,
register, schema, typeProperty, typeValue)` SHALL be unique. `tabs[]` and
`widgets[]` SHALL validate against the canonical manifest detail-page config.

**ID:** REQ-OBPL-001

#### Scenario: Two layouts for one type value are refused

- **WHEN** an admin saves a second published layout for `dossiq/case` with `caseType = bouwvergunning`
- **THEN** OpenRegister validation refuses it and names the existing layout
- @e2e exclude uniqueness is a schema-validation invariant; covered by PHPUnit on the register import

### Requirement: The detail-page editor authors a layout per type (REQ-OBPL-002)

The detail-page sub-editor SHALL offer an "applies to" panel with target app,
register, schema and an optional type property and value. Saving SHALL write
a `pageLayout` object through the OpenRegister objects API. A `tabs[].ref`
unknown to the integration registry SHALL raise a warning, not block the save.

**ID:** REQ-OBPL-002

#### Scenario: An admin lays out the building-permit case page

- **WHEN** an admin picks `dossiq`, `case`, `caseType = bouwvergunning`, orders three tabs and saves
- **THEN** a `pageLayout` object exists in `draft` with those tabs and the editor shows it in the live preview
- e2e: `tests/e2e/page-layout-editor.spec.ts`

### Requirement: The layout is served as a data-provider leaf (REQ-OBPL-003)

Buildiq SHALL register a leaf `buildiq-page-layout` of kind `data-provider`,
storage strategy `app-local`, through `RegisterLeafProvidersEvent`. For a host
object its `list` SHALL return at most one published layout: the one matching
the object's type value first, else the schema-wide one, else nothing. Draft
layouts SHALL never resolve. The provider SHALL NOT offer `create` and SHALL
NOT call any action in the consuming app.

**ID:** REQ-OBPL-003

#### Scenario: A type-specific layout wins over the schema-wide one

- **WHEN** a published schema-wide layout and a published `bouwvergunning` layout exist and the provider lists for a `bouwvergunning` case
- **THEN** only the `bouwvergunning` layout is returned
- @e2e exclude resolution runs in buildiq's DI context; covered by PHPUnit on `PageLayoutLeafProvider::list()`

#### Scenario: Buildiq absent means manifest layout

- **WHEN** the consuming app renders a case detail page on an instance without buildiq
- **THEN** no provider answers and the page renders its manifest layout unchanged
- @e2e exclude the absent-app path cannot be staged on CI, which installs the whole fleet; covered by the consumer's unit test on the fallback

### Requirement: A tab declares its kind, and an admin orders tabs per case type (REQ-OBPL-004)

`tabs[]` SHALL carry a `kind` of `leaf`, `widgets`, `fieldGroup` or
`relatedList`, a `label`, an `order` and the reference its kind needs: a leaf
id, a widget slot, a list of field names, or a register and schema. The
editor SHALL let an admin add, reorder and remove tabs for one case type
without touching the schema-wide layout.

**ID:** REQ-OBPL-004

#### Scenario: A case type gets its own three tabs

- **WHEN** an admin adds a `fieldGroup` tab, a `widgets` tab and a `leaf` tab to the `bouwvergunning` layout and drags the leaf tab to the front
- **THEN** the published layout lists the three tabs in that order with their kinds, and the schema-wide layout is unchanged
- e2e: `tests/e2e/page-layout-editor.spec.ts`

#### Scenario: A tab kind without its reference is refused

- **WHEN** a `leaf` tab is saved with no leaf id
- **THEN** validation refuses the save and names the missing reference
- @e2e exclude a per-kind required-field rule on the schema; covered by PHPUnit on the register import

### Requirement: A widget tab holds a grid, each widget with a width and an order (REQ-OBPL-005)

A `widgets` tab SHALL hold `widgets[]` entries carrying `id`, `config`,
`order` and a `width` of `small`, `medium`, `large` or `extraLarge`. The
editor SHALL offer the four widths and drag ordering. The served layout SHALL
keep the stored order.

**ID:** REQ-OBPL-005

#### Scenario: Two widgets sit side by side and a third fills the row

- **WHEN** an admin sets two widgets to `medium` and one to `extraLarge` and orders them
- **THEN** the published layout carries the three widths and the order, and the live preview shows two in a row and one below
- e2e: `tests/e2e/page-layout-editor.spec.ts`

#### Scenario: An unknown width is refused

- **WHEN** a layout is imported with `width: huge`
- **THEN** validation refuses it and names the four allowed values
- @e2e exclude an enum on the schema; covered by PHPUnit on the register import

### Requirement: A widget carries display conditions and a high-contrast flag (REQ-OBPL-006)

A widget entry SHALL carry `conditions[]` of `{field, operator, value}` and a
boolean `highContrast`. The leaf SHALL evaluate `conditions[]` against the
host object and SHALL omit a widget whose conditions do not hold. An empty
`conditions[]` SHALL always hold. `highContrast` SHALL be served as declared
and SHALL NOT change which widgets are returned.

**ID:** REQ-OBPL-006

#### Scenario: A widget hides itself on a case that has no decision

- **WHEN** a widget declares `conditions[] = [{field: resultType, operator: isNotEmpty}]` and the leaf lists for a case with no `resultType`
- **THEN** the widget is absent from the served layout and the rest of the tab is returned
- @e2e exclude condition evaluation runs in buildiq's DI context; covered by PHPUnit on `PageLayoutLeafProvider::list()`

#### Scenario: A high-contrast widget reaches the consumer marked

- **WHEN** an admin ticks high contrast on the term widget and the leaf serves the layout
- **THEN** the served entry carries `highContrast: true` and the consumer renders the marked variant
- e2e: `tests/e2e/page-layout-leaf.spec.ts`

### Requirement: The header is its own slot, with fields and chips per case type (REQ-OBPL-007)

`header` SHALL carry `titleField`, `subtitleField`, `chips[]`, `fields[]` of
`{field, label, order}` and an optional `widgetId`. The editor SHALL author
the header beside the tabs. A case-type header SHALL replace the schema-wide
header whole, and SHALL NOT merge field by field.

**ID:** REQ-OBPL-007

#### Scenario: The building-permit header shows the address and the term

- **WHEN** an admin puts `location` and `dueDate` in the `bouwvergunning` header and publishes
- **THEN** the served layout's header carries both fields in that order, and a case of another type still gets the schema-wide header
- e2e: `tests/e2e/page-layout-editor.spec.ts`

#### Scenario: Replacement is whole, not field by field

- **WHEN** a case-type header declares one field and the schema-wide header declares four
- **THEN** the served header has exactly the one field
- @e2e exclude resolution rule; covered by PHPUnit on `PageLayoutLeafProvider::list()`

### Requirement: A case type declares its task-list columns and search fields (REQ-OBPL-008)

`pageLayout` SHALL carry `taskList` with `columns[]` of `{field, label,
order, width}` and `searchFields[]` of `{field, operator}`. The leaf SHALL
serve them beside the detail layout, resolved by the same order: type value,
then schema-wide, then nothing. Buildiq SHALL NOT read or write any task
object.

**ID:** REQ-OBPL-008

#### Scenario: Building permits show the address column on the task list

- **WHEN** an admin adds `location` as a task-list column on the `bouwvergunning` layout and dossiq's task list asks the leaf for that case type
- **THEN** the served `taskList.columns[]` carries `location` and dossiq renders it
- e2e: `tests/e2e/page-layout-leaf.spec.ts`

#### Scenario: A case type with no task-list declaration falls back

- **WHEN** the `bouwvergunning` layout declares no `taskList` and a schema-wide layout does
- **THEN** the schema-wide column set is served
- @e2e exclude resolution order; covered by PHPUnit on `PageLayoutLeafProvider::list()`

### Requirement: A case type declares its document upload fields (REQ-OBPL-009)

`pageLayout` SHALL carry `uploadFields[]` of `{field, label, order,
visibility}` where `visibility` is `editable`, `readOnly` or `hidden`, and an
optional `default`. The leaf SHALL serve them beside the detail layout. A
`hidden` field with no `default` SHALL be refused on save, because it can
never be filled.

**ID:** REQ-OBPL-009

#### Scenario: The upload dialog defaults the confidentiality and locks it

- **WHEN** an admin declares `confidentiality` as `readOnly` with default `intern` on the `bouwvergunning` layout and a handler opens the upload dialog on such a case
- **THEN** the field shows `intern` and cannot be changed
- e2e: `tests/e2e/page-layout-leaf.spec.ts`

#### Scenario: A hidden field without a default is refused

- **WHEN** an admin marks `documentType` hidden and leaves its default empty
- **THEN** the save is refused and the editor says a hidden field needs a default
- @e2e exclude a cross-field validation rule; covered by PHPUnit on the register import
