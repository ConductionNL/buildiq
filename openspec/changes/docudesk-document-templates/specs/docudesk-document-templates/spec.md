## ADDED Requirements

### Requirement: REQ-DDT-001 Document-attachment declaration in the v2 manifest

The system SHALL support a `documents[]` array in the manifest v2 `runtime` block. Each entry SHALL carry:

- `id` (string, required, unique within the array) — attachment identifier.
- `schema` (string, required) — slug of a schema belonging to the virtual app; **multiple attachments per schema are allowed**, but the *(schema, label)* pair SHALL be unique.
- `templateId` (UUID string, required) — the Docudesk template UUID.
- `templateName` (string, required) — display snapshot of the template name at attach time (refreshed on edit).
- `label` (string, required) — the action label end users see (e.g. "Generate confirmation letter").
- `format` (string, optional) — output format passed to Docudesk's `options.format`; the allowed value set is pinned against the deployed Docudesk during apply (flagged dependency), defaulting to the template's own default when absent.
- `filenameTemplate` (string, optional) — download filename; may interpolate `{{objectProperty}}` placeholders resolved against the object; defaults to `<label>-<objectUuid>.<ext>`.

OpenBuild's manifest validation layer SHALL reject: duplicate `id`s, duplicate *(schema, label)* pairs, a `schema` not present in the virtual app, a non-UUID `templateId`, an empty `label`, a `format` outside the pinned value set, and unknown keys. Apps with zero attachments SHALL serialize byte-identical manifests (purely additive). Codification into the canonical `app-manifest-v2.schema.json` is an external `nextcloud-vue` follow-up, not part of this requirement.

#### Scenario: Valid attachment passes validation

- **GIVEN** a virtual app with schema `kapaanvraag`
- **WHEN** the manifest declares `runtime.documents: [{ id: "kap-confirm", schema: "kapaanvraag", templateId: "<uuid>", templateName: "Bevestigingsbrief", label: "Generate confirmation letter" }]`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Two attachments on the same schema with distinct labels are accepted

- **WHEN** a second entry targets `schema: "kapaanvraag"` with `label: "Generate besluit"`
- **THEN** the validator pass reports no errors

#### Scenario: Duplicate (schema, label) pair is rejected

- **WHEN** a second entry targets `schema: "kapaanvraag"` with the identical label "Generate confirmation letter"
- **THEN** the validator marks both entries with the error `openbuild.document.error.duplicate-label`
- **AND** the Save button is disabled

#### Scenario: Attachment to a foreign schema is rejected

- **WHEN** an entry names `schema: "not-in-this-app"`
- **THEN** the validator reports `openbuild.document.error.unknown-schema` with a click-to-focus link to the entry

### Requirement: REQ-DDT-002 Builder UI: attach a Docudesk template to a virtual-app schema

The system SHALL provide `DocumentTemplateAttachmentDialog.vue` (standalone dialog in `src/dialogs/` per the modal-isolation rule), opened from a "Documents" section on the application-detail/designer surface that lists existing attachments (template name, schema, label) with add, edit, and detach actions. The dialog SHALL expose: (a) a **template picker** populated from `GET /apps/docudesk/api/templates` (showing name; loading/error states); (b) a **schema picker** listing the virtual app's own schemas; (c) a required **action label** input; (d) an optional **format picker** limited to the pinned value set; (e) an optional **filename template** input with `{{property}}` placeholder hints; (f) a **Preview** affordance calling `POST /apps/docudesk/api/templates/{id}/preview` and presenting the rendered result before saving; and (g) an optional "add document actions to this schema's detail page" toggle that injects a `docudesk-document-actions` entry into the matching detail page's `sidebarProps.tabs` (or detail action group). Editing an attachment SHALL refresh `templateName` via `GET /apps/docudesk/api/templates/{id}` and SHALL surface a warning when the template no longer exists. Detaching SHALL only remove the manifest entry — previously generated documents are user downloads and are never touched. All `NcSelect`s carry `inputLabel`; every user-visible string uses English-source i18n keys under `openbuild.document.*` with nl translations.

