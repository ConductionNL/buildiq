## MODIFIED Requirements

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

## ADDED Requirements

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

## REMOVED Requirements

### Requirement: REQ-OBA-006 Application schema carries a currentVersion reference

**Reason**: The `currentVersion` field is retired under the versioned model
(ADR-002). "Which version is live" is now an explicit relation pointer
(`Application.productionVersion` — see REQ-OBA-008), not a denormalised cache
populated by a writeback listener.

**Migration**: The green-field migration (capability `green-field-migration`)
wipes pre-migration Application rows entirely; no carry-forward of
`currentVersion` is required. Consumers that previously read
`Application.currentVersion` MUST switch to reading
`Application.productionVersion` (a relation to an `ApplicationVersion`, not a UUID
string) and dereference it to obtain the live manifest.

### Requirement: REQ-OBA-007 Draft-to-published transition declares a snapshot action

**Reason**: The snapshot-on-publish writeback model is retired under ADR-002.
History is captured by OR object time-travel on the `ApplicationVersion` row
itself — no sibling `ApplicationVersion` rows are spawned on each publish. The
declarative `on_transition` snapshot action and its
`ApplicationVersionSnapshotListener` PHP fallback are both removed.

**Migration**: The `lib/Listener/ApplicationVersionSnapshotListener.php` file is
deleted along with its `register()` registration in
`lib/AppInfo/Application.php`. The `on_transition` action that performed
`create_relation(ApplicationVersion)` + `self.currentVersion = @result.uuid` is
removed from the Application schema's `x-openregister-lifecycle`. Consumers that
previously relied on the spawn-on-publish audit trail SHOULD switch to OR's
object time-travel on the `ApplicationVersion` row.
