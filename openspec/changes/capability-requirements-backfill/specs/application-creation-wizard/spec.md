## ADDED Requirements

### Requirement: A maker fills a new app with demo records (REQ-BQCV-030)

The create-app wizard and the app's detail page SHALL offer to fill the app with demo records. Demo records SHALL be generated from the app's own schemas, so each one validates against its schema, and SHALL be marked as demo data so the maker can remove them in one action. Demo records SHALL only be created when the maker asks for them.

#### Scenario: A maker tries a new app with demo records

- **GIVEN** a maker who created the app `meldingen` with the schema `report`
- **WHEN** they choose "Fill with demo records"
- **THEN** the app's register holds report records that validate against the schema, marked as demo data
- **AND** the app's list page shows them

#### Scenario: The maker removes the demo records

- **WHEN** the maker chooses "Remove demo records"
- **THEN** the demo records are deleted and records the maker created are kept
