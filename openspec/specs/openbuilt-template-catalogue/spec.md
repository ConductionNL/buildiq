# openbuilt-template-catalogue Specification

## Purpose
TBD - created by archiving change openbuilt-templates-marketplace. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBTC-001 ApplicationTemplate schema declares the template record contract

The system SHALL declare an `ApplicationTemplate` schema in
`lib/Settings/openbuilt_register.json` under the existing `openbuilt`
register namespace established by chain spec #1. The schema SHALL
declare the following properties:

- `uuid` (string, UUID-format, required)
- `slug` (string, kebab-case pattern, required, unique per
  organisation)
- `title` (string, required) — human-readable title shown in the
  gallery
- `description` (string, required) — one-paragraph plain-text summary
- `useCase` (string, required) — short one-line "what it solves"
  label (e.g. "Municipal building-permit workflow")
- `category` (string enum, required) — initial values
  `government-services`, `internal-operations`, `citizen-engagement`,
  `field-work`. Additional values added in follow-up specs.
- `screenshotUrl` (string, URI, optional) — relative path under the
  app's `img/templates/` or an OR Files URL
- `manifest` (object, required) — full app-manifest JSON blob,
  validates against the canonical schema referenced from the same
  register
- `companionSchemas` (array of objects, optional) — JSON-schema
  blobs to be cloned alongside the manifest into the user's namespace
- `isSeeded` (boolean, required, default `false`) — `true` for the
  four Conduction-shipped templates, `false` for org-local templates
  added in a follow-up spec
- `sourceUrl` (string, URI, optional) — link back to the originating
  user-story / RFP / blog post for traceability
- `version` (string, semver pattern, required) — template version
  recorded on clone for one-shot snapshot semantics (see REQ-OBTC-007)

The schema SHALL validate against OpenAPI 3.0.0 and SHALL be scoped
per organisation via OR's standard `organisation` field. No bespoke
`x-openregister-lifecycle` block beyond OR's defaults — templates do
not have a draft/published/archived state machine of their own; they
are either present or removed.

#### Scenario: Schema validation rejects a template with no manifest

- **WHEN** an API client posts an `ApplicationTemplate` with `title`
  and `description` but no `manifest` field
- **THEN** OR returns a 4xx schema-validation error citing the
  missing `manifest` field
- **AND** no template is created

#### Scenario: Slug uniqueness is enforced per organisation

- **WHEN** two `ApplicationTemplate` records are submitted with the
  same `slug` in the same organisation
- **THEN** the second request is rejected with a 4xx error
- **AND** the first template remains intact

### Requirement: REQ-OBTC-002 Four Conduction-curated templates seeded via repair step

The system SHALL seed at minimum four Conduction-curated
`ApplicationTemplate` records on install via
`lib/Repair/SeedApplicationTemplates.php`, following the canonical
ADR-001 seed pattern established by `SeedHelloWorld.php` in chain spec
#1. The four seeded templates SHALL be:

1. **`permit-tracker`** (category `government-services`, derived from
   user-story US-1 in `concurrentie-analyse/app-builder/README.md`) —
   municipal building-permit workflow with index, detail, form, and
   kanban pages over a `permit-application` companion schema.
2. **`stakeholder-consultation`** (category `citizen-engagement`,
   US-2) — policy-advisor consultation with index, detail, form, and
   comment-thread pages over `consultation` and `consultation-comment`
   companion schemas.
3. **`employee-onboarding`** (category `internal-operations`, US-4) —
   HR checklist with index, detail, form, and checklist pages over
   `onboarding-task` and `onboarding-document` companion schemas.
4. **`incident-reporter`** (category `field-work`, US-3) — safety-
   region field incident intake with index, detail, and form pages
   over an `incident` companion schema.

Each seeded template SHALL have `isSeeded: true` and a `sourceUrl`
pointing to the relevant user-story section of
`concurrentie-analyse/app-builder/README.md`.

The seed repair step SHALL be idempotent: re-running on an
already-seeded install SHALL produce no duplicates and SHALL be
guarded by per-template `slug` existence checks (matching the
`SeedHelloWorld.php` guard pattern).

#### Scenario: Fresh install seeds four templates

- **WHEN** the OpenBuilt app is installed on a fresh Nextcloud
- **THEN** four `ApplicationTemplate` records exist in the
  `openbuilt` register with `isSeeded: true`
- **AND** their slugs are `permit-tracker`, `stakeholder-consultation`,
  `employee-onboarding`, and `incident-reporter`

#### Scenario: Repair step re-run is idempotent

