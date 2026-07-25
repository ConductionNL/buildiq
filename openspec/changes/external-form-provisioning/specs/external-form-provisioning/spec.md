## ADDED Requirements

### Requirement: REQ-EFP-001 Manifest declaration: `runtime.externalForms[]`

The system SHALL support an optional `externalForms` array in the manifest v2 `runtime`
block. Each entry SHALL carry: `id` (string, OpenBuild-generated bookkeeping key),
`pageId` (string, the owning page), `register` / `schema` (string slugs, the OR target),
`status` (`enabled` | `disabled`), `publicRead` (boolean, default `false`),
`organisationScope` (string organisation id, or `null`), `portalPage` (object
`{objectId, portalPath}` or `null` when the Portaliq leg has not yet provisioned), and
`trackLinkAction` (object `{enabled: boolean}`). At most one entry exists per `id`;
multiple entries MAY target the same `(register, schema)` pair (per design.md OQ-3).
OpenBuild's manifest validation layer SHALL reject: an entry missing `register` or
`schema`, an unknown `status` value, a non-boolean `publicRead`/`trackLinkAction.enabled`,
and unknown top-level or nested keys. An app with no `externalForms` entries SHALL
serialize byte-identical manifests to before this feature (purely additive).

#### Scenario: Valid externalForms entry passes validation

- **GIVEN** a virtual app manifest
- **WHEN** it declares `runtime.externalForms: [{ id: "ef-1", pageId: "page-1", register: "intake", schema: "report", status: "enabled", publicRead: false, organisationScope: null, portalPage: null, trackLinkAction: { enabled: false } }]`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Missing register/schema is rejected

- **WHEN** the manifest declares an `externalForms` entry with no `schema` key
- **THEN** the validator reports `openbuild.externalForm.error.schema-required`
- **AND** the Save button is disabled

#### Scenario: An app without externalForms serializes byte-identically

- **GIVEN** a virtual app that has never used this feature
- **WHEN** the app is saved through a build containing this feature
- **THEN** the persisted manifest is byte-identical to the pre-feature baseline

### Requirement: REQ-EFP-002 Builder UI: External access toggle on the Form page editor

`src/components/page-editor/FormPageEditor.vue` SHALL gain an "External access" section,
enabled only when `submitShape === 'endpoint'` and `config.submitEndpoint` resolves to an
OR `/api/objects/{register}/{schema}` shape (otherwise the section renders disabled with
the hint `openbuild.externalForm.hint.requires-or-endpoint`). The section SHALL open
`src/dialogs/ExternalFormAccessDialog.vue` (standalone dialog per the modal-isolation
rule). The dialog SHALL let the builder: enable/disable public create, optionally enable
public read, optionally set an organisation scope, optionally enable the track-link
action, and see the resulting public URLs (raw OR public-create endpoint; Portaliq
`/portal` page when provisioned) before confirming. All `NcSelect`s carry `inputLabel`;
every user-visible string uses English-source i18n keys under `openbuild.externalForm.*`
with nl translations.

#### Scenario: Builder enables external access from the Form page editor

<!-- @e2e exclude live E2E against a running instance deferred (no shared-dev deploy per workflow); the dialog-open/save flow is covered by ExternalFormAccessDialog.spec.js + FormPageEditor.externalAccess.spec.js (Vitest); verify live before general availability. -->

- **GIVEN** a Form page whose `submitEndpoint` is `/api/objects/intake/report`
- **WHEN** the builder opens the "External access" section and clicks "Configure"
- **THEN** `ExternalFormAccessDialog` opens showing the resolved `register: "intake"`, `schema: "report"`
- **WHEN** the builder enables public create and saves
- **THEN** the in-flight manifest gains a `runtime.externalForms` entry with `status: "enabled"`

#### Scenario: Section is disabled for handler-shaped forms

- **GIVEN** a Form page with `submitShape === 'handler'`
- **WHEN** the builder views the page editor
- **THEN** the "External access" section renders disabled with `openbuild.externalForm.hint.requires-or-endpoint`

