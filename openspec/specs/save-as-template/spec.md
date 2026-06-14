# save-as-template Specification

## Purpose
TBD - created by archiving change save-as-template. Update Purpose after archive.
## Requirements
### Requirement: REQ-SAT-001 "Save as template" action and dialog on the application-detail surface

The system SHALL provide `SaveAsTemplateDialog.vue` (standalone dialog in `src/dialogs/` per the modal-isolation rule), opened from a "Save as template" action on the application-detail surface, visible to users with editor/owner rights on the Application (per the existing openbuild-rbac surface). The dialog SHALL expose: (a) metadata inputs — `title` (prefilled from the app name), `slug` (auto-suggested kebab-case from the title, editable), `description`, `useCase`, `category` (picker over the REQ-OBTC-001 enum values), and optional `sourceUrl`; (b) a **capture summary** listing what will be stored — the manifest plus each companion schema with its de-namespaced slug, flagging any schema captured without de-namespacing per REQ-SAT-002; and (c) a Save action gated by REQ-SAT-003 validation. Saving SHALL create the `ApplicationTemplate` via standard OR REST with `isSeeded: false` and `version` set from the source Application's current version. Object data (rows) SHALL NEVER be captured — a template is a definition, not a dataset. All `NcSelect`s carry `inputLabel`; every user-visible string uses English-source i18n keys under `openbuild.templates.saveAs.*` with nl translations.

#### Scenario: Saving captures the app as an org-local template

- **GIVEN** a virtual app "My permits" (slug `my-permits`) with two schemas and a valid manifest
- **WHEN** the owner opens "Save as template", accepts the suggested metadata, picks category `government-services`, and saves
- **THEN** an `ApplicationTemplate` record exists with `isSeeded: false`, the captured manifest, two `companionSchemas` entries, and `version` equal to the app's current version
- **AND** the record is org-scoped via OR's standard `organisation` field

#### Scenario: No object data is captured

@e2e exclude pure-logic capture contract — verifying the captured record carries schema definitions only and no object rows is a property of the pure `captureTemplate` function, covered by Vitest (tests/vitest/templateCapture.spec.js); no Playwright-observable UI surface for record internals.

- **GIVEN** the same app holding 50 objects in its schemas
- **WHEN** the template is saved
- **THEN** the `ApplicationTemplate` record contains schema definitions only
- **AND** no object rows appear anywhere in the record

#### Scenario: Viewer cannot save a template

- **GIVEN** a user with only viewer rights on the Application
- **WHEN** they open the application-detail surface
- **THEN** the "Save as template" action is not offered

### Requirement: REQ-SAT-002 Companion schemas are de-namespaced as the exact inverse of clone-time prefixing

When capturing, the system SHALL strip the leading `<sourceAppSlug>-` prefix from every companion schema slug and rewrite every manifest reference (page `config`, data sources, and any `runtime.*` block references) to the de-namespaced slug — the exact inverse of clone-time REQ-OBTC-005 — so that save→clone composes to a clean rename without prefix stacking. A schema slug that does not carry the source app's prefix SHALL be captured unchanged and flagged in the dialog's capture summary as a shared schema (clones will receive an independent prefixed copy). If two captured schemas would de-namespace to the same canonical slug, the save SHALL be hard-blocked with the error `openbuild.templates.saveAs.error.slug-collision` naming both schemas.

#### Scenario: Round-trip is a clean rename

- **GIVEN** app `my-permits` whose schema `my-permits-permit-application` is referenced by its index and form pages
- **WHEN** the app is saved as template `permit-pack` and a user clones `permit-pack` into a new app `vggm-permits`
- **THEN** the template's companion schema slug is `permit-application`
- **AND** the cloned app's schema slug is `vggm-permits-permit-application` (no prefix stacking)
- **AND** every page in the cloned manifest references `vggm-permits-permit-application`

#### Scenario: Unprefixed shared schema is flagged, not mangled

@e2e exclude pure-logic de-namespace contract — shared-schema detection is a property of the pure `captureTemplate`/`deNamespaceSlug` functions, covered by Vitest; no Playwright-observable UI invariant beyond the dialog flag (exercised in the SaveAsTemplateDialog Vitest tests).