- **WHEN** the `SeedApplicationTemplates` repair step runs a second
  time on an already-seeded install
- **THEN** no duplicate templates are created
- **AND** no existing template data is overwritten

### Requirement: REQ-OBTC-003 Gallery view lists templates with filter and detail

The OpenBuilt frontend SHALL register a Vue route `/templates` whose
view (`src/views/TemplateGallery.vue`) lists every
`ApplicationTemplate` visible to the caller via OR REST. The gallery
SHALL:

- Show each template's `title`, `useCase`, `description`,
  `category`, and `screenshotUrl` if present
- Provide filter controls for `category` and a free-text search over
  `title` + `useCase` + `description`
- Surface a "Use this template" action per card
- Be reachable from a top-level OpenBuilt left-nav entry and from a
  "Create from template" CTA on the empty-state of the Application
  list

The gallery SHALL render using `@conduction/nextcloud-vue`'s standard
`CnAppRoot` chrome (no bespoke layout system) and SHALL use Nextcloud
CSS variables only (per ADR-010 — no hardcoded colours).

#### Scenario: Filtering by category narrows the gallery

- **WHEN** a user opens `/index.php/apps/openbuilt/templates` and
  selects the `government-services` category filter
- **THEN** the gallery shows only the `permit-tracker` template
- **AND** the three other seeded templates are hidden from view

#### Scenario: Empty Application list surfaces the gallery CTA

- **WHEN** a user with no Applications navigates to the OpenBuilt
  shell home
- **THEN** the empty-state of the Application list shows a "Create
  from template" CTA
- **AND** clicking the CTA navigates to `/templates`

### Requirement: REQ-OBTC-004 "Use this template" clones into a new Application

The system SHALL expose `POST
/index.php/apps/openbuilt/api/applications/from-template/{templateSlug}`
backed by `ApplicationsController::createFromTemplate`. The endpoint
SHALL accept a JSON body with at least `{ name: string, slug: string
}` for the new Application. On success, it SHALL:

1. Read the `ApplicationTemplate` identified by `{templateSlug}` from
   OR via the standard ObjectService.
2. Deep-copy the template's `manifest` blob into a new `Application`
   record with the user-supplied `name` and `slug`, `status: draft`,
   `version: 0.1.0`, owned by the calling user, scoped to the
   calling user's organisation.
3. For each entry in the template's `companionSchemas` array, clone
   the JSON-schema blob into the user's namespace with its schema
   slug prefixed by the new Application's slug (see REQ-OBTC-005).
4. Record the template's `slug` + `version` on the new Application
   under a `templateOrigin` metadata field for traceability.
5. Return a 201 response with the new Application's UUID and slug.

The route SHALL be registered in `appinfo/routes.php` (ADR-016) with
`#[NoAdminRequired]`. The controller method is the only new
PHP code surface in this spec beyond the seed step (≤30 LOC). No
state-machine or "template service" class is introduced (ADR-031).

#### Scenario: Clone produces a draft Application with the template manifest

- **WHEN** an authenticated user POSTs `{ name: "My permits", slug:
  "my-permits" }` to
  `/index.php/apps/openbuilt/api/applications/from-template/permit-tracker`
- **THEN** the response is 201 with the new Application's UUID
- **AND** a new `Application` record exists in OR with `slug:
  my-permits`, `status: draft`, and a `manifest` equal to the
  `permit-tracker` template's manifest
- **AND** the new Application's `templateOrigin.slug` is
  `permit-tracker` and `templateOrigin.version` matches the
  template's recorded version

#### Scenario: Clone with an existing slug is rejected

- **WHEN** an authenticated user POSTs a clone with a `slug` that
  matches an existing Application in their organisation
- **THEN** the response is 4xx with a JSON error body citing the slug
  collision
- **AND** no Application is created
- **AND** no companion schemas are cloned

### Requirement: REQ-OBTC-005 Cloned companion schemas are namespaced by Application slug

When a template clone runs, the system SHALL prefix every cloned
companion-schema `slug` with the new Application's slug joined by a
hyphen. For example, cloning `permit-tracker` (which carries a
`permit-application` companion schema) into a new Application with
`slug: my-permits` SHALL produce a cloned schema with `slug:
my-permits-permit-application`. The cloned manifest's page `config`
references to that schema SHALL be rewritten to the prefixed slug so
that the new Application loads correctly without manual edits.

This avoids slug collisions when multiple Applications are cloned from
the same template into the same organisation, and keeps the original
template's companion schemas untouched.

#### Scenario: Two clones of the same template coexist

- **WHEN** an authenticated user clones `permit-tracker` into
  `slug: my-permits` and then again into `slug: vggm-permits`
