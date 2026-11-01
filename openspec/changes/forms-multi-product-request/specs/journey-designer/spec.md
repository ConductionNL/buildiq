# Spec: journey-designer

## Purpose

Adds to the `journey-designer` capability: a journey write can repeat once per
item of a list the user filled in, each item going to the target of its own
product, so one submission requests several products and each starts its own
process.

## ADDED Requirements

### Requirement: A write can repeat per list item (REQ-BQMP-001)

The writes editor SHALL let a maker mark a write as repeating over a list answer
collected earlier in the journey (`forEach`). The mapping SHALL be able to read
the item's own fields and the other answers. The designer SHALL refuse a
`forEach` that names an answer that is not a list or that is first asked after
the write's step.

#### Scenario: A maker repeats the request write per product

- **GIVEN** a maker in the journey designer with a step that asks the product list `producten`
- **WHEN** they mark the write on the last step as repeating over `producten` and map `item.toelichting` to `omschrijving`
- **THEN** the journey saves with `forEach` set to `producten` on that write

### Requirement: Each item goes to its product's target (REQ-BQMP-002)

A repeating write SHALL accept `targetBy` and a `targets` map from each allowed
product value to a register, schema and type value. Saving SHALL be refused when
a product value has no target, or when the mapping names a field that one of the
target schemas lacks, and the refusal SHALL name the product and the field. The
designer SHALL use the run's own validator.

#### Scenario: Two products, two case types

- **GIVEN** a product list whose values are `parkeervergunning` and `afvalcontainer`
- **WHEN** the maker maps them to case type values `PV` and `AC` on schema `zaak`, and saves
- **THEN** the journey saves, and the preview's review shows one request per product with its case type

#### Scenario: A field missing on one target is named

- **GIVEN** a mapping that writes `kenteken` and a target schema for `afvalcontainer` without that field
- **WHEN** the maker saves
- **THEN** the save is refused with "afvalcontainer: kenteken does not exist on the target schema."

### Requirement: The parts can hang under one bundle (REQ-BQMP-003)

The writes editor SHALL allow one bundle write before the repeating write, and
item mappings SHALL be able to reference the bundle's id. The form builder SHALL
offer a "Product list" field with a product column, detail columns and a maximum
number of rows of at most 25.

#### Scenario: A resident requests two products at once

- **GIVEN** a published journey with a bundle write and a repeating write, and a resident who picks a parking permit and a waste container
- **WHEN** the resident submits
- **THEN** one bundle record and two request records exist, each request points at the bundle, and each request is on its own case type

### Requirement: The designer says whether a part starts a process (REQ-BQMP-004)

For each target of a repeating write, the designer SHALL list the flows that start
on creation of a record in that target's register and schema, and SHALL warn
"This product starts no process." when there is none.

#### Scenario: A product without a process is flagged

- **GIVEN** targets where only `parkeervergunning` has a flow on record creation
- **WHEN** the maker opens the writes editor
- **THEN** `afvalcontainer` shows "This product starts no process."
