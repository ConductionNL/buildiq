# openbuild-schema-designer Specification (delta)

## ADDED Requirements

### Requirement: A text field can be an automatic number (REQ-OBSD-009)

The schema designer SHALL let the maker turn a string field into an automatic number, written as OpenRegister's `x-openregister-generated` keyword with `sequence`, `format` and `resetOn`. The designer SHALL show an example of the next number, SHALL refuse a format without a `{seq:n}` placeholder, and SHALL remove the keyword when the setting is switched off.

**ID:** REQ-OBSD-009

#### Scenario: Invoice numbers
- **GIVEN** a schema Factuur with a text field `nummer`
- **WHEN** the maker switches on Automatic number with format `INV-{year}-{seq:4}` and restart each year
- **THEN** the property carries `x-openregister-generated: { sequence: "factuur", format: "INV-{year}-{seq:4}", resetOn: "year" }`
- **AND** the designer shows the example `INV-2026-0001`

#### Scenario: A format without a counter
- **WHEN** the maker enters the format `INV-{year}`
- **THEN** the designer says the format needs a counter such as `{seq:4}` and does not save it
