## ADDED Requirements

### Requirement: A page binds to another app's schema without copying it (REQ-BQCV-023)

The register and schema pickers in the page editors SHALL list every register and schema in the instance the maker may read, not only the app's own. A page bound to another app's schema SHALL read and write that app's records in place. Binding SHALL NOT copy the schema into the app's register or change the other app's schema.

#### Scenario: A maker builds a page on another app's object type

- **GIVEN** a maker editing an index page in the app `vergunningen`, and the app `klantbeeld` owns the schema `person`
- **WHEN** they pick the register of `klantbeeld` and the schema `person` in the page editor and save
- **THEN** the page lists the `person` records of `klantbeeld`
- **AND** the register of `vergunningen` has no `person` schema afterwards
- **AND** the `person` schema of `klantbeeld` is unchanged
