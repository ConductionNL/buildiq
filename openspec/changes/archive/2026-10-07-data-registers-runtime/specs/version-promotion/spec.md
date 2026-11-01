## ADDED Requirements

### Requirement: Promotion never reads or writes Application.dataRegisters

`VersionPromotionService::promote()` and every private method it calls (`forwardSchemaSetToOR()`, `wipeTargetRegister()`, `copyRowsFromSource()`, `applyManifestAndSemver()`, `handlePromotionFailure()`) SHALL resolve their
source and target register exclusively via `ApplicationVersion.register` (the
per-version, app-owned register). None of these methods SHALL read, write, or
otherwise reference the parent Application's `dataRegisters` property, under
any of the three strategies (`start-with-source-data`,
`migrate-existing-data`, `empty-start`). Promoting a version SHALL therefore
neither copy, migrate, wipe, nor otherwise modify any row or schema in a
register named in `Application.dataRegisters` — a shared data register bound
to the app is invisible to the promotion flow in both directions.

**ID:** REQ-OBVP-012

#### Scenario: start-with-source-data leaves a bound data register untouched

- **GIVEN** an Application whose `dataRegisters` includes
  `{ "register": "spectr" }`, and a source ApplicationVersion whose
  `promotesTo` target has 3 pre-existing rows in its own per-version register
- **WHEN** an owner promotes with `strategy: "start-with-source-data"`
- **THEN** the target's per-version register is wiped and repopulated from
  the source's per-version register, exactly as REQ-OBVP-002 already
  specifies
- **AND** no read, write, lock, or delete operation is issued against the
  `spectr` register at any point during the promotion

#### Scenario: migrate-existing-data leaves a bound data register untouched

- **GIVEN** the same Application as above, promoting with
  `strategy: "migrate-existing-data"`
- **WHEN** the promotion completes
- **THEN** the target's per-version register schema set is aligned with the
  source's, exactly as REQ-OBVP-003 already specifies
- **AND** no operation of any kind touches the `spectr` register

#### Scenario: empty-start leaves a bound data register untouched

- **GIVEN** the same Application as above, promoting with
  `strategy: "empty-start"`
- **WHEN** the promotion completes
- **THEN** the target's per-version register is wiped and left schema-only,
  exactly as REQ-OBVP-004 already specifies
- **AND** no operation of any kind touches the `spectr` register

#### Scenario: A promotion failure does not archive or otherwise modify a bound data register

- **GIVEN** an Application with a `dataRegisters` binding, whose promotion
  fails mid-strategy (per REQ-OBVP-009)
- **WHEN** `handlePromotionFailure()` flips the target ApplicationVersion's
  `status` to `archived`
- **THEN** only the target ApplicationVersion row is modified
- **AND** the bound data register (and every object inside it) is unmodified
