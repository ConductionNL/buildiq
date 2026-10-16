# Spec: external-database-sources

## Purpose

A maker builds apps on the tables of a database the organisation already runs.
OpenRegister connects the database and serves its tables live; buildiq lets the
maker bind that database to an app, builds pages that follow its write setting,
generates an app from its tables, and lets an administrator save a read-only SQL
query as a table pages can use.

## ADDED Requirements

### Requirement: An app binds a connected database (REQ-BQDB-001)

The app settings SHALL list the registers OpenRegister serves from a source of
type `database` and SHALL let a maker with the owner or editor role bind one to
the app as a `dataRegisters` entry with a label. The list SHALL come from
OpenRegister on every open and SHALL leave out registers that are not backed by a
database.

#### Scenario: A maker binds the permit database to an app

- **GIVEN** an administrator connected a PostgreSQL database in OpenRegister and created the register `permits-db` from it
- **WHEN** a maker opens the settings of the app `vergunningen`, picks "permits-db" under "Connect a database" and saves
- **THEN** the app's `dataRegisters` holds `{register: "permits-db", label: "permits-db"}` and the page editor's register list shows it second, after the app's own register

#### Scenario: Registers without a database are not offered

- **GIVEN** the instance has the registers `permits-db` (database) and `publications` (not a database)
- **WHEN** a maker opens "Connect a database" in the app settings
- **THEN** the picker lists `permits-db` and does not list `publications`

### Requirement: Pages follow the database's write setting (REQ-BQDB-002)

The page editor SHALL mark a register backed by a database as "Connected
database". When its source does not take writes, the index page editor SHALL set
`showAdd`, `showEditAction` and `showDeleteAction` to `false`, the detail page
editor SHALL set `showEditAction` to `false`, and both SHALL lock those controls
with the note "This database is read only. Ask an administrator to allow writes."
The write setting SHALL be read from OpenRegister each time the editor opens and
SHALL NOT be stored on the Application.

#### Scenario: A read-only database gets no add button

- **GIVEN** the register `permits-db` whose source has writes turned off
- **WHEN** a maker binds an index page to `permits-db` and the table `permits`
- **THEN** the page config carries `showAdd: false`, `showEditAction: false` and `showDeleteAction: false`, and the published app user sees the list without an add, edit or delete button

#### Scenario: An administrator turns writes on

- **GIVEN** an index page bound to `permits-db` saved while writes were off
- **WHEN** an administrator turns on "Allow writes" for the source and the maker opens the page editor again
- **THEN** the action controls are unlocked and the note is gone

### Requirement: Generate an app from a connected database (REQ-BQDB-003)

The create-app wizard SHALL offer "Start from a database". The maker SHALL pick a
connected database and tick one or more of its tables. Creating the app SHALL
bind the database as a data register and SHALL add, per ticked table, an index
page, a detail page and a menu entry, inside the wizard's existing atomic
creation. The server SHALL refuse a register that is not backed by a database or
that the caller cannot read, and SHALL create nothing in that case.

#### Scenario: A maker makes a permit app from two tables

- **GIVEN** an administrator in the create-app wizard, and a connected database register `permits-db` with the tables `permits` and `applicants`
- **WHEN** they choose "Start from a database", pick `permits-db`, tick `permits` and `applicants`, and create the app `vergunningen`
- **THEN** the app opens with the menu entries "Permits" and "Applicants", each with a list page and a detail page showing that table's rows

#### Scenario: A register that is not a database is refused

- **GIVEN** a wizard payload whose `fromDatabase.register` is `publications`, which no database backs
- **WHEN** the wizard submits it to `POST /api/applications/wizard`
- **THEN** the response is an error naming `publications`, and no Application, version or register exists afterwards

### Requirement: Generated pages keep the database register (REQ-BQDB-004)

When a version's manifest is rewritten for its own register, a page whose
`config.register` is a register the Application declares in `dataRegisters`
SHALL keep that register and its `config.schema` unchanged. The same SHALL hold
for a page or widget written through the copilot plan path.

#### Scenario: The development version still reads the database

- **GIVEN** the app `vergunningen` made from `permits-db` with the versions development and production
- **WHEN** a maker opens the development version's `Permits` page
- **THEN** its config names register `permits-db` and schema `permits`, not `openbuild-vergunningen-development` or a prefixed schema

### Requirement: An administrator saves a SQL query as a table (REQ-BQDB-005)

An administrator SHALL be able to open a query editor from the schema list of an
app, pick a connected database, write one SQL query, run a preview that shows at
most 50 rows, and save the query as a named table in that database's register.
The query SHALL run in OpenRegister on the source's connection, never in
buildiq. A saved query table SHALL be read only, and pages SHALL bind to it like
any other table.

#### Scenario: An administrator previews and saves open permits per district

- **GIVEN** an administrator on the schema list of the app `vergunningen`, with `permits-db` connected
- **WHEN** they open "Add a query table", write `SELECT district, count(*) AS open FROM permits WHERE status = 'open' GROUP BY district`, click "Run preview", then save it as "Open permits per district"
- **THEN** the preview shows at most 50 rows with the columns `district` and `open`, and the page editor's schema list for `permits-db` now offers "Open permits per district"

### Requirement: Only a safe query from an administrator runs (REQ-BQDB-006)

The query editor SHALL only be shown to a Nextcloud administrator, and the
preview and save calls SHALL be refused server side for anyone else. A query that
is not a single read-only statement, or that runs past the time limit, SHALL be
refused, and the editor SHALL show the refusal without SQL fragments, connection
details or credentials. "Save as table" SHALL stay off until a preview succeeded.

#### Scenario: A write statement is refused

- **GIVEN** an administrator in the query editor for `permits-db`
- **WHEN** they write `DELETE FROM permits` and click "Run preview"
- **THEN** the editor says "Only a single read-only query can run." and no statement reaches the database

#### Scenario: A maker who is not an administrator cannot run a query

- **GIVEN** a maker with the editor role on `vergunningen` who is not a Nextcloud administrator
- **WHEN** they open the schema list, and separately post a query to the preview route
- **THEN** the list shows no "Add a query table" button, and the preview route answers 403
