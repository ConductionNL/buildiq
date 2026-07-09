## ADDED Requirements

### Requirement: ApplicationVersion carries GitHub provenance fields

The `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` SHALL be
extended with two optional, additive properties that record the exact GitHub
provenance of a version:

- `commitSha` — string, the exact Git commit a version was pushed to (on publish
  to GitHub) or pulled from (on import). Optional.
- `sourceRef` — string, the branch/tag/ref a *pulled* version was imported from
  (e.g. `refs/heads/main` or a tag name). Optional.

Both properties SHALL be omittable so that every existing `ApplicationVersion`
object remains schema-valid without a data migration, and SHALL be absent on
versions with no GitHub provenance. The properties are purely declarative per
ADR-031 (stored provenance metadata, no state machine, no calculation) and SHALL
NOT alter the existing lifecycle (REQ-OBV-106) or the manifest-content semver
auto-bump (REQ-OBV-103) — a `commitSha` / `sourceRef` write is metadata and SHALL
NOT by itself bump `semver`. The schema SHALL be re-imported via
`ConfigurationService::importFromApp()`, gated by an `info.xml` + schema-version
bump per the OpenRegister version-gate rule.

**ID:** REQ-OBV-112

#### Scenario: Schema declares the provenance fields after install

- **WHEN** the OpenBuild repair step runs (fresh install or upgrade)
- **THEN** the `ApplicationVersion` schema exposes optional `commitSha` and
  `sourceRef` properties
- **AND** an ApplicationVersion object that omits both remains schema-valid

#### Scenario: Saving provenance fields round-trips without bumping semver

- **GIVEN** an ApplicationVersion with `semver: 0.3.0` and an unchanged `manifest`
- **WHEN** a client saves the row with only `commitSha` and `sourceRef` set (the
  `manifest` blob is byte-identical to the previously-saved one)
- **THEN** OR persists the `commitSha` and `sourceRef` values
- **AND** the persisted `semver` remains `0.3.0` (provenance metadata does not
  trigger the manifest auto-bump)

#### Scenario: Legacy ApplicationVersion without provenance stays valid

- **GIVEN** an existing ApplicationVersion with no `commitSha` or `sourceRef`
- **WHEN** this change's repair step re-imports the schema
- **THEN** the version remains schema-valid and unmodified (the fields are
  omittable; no migration back-fills them)