- **GIVEN** the app also references a hand-attached schema `shared-contacts` that does not carry the `my-permits-` prefix
- **WHEN** the capture summary renders
- **THEN** `shared-contacts` is listed with the shared-schema flag and captured with its slug unchanged

#### Scenario: De-namespace collision blocks the save

@e2e exclude pure-logic collision contract — the typed SlugCollisionError and no-partial-result guarantee are properties of the pure `captureTemplate` function, covered by Vitest; the dialog hard-block is exercised in the SaveAsTemplateDialog Vitest tests.

- **GIVEN** an app carrying schemas `my-permits-tasks` and a hand-attached `tasks`
- **WHEN** the builder attempts to save it as a template
- **THEN** the save is blocked with `openbuild.templates.saveAs.error.slug-collision` naming both schemas
- **AND** no template record is created

### Requirement: REQ-SAT-003 Validation gate: a template that cannot clone cleanly cannot be published

Before allowing Save, the dialog SHALL validate the **captured, de-namespaced** manifest with the canonical `validateManifest` from `@conduction/nextcloud-vue` plus openbuild's app-side validation layer (which tolerates and validates the sibling `runtime.workflows[]` / `runtime.documents[]` / `runtime.theme` blocks). Any validation error SHALL disable Save and render the errors through the existing validation-display path. This extends REQ-OBTC-009's "never persist a broken template" guarantee from seeded to user templates; cloned apps inherit the guarantee transitively per REQ-OBTC-009.

#### Scenario: Invalid manifest blocks publication

@e2e exclude validation-gate logic contract — the validateManifest-driven Save-disable is covered by the SaveAsTemplateDialog Vitest tests (mocked validator returning errors); the full UI flow is quarantined under Conduction/openbuild#41.

- **GIVEN** an app whose manifest currently fails canonical validation
- **WHEN** the builder opens "Save as template"
- **THEN** Save is disabled and the validation errors are displayed
- **AND** no template record is created

#### Scenario: Sibling runtime blocks are captured and pass validation

@e2e exclude pure-logic capture contract — verbatim `runtime.*` capture + `runtime.documents[].schema` rewrite is a property of the pure `captureTemplate`/`rewriteSchemaRefs` functions, covered by Vitest (round-trip test asserts the runtime.documents schema rewrite); no Playwright-observable UI surface for captured-blob internals.

- **GIVEN** an app whose manifest declares `runtime.theme` and `runtime.documents[]`
- **WHEN** it is saved as a template
- **THEN** the captured manifest carries both blocks verbatim (with `runtime.documents[].schema` rewritten per REQ-SAT-002)
- **AND** validation passes via the app-side layer

### Requirement: REQ-SAT-004 Re-saving onto an existing slug: update-in-place for own templates, hard error for seeded slugs

When the chosen slug matches an existing `ApplicationTemplate` in the caller's organisation: if the record has `isSeeded: true`, the save SHALL be rejected with `openbuild.templates.saveAs.error.seeded-slug` (curated slugs are never overwritable). If the record has `isSeeded: false` and OR reports the caller may write it, the dialog SHALL offer **Update template** — replacing `manifest` and `companionSchemas`, refreshing metadata from the form, and bumping `version` (minor) — or picking a new slug. If the caller may not write the existing record, the slug is rejected as taken. Updating a template SHALL NOT modify any Application previously cloned from it (REQ-OBTC-007's one-shot semantics hold in both directions).

#### Scenario: Update-in-place bumps the version

- **GIVEN** the user previously published template `permit-pack` at version `1.0.0` from their app
- **WHEN** they save the improved app onto slug `permit-pack` and confirm "Update template"
- **THEN** the existing record's `manifest` and `companionSchemas` are replaced
- **AND** its `version` is bumped to `1.1.0`
- **AND** no second template record is created

#### Scenario: Existing clones are untouched by an update

@e2e exclude backend immutability contract — verifying a template update does NOT mutate previously cloned Applications requires two-step OR REST mutation + assertion; covered by Newman (update-in-place keeps UUID) + the one-shot clone semantics inherited from REQ-OBTC-007; no Playwright-observable UI invariant.

