## ADDED Requirements

### Requirement: REQ-OBA-006 Application schema carries a permissions block

The system SHALL extend the `Application` schema in `lib/Settings/openbuilt_register.json` with an optional `permissions` property of shape:

```json
{
  "permissions": {
    "type": "object",
    "properties": {
      "owners":  { "type": "array", "items": { "type": "string" } },
      "editors": { "type": "array", "items": { "type": "string" } },
      "viewers": { "type": "array", "items": { "type": "string" } }
    },
    "additionalProperties": false
  }
}
```

Each array element is a Nextcloud group ID (`gid`) string. The
property is optional in the schema so that legacy Applications
created by spec #1's repair step (the seeded `hello-world`
Application) remain schema-valid; a migration step (see
REQ-OBA-007) populates a default value for every existing
Application on apply. New Applications created after this spec
lands carry `permissions` from the moment of creation by virtue of
REQ-OBRBAC-001 in the `openbuilt-rbac` capability. The OpenBuilt
repair step that imports the register configuration SHALL update
the schema in place idempotently via
`ConfigurationService::importFromApp()` (memory rule). No new
schema is introduced; the `permissions` property is a declarative
addition to `Application` per ADR-031 (no service class).

#### Scenario: Schema declares the permissions property after install

- **WHEN** the OpenBuilt app is installed (or upgraded) and its
  repair step runs
- **THEN** the `Application` schema in the `openbuilt` register
  exposes the `permissions` property with the shape above
- **AND** the property is omittable (legacy Application objects
  without it remain schema-valid)

#### Scenario: Saving an Application with a permissions block round-trips

- **WHEN** a client PUTs an Application via OR REST with
  `permissions = { owners: ["team-alpha"], editors: ["qa-alpha"], viewers: [] }`
- **THEN** OR persists the object and a subsequent GET returns the
  same `permissions` block byte-for-byte

#### Scenario: Saving with extra properties is rejected

- **WHEN** a client PUTs an Application with
  `permissions = { owners: ["x"], admins: ["y"] }` (note the
  unknown `admins` key)
- **THEN** OR rejects the save with a 4xx citing the unknown
  property under `permissions`

### Requirement: REQ-OBA-007 Migration populates permissions for pre-existing Applications

The OpenBuilt repair step SHALL include an idempotent migration
that, for every existing `Application` object whose `permissions`
property is missing or null, populates `permissions.owners` with the
system organisation's `admin` group, and sets `editors` and
`viewers` to empty arrays. The migration SHALL skip any Application
that already has a non-empty `permissions.owners`. The seeded
`hello-world` Application from spec #1 (which has no `permissions`
field) is the canonical case the migration covers; after this
spec's apply phase, every Application in every installed instance
has a populated `permissions` field.

#### Scenario: Pre-existing Application receives a default permissions block

- **GIVEN** an existing Application with `slug: hello-world` and no
  `permissions` field (the spec #1 seed)
- **WHEN** this spec's repair step runs
- **THEN** the Application's `permissions.owners` contains the
  `admin` group of its organisation
- **AND** `permissions.editors = []` and `permissions.viewers = []`

#### Scenario: Migration is idempotent

- **WHEN** the migration runs a second time on an already-migrated
  install
- **THEN** no Application is changed
- **AND** no duplicate audit entries are produced
