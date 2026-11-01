# data-calculated-fields Specification

**OpenSpec changes**: [data-calculated-field-authoring](../../changes/archive/2026-09-29-data-calculated-field-authoring/) _(archived 2026-09-29)_

**Status**: done

@e2e exclude component-contract spec: every scenario is proven by Vitest against the real service and view code (tests/services/calculations.spec.js, tests/components/schema-editor/CalculationEditor.spec.js, tests/views/SchemaDesigner.spec.js "calculated fields") with OpenRegister's recorded responses, and the saved body was validated with OpenRegister's own CalculationAnnotationValidator. No Playwright test drives the designer's calculation section yet; the live check in the PR body is the end-to-end proof until one is written.

## Purpose

A maker adds a field to a schema whose value OpenRegister calculates from the
record's other fields, and tries it before saving.

## Requirements


### Requirement: A maker adds a calculated field in the schema designer (REQ-BQCF-001)

The Calculations section of the schema designer SHALL let a maker add a
calculated field with a name, a type and an expression, and SHALL save it as the
`calculation` key on that property. Entries in the schema's
`x-openregister-calculations` block SHALL be listed read-only and saved back
unchanged.

#### Scenario: A maker adds a total to an order schema

- **GIVEN** the schema `order` with the number fields `quantity` and `unitPrice`
- **WHEN** a maker opens the schema designer, adds the calculated field `total` of type number with the expression quantity times unitPrice, and saves
- **THEN** the schema OpenRegister stores has `properties.total.calculation` with type `number` and a multiply expression over `quantity` and `unitPrice`, and a new order with quantity 3 and unit price 5 reads back total 15

#### Scenario: A calculation from the register file is not editable

- **GIVEN** a schema whose `x-openregister-calculations` block declares `age`
- **WHEN** a maker opens its Calculations section
- **THEN** `age` is listed without edit or remove controls, with the note that it comes from the register file, and a save leaves the block unchanged

### Requirement: An expression is built from the published operators (REQ-BQCF-002)

The editor SHALL build an expression only from OpenRegister's operator
catalogue, the schema's own fields and typed literals. It SHALL NOT accept a
free-text formula.

#### Scenario: Only catalogue operators are offered

- **GIVEN** OpenRegister publishes an operator catalogue
- **WHEN** a maker adds a node to an expression
- **THEN** the operator list holds exactly the catalogue's operators and the field list holds exactly the schema's properties

### Requirement: A maker tries a calculation before saving (REQ-BQCF-003)

The editor SHALL evaluate an unsaved calculation against a sample record through
OpenRegister's evaluate endpoint and show the result or the refusal.

#### Scenario: Trying a total

- **GIVEN** the unsaved calculation `total` from the first scenario
- **WHEN** the maker enters quantity 2 and unit price 4 and chooses "Try"
- **THEN** the panel shows 8 and nothing is saved

### Requirement: A refused save is shown on its field (REQ-BQCF-004)

When OpenRegister refuses a schema save because of a calculation, the designer
SHALL show the refusal on the field it names and SHALL keep the staged edit.

#### Scenario: A cycle is refused

- **GIVEN** calculated fields `a` reading `b` and `b` reading `a`
- **WHEN** the maker saves
- **THEN** the save is refused, the message shows next to `a` or `b`, and both expressions are still in the editor
