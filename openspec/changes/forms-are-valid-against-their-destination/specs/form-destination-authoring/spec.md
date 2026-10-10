# form-destination-authoring Delta: forms-are-valid-against-their-destination

**Status**: draft
**Scope**: buildiq form pages, registration forms, external forms, journey designer. Implements hydra `form-submits-into-its-destination-object`.

## ADDED Requirements

### Requirement: Every buildiq form MUST be valid against its destination before it is published

Saving a form page, a registration form, an external form or a journey SHALL call OpenRegister's form destination validator with the form's fields, presets and fixed values. The designer SHALL show each finding on its field. A form with findings SHALL NOT be published: publishing a registration form, publishing the app that carries a form page, and provisioning an external form each SHALL require zero findings. There SHALL be no report-only mode for publishing (decision 181). A form that names no destination, or whose destination cannot be read, or an OpenRegister without the validator, SHALL count as a finding (`destination-missing`, `destination-unchecked`), because a check that did not run is not a pass.

#### Scenario: A registration form missing a required property cannot be published
- **GIVEN** a registration form into dossiq `case` that sets no `caseType`
- **WHEN** its author publishes it
- **THEN** the publish is refused and the designer marks the missing `caseType` with `required-unmapped`

#### Scenario: A preset fills a required property
- **GIVEN** the same form with a hidden preset `caseType = omgevingsvergunning`
- **WHEN** it is saved
- **THEN** the validator reports no finding on `caseType`

#### Scenario: Every finding is shown when the publish is refused
- **GIVEN** a form with two findings on different fields
- **WHEN** the author publishes
- **THEN** the publish is refused and both findings are shown, each on its own field

#### Scenario: An app whose form page has findings is not published
- **GIVEN** an app whose form page writes a text field into an integer property
- **WHEN** its owner publishes the app
- **THEN** the publish is refused with the `type-mismatch` finding on that page's field

### Requirement: A draft form saves with its findings

A form in draft SHALL save while it has findings, and the save SHALL return every finding so the designer marks each on its field (decision 189). A registration form is a draft when its own `status` is `draft`; the form pages in the page designer are saved into the app's concept version, which is a draft until the app is published. Buildiq SHALL use the form's own `status` property and SHALL NOT use OpenRegister's `@self.status` draft (openregister#4566) for its forms: that status is left only through a form submit, and a form is published by its author, not submitted.

#### Scenario: A half-built registration form is saved as a draft
- **GIVEN** a registration form in status `draft` into dossiq `case` that sets no `caseType`
- **WHEN** its author saves it
- **THEN** the form is stored, and the answer carries the `required-unmapped` finding on `caseType`

#### Scenario: A page designer save keeps its findings visible
- **GIVEN** a form page whose field writes into a property the schema does not have
- **WHEN** the author saves the pages
- **THEN** the pages are saved and the field shows its `property-unknown` finding

### Requirement: An external form MUST have a public destination and spam protection

Publishing a form for an anonymous audience SHALL require the destination schema to grant public create and SHALL add a honeypot field. The portaliq page SHALL be created only after the form validates with zero findings.

#### Scenario: A clean external form gets its portal page
- **GIVEN** a form with zero findings and External access switched on
- **WHEN** it is provisioned
- **THEN** the schema grants public create, the form carries a honeypot, and the portaliq page exists

### Requirement: A registration form's presets are checked against its destination

Presets SHALL count as fixed values for OpenRegister's form destination validator. A preset whose value the target property refuses SHALL be a finding, and SHALL refuse publishing. This replaces the "Saving SHALL warn" clause of REQ-OBRF-005 in the open change `forms-per-case-type`.

#### Scenario: A preset outside the target enum is refused
- **GIVEN** a preset `intakeChannel = fax` where the target enum is `portal, phone, desk`
- **WHEN** the form is published
- **THEN** the publish is refused with `fixed-value-invalid` on `intakeChannel`