#### Scenario: Attaching a template writes the manifest entry

- **GIVEN** Docudesk is installed with a template "Bevestigingsbrief"
- **WHEN** the builder opens the Documents section, clicks Add, picks "Bevestigingsbrief", picks schema `kapaanvraag`, enters label "Generate confirmation letter", and saves
- **THEN** the in-flight manifest gains the corresponding `runtime.documents[]` entry with the template's UUID and name
- **AND** the Documents section lists the new attachment

#### Scenario: Preview renders before committing

- **GIVEN** the dialog with "Bevestigingsbrief" selected
- **WHEN** the builder clicks Preview
- **THEN** Docudesk's preview endpoint is called for that template
- **AND** the rendered preview is presented without saving the attachment

#### Scenario: Edit warns about a deleted template

- **GIVEN** an attachment whose template was deleted in Docudesk
- **WHEN** the builder opens the attachment for editing
- **THEN** the dialog shows `openbuild.document.warning.template-missing`
- **AND** the builder can re-pick a template or detach

### Requirement: REQ-DDT-003 Runtime: user-invoked generation delivers a download

The system SHALL provide `useDocudeskDocument.js` with a `generate(attachment, object)` action that: (1) resolves a single `dataRefs` entry `{ register, schema, id }` from the runtime's active data context (the version-routed register/schema the app is reading and the object's UUID — never a serialized copy of the object's data); (2) renders the filename from `filenameTemplate` (safe `{{prop}}` interpolation against the object; missing properties → empty string; no eval), defaulting per REQ-DDT-001; (3) POSTs `POST /apps/docudesk/api/correspondence/generate` with `{ templateId, dataRefs, options: { format? }, filename }`; and (4) hands the returned document to the browser as a download. Generation SHALL be idempotent under double-click (in-flight guard per attachment+object). Failure SHALL surface as a non-blocking toast — a 403 SHALL render the distinct no-access message `openbuild.document.error.no-access`, any other failure `openbuild.document.error.generate-failed` with a retry affordance — and SHALL never navigate, mutate the object, or block any other surface.

#### Scenario: Generate downloads the document

- **GIVEN** schema `kapaanvraag` with the confirmation-letter attachment and an object with UUID `abc-123`
- **WHEN** the user clicks "Generate confirmation letter" on the object's detail surface
- **THEN** a `correspondence/generate` request is sent with `templateId`, `dataRefs: [{ register: <app register>, schema: "kapaanvraag", id: "abc-123" }]`, and the rendered filename
- **AND** the response is delivered to the browser as a download
- **AND** the object is not modified

#### Scenario: Filename template interpolates object properties

- **GIVEN** the attachment declares `filenameTemplate: "bevestiging-{{dossiernummer}}.pdf"` and the object's `dossiernummer` is `2026-0042`
- **WHEN** the user generates the document
- **THEN** the request's `filename` is `bevestiging-2026-0042.pdf`

#### Scenario: 403 renders a no-access toast, not an error

- **GIVEN** a user who may view the object but lacks rights on the template in Docudesk
- **WHEN** generation returns 403
- **THEN** the no-access toast appears and no console error is thrown
- **AND** the detail surface remains fully usable

#### Scenario: Double-click issues one request

- **WHEN** the user clicks the generate button twice in quick succession
- **THEN** exactly one `correspondence/generate` request is issued

### Requirement: REQ-DDT-004 Document actions rendered on the object's detail surface

The system SHALL provide `DocumentActions.vue`, registered as `docudesk-document-actions` in the virtual-app runtime's component registry and referencable from a detail page's `sidebarProps.tabs` or as a detail action group. For an object whose schema has ≥1 `documents[]` attachment, the surface SHALL render one button per attachment, ordered as declared, showing the attachment's `label` with a per-button busy state during generation and per-button error display per REQ-DDT-003. The surface SHALL render nothing (no empty placeholder, no heading) when the object's schema has no attachments, and SHALL render the Docudesk-unavailable state of REQ-DDT-005 when Docudesk is absent.

#### Scenario: Two attachments render two ordered buttons