- **THEN** OR contains two distinct schemas with slugs
  `my-permits-permit-application` and
  `vggm-permits-permit-application`
- **AND** each Application's manifest references its own prefixed
  schema slug

### Requirement: REQ-OBTC-006 Clone redirects to the page editor for customisation

After a successful template clone, the frontend SHALL redirect the
user to the page editor view (from chain spec #5,
`openbuilt-page-editor`) for the new Application, opened on the
manifest's first page. The redirect SHALL preserve the new
Application's slug in the URL so the editor loads against the right
record.

If the page editor view from chain #5 is not yet present in the
running build (because chain #5 has not yet landed in the same
deployment), the frontend SHALL fall back to the textarea editor
shipped in chain spec #1 (REQ-OBR-005) without breaking the clone
flow.

#### Scenario: Clone redirects into the page editor

- **WHEN** a user successfully clones `permit-tracker` from the
  gallery
- **THEN** the browser navigates to the page editor route for the new
  Application
- **AND** the editor surface shows the cloned manifest's first page

### Requirement: REQ-OBTC-007 Template clones are one-shot snapshots

A cloned Application SHALL be a fully independent record from the
source template. The system SHALL NOT propagate later changes to a
template back into Applications previously cloned from it. The
template's `version` SHALL be recorded on the new Application under
`templateOrigin.version` for traceability, but no auto-update or
"upgrade-from-template" flow is supported in this spec.

This decision is documented in `design.md` (Decision 5 — Versioning)
and is explicitly deferred to a future versioning spec.

#### Scenario: Updating a template does not change existing clones

- **GIVEN** a user has cloned the `permit-tracker` template (version
  1.0.0) into a new Application
- **WHEN** an admin updates the `permit-tracker` template's manifest
  in place (or the seed step re-runs against a new repo version)
- **THEN** the user's previously cloned Application's manifest is
  unchanged
- **AND** the user's `templateOrigin.version` still reads `1.0.0`

### Requirement: REQ-OBTC-008 Conduction-curated templates are read-only via UI

The system SHALL present Conduction-shipped templates (records with
`isSeeded: true`) as read-only in the gallery and SHALL NOT expose UI
controls to edit or delete `isSeeded: true` records via the OpenBuilt
frontend in this spec. Backend deletion via OR REST remains
governed by OR's standard RBAC; this requirement only constrains the
UI surface to prevent accidental damage to the curated catalogue
during the integrator workflow.

Org-local user-submitted templates (an explicit non-goal of this spec;
deferred to a follow-up) will be `isSeeded: false` and editable; that
flow lives in a separate change.

#### Scenario: Gallery hides edit controls on a seeded template

- **WHEN** a user views the `permit-tracker` template card in the
  gallery
- **THEN** no "Edit template" or "Delete template" control is
  rendered
- **AND** only the "Use this template" action is shown

### Requirement: REQ-OBTC-009 Template manifests validate against the canonical app-manifest schema

Every seeded template's `manifest` blob SHALL validate against the
canonical `app-manifest.schema.json` pinned in `package.json`
(ADR-024). The repair step SHALL run `validateManifest` against each
seeded manifest before persisting it; a validation failure SHALL fail
the repair step loudly (rather than seeding a broken template).
Cloned manifests (REQ-OBTC-004) inherit this guarantee transitively
because they are byte-for-byte copies modulo the schema-slug rewrite
in REQ-OBTC-005.

#### Scenario: A broken seeded manifest fails install

- **WHEN** a developer modifies the `permit-tracker` seed manifest to
  reference an unknown `type: xyz` page type
- **AND** the repair step runs on `occ maintenance:repair`
- **THEN** the repair step exits non-zero with a validation error
  citing the offending page type
- **AND** no `permit-tracker` template is seeded

### Requirement: REQ-OBTC-010 i18n keys for gallery and seeded templates

The system SHALL ensure every user-visible string in the gallery view
(gallery section title, filter labels, category labels, "Use this
template" button label, empty-state copy) uses i18n keys under the
`openbuilt.templates.*` namespace. Every seeded template's `title`,
`description`, `useCase`, and `category` SHALL be stored either as
i18n keys (preferred) or as English strings with Dutch translations
shipped in `l10n/nl.json` so the gallery is bilingual on install
(per the project-wide nl/en minimum).

#### Scenario: Dutch user sees Dutch gallery copy

- **WHEN** an authenticated Dutch-locale user opens
  `/index.php/apps/openbuilt/templates`
- **THEN** the page title, filter labels, and the four seeded
  template descriptions render in Dutch

