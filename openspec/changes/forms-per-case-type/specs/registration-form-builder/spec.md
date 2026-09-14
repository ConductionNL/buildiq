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

### Requirement: A form declares the intake channel it serves (REQ-OBRF-007)

`registrationForm` SHALL carry `channel`, an open string validated against
the consuming schema's own channel property when one is declared on the
form's `typeProperty` target. Uniqueness SHALL become `(targetApp, register,
schema, typeProperty, typeValue, name)`, unchanged, and at most one published
form per `(type tuple, audience, channel)` SHALL be `isDefault`. A form
without a `channel` SHALL serve every channel.

**ID:** REQ-OBRF-007

#### Scenario: The portal and the desk get different forms for one case type

- **WHEN** an admin publishes `client-intake` with `channel = portal` and `desk-intake` with `channel = desk` for `dossiq/case` `caseType = bouwvergunning`
- **THEN** both are published and each is the default of its own channel
- e2e: `tests/e2e/registration-form-builder.spec.ts`

#### Scenario: A channel the consumer does not know is refused

- **WHEN** an admin saves a form with `channel = fax` and dossiq's `case.intakeChannel` enum does not list it
- **THEN** the save is refused and the editor names the values the consumer accepts
- @e2e exclude validation against the target schema's enum; covered by PHPUnit on the save path

### Requirement: The form owns field order and grouping (REQ-OBRF-008)

A form SHALL carry `sections[]` of `{name, label, order}` and an `order` and
optional `section` on each entry of `fields[]`. The served form SHALL list
fields in the stored order, grouped into the declared sections, and SHALL NOT
fall back to the schema's property order. A `section` naming no declared
section SHALL be refused on save.

**ID:** REQ-OBRF-008

#### Scenario: The citizen's form asks four fields in the order the admin set

- **WHEN** an admin puts four fields in a section called `uw gegevens`, orders them, and dossiq's portal journey asks the leaf for the `client` default
- **THEN** the served form lists the four fields in that order inside that section
- e2e: `tests/e2e/registration-form-leaf.spec.ts`

#### Scenario: An unknown section is refused

- **WHEN** a field points at a section the form does not declare
- **THEN** the save is refused and the editor names the sections that exist
- @e2e exclude a cross-field validation rule; covered by PHPUnit on the register import

### Requirement: The leaf serves by channel, and the channel comes back with the form (REQ-OBRF-009)

`buildiq-registration-form`'s `list` SHALL accept an optional `channel`
beside `audience` and `name`. With `channel` it SHALL return the forms of
that channel, the default first, and then the forms that declare no channel.
Every served entry SHALL carry `channel`, `isPublic` and `confirmationText`,
so the consumer writes the channel from the form rather than setting it after
the write.

**ID:** REQ-OBRF-009

#### Scenario: dossiq writes the channel the form declared

- **WHEN** dossiq's portal journey asks the leaf for `audience = client`, `channel = portal` and submits the form
- **THEN** the served entry carries `channel = portal` and dossiq writes `case.intakeChannel = portal` with the case rather than afterwards
- e2e: `tests/e2e/registration-form-leaf.spec.ts`

#### Scenario: A channel with no form of its own falls back

- **WHEN** a consumer asks for `channel = post` and only a channel-less form is published for the type
- **THEN** the channel-less form is returned
- @e2e exclude serving order; covered by PHPUnit on `RegistrationFormLeafProvider::list()`

#### Scenario: A public form reaches the portal marked public

- **WHEN** an admin ticks public on `client-intake` and writes its confirmation text
- **THEN** the served entry carries `isPublic: true` and the text, and portaliq renders both
- @e2e exclude the rendering half is portaliq's; covered by PHPUnit on the served shape
