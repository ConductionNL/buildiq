## ADDED Requirements

### Requirement: Bound data registers' schema definitions are bundled into every export

`ExportService::generateAppZip()` SHALL, for every `dataRegisters` binding the source Application declares, resolve the named register and write its schema definitions into the exported tree at `lib/Settings/data-registers/<register-slug>.schema.json` — one file per
binding, containing JSON Schema definitions only. This file SHALL NOT be
merged into the exported app's own `<app>_register.json` and SHALL NOT be
referenced by any `<repair-step>` in the exported `appinfo/info.xml` — it is
reference documentation of a register the exported app does not own,
consuming the same non-ownership contract `Application.dataRegisters`
already establishes for the running virtual app. An Application with no
`dataRegisters` SHALL produce an export tree with no
`lib/Settings/data-registers/` directory at all — this requirement is fully
additive and has no effect on an export that predates it.

#### Scenario: Export bundles schema defs for every declared binding

- **GIVEN** a published Application whose `dataRegisters` is
  `[{ "register": "spectr", "label": "Spectr market intelligence data" }]`
- **WHEN** the Application is exported (either target: `zip` or `github`)
- **THEN** the exported tree contains
  `lib/Settings/data-registers/spectr.schema.json` holding the `spectr`
  register's current schema definitions
- **AND** `lib/Settings/spectr_register.json` (an app-owned-looking filename)
  is NOT created — the file lives only under the dedicated
  `data-registers/` subdirectory

#### Scenario: Export with no bindings produces no data-registers directory

- **GIVEN** a published Application whose `dataRegisters` is absent or `[]`
- **WHEN** the Application is exported
- **THEN** the exported tree contains no `lib/Settings/data-registers/`
  directory

### Requirement: Per-binding includeData toggle controls data-register row-data inclusion

The `exportJob` schema SHALL gain an optional `dataRegisters` array property
(items shaped `{ register: string, includeData: boolean }`, default `[]`),
populated by `ExportJobService::queue()` from the submit request body —
mirroring the existing `includeSeedData` field's role as export-flow state
persisted on the async job record, not Application configuration. For each
binding whose `includeData` is `true`, `ExportService::generateAppZip()`
SHALL additionally write
`lib/Settings/data-registers/<register-slug>.seed-data.json` containing that
register's current row data as a reference fixture, alongside (never instead
of) that binding's schema-definitions file. A binding omitted from the
export request's `dataRegisters`, or present with `includeData: false`,
SHALL produce its schema-definitions file only — no row data SHALL be
written for it under any circumstance where `includeData` is not explicitly
`true`.

#### Scenario: includeData true bundles row data alongside the schema

- **GIVEN** a published Application bound to `spectr`, exported with request
  body `dataRegisters: [{ "register": "spectr", "includeData": true }]`
- **WHEN** the export completes
- **THEN** the exported tree contains both
  `lib/Settings/data-registers/spectr.schema.json` and
  `lib/Settings/data-registers/spectr.seed-data.json`
- **AND** neither file is referenced by any `<repair-step>` in the exported
  `appinfo/info.xml`

#### Scenario: includeData omitted defaults to schema-defs-only

- **GIVEN** the same Application, exported with a request body that omits
  `dataRegisters` entirely
- **WHEN** the export completes
- **THEN** the exported tree contains
  `lib/Settings/data-registers/spectr.schema.json`
- **AND** it does NOT contain `lib/Settings/data-registers/spectr.seed-data.json`
