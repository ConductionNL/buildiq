# openbuilt-application-register Specification

## Purpose
TBD - created by archiving change bootstrap-openbuilt. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBA-001 Application schema registered in OpenRegister

The system SHALL declare an `Application` schema in
`lib/Settings/openbuilt_register.json` under the `openbuilt` register
namespace. The schema SHALL define properties `uuid` (string,
UUID-format), `slug` (string, kebab-case pattern), `name` (string,
required), `description` (string, optional), `manifest` (object,
required — the manifest JSON blob), `version` (string, semver pattern,
required), and `status` (string, enum: `draft | published | archived`,
required, default `draft`). The schema SHALL be imported into
OpenRegister at app install / post-migration time via a repair step.

#### Scenario: Schema is available after install

- **WHEN** the OpenBuilt app is installed and its repair step runs
- **THEN** OpenRegister exposes the `openbuilt` register containing
  the `Application` schema
- **AND** the schema's properties match the declaration in
  `lib/Settings/openbuilt_register.json`

#### Scenario: Application object is created via OR REST

- **WHEN** a client POSTs a payload to OR's REST endpoint for the
  `openbuilt/application` namespace with a valid `manifest`, `slug`,
  `name`, `version`, and `status: draft`
- **THEN** OR persists the object, returns 201, and the returned
  object carries an OR-assigned `uuid` and the submitted fields

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

The `Application` schema SHALL declare its state machine via
`x-openregister-lifecycle` in
`lib/Settings/openbuilt_register.json`. The lifecycle SHALL define
three states (`draft`, `published`, `archived`) and the allowed
transitions: `draft → published`, `published → archived`,
`archived → draft` (re-open for editing). No service class (e.g.
`ApplicationLifecycleService`) SHALL be written; the lifecycle is the
canonical declarative example for this spec per ADR-031. Each
transition SHALL be recorded in OR's audit trail.

#### Scenario: Allowed transition succeeds with an audit entry

- **WHEN** an authorised user transitions a `draft` Application to
  `published` via the lifecycle endpoint
- **THEN** the object's `status` becomes `published`
- **AND** OR's audit trail records a `lifecycle.transition` event with
  the from-state, to-state, and actor identity

#### Scenario: Disallowed transition is rejected

- **WHEN** a client attempts to transition a `draft` Application
  directly to `archived` (a transition not declared in the lifecycle)
- **THEN** the system returns a 4xx error
- **AND** the object's `status` remains `draft`
- **AND** no audit entry is recorded

### Requirement: REQ-OBA-004 BuiltAppRoute index for slug lookup

The system SHALL declare a `BuiltAppRoute` schema in
`lib/Settings/openbuilt_register.json` with properties `slug` (string,
required, kebab-case pattern) and `applicationUuid` (string,
UUID-format, required). The `slug` property SHALL be unique within an
organisation. The repair step SHALL create or maintain a
`BuiltAppRoute` row for every published Application, keyed by its
slug, so that the runtime can resolve `slug → Application UUID` in a
single OR lookup without scanning every Application.

#### Scenario: Publishing an Application creates a BuiltAppRoute

- **WHEN** an Application with `slug: hello-world` transitions from
  `draft` to `published`
- **THEN** a `BuiltAppRoute` object exists with `slug: hello-world`
  and `applicationUuid` matching the Application's UUID

#### Scenario: Slug uniqueness is enforced per organisation

- **WHEN** a client attempts to publish a second Application with
  `slug: hello-world` in the same organisation
- **THEN** the system returns a 4xx error citing the slug conflict
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

### Requirement: REQ-OBA-006 Application schema carries a currentVersion reference

The `Application` schema declared in `lib/Settings/openbuilt_register.json` (REQ-OBA-001) SHALL be extended with a `currentVersion` property of type string with UUID-format. The property SHALL be optional (an Application that has never been published has no `currentVersion`). When populated, it SHALL hold the `uuid` of the most recent `ApplicationVersion` row for this Application (see capability `openbuilt-version-snapshots`, REQ-OBV-006). The schema change SHALL remain backward-compatible: existing Applications imported from spec #1 carry no `currentVersion` and SHALL continue to load, list, and edit without error.

#### Scenario: Existing Applications remain valid without currentVersion

- **WHEN** the OpenBuilt repair step runs an upgrade on an install
  that already has seeded Applications from spec #1
- **THEN** those Applications continue to load via OR REST
- **AND** their `currentVersion` field is absent or `null`
- **AND** the textarea editor renders them without validation
  errors

#### Scenario: currentVersion is updated atomically with the snapshot

- **WHEN** an Application transitions from `draft` to `published`
- **THEN** the same lifecycle action that creates the
  `ApplicationVersion` row also writes the new row's `uuid` into
  the Application's `currentVersion`
- **AND** both writes are observed by a subsequent OR REST GET of
  the Application

### Requirement: REQ-OBA-007 Draft-to-published transition declares a snapshot action

The `x-openregister-lifecycle` block on the `Application` schema (REQ-OBA-003) SHALL declare an `on_transition` action on the `draft → published` edge that creates a new `ApplicationVersion` row populated from the Application's current `manifest`, `version`, the actor's NC user id, and the transition timestamp; updates the Application's `currentVersion` to the new row's `uuid`; and sets the Application's `status` back to `draft` so that the next edit session continues from a draft state, while the just-created `ApplicationVersion` serves as the "published" record (see design.md Decision 3 for rationale).

If OR's lifecycle engine cannot yet express a sibling-object create action in `on_transition`, the action MAY be implemented as a single PHP listener subscribed to `ObjectLifecycleTransitionedEvent` per ADR-031 §Exceptions(1) — mirroring the OQ-1 escape hatch bootstrap-openbuilt established. The observed behaviour SHALL be identical in either case. The implementer SHALL NOT introduce a generic `VersioningService` / `SnapshotService` class.

#### Scenario: Declarative path emits the snapshot

- **WHEN** OR's engine supports `on_transition.create_relation` (or
  equivalent)
- **AND** an Application transitions from `draft` to `published`
- **THEN** a snapshot is created without any custom PHP listener
  being invoked
- **AND** the OR audit trail records both the transition and the
  snapshot create

#### Scenario: Listener fallback produces the same outcome

- **WHEN** OR's engine does not yet expose the sibling-create action
  and the fallback `ApplicationVersionSnapshotListener` is registered
- **AND** an Application transitions from `draft` to `published`
- **THEN** the listener creates the `ApplicationVersion` row,
  updates `currentVersion`, and resets the Application's `status`
  to `draft`
- **AND** the resulting Application + ApplicationVersion records
  are byte-equal (modulo `uuid` and timestamps) to the declarative
  path

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