### Requirement: REQ-EFP-003 Provisioning: merge-safe schema authorization

`src/services/externalFormProvisioningService.js` SHALL, on enabling public create for a
`(register, schema)` target: `GET /api/schemas/{id}` (resolving `{id}` from the schema
slug), deep-copy the returned `authorization` object, append `"public"` to the `create`
array (and to `read` when `publicRead` is true) without removing or altering any other
group already present in `create`, `read`, `update`, or `delete`, then
`PATCH /api/schemas/{id}` with the full merged `authorization` object as the only changed
top-level key. The service SHALL NEVER send a partial `authorization` fragment (e.g.
`{authorization: {create: [...]}}` alone) — the field replaces wholesale server-side, so
omitting existing groups would silently delete them.

#### Scenario: Enabling public create preserves existing authorization

- **GIVEN** a schema with `authorization: { read: ["public"], update: ["editors"] }`
- **WHEN** the builder enables public create via the dialog
- **THEN** the PATCH payload's `authorization` is `{ read: ["public"], update: ["editors"], create: ["public"] }`
- **AND** no existing group is removed

#### Scenario: Enabling public read adds to the read array without removing entries

- **GIVEN** a schema with `authorization: { read: ["members"] }`
- **WHEN** the builder enables public create AND public read
- **THEN** the PATCH payload's `authorization.read` is `["members", "public"]`

### Requirement: REQ-EFP-004 Provisioning: Portaliq `portalPage` object create/update

The provisioning service SHALL create or update a Portaliq `portalPage` object when the
`portaliq`/`portalPage` schema exists on the instance: create (first save) or update
(subsequent saves, matched by the stored `portalPage.objectId`) an OpenRegister object of
register `portaliq`, schema `portalPage`, via `POST`/`PUT /api/objects/portaliq/portalPage`,
with a `type: "create"` action entry
carrying `anonymous: true` bound to the same `(register, schema)` the toggle targets, and
`status: "active"`. When the `portaliq`/`portalPage` schema does not exist on the instance
(OR responds schema-not-found for that register/schema pair), the service SHALL skip this
write, leave `portalPage: null` in the manifest entry, and surface
`openbuild.externalForm.hint.portaliq-unavailable` in the dialog — the OR-only leg (raw
public POST URL) remains fully functional. Disabling the toggle SHALL PUT the linked
`portalPage` object's `status` to `"draft"` (never delete it).

#### Scenario: First save creates a portalPage object

