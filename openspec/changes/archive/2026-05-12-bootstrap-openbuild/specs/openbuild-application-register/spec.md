## ADDED Requirements

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

The `manifest` property of every `Application` object SHALL validate
against the canonical app-manifest schema at
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
(v1.4.0 or later). The system SHALL reject save operations whose
`manifest` blob fails schema validation, returning a 4xx response that
names the failing JSON path. Validation runs both at save time
(server-side via the seeded JSON-schema reference in the OR schema's
`manifest` property) and pre-save in the textarea editor (client-side
via the `validateManifest` utility re-exported from
`@conduction/nextcloud-vue`).

#### Scenario: Save rejects a structurally invalid manifest

- **WHEN** a client attempts to save an Application whose `manifest`
  blob omits the required `pages` array
- **THEN** the system returns a 4xx error citing the missing field
- **AND** no Application object is persisted

#### Scenario: Save accepts a minimal valid manifest

- **WHEN** a client saves an Application whose `manifest` validates
  against the canonical schema (has `version`, `menu`, `pages`)
- **THEN** the system persists the object and returns the saved
  representation

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
