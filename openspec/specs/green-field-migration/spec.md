# green-field-migration Specification

## Purpose
TBD - created by archiving change openbuilt-versioning-model. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBGFM-001 Destructive migration repair step

The system SHALL ship a Nextcloud `\\OCP\\Migration\\IRepairStep` implementation at
`lib/Repair/MigrateToVersionedModel.php`. The repair step SHALL be registered in
`appinfo/info.xml` under `<repair-steps><post-migration>` so that it runs on every
install and every upgrade. The step SHALL perform a destructive green-field
migration: for every pre-migration `Application` row in the `openbuilt` register, it
SHALL drop the corresponding per-app register (named `openbuilt-{slug}`) entirely
(removing every object inside it) and then delete the `Application` row itself.

The destructive behaviour is intentional. ADR-002 records that existing OpenBuilt
installs hold only test data and that the new versioning model re-seeds Hello World
at install time via the creation-wizard capability (out of scope for this spec).

#### Scenario: Migration drops a single pre-migration Application

- **GIVEN** a pre-migration install with one Application row (`slug: <slug>`,
  `currentVersion: 00000000-0000-0000-0000-000000000000`) and its per-app register
  `openbuilt-<slug>`
- **WHEN** the OpenBuilt app's post-migration repair step runs
- **THEN** the Application row no longer exists in the `openbuilt` register
- **AND** the per-app register `openbuilt-<slug>` no longer exists
- **AND** every object that lived in that register is gone

#### Scenario: Migration drops multiple pre-migration Applications

- **GIVEN** a pre-migration install with three Application rows and their three
  per-app registers
- **WHEN** the OpenBuilt app's post-migration repair step runs
- **THEN** all three Application rows are gone
- **AND** all three per-app registers are gone

### Requirement: REQ-OBGFM-002 Migration is idempotent via versioned-shape short-circuit

The repair step SHALL be safe to re-run. On every invocation, it SHALL first detect
whether the OpenBuilt schema is already in versioned shape and SHALL short-circuit
to a no-op when it is. The detection SHALL fire on either of:

- The `applicationVersion` schema exists in the `openbuilt` register, OR
- No pre-migration `Application` row in the `openbuilt` register carries a
  `currentVersion` field (i.e. all surviving rows already match the new shape).

A short-circuit run SHALL produce no log output beyond a single info line indicating
the no-op (e.g. `Migrated-to-versioned-model: schema already in versioned shape,
skipping`).

#### Scenario: Already-versioned install is a no-op

- **GIVEN** an install whose `openbuilt` register exposes the `applicationVersion`
  schema and contains zero pre-migration Application rows
- **WHEN** the repair step runs
- **THEN** no register is dropped
- **AND** no Application row is deleted
- **AND** the step logs a single short-circuit info line

#### Scenario: Repair step is re-runnable

- **GIVEN** the repair step has already run once successfully on a pre-migration
  install and dropped two Applications + registers
- **WHEN** the repair step runs again (e.g. on the next OCC upgrade)
- **THEN** the run is a no-op via the short-circuit guard
- **AND** the surviving data is unchanged

### Requirement: REQ-OBGFM-003 One log line per deleted Application

The repair step SHALL emit exactly one `$output->info()` log line per deleted
Application, with the literal format:

```
Migrated-to-versioned-model: dropped Application '<slug>' and register 'openbuilt-<slug>'
```

where `<slug>` is the deleted Application's `slug` value. The line SHALL surface in
standard OCC upgrade output so the migration is observable during deployment.

#### Scenario: Each deletion is logged individually

- **GIVEN** a pre-migration install with three Applications whose slugs are
  `<slug-a>`, `<slug-b>`, `<slug-c>`
- **WHEN** the repair step runs
- **THEN** the output contains exactly one line
  `Migrated-to-versioned-model: dropped Application '<slug-a>' and register
  'openbuilt-<slug-a>'`
- **AND** one line for `<slug-b>`
- **AND** one line for `<slug-c>`

### Requirement: REQ-OBGFM-004 Per-app register deletion uses OR's register-delete API

The repair step SHALL drop each per-app register via OpenRegister's
register-delete API (consume the existing OR abstraction per ADR-022 — do not
delete OR-backed tables directly). The deletion SHALL be transactional from the
caller's perspective: if OR's API returns failure for a given register, the
repair step SHALL log the failure, SHALL NOT delete the corresponding Application
row, and SHALL continue with the next Application in the enumeration. The
operator is expected to inspect the OCC log and retry on the next upgrade.

#### Scenario: Register-delete failure is logged and the Application row is preserved

- **GIVEN** a pre-migration install where dropping the register `openbuilt-<slug>`
  fails (e.g. OR returns 500)
- **WHEN** the repair step runs
- **THEN** the failure is logged with the slug and an error message
- **AND** the Application row for `<slug>` is NOT deleted
- **AND** the repair step continues with the next pre-migration Application