- **GIVEN** an Application cloned from `permit-pack` 1.0.0 with `templateOrigin.version: "1.0.0"`
- **WHEN** `permit-pack` is updated to 1.1.0
- **THEN** the cloned Application's manifest and `templateOrigin.version` are unchanged

#### Scenario: Seeded slug is never overwritable

@e2e exclude slug-resolution logic contract — the `seeded-slug` rejection is a property of the pure `resolveSaveTarget` function, covered by Vitest (templateCapture + SaveAsTemplateDialog tests); no Playwright-observable UI surface beyond the disabled-Save state.

- **WHEN** a builder attempts to save a template with slug `permit-tracker` (a seeded template)
- **THEN** the save is rejected with `openbuild.templates.saveAs.error.seeded-slug`
- **AND** the seeded template is unchanged

### Requirement: REQ-SAT-005 Org-local templates are manageable in the gallery; seeded read-only contract preserved

The template gallery SHALL render `isSeeded: false` templates with an "Organisation template" badge and, only when OR reports the caller may write the record, **Edit metadata** (title, description, useCase, category, sourceUrl — never manifest or companions, which change only via REQ-SAT-004 re-capture) and **Delete** (with a confirmation stating that previously cloned Applications are not affected) actions. `isSeeded: true` templates SHALL keep exactly the REQ-OBTC-008 read-only rendering. "Use this template" SHALL behave identically for seeded and org-local templates via the existing unmodified clone endpoint. Deleting a template SHALL remove only the `ApplicationTemplate` record — no cloned Application and no source Application is modified.

#### Scenario: Org-local template appears with badge and clones normally

- **GIVEN** a published org-local template `permit-pack`
- **WHEN** a colleague in the same organisation opens the gallery
- **THEN** `permit-pack` renders with the organisation badge alongside the four seeded templates
- **AND** "Use this template" clones it through `POST /api/applications/from-template/permit-pack` exactly like a seeded template

#### Scenario: Management actions are rights-gated

@e2e exclude rights-gating logic contract — the `canManage` writability gate is covered by the TemplateGalleryManagement Vitest tests (writable vs non-writable org-local cards); the live UI is quarantined under Conduction/openbuild#41.

- **GIVEN** an org-local template owned by user A
- **WHEN** user B (no write rights on the record) views its gallery card
- **THEN** no Edit or Delete action renders for user B
- **AND** user A sees both actions on the same card

#### Scenario: Delete leaves clones and the source app intact

- **GIVEN** `permit-pack` was cloned into two Applications and originated from app `my-permits`
- **WHEN** the owner deletes the template and confirms
- **THEN** the template disappears from the gallery
- **AND** both cloned Applications and `my-permits` are unchanged

#### Scenario: Seeded cards remain read-only

- **WHEN** any user views the `permit-tracker` seeded card after this change
- **THEN** no Edit or Delete control is rendered (REQ-OBTC-008 unchanged)

### Requirement: REQ-SAT-006 Zero new PHP: capture composes existing OR and openbuild surfaces only

The save-as-template flow SHALL introduce no new PHP controllers, routes, services, or repair steps. Template create, update, and delete SHALL go through OR's standard object REST surface (`useObjectStore`) against the existing `ApplicationTemplate` schema under OR's RBAC and organisation scoping; cloning SHALL continue through the existing unmodified `from-template` endpoint. The `ApplicationTemplate` schema SHALL NOT be modified by this change.

#### Scenario: No new backend surface

@e2e exclude static diff contract — "lib/ and appinfo/routes.php untouched" is a build-time invariant asserted by the PR diff (REQ-SAT-006), not a runtime behaviour; no Playwright-observable UI surface.

- **WHEN** the change's diff is inspected
- **THEN** `appinfo/routes.php` and `lib/` are untouched
- **AND** all template writes in the new code target OR's object REST endpoints

#### Scenario: OR RBAC governs template writes

@e2e exclude backend RBAC contract — OR rejecting an unauthorized write to a foreign template is an OR REST authorization contract verified by Newman; no Playwright-observable UI surface for the server-side rejection.

- **GIVEN** a user whose OR rights do not allow writing another user's template record
- **WHEN** they attempt the update flow against it (e.g. crafted request)
- **THEN** OR rejects the write with its standard authorization error
- **AND** openbuild adds no bypass path

