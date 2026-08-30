## ADDED Requirements

### Requirement: Application schema carries GitHub linkage fields

The `Application` schema in `lib/Settings/openbuild_register.json` SHALL be
extended with two optional, additive top-level properties that record the app's
GitHub home:

- `githubRepo` — object `{ owner: string, name: string }` naming the GitHub
  repository the app is linked to (its round-trip home). Optional.
- `githubDefaultBranch` — string, the branch that push/pull operations target
  (resolved from the repo's default branch at link time, admin-overridable).
  Optional.

Both properties SHALL be omittable so that every existing `Application` object
(including the seeded `hello-world` app) remains schema-valid without a data
migration. The properties are purely declarative (stored data, no state machine,
no calculation) per ADR-031 — no service class is introduced by this requirement.
They are set by the owner round-trip capability (`github-app-sync`) and read by
the shop and sync surfaces; a runtime that never touches GitHub never populates
them. The schema SHALL be re-imported via the existing repair step
(`ConfigurationService::importFromApp()`), gated by an `info.xml` + schema-version
bump per the OpenRegister version-gate rule.

**ID:** REQ-OBA-010

#### Scenario: Schema declares the GitHub linkage fields after install

- **WHEN** the Buildiq repair step runs (fresh install or upgrade)
- **THEN** the `Application` schema in the `buildiq` register exposes optional
  `githubRepo` (`{ owner, name }`) and `githubDefaultBranch` properties
- **AND** an Application object that omits both remains schema-valid

#### Scenario: Saving an Application with a githubRepo round-trips

- **WHEN** a client saves an Application with
  `githubRepo = { owner: "conduction", name: "permit-tracker" }` and
  `githubDefaultBranch = "main"`
- **THEN** OR persists the object and a subsequent GET returns the same
  `githubRepo` and `githubDefaultBranch` values

#### Scenario: Legacy Application without linkage fields stays valid

- **GIVEN** an existing Application (e.g. the seeded `hello-world`) with no
  `githubRepo` or `githubDefaultBranch`
- **WHEN** this change's repair step re-imports the schema
- **THEN** the Application remains schema-valid and unmodified (the fields are
  omittable; no migration back-fills them)
