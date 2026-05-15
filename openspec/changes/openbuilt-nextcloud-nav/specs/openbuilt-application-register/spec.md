## MODIFIED Requirements

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
