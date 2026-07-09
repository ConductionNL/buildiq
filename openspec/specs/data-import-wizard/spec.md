# data-import-wizard Specification

## Purpose
TBD - created by archiving change openbuild-data-import-wizard. Update Purpose after archive.
## Requirements
### Requirement: A maker can seed a virtual app's data from an uploaded file

OpenBuild MUST offer a guided "Import data" wizard that lets a maker upload an
`xlsx`/`xls`/`csv`/`json` file and, from it, either populate an existing schema or create a
new schema from the file's header row plus its rows. The wizard MUST delegate ALL file
parsing, schema inference, and object creation to OpenRegister's existing register-import
capability (`POST /api/registers/{id}/import`, with the type auto-detected and objects
included) — OpenBuild MUST NOT parse files, infer schemas, or write objects itself (ADR-022,
one write path). The wizard MUST present a column-mapping / inferred-field preview with a
sample of the first rows before commit, and a result summary (created / updated / skipped
counts, per-row errors) with an Undo action backed by OpenRegister's import rollback.

#### Scenario: Uploading a spreadsheet creates a schema and rows via OpenRegister

- **GIVEN** a maker with a build/manage role on an Application
- **WHEN** they upload a CSV/XLSX in the Import-data wizard and choose "create schema from file"
- **THEN** OpenBuild MUST POST the file to OpenRegister's register import for the target register (no OpenBuild-side parsing)
- **AND** show the created/updated/skipped summary returned by OpenRegister, with an Undo (rollback) action

#### Scenario: Import into an existing schema offers a matching template

- **GIVEN** the maker chooses an existing schema as the import target
- **THEN** the wizard MUST offer a "download template" that calls OpenRegister's schema import-template endpoint so the columns match exactly

@e2e exclude the delegation + wizard steps are unit-tested against a stubbed OR import endpoint; a Playwright upload smoke follows once a live OR import fixture exists.

### Requirement: Imports are version-scoped and promotion-safe

The wizard MUST target only the **active `ApplicationVersion`'s own register**
(`openbuild-{slug}-{versionSlug}`), never a shared/bound data register (those are read-only
shared data and MUST be excluded from the import-target list). Imported rows MUST be
ordinary objects in that per-version register so they are captured by version snapshots and
carried/isolated by promotion exactly like hand-entered data — the wizard MUST NOT introduce
any new versioning or promotion surface.

#### Scenario: Import targets the active version's register only

- **WHEN** the maker opens the import-target picker
- **THEN** only schemas in the active version's own register MUST be selectable
- **AND** shared bound `dataRegisters` MUST NOT appear as import targets

@e2e exclude target-scoping asserted by the wizard's target-list unit test.

### Requirement: The import is authorised on both sides

The wizard MUST only be offered when the caller holds a build/manage role on the Application
(OpenBuild's existing permission gate), and the import itself MUST be independently
re-checked server-side by OpenRegister's own manage-permission gate on the target register,
so OpenBuild never becomes a permission-bypass path into OpenRegister data.

#### Scenario: A non-builder cannot import

- **GIVEN** a user without a build/manage role on the Application
- **THEN** the Import-data affordance MUST NOT be offered
- **AND** even if the underlying OR import endpoint is called directly, OpenRegister's manage-permission gate MUST independently reject it

@e2e exclude double-authorisation covered by the OpenBuild permission-gate unit test + OpenRegister's own import-auth test.

