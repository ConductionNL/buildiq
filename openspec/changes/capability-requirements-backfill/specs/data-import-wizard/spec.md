## ADDED Requirements

### Requirement: A maker creates a new app from an uploaded spreadsheet (REQ-BQCV-001)

The create-app wizard SHALL offer to start from an uploaded spreadsheet (CSV, XLS, XLSX). The system SHALL create the app with its first version, create one schema from the file's header row through OpenRegister's register import, load the rows as records, and add a list page and a detail page bound to that schema, so the new app opens with its data.

#### Scenario: A maker starts an app from a spreadsheet

- **GIVEN** an administrator in the create-app wizard with a CSV file of 40 rows and the columns name, address and status
- **WHEN** they choose "Start from a spreadsheet", upload the file and create the app
- **THEN** the new app has a schema with the fields name, address and status and 40 records
- **AND** the app opens on a list page that shows the 40 records, and each row opens a detail page

#### Scenario: A file that cannot be read creates nothing

- **GIVEN** the same wizard
- **WHEN** the maker uploads a file that OpenRegister cannot import
- **THEN** the wizard shows the import error and no app, schema or record is created
