## ADDED Requirements

### Requirement: REQ-OBR-001 Manifest endpoint per virtual-app slug

The system SHALL expose
`GET /index.php/apps/openbuilt/api/applications/{slug}/manifest`
backed by `ApplicationsController::getManifest`. The endpoint SHALL
resolve `{slug}` to an `Application` via the `BuiltAppRoute` index,
return the stored `manifest` JSON blob with `Content-Type:
application/json`, and respond `200` on success or `404` when no
matching published Application exists in the caller's organisation
scope. The endpoint SHALL be registered via `appinfo/routes.php`
(ADR-016) with `#[NoAdminRequired]` and a route-auth posture that
treats it as authenticated-user-readable.

#### Scenario: Endpoint returns the stored manifest

- **WHEN** an authenticated user requests
  `/index.php/apps/openbuilt/api/applications/hello-world/manifest`
- **AND** a published `Application` with `slug: hello-world` exists
  in their organisation
- **THEN** the response is `200 application/json` and the body is the
  exact `manifest` blob persisted on the Application

#### Scenario: Unknown slug returns 404

- **WHEN** an authenticated user requests the manifest for a slug
  that has no matching `BuiltAppRoute`
- **THEN** the response is `404` with a JSON error body

### Requirement: REQ-OBR-002 OpenBuilt shell mounts a nested CnAppRoot per virtual app

The OpenBuilt frontend SHALL register a route `/builder/:slug/*` whose
view (`BuilderHost.vue`) mounts a **nested** `CnAppRoot` instance.
The nested mount SHALL be supplied with `appId = openbuilt-{slug}`
and a `bundledManifest` value, so that
`useAppManifest(appId, bundledManifest)` deep-merges the per-slug
endpoint response over the bundled placeholder and renders the virtual
app inside the OpenBuilt shell. The outer OpenBuilt shell's
`CnAppNav`, header, and chrome SHALL remain visible; the inner
`CnAppRoot` SHALL render only into the OpenBuilt page area.

#### Scenario: Navigating into a virtual app renders its manifest pages

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the outer OpenBuilt shell stays mounted
- **AND** a nested `CnAppRoot` mounts inside the page area with
  `appId = openbuilt-hello-world`
- **AND** the index page declared in the `hello-world` manifest
  renders

### Requirement: REQ-OBR-003 Path segments after the slug forward to the inner router

For routes matching `/builder/:slug/*`, the system SHALL forward the
path segments after `/{slug}` to the **inner** manifest's vue-router
so that detail, form, and dashboard pages inside the virtual app
resolve correctly. The outer OpenBuilt router SHALL treat everything
after `/{slug}/` as opaque to the inner router; the inner router
MUST match its own routes against that suffix.

#### Scenario: Detail route inside a virtual app resolves

- **WHEN** an authenticated user navigates to
  `/index.php/apps/openbuilt/builder/hello-world/messages/00000000-0000-0000-0000-000000000000`
- **THEN** the inner `CnAppRoot`'s router matches its `detail` page
  for the `hello-message` schema
- **AND** the detail page renders for the requested object id

### Requirement: REQ-OBR-004 Seeded hello-world Application exercises index, detail, form

The repair step SHALL seed a single Application with `slug:
hello-world`, `status: published`, a `manifest` declaring at least
one `type: index`, one `type: detail`, and one `type: form` page over
a seeded `hello-message` schema in the OpenBuilt register, plus three
sample `hello-message` objects. The seed SHALL be idempotent (safe to
re-run) and SHALL only run when no `Application` with `slug:
hello-world` exists in the system organisation scope.

#### Scenario: Fresh install renders the seeded virtual app

- **WHEN** the OpenBuilt app is installed on a fresh Nextcloud
- **AND** an administrator navigates to
  `/index.php/apps/openbuilt/builder/hello-world`
- **THEN** the seeded index page lists the three sample
  `hello-message` objects
- **AND** opening one of them renders the seeded detail page
- **AND** the seeded form page is reachable from the index actions

#### Scenario: Re-running the repair step is idempotent

- **WHEN** the repair step runs a second time on an already-seeded
  install
- **THEN** no duplicate `hello-world` Application is created
- **AND** no duplicate `hello-message` objects are created

### Requirement: REQ-OBR-005 Textarea manifest editor saves to the Application object

The OpenBuilt shell SHALL render a JSON `<textarea>`-based editor for
the `manifest` field of an `Application` object. The editor SHALL:
(a) load the current `manifest` blob from OR via the standard OR REST
API; (b) validate the edited blob client-side using
`validateManifest` from `@conduction/nextcloud-vue` and refuse to save
when validation fails, surfacing the failing JSON path; (c) on
successful validation, PUT the updated Application back to OR.
Visual editors are explicitly out of scope (chained spec #4 / #5).

#### Scenario: Invalid edit is blocked before save

- **WHEN** an integrator pastes a manifest blob missing the required
  `pages` array into the textarea and clicks Save
- **THEN** the editor surfaces a validation error citing the missing
  `pages` field
- **AND** no PUT request is sent to OR

#### Scenario: Valid edit persists and reloads

- **WHEN** an integrator pastes a valid manifest blob and clicks Save
- **THEN** the editor sends a PUT to OR's Application endpoint
- **AND** reloading the editor surfaces the new manifest