<!-- @e2e exclude live E2E against a running instance deferred (no shared-dev deploy per workflow; also depends on Portaliq's portal-page-provisioning schema being present in the target env); the create-vs-update payload shape is covered by externalFormProvisioningService.spec.js (Vitest). -->

- **GIVEN** the `portaliq`/`portalPage` schema exists and no `portalPage.objectId` is stored yet
- **WHEN** the builder saves the dialog with public create enabled
- **THEN** a new `portalPage` object is created with an `anonymous: true` create action targeting the toggle's `(register, schema)`
- **AND** the returned object uuid is stored as `runtime.externalForms[].portalPage.objectId`

#### Scenario: Portaliq schema not yet available degrades gracefully

<!-- @e2e exclude live E2E against a running instance deferred (no shared-dev deploy per workflow); the 404-degrade branch is covered by externalFormProvisioningService.spec.js's "degrades gracefully" test (Vitest) plus ExternalFormAccessDialog.spec.js's Portaliq-unavailable test. -->

- **GIVEN** the `portaliq`/`portalPage` schema does not exist on the instance
- **WHEN** the builder saves the dialog with public create enabled
- **THEN** the OR schema-authorization PATCH still completes
- **AND** `portalPage` remains `null` in the manifest entry
- **AND** the dialog shows the "Portaliq rendering not available on this instance yet" hint alongside the working raw public-create URL

#### Scenario: Disabling sets the portalPage to draft, not deleted

<!-- @e2e exclude live E2E against a running instance deferred (no shared-dev deploy per workflow); the GET-merge-PUT status-only-change behaviour is covered by externalFormProvisioningService.spec.js's draftPortalPage tests (Vitest). -->

- **GIVEN** an enabled toggle with a linked `portalPage` object
- **WHEN** the builder disables external access
- **THEN** the `portalPage` object's `status` becomes `"draft"`
- **AND** the object is not deleted

### Requirement: REQ-EFP-005 Revoke removes public authorization without touching other groups

Disabling the toggle SHALL reverse REQ-EFP-003's merge: `GET` the current schema, remove
`"public"` from `create` (and from `read` if it was added by this toggle) when present,
leave every other group untouched, and `PATCH` the result. The manifest entry's `status`
SHALL become `"disabled"`.

#### Scenario: Disabling removes only the public entry this toggle added

- **GIVEN** a schema with `authorization: { create: ["public"], read: ["public"], update: ["editors"] }` where this toggle added `public` to `create` only (`publicRead` was never enabled by this toggle)
- **WHEN** the builder disables the toggle
- **THEN** the PATCH payload's `authorization` is `{ create: [], read: ["public"], update: ["editors"] }`

### Requirement: REQ-EFP-006 Owner-context track-link minting

`src/composables/useTrackLinkAction.js` SHALL expose a `mintTrackLink(register, schema,
objectId, {label, ttlSeconds})` function calling
`POST /api/objects/{register}/{schema}/{id}/integrations/shares` with
`{type: "public-token", label, ttlSeconds}`, returning the minted token's public URL. It
SHALL only be callable from an authenticated OpenBuild session viewing an object the
builder/staff member already has access to (a data-register object list/detail view) —
never from an anonymous context, and never invoked automatically as a side effect of an
object being created. It SHALL be offered on a schema's object views only when that
schema's `runtime.externalForms` entry has `trackLinkAction.enabled: true`.

#### Scenario: Staff member mints a track-link for a submitted object

<!-- @e2e exclude live E2E against a running instance deferred (no shared-dev deploy per workflow); the mint request/response shape is covered by useTrackLinkAction.spec.js and TrackLinkAction.spec.js (Vitest). -->

- **GIVEN** an authenticated OpenBuild session viewing an object of a schema whose external-form entry has `trackLinkAction.enabled: true`
- **WHEN** the staff member clicks "Mint track-link"
- **THEN** `POST /api/objects/{register}/{schema}/{id}/integrations/shares` is called with `{type: "public-token"}`
- **AND** the returned public `GET /api/public/case-tokens/{token}` URL is shown for the staff member to copy/relay

#### Scenario: Track-link action absent when not enabled

- **GIVEN** an external-form entry with `trackLinkAction.enabled: false`
- **WHEN** the staff member views a submitted object of that schema
- **THEN** no "Mint track-link" action is rendered

### Requirement: REQ-EFP-007 Closed integration contract — no OpenBuild anonymous surface

OpenBuild's external-form-provisioning integration SHALL call exactly:
`GET`/`PATCH /api/schemas/{id}`, `POST`/`PUT /api/objects/portaliq/portalPage`, and
`POST /api/objects/{register}/{schema}/{id}/integrations/shares` — all authenticated,
owner-context calls riding the builder's own NC session. OpenBuild SHALL NOT declare any
`#[PublicPage]` route, SHALL NOT import OpenRegister or Portaliq PHP classes, SHALL NOT
read their database tables directly, and SHALL NOT implement a `ShareToken` model or any
anonymous-write/anonymous-render code path of its own.

#### Scenario: Contract surface is closed

- **WHEN** the OpenBuild source tree is scanned for `#[PublicPage]` attributes and for `OCA\OpenRegister`/`OCA\Portaliq` PHP namespace imports
- **THEN** no `#[PublicPage]` route exists anywhere in `lib/Controller/`
- **AND** no OpenRegister or Portaliq PHP namespace is imported anywhere in OpenBuild
- **AND** no `ShareToken` class/file exists in the repository
