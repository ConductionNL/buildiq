# form-destination-authoring Delta: forms-are-valid-against-their-destination

**Status**: draft
**Scope**: buildiq form pages, registration forms, external forms, journey designer. Implements hydra `form-submits-into-its-destination-object`.

## ADDED Requirements

### Requirement: Every buildiq form MUST be valid against its destination before it is published

Saving a form page, a registration form, an external form or a journey SHALL call OpenRegister's form destination validator with the form's fields, presets and fixed values. The designer SHALL show each finding on its field. In refuse mode, a form with findings SHALL NOT be published.

#### Scenario: A registration form missing a required property cannot be published
- **GIVEN** a registration form into dossiq `case` that sets no `caseType`
- **WHEN** its author publishes it in refuse mode
- **THEN** the publish is refused and the designer marks the missing `caseType` with `required-unmapped`

#### Scenario: A preset fills a required property
- **GIVEN** the same form with a hidden preset `caseType = omgevingsvergunning`
- **WHEN** it is saved
- **THEN** the validator reports no finding on `caseType`

#### Scenario: Report mode shows findings and saves
- **GIVEN** the validator in report mode and a form with one finding
- **WHEN** the author saves
- **THEN** the save succeeds and the finding is shown on its field

### Requirement: An external form MUST have a public destination and spam protection

Publishing a form for an anonymous audience SHALL require the destination schema to grant public create and SHALL add a honeypot field. The portaliq page SHALL be created only after the form validates with zero findings.

#### Scenario: A clean external form gets its portal page
- **GIVEN** a form with zero findings and External access switched on
- **WHEN** it is provisioned
- **THEN** the schema grants public create, the form carries a honeypot, and the portaliq page exists

### Requirement: A registration form's presets are checked against its destination

Presets SHALL count as fixed values for OpenRegister's form destination validator. A preset whose value the target property refuses SHALL be a finding, and SHALL block publish in refuse mode. This replaces the "Saving SHALL warn" clause of REQ-OBRF-005 in the open change `forms-per-case-type`.

#### Scenario: A preset outside the target enum is refused
- **GIVEN** a preset `intakeChannel = fax` where the target enum is `portal, phone, desk`
- **WHEN** the form is saved in refuse mode
- **THEN** the save is refused with `fixed-value-invalid` on `intakeChannel`
