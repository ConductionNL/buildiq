## MODIFIED Requirements

### Requirement: REQ-OBA-001 Application schema registered in OpenRegister

The system SHALL declare an `Application` schema in
`lib/Settings/openbuilt_register.json` under the `openbuilt` register namespace.
Under the ADR-002 versioned model the Application schema SHALL define the following
top-level properties: `uuid` (string, UUID-format), `slug` (string, required,
kebab-case pattern, max 48 characters), `name` (string, required), `description`
(string, optional), `permissions` (object, optional — RBAC block per REQ-OBA-006),
and `productionVersion` (relation → ApplicationVersion, optional — names which
ApplicationVersion end users see at the canonical URL).

The Application schema SHALL NOT define `manifest`, `version`, `status`, or
`currentVersion`. Those properties were removed by `openbuilt-versioning-model` per
ADR-002 and live on `ApplicationVersion` (see capability `application-versions`).
The schema SHALL be imported into OpenRegister at app install / post-migration time
via a repair step using `ConfigurationService::importFromApp()`.

#### Scenario: Schema is available after install

- **WHEN** the OpenBuilt app is installed and its repair step runs
- **THEN** OpenRegister exposes the `openbuilt` register containing the
  `Application` schema with the versioned-model property set: `uuid`, `slug`,
  `name`, `description`, `permissions`, `productionVersion`
- **AND** the schema does NOT expose `manifest`, `version`, `status`, or
  `currentVersion` properties
- **AND** the schema's properties match the declaration in
  `lib/Settings/openbuilt_register.json`

#### Scenario: Application object is created via OR REST

- **WHEN** a client POSTs a payload to OR's REST endpoint for the
  `openbuilt/application` namespace with valid `slug`, `name`, and `permissions`
- **THEN** OR persists the object, returns 201, and the returned object carries an
  OR-assigned `uuid` and the submitted fields
- **AND** the returned object has no `manifest`, `version`, `status`, or
  `currentVersion` field

### Requirement: REQ-OBA-002 Manifest blob is structurally valid

