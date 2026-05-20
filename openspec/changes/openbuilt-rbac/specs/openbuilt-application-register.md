## Purpose

Delta requirements added to the `openbuilt-application-register`
capability by the `openbuilt-rbac` change. These requirements extend
the `Application` schema with the `permissions` block and provide the
idempotent migration that populates it for pre-existing Applications.

---

### Requirement: REQ-OBA-006 Application schema carries a permissions block

The `Application` schema in `lib/Settings/openbuilt_register.json`
SHALL declare an optional `permissions` property of type `object` with
the following shape:

```jsonc
"permissions": {
  "type": "object",
  "properties": {
    "owners":  { "type": "array", "items": { "type": "string" } },
    "editors": { "type": "array", "items": { "type": "string" } },
    "viewers": { "type": "array", "items": { "type": "string" } }
  },
  "required": ["owners", "editors", "viewers"],
  "additionalProperties": false
}
```

Each string element is a Nextcloud group ID (`gid`). The arrays MAY
be empty. The property itself is optional on the `Application` schema
so that pre-existing Application objects remain schema-valid during the
migration window before the repair step runs.

#### Scenario: Schema declaration round-trip

- **GIVEN** a fresh OpenBuilt install with the repair step applied
- **WHEN** a client POSTs an Application with
  `permissions = { owners: ["digitaal-team"], editors: ["redactie"], viewers: [] }`
  via OR REST
- **THEN** OR accepts the object and the GET response echoes back
  the exact `permissions` block without mutation

#### Scenario: Unknown sub-key in permissions is rejected

- **GIVEN** a fresh OpenBuilt install
- **WHEN** a client POSTs an Application with
  `permissions = { owners: ["digitaal-team"], admins: ["sysops"] }`
- **THEN** OR returns `4xx` (schema validation failure)
- **AND** no Application object is persisted

#### Scenario: Application without permissions remains schema-valid

- **GIVEN** a pre-existing Application object that has no `permissions`
  property (created before this change was deployed)
- **WHEN** the repair step has not yet been run
- **THEN** the Application object is still schema-valid (property is
  optional) and OR serves it normally

---

### Requirement: REQ-OBA-007 Migration populates permissions for pre-existing Applications

A repair step (`lib/Repair/PopulateApplicationPermissions.php`) SHALL
be registered as a `<post-migration>` step in `appinfo/info.xml`. It
SHALL iterate every `Application` object in the `openbuilt` register
whose `permissions` property is missing or whose `permissions.owners`
array is empty, and SHALL patch it to:

```jsonc
{ "owners": ["admin"], "editors": [], "viewers": [] }
```

The step SHALL be idempotent: re-running it MUST NOT modify
Applications that already have a non-empty `permissions.owners` array.
The step SHALL use `ObjectService::saveObject()` per ADR-001 (data
layer) and SHALL carry SPDX + EUPL-1.2 headers per ADR-014.

#### Scenario: Default permissions applied to pre-existing Application

- **GIVEN** an OpenBuilt instance with one Application that has no
  `permissions` property (seeded by spec #1's repair step)
- **WHEN** the `PopulateApplicationPermissions` repair step runs
- **THEN** that Application has
  `permissions = { owners: ["admin"], editors: [], viewers: [] }`
- **AND** the OR audit trail records the change with the repair-step
  actor identity and timestamp

#### Scenario: Repair step is idempotent

- **GIVEN** an OpenBuilt instance where the repair step has already
  run and all Applications have non-empty `permissions.owners`
- **WHEN** the repair step runs a second time
- **THEN** no Application's `permissions` is modified
- **AND** no new OR audit entries are written for the Applications
