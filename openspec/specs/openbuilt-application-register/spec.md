# openbuilt-application-register Specification

## Purpose
TBD - created by archiving change bootstrap-openbuilt. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBA-001 Application schema registered in OpenRegister

The system SHALL declare an `Application` schema in
`lib/Settings/openbuilt_register.json` under the `openbuilt` register namespace.
Under the versioned model (ADR-002) the Application schema SHALL define the following
top-level properties: `uuid` (string, UUID-format), `slug` (string, kebab-case
pattern), `name` (string, required), `description` (string, optional), `permissions`
(object, optional — RBAC block per REQ-OBA-006), and `productionVersion` (relation
→ ApplicationVersion, optional — names which ApplicationVersion end users see at the
canonical URL).

The Application schema SHALL NOT define `manifest`, `version`, `status`, or
`currentVersion` — those properties move to the new `ApplicationVersion` schema or
disappear entirely (`currentVersion` is retired per ADR-002 §Decision). The schema
SHALL be imported into OpenRegister at app install / post-migration time via the
existing repair step.

#### Scenario: Schema is available after install

- **WHEN** the OpenBuilt app is installed and its repair step runs
- **THEN** OpenRegister exposes the `openbuilt` register containing the
  `Application` schema with the versioned-model property set above
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

The `manifest` property of every `Application` object SHALL validate against the canonical
app-manifest schema at `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
(v1.4.0 or later). The system SHALL reject save operations whose `manifest` blob fails schema
validation, returning a 4xx response that identifies the failing property path.

The `Application` schema SHALL additionally declare two optional top-level properties —
`icon` and `iconDark` — each of shape `{ "ref": "<filename>" }` referencing an OR-attached
SVG file on the Application record (per ADR-001). These properties live as siblings to
`slug`, `name`, `manifest`, `version`, and `permissions` on the `application` schema in
`lib/Settings/openbuilt_register.json`. They are deliberately outside the `manifest` object
because they are openbuilt-side admin metadata about the virtual app, not part of the
manifest blob the citizen developer designs and the runtime serves to `CnAppRoot` — so this
spec does not touch `app-manifest.schema.json` and carries no upstream coupling.

#### Scenario: Application with valid top-level icon fields is accepted

- **WHEN** a client saves an Application carrying
  `"icon": { "ref": "app-icon.svg" }` and `"iconDark": { "ref": "app-icon-dark.svg" }` at the
  top level (sibling to `manifest`)
- **THEN** OR persists the object and returns 2xx with no validation error

#### Scenario: Application without icon fields is still accepted

- **WHEN** a client saves an Application that omits both `icon` and `iconDark`
- **THEN** OR persists the object and returns 2xx — the fields are optional

#### Scenario: Top-level `icon` field with wrong shape is rejected

- **WHEN** a client saves an Application carrying `"icon": "inline-data-url"`
  (a string instead of `{ ref: ... }`)
- **THEN** OR returns a 4xx validation error identifying `icon` as the failing path

#### Scenario: `icon` inside the manifest object is ignored

- **WHEN** a client saves an Application whose `manifest` blob contains an `icon` key
  (e.g. `"manifest": { "version": "1.0.0", "menu": [...], "pages": [...], "icon": {...} }`)
- **THEN** OR accepts the save (the manifest's `additionalProperties` posture is opaque to
  this spec), but the openbuilt icon-serving endpoint and nav-entry rendering SHALL ignore
  the value — only the top-level `icon` / `iconDark` fields drive icon resolution

#### Scenario: Application object is created via OR REST (unchanged from prior revision)

- **WHEN** a client POSTs a payload to OR's REST endpoint for the `openbuilt/application`
  namespace with a valid `manifest`, `slug`, `name`, `version`, and `status: draft`
- **THEN** OR persists the object, returns 201, and the returned object carries an OR-assigned
  `uuid` and the submitted fields

### Requirement: REQ-OBA-003 Declarative lifecycle drives state transitions

Under the versioned model, the `Application` schema SHALL NOT declare a
`status`-based state machine in `x-openregister-lifecycle` — lifecycle is per-version
now. The Application's previous `draft | published | archived` lifecycle SHALL
relocate to the `ApplicationVersion` schema (see capability
`application-versions`, REQ-OBV-106).

The Application schema MAY retain `x-openregister-lifecycle` only for any cross-row
hooks (e.g. integrity guards) — it SHALL NOT carry a `states` block or `transitions`
in v1 of this change. No `ApplicationLifecycleService` SHALL be written.

#### Scenario: Application has no status state machine

- **WHEN** the OpenBuilt repair step runs and imports the Application schema
- **THEN** the imported schema does not expose a `status` enum
- **AND** the imported schema's `x-openregister-lifecycle` carries no `states` or
  `transitions` block

### Requirement: REQ-OBA-004 BuiltAppRoute index for slug lookup

The system SHALL declare a `BuiltAppRoute` schema in
`lib/Settings/openbuilt_register.json` with properties `slug` (string, required,
kebab-case pattern) and `applicationUuid` (string, UUID-format, required). The `slug`
property SHALL be unique within an organisation. The `BuiltAppRoute` row SHALL be
created or updated by the `on_transition` action that fires when an
`ApplicationVersion` transitions from `draft` to `published` (the action is declared
on `ApplicationVersion`'s lifecycle — see `application-versions`/REQ-OBV-106, not on
`Application`'s). The `applicationUuid` field on the route record points at the
parent Application (i.e. `ApplicationVersion.application.uuid`).

#### Scenario: Publishing the first version creates a BuiltAppRoute

- **WHEN** an Application with `slug: hello-world` has its first ApplicationVersion
  transition from `draft` to `published`
- **THEN** a `BuiltAppRoute` object exists with `slug: hello-world` and
  `applicationUuid` matching the Application's `uuid`

#### Scenario: Slug uniqueness is enforced per organisation

- **WHEN** an admin attempts to create a second Application with `slug: hello-world`
  in the same organisation
- **THEN** OR returns a 4xx error citing the slug conflict
- **AND** no second `BuiltAppRoute` is created

### Requirement: REQ-OBA-005 Multi-tenant scoping via OR organisation

Every `Application` and `BuiltAppRoute` object SHALL inherit
OpenRegister's `organisation` field for multi-tenant scoping. List,
read, write, and lifecycle operations SHALL only return / accept
objects in the caller's organisation scope, enforced by OR's existing
authorization layer (ADR-022 — no app-local RBAC duplication).

#### Scenario: Cross-organisation reads are blocked

- **WHEN** a user in organisation A requests Applications owned by
  organisation B
- **THEN** OR returns an empty list (or a 403, per its standard
  contract) — the cross-org objects are not visible

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

### Requirement: REQ-OBA-008 Application carries a productionVersion relation

The `Application` schema SHALL be extended with a `productionVersion` property of
type relation (OR's first-class relation type — not a raw UUID string per ADR-002
§Decision). The relation SHALL point at exactly one `ApplicationVersion` row.
The property SHALL be optional (an Application that has not yet had its production
version chosen — e.g. immediately after creation, before the creation wizard
finishes — carries no `productionVersion`).

When populated, `productionVersion` SHALL satisfy the integrity guard in
`application-versions`/REQ-OBV-105: the referenced ApplicationVersion's
`application` relation MUST point back at this Application. Mismatched pointers
SHALL be rejected with a 422 response.

#### Scenario: Schema declares productionVersion as an optional relation

- **WHEN** the OpenBuilt repair step runs and imports the Application schema
- **THEN** the imported schema exposes `productionVersion` as a relation property
  referencing `applicationVersion`
- **AND** the property is omittable

#### Scenario: Pointing at a foreign ApplicationVersion is rejected

- **GIVEN** an Application X and an ApplicationVersion V whose `application`
  relation points at a different Application Y
- **WHEN** a client saves `X.productionVersion = V`
- **THEN** the response is `422` citing the back-reference mismatch
- **AND** X's `productionVersion` is unchanged

