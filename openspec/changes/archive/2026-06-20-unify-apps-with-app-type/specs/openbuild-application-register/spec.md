## MODIFIED Requirements

### Requirement: Application schema registered in OpenRegister

The system SHALL declare an `Application` schema in
`lib/Settings/openbuild_register.json` under the `openbuild` register namespace.
Under the versioned model (ADR-002) the Application schema SHALL define the following
top-level properties: `uuid` (string, UUID-format), `slug` (string, kebab-case
pattern), `name` (string, required), `description` (string, optional), `permissions`
(object, optional — RBAC block per REQ-OBA-006), `productionVersion` (relation
→ ApplicationVersion, optional — names which ApplicationVersion end users see at the
canonical URL), `appType` (enum `virtual` | `hybrid`, default `virtual` — the unified-app
discriminator), and `baseRef` (object `{ kind, id, manifestVersion? }`, optional — for a
`hybrid` app `baseRef.kind` is `"fleet-app"` and `baseRef.id` is the installed Nextcloud
app id the hybrid app customizes).

The Application schema SHALL NOT define `manifest`, `version`, `status`, or
`currentVersion` — those properties move to the new `ApplicationVersion` schema or
disappear entirely (`currentVersion` is retired per ADR-002 §Decision). The schema
SHALL be imported into OpenRegister at app install / post-migration time via the
existing repair step. The `appType` and `baseRef` properties are additive — an
Application record with no `appType` SHALL be treated as `virtual` on read.

**ID:** REQ-OBA-001

#### Scenario: Schema is available after install

- **WHEN** the OpenBuild app is installed and its repair step runs
- **THEN** OpenRegister exposes the `openbuild` register containing the
  `Application` schema with the versioned-model property set above including
  `appType` and `baseRef`
- **AND** the schema's properties match the declaration in
  `lib/Settings/openbuild_register.json`

#### Scenario: Application object is created via OR REST

- **WHEN** a client POSTs a payload to OR's REST endpoint for the
  `openbuild/application` namespace with valid `slug`, `name`, and `permissions`
- **THEN** OR persists the object, returns 201, and the returned object carries an
  OR-assigned `uuid` and the submitted fields
- **AND** the returned object has no `manifest`, `version`, `status`, or
  `currentVersion` field

#### Scenario: appType defaults to virtual when omitted

- **WHEN** an Application is created without an `appType` field
- **THEN** the persisted/read object SHALL be treated as `appType: "virtual"`

## ADDED Requirements

### Requirement: AppOverride schema is removed in favour of hybrid Applications

The `AppOverride` schema SHALL be removed from `lib/Settings/openbuild_register.json` in
this change (clean break) — fleet-app customizations are stored exclusively as hybrid
`Application` records (`appType: "hybrid"`) per the `unified-app-model` capability. The
removal SHALL be ordered after the migration (which copies every `AppOverride` row into a
hybrid Application and then deletes the source row), so no readable data is orphaned. The
`/api/app-overrides/{appId}` shim SHALL source its delta solely from the hybrid
Application — there is no legacy `AppOverride` read path.

**ID:** REQ-OBA-009

#### Scenario: AppOverride schema is absent after migration

- **WHEN** the OpenBuild register is imported after this change and the migration has run
- **THEN** the `openbuild` register SHALL NOT contain an `AppOverride` schema
- **AND** every former override SHALL be readable as a hybrid `Application`
- **AND** the `/api/app-overrides/{appId}` shim SHALL resolve its delta from the hybrid
  Application without any legacy fallback