- **GIVEN** schema `kapaanvraag` with attachments labelled "Generate confirmation letter" then "Generate besluit"
- **WHEN** the user opens an object's detail page with the document actions surface configured
- **THEN** both buttons render in declared order
- **AND** clicking the first sets only that button's busy state

#### Scenario: No attachments renders nothing

- **GIVEN** a schema with zero document attachments
- **WHEN** its detail page renders
- **THEN** no document-actions block, heading, or placeholder appears

### Requirement: REQ-DDT-005 Capability check and graceful absence of Docudesk

When a manifest contains at least one `documents[]` attachment, the save flow SHALL ensure `"docudesk"` is present exactly once in the manifest v2 `dependencies[]` array, via the same shared `ensureDependency(appId)` utility as the sibling integrations (one implementation, not copies). At design time, when `useAppStatus('docudesk')` reports the app missing or disabled, the Documents section SHALL render its add action disabled with the i18n hint `openbuild.document.hint.docudesk-missing` (existing attachments remain listed and detachable). At runtime on an instance without Docudesk: CnAppRoot's standard dependency gate applies for end users; if a surface renders regardless, the document-actions surface SHALL show an "integration unavailable" state and no request SHALL be sent to `/apps/docudesk/...`. No openbuild surface SHALL hard-fail, blank, or throw because Docudesk is absent.

#### Scenario: Dependency auto-added on save

- **WHEN** the builder saves a manifest containing its first document attachment
- **THEN** the persisted manifest's `dependencies` array contains `"docudesk"` exactly once
- **AND** re-saving does not duplicate the entry

#### Scenario: Designer degrades when Docudesk is missing

- **GIVEN** Docudesk is not installed
- **WHEN** the builder opens the Documents section
- **THEN** the Add action is disabled with the missing-app hint
- **AND** an existing attachment can still be viewed and detached

#### Scenario: Runtime surface degrades without requests

- **GIVEN** a published app with attachments, on an instance where Docudesk was disabled after publication, with the dependency gate bypassed (e.g. admin preview)
- **WHEN** a user opens an object's detail page
- **THEN** the document-actions surface shows the unavailable state
- **AND** no request is sent to `/apps/docudesk/...`

### Requirement: REQ-DDT-006 Integration contract pinned to Docudesk's existing public API surface

OpenBuild's Docudesk integration SHALL call exactly the following existing Docudesk routes and no others: `GET /apps/docudesk/api/templates` (template picker), `GET /apps/docudesk/api/templates/{id}` (snapshot refresh + existence check), `POST /apps/docudesk/api/templates/{id}/preview` (builder preview), and `POST /apps/docudesk/api/correspondence/generate` (runtime generation). All calls use the caller's Nextcloud session; openbuild SHALL NOT bypass or re-implement Docudesk's authorization, SHALL NOT import Docudesk PHP classes or read its tables, SHALL NOT persist rendered documents, template content, or huisstijl assets, and SHALL NOT modify any Docudesk data. The contract — including the `dataRefs` request shape and the pinned `options.format` value set — SHALL be pinned by a Newman collection asserting each route's request/response shape against the dev instance, so Docudesk-side drift fails CI rather than production. During apply, the team SHALL verify whether `GET api/templates/{id}` exposes placeholder metadata; if not, a Codeberg issue against `Conduction/docudesk` MUST be filed requesting (a) per-template placeholder metadata for design-time validation and (b) documentation of the supported `options.format` values; the issue URL is recorded in tasks.md.

#### Scenario: Contract surface is closed

- **WHEN** the openbuild source tree is scanned for `/apps/docudesk/` references
- **THEN** every call target is one of the four listed routes
- **AND** no Docudesk PHP namespace is imported anywhere in openbuild

#### Scenario: Newman pins the generate contract

- **GIVEN** the Newman collection's generate request with a seeded template and a seeded OR object reference
- **WHEN** the collection runs against the dev instance
- **THEN** `correspondence/generate` returns the document response for `dataRefs: [{ register, schema, id }]`
- **AND** an unknown `templateId` returns the documented 4xx error shape
