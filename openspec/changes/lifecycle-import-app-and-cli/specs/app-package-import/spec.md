# Spec: app-package-import

## Purpose

A maker brings an app exported from one buildiq into another by uploading the
archive. The archive is read as data: its package files are parsed with the same
rules as a GitHub install, a new app is created that the maker owns, and its
records come along only when asked.

## ADDED Requirements

### Requirement: A maker imports an exported archive as a new app (REQ-BQIC-001)

The Applications page SHALL offer "Import app" next to "Add app". After a maker
picks an archive, the dialog SHALL show the app name and the number of pages,
schemas and records it holds, and SHALL ask for a slug. Importing SHALL create a
new app from the archive's `openbuild-app.json`, `manifest.json` and package
folders, owned by the maker. A slug already in use SHALL be refused with a
message asking for another slug, and nothing SHALL be created.

#### Scenario: A maker moves the permit app to the test instance

- **GIVEN** a maker signed in on the test instance with the archive `vergunningen-1.2.0.zip` exported from production
- **WHEN** they click "Import app", pick the archive, keep the slug `vergunningen` and import
- **THEN** the app `vergunningen` appears on the Applications page with the same pages, menu and schemas, and the maker is its owner

#### Scenario: A slug that is taken is refused

- **GIVEN** an instance that already has the app `vergunningen`
- **WHEN** a maker imports an archive with the slug `vergunningen`
- **THEN** the dialog says the slug is taken and asks for another, and no app, version or register is created

### Requirement: The archive is read and never run (REQ-BQIC-002)

The import SHALL read only the package files (`openbuild-app.json`,
`manifest.json`, and the folders the package format defines, plus `data/`) and
SHALL ignore every other entry. It SHALL refuse an archive over 50 MB or with
more than 5,000 entries, an entry with an absolute path, a `..` segment or a
symlink, and an archive whose unpacked size passes 200 MB. It SHALL NOT load or
execute any code from the archive. A package that fails parsing SHALL be refused
as a whole, with the parser's error code and the path of the file at fault.

#### Scenario: An archive with a path outside the package is refused

- **GIVEN** an archive with an entry named `../../config/config.php`
- **WHEN** a maker imports it
- **THEN** the import is refused with a message about an unsafe path, and nothing is written or created

#### Scenario: A broken schema file names itself

- **GIVEN** an archive whose `schemas/permit.json` is not valid JSON
- **WHEN** a maker imports it
- **THEN** the dialog shows the parser's code and `schemas/permit.json`, and no app is created

### Requirement: Records come along only when asked (REQ-BQIC-003)

The dialog SHALL offer "Import records", off by default, when the archive holds a
`data/` folder. When it is on, the records SHALL be written through
OpenRegister's register import into the new app's register, schema by schema. A
failure in one schema SHALL be reported with the schema and the count that
failed, and SHALL NOT remove the app or the other schemas' records.

#### Scenario: A maker brings the test records along

- **GIVEN** an archive with `data/permit.jsonl` holding 40 records
- **WHEN** a maker ticks "Import records" and imports
- **THEN** the new app lists 40 permits, and the result says "40 permits imported"