The `manifest` property is owned by the `ApplicationVersion` schema (not by
`Application`). The Application schema SHALL NOT declare a `manifest` property.
The manifest validation contract — requiring conformance to
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` v1.4.0 or later —
applies to every `ApplicationVersion` object via the `ApplicationVersion` schema's
`manifest` property declaration and its referenced JSON-schema validator (see
capability `application-versions`).

The system SHALL reject save operations on `ApplicationVersion` whose `manifest`
blob fails schema validation, returning a 4xx response that names the failing JSON
path. Client-side pre-save validation via the `validateManifest` utility re-exported
from `@conduction/nextcloud-vue` is the frontend counterpart.

#### Scenario: Save rejects a structurally invalid manifest on ApplicationVersion

- **WHEN** a client attempts to save an ApplicationVersion whose `manifest`
  blob omits the required `pages` array
- **THEN** the system returns a 4xx error citing the missing field
- **AND** no ApplicationVersion object is persisted

#### Scenario: Save accepts a minimal valid manifest on ApplicationVersion

- **WHEN** a client saves an ApplicationVersion whose `manifest` validates
  against the canonical schema (has `version`, `menu`, `pages`)
- **THEN** the system persists the object and returns the saved representation

### Requirement: REQ-OBA-003 Declarative lifecycle drives state transitions

Under the versioned model (ADR-002), the `Application` schema SHALL NOT declare a
`status`-based state machine in `x-openregister-lifecycle`. The
`draft | published | archived` lifecycle has been relocated to the
`ApplicationVersion` schema (see capability `application-versions`).

The Application schema MAY retain `x-openregister-lifecycle` only for cross-row
integrity guards (e.g. the `productionVersion` back-reference check per REQ-OBA-008).
It SHALL NOT carry a `states` block or `transitions` in its `x-openregister-lifecycle`.
No `ApplicationLifecycleService` SHALL be written.

Each transition on `ApplicationVersion` (including the `BuiltAppRoute` upsert action
on `draft → published`) is recorded in OR's audit trail automatically.

#### Scenario: Application has no status state machine after install

- **WHEN** the OpenBuilt repair step runs and imports the `Application` schema
- **THEN** the imported schema does not expose a `status` enum
- **AND** the imported schema's `x-openregister-lifecycle` carries no `states` or
  `transitions` block (only optional integrity guards are permitted)

#### Scenario: ApplicationVersion lifecycle drives BuiltAppRoute upkeep

- **WHEN** an `ApplicationVersion` transitions from `draft` to `published`
- **THEN** the lifecycle action declared on `ApplicationVersion` creates or updates
  the corresponding `BuiltAppRoute` row (per REQ-OBA-004)
- **AND** OR's audit trail records the `lifecycle.transition` event with the
  from-state, to-state, and actor identity

#### Scenario: Disallowed transition on ApplicationVersion is rejected

- **WHEN** a client attempts to transition a `draft` ApplicationVersion directly
  to `archived` (a transition not declared in ApplicationVersion's lifecycle)
- **THEN** the system returns a 4xx error
- **AND** the object's `status` remains `draft`
- **AND** no audit entry is recorded

### Requirement: REQ-OBA-004 BuiltAppRoute index for slug lookup

The system SHALL declare a `BuiltAppRoute` schema in
`lib/Settings/openbuilt_register.json` with properties `slug` (string, required,
kebab-case pattern) and `applicationUuid` (string, UUID-format, required). The
`slug` property SHALL be unique within an organisation, enforced via
`x-openregister-unique` on `BuiltAppRoute.slug` scoped to the `organisation` field.

The `BuiltAppRoute` row SHALL be created or updated by the `on_transition` action
declared on `ApplicationVersion.x-openregister-lifecycle` when an ApplicationVersion
transitions from `draft` to `published`. The `applicationUuid` field SHALL hold the
UUID of the parent `Application` (resolved from `ApplicationVersion.application`),
so that the runtime can resolve `slug → Application UUID` in a single OR lookup.

The repair step SHALL ensure at least the seed BuiltAppRoute objects exist on fresh
install (see design.md Seed Data).

#### Scenario: Publishing the first ApplicationVersion creates a BuiltAppRoute

- **WHEN** an Application with `slug: hello-world` has its first ApplicationVersion
  transition from `draft` to `published`
- **THEN** a `BuiltAppRoute` object exists with `slug: hello-world` and
  `applicationUuid` matching the parent Application's `uuid`

#### Scenario: Slug uniqueness is enforced per organisation

- **WHEN** an admin attempts to create a second Application with
  `slug: hello-world` in the same organisation
- **THEN** OR returns a 4xx error citing the slug conflict on BuiltAppRoute
- **AND** no second `BuiltAppRoute` is created

### Requirement: REQ-OBA-005 Multi-tenant scoping via OR organisation

Every `Application` and `BuiltAppRoute` object SHALL inherit OpenRegister's
`organisation` field for multi-tenant scoping. List, read, write, and lifecycle
operations SHALL only return / accept objects in the caller's organisation scope,
enforced by OR's existing authorization layer (ADR-022 — no app-local RBAC
duplication for organisation scoping).

#### Scenario: Cross-organisation reads are blocked

- **WHEN** a user in organisation A requests Applications owned by organisation B
- **THEN** OR returns an empty list (or a 403, per its standard contract) — the
  cross-org objects are not visible

### Requirement: REQ-OBA-006 Application schema carries a permissions block

The system SHALL extend the `Application` schema in
`lib/Settings/openbuilt_register.json` with an optional `permissions` property of
shape:

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

Each array element is a Nextcloud group ID (`gid`) string. The property is optional
in the schema so that legacy Applications (e.g. the seeded `hello-world` Application
from a spec #1 install) remain schema-valid; the migration step (REQ-OBA-007)
populates a default value for every existing Application on apply. New Applications
created after this spec lands carry `permissions` from the moment of creation via the
default-on-create path (caller's primary group → `owners`; others empty).

The OpenBuilt repair step that imports the register configuration SHALL update the
schema in place idempotently via `ConfigurationService::importFromApp()`. No new
schema is introduced; the `permissions` property is a declarative addition to
`Application` per ADR-031 (no service class).

#### Scenario: Schema declares the permissions property after install

- **WHEN** the OpenBuilt app is installed (or upgraded) and its repair step runs
- **THEN** the `Application` schema in the `openbuilt` register exposes the
  `permissions` property with the shape above
- **AND** the property is omittable (legacy Application objects without it remain
  schema-valid)

#### Scenario: Saving an Application with a permissions block round-trips

- **WHEN** a client PUTs an Application via OR REST with
  `permissions = { owners: ["team-vergunningen"], editors: ["team-buurtbeheer"], viewers: [] }`
- **THEN** OR persists the object and a subsequent GET returns the same `permissions`
  block byte-for-byte

#### Scenario: Saving with extra properties is rejected

- **WHEN** a client PUTs an Application with
  `permissions = { owners: ["x"], admins: ["y"] }` (note the unknown `admins` key)
- **THEN** OR rejects the save with a 4xx citing the unknown property under
  `permissions`

### Requirement: REQ-OBA-007 Migration populates permissions for pre-existing Applications

The OpenBuilt repair step SHALL include an idempotent migration
(`lib/Repair/PopulateApplicationPermissions.php`) that, for every existing
`Application` object whose `permissions` property is missing or null, populates
`permissions.owners` with `["admin"]` and sets `editors` and `viewers` to empty
arrays. The migration SHALL skip any Application that already has a non-empty
`permissions.owners`. The seeded `hello-world` Application from a spec #1 install
(which has no `permissions` field) is the canonical case the migration covers; after
this spec's apply phase, every Application in every installed instance has a
populated `permissions` field.

The repair step uses `ObjectService::saveObject($entityOrArray)` (first argument is
entity/array, not a type string — memory rule). It is registered in `appinfo/info.xml`
as a `<post-migration>` step after `InitializeSettings`. Idempotency is guaranteed
by the `permissions.owners`-non-empty check before each patch.

#### Scenario: Pre-existing Application receives a default permissions block

- **GIVEN** an existing Application with `slug: hello-world` and no
  `permissions` field (installed from spec #1)
- **WHEN** this spec's repair step runs
- **THEN** the Application's `permissions.owners` contains `["admin"]`
- **AND** `permissions.editors = []` and `permissions.viewers = []`

#### Scenario: Migration is idempotent

- **WHEN** the migration runs a second time on an already-migrated install
- **THEN** no Application is changed
- **AND** no duplicate audit entries are produced

#### Scenario: Application with existing permissions is skipped

- **GIVEN** an Application with `permissions.owners = ["team-vergunningen"]`
- **WHEN** the migration repair step runs
- **THEN** the Application's `permissions.owners` remains `["team-vergunningen"]`
  unchanged

## ADDED Requirements

### Requirement: REQ-OBA-008 Application carries a productionVersion relation

The `Application` schema SHALL be extended with a `productionVersion` property of
type relation (OR's first-class relation type — not a raw UUID string per ADR-002
§Decision). The relation SHALL point at exactly one `ApplicationVersion` row.
The property SHALL be optional (an Application that has not yet had its production
version designated — e.g. immediately after creation, before the creation wizard
assigns one — carries no `productionVersion`).

When populated, `productionVersion` SHALL satisfy the integrity guard described in
capability `application-versions` (REQ-OBV-105): the referenced ApplicationVersion's
`application` relation MUST point back at this Application. Mismatched pointers
SHALL be rejected with a 422 response.

#### Scenario: Schema declares productionVersion as an optional relation

- **WHEN** the OpenBuilt repair step runs and imports the Application schema
- **THEN** the imported schema exposes `productionVersion` as a relation property
  referencing `applicationVersion`
- **AND** the property is omittable (an Application without a `productionVersion`
  set is valid)

#### Scenario: Pointing at a foreign ApplicationVersion is rejected

- **GIVEN** an Application X and an ApplicationVersion V whose `application`
  relation points at a different Application Y
- **WHEN** a client saves `X.productionVersion = V`
- **THEN** the response is `422` citing the back-reference mismatch
- **AND** X's `productionVersion` is unchanged

## REMOVED Requirements

### Requirement: REQ-OBA-006 Application schema carries a currentVersion reference

**Reason**: The `currentVersion` field is retired under the versioned model (ADR-002).
"Which version is live" is now answered by the explicit `productionVersion` relation
on `Application` (REQ-OBA-008), not by a denormalised UUID string cache maintained
by a writeback listener.

**Migration**: The `openbuilt-versioning-model` spec (archived 2026-05-17) removed
`currentVersion` from the `Application` schema and deleted
`lib/Listener/ApplicationVersionSnapshotListener.php`. Consumers that previously
read `Application.currentVersion` MUST switch to reading `Application.productionVersion`
(a relation to an `ApplicationVersion`, not a UUID string) and dereference it via the
OR relation API.

### Requirement: REQ-OBA-007 Draft-to-published transition declares a snapshot action

**Reason**: The snapshot-on-publish writeback model is retired under ADR-002.
OR's object time-travel on the `ApplicationVersion` row serves the audit-history use
case — no additional snapshot rows are spawned on each publish. The declarative
`on_transition` snapshot action that performed
`create_relation(ApplicationVersion) + self.currentVersion = @result.uuid` and its
`ApplicationVersionSnapshotListener` PHP fallback are both removed.

**Migration**: The `openbuilt-versioning-model` spec deleted
`lib/Listener/ApplicationVersionSnapshotListener.php` and removed the snapshot
`on_transition` action from `Application.x-openregister-lifecycle`. This requirement
is archived; its content is superseded by REQ-OBA-008 and by the `application-versions`
capability.
