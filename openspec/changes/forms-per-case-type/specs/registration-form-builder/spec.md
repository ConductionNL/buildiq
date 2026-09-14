# registration-form-builder Specification (delta)

## Purpose

One type carries several registration forms, each named, each for an
audience, each able to preset a field the filer must not see. Extends
`registration-form-builder`. Requested by the dossiq competitor analysis,
register row Q1.15.

## ADDED Requirements

### Requirement: Several forms per type value, one default per audience (REQ-OBRF-004)

`registrationForm` SHALL carry `name`, `audience` and `isDefault`.
Uniqueness SHALL be `(targetApp, register, schema, typeProperty,
typeValue, name)`. At most one published form per `(type tuple,
audience)` SHALL be `isDefault`.

**ID:** REQ-OBRF-004

#### Scenario: Three forms bind to one case type

- **WHEN** an admin publishes `client-intake`, `desk-intake` and `architect-intake` for `dossiq/case` `caseType = bouwvergunning` with audiences `client`, `internal`, `supplier`
- **THEN** all three exist published and each is the default of its audience
- e2e: `tests/e2e/registration-form-builder.spec.ts`

#### Scenario: A second client default is refused

- **WHEN** an admin marks a second `client` form for the same type as default
- **THEN** validation refuses it and names the existing default
- @e2e exclude uniqueness is a schema-validation invariant; covered by PHPUnit on the register import

### Requirement: A form presets fields, hidden or visible (REQ-OBRF-005)

A form SHALL carry `presets[]` of `{field, value, hidden}`. Saving SHALL
warn on a `field` absent from the target schema. The served form SHALL
omit a hidden preset field from `fields[]` and SHALL set `default` on a
visible one. The leaf SHALL return `presets[]` beside the form, and
buildiq SHALL NOT write the target object.

**ID:** REQ-OBRF-005

#### Scenario: The citizen never sees the channel field

- **WHEN** `client-intake` presets `intakeChannel = portal` as hidden and dossiq's journey asks the leaf for the `client` default
- **THEN** the served `fields[]` has no `intakeChannel` and `presets[]` carries it, so the journey writes `portal` on submit
- e2e: `tests/e2e/registration-form-leaf.spec.ts`

#### Scenario: A visible preset is pre-filled and editable

- **WHEN** `architect-intake` presets `applicantRole = gemachtigde` as visible
- **THEN** the served field carries `default = gemachtigde` and stays in `fields[]`
- @e2e exclude served-shape transformation; covered by PHPUnit on `RegistrationFormLeafProvider::list()`

### Requirement: The leaf serves by audience and by name (REQ-OBRF-006)

`buildiq-registration-form`'s `list` SHALL accept optional `audience` and
`name`. Without a filter it SHALL return every published form for the
type, defaults first; with `audience` the forms of that audience, default
first; with `name` that form. Every entry SHALL carry `name`, `audience`,
`isDefault` and `presets[]`.

**ID:** REQ-OBRF-006

#### Scenario: dossiq asks for the desk default

- **WHEN** dossiq's create dialog asks the leaf for `audience = internal` on the `bouwvergunning` type
- **THEN** `desk-intake` is returned first with `isDefault = true`, and `CnFormDialog` renders its steps
- e2e: `tests/e2e/registration-form-leaf.spec.ts`

#### Scenario: An unknown name returns nothing

- **WHEN** a consumer asks for `name = nope`
- **THEN** the list is empty and no error is raised
- @e2e exclude covered by PHPUnit on the provider
