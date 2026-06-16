# procest-workflow-attachments Specification

## Purpose
TBD - created by archiving change procest-workflow-attachments. Update Purpose after archive.
## Requirements
### Requirement: REQ-PWA-001 Workflow-attachment declaration in the v2 manifest

The system SHALL support a `workflows[]` array in the manifest v2 `runtime` block. Each entry SHALL carry:

- `id` (string, required, unique within the array) — attachment identifier.
- `schema` (string, required) — slug of a schema belonging to the virtual app; **at most one attachment per schema** in v1.
- `caseTypeUuid` (UUID string, required) — the Procest zaaktype UUID.
- `caseTypeName` (string, required) — display snapshot of the zaaktype name at attach time (picker refreshes it on edit).
- `trigger` (closed enum `on-create`, required) — v1 supports object creation only.
- `linkProperty` (string, required) — name of a string/url-typed property on the target schema that stores the linked case reference.
- `descriptionTemplate` (string, optional) — template for the case description; may interpolate `{{objectProperty}}` placeholders resolved against the created object.

OpenBuild's manifest validation layer SHALL reject: duplicate `id`s, more than one attachment for the same `schema`, a `schema` not present in the virtual app, a `linkProperty` not declared on that schema (or not string-typed), an unknown `trigger`, and unknown keys. Apps with zero attachments SHALL serialize byte-identical manifests (purely additive). Codification into the canonical `app-manifest-v2.schema.json` is an external `nextcloud-vue` follow-up, not part of this requirement.

#### Scenario: Valid attachment passes validation

- **GIVEN** a virtual app with schema `kapaanvraag` declaring a string property `zaakUrl`
- **WHEN** the manifest declares `runtime.workflows: [{ id: "kap-handling", schema: "kapaanvraag", caseTypeUuid: "<uuid>", caseTypeName: "Kapvergunning", trigger: "on-create", linkProperty: "zaakUrl" }]`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Second attachment on the same schema is rejected

- **WHEN** a second `workflows[]` entry targets `schema: "kapaanvraag"`
- **THEN** the validator marks both entries with the error `openbuild.workflow.error.duplicate-schema-attachment`
- **AND** the Save button is disabled

#### Scenario: Missing linkProperty is rejected

- **WHEN** an attachment names `linkProperty: "zaakUrl"` but the `kapaanvraag` schema declares no such property
- **THEN** the validator reports `openbuild.workflow.error.link-property-missing` with a click-to-focus link to the attachment entry

### Requirement: REQ-PWA-002 Builder UI: attach a Procest case type to a virtual-app schema

The system SHALL provide `WorkflowAttachmentDialog.vue` (standalone dialog in `src/dialogs/` per the modal-isolation rule), opened from a "Workflows" section on the application-detail/designer surface that lists existing attachments with add, edit, and detach actions. The dialog SHALL expose: (a) a **case-type picker** populated from `GET /apps/procest/api/zgw/catalogi/v1/zaaktypen` (published case types, showing name + identification); (b) a **schema picker** listing the virtual app's own schemas, excluding those already attached; (c) a **link-property picker** listing the chosen schema's string-typed properties, with a one-click "create `zaakUrl` property" affordance that delegates to the existing schema-designer property-add flow; (d) an optional **description template** input with placeholder hints; and (e) an optional "add a case-status tab to this schema's detail page" toggle that injects a `procest-case-status` tab into the matching detail page's `sidebarProps.tabs`. Detaching an attachment SHALL warn that existing linked cases are not affected (links on objects remain; no cases are deleted).

#### Scenario: Attaching a case type writes the manifest entry

- **GIVEN** Procest is installed with a published zaaktype "Kapvergunning"
- **WHEN** the builder opens the Workflows section, clicks Add, picks "Kapvergunning", picks schema `kapaanvraag`, picks property `zaakUrl`, and saves
- **THEN** the in-flight manifest gains the corresponding `runtime.workflows[]` entry with the zaaktype's UUID and name
- **AND** the Workflows section lists the new attachment

#### Scenario: One-click link property creation

- **GIVEN** schema `kapaanvraag` has no string property suitable as a link target
- **WHEN** the builder clicks "create `zaakUrl` property" in the dialog
- **THEN** the schema-designer flow adds a string property `zaakUrl` to the schema (same validation as the schema designer's own field editor)
- **AND** the dialog's link-property picker selects it

#### Scenario: Detach leaves existing data intact

- **GIVEN** an attachment with five objects already linked to cases
- **WHEN** the builder detaches it and confirms the warning
- **THEN** the `workflows[]` entry is removed from the manifest
- **AND** no Procest case is deleted and no object's `linkProperty` value is modified

### Requirement: REQ-PWA-003 Runtime: object creation starts a linked Procest case

When an object of an attached schema is created through the virtual app's UI (form page in create mode, or an index-page create action), the runtime SHALL, after the OR object create succeeds: (1) POST a case to `POST /apps/procest/api/zgw/zaken/v1/zaken` carrying the attachment's `caseTypeUuid` (as the zaaktype reference), the rendered `descriptionTemplate` (or a default naming the app + object), and a `kenmerken` entry `{ kenmerk: "<objectUuid>", bron: "openbuild:<appSlug>:<schemaSlug>" }`; (2) write the created case's URL and UUID back onto the object's `linkProperty` via the standard OR object-update path. Case-start failure SHALL NOT roll back, block, or delay the object creation: the object stands, the user sees a non-blocking warning toast, and the object's detail surface offers a "Start case" retry which SHALL first attempt reconciliation via `POST /apps/procest/api/zgw/zaken/v1/zaken/_zoek` by the object-UUID kenmerk (re-linking a half-completed case) before creating a new case. The runtime SHALL NOT start a second case when the object's `linkProperty` already holds a case reference.

#### Scenario: Create flow starts a case and links both ways

- **GIVEN** schema `kapaanvraag` attached to case type "Kapvergunning" with `linkProperty: "zaakUrl"`
- **WHEN** an end user submits the app's create form and the OR object is created with UUID `abc-123`
- **THEN** a case is created in Procest with the Kapvergunning zaaktype and a kenmerk `abc-123` (bron `openbuild:...`)
- **AND** the object's `zaakUrl` property is updated with the case URL
- **AND** the user lands on the normal post-create surface with no extra confirmation step

#### Scenario: Case-start failure preserves the object and offers retry

- **GIVEN** the same attachment, with Procest returning 500 on the ZRC create
- **WHEN** the user submits the form
- **THEN** the object is created and remains persisted
- **AND** a warning toast states the case could not be started
- **AND** the object's detail panel shows a "Start case" retry affordance

#### Scenario: Retry reconciles a half-completed start instead of duplicating

- **GIVEN** a previous attempt created the case but failed before writing `zaakUrl` back to the object
- **WHEN** the user clicks "Start case"
- **THEN** the runtime finds the existing case via `_zoek` on the kenmerk `abc-123`
- **AND** re-links it to the object's `zaakUrl` without creating a second case

#### Scenario: Already-linked object never starts a duplicate

- **GIVEN** an object whose `linkProperty` already holds a case URL
- **WHEN** any code path evaluates the on-create trigger for that object (e.g. a re-submit or refresh race)
- **THEN** no ZRC create is issued

### Requirement: REQ-PWA-004 Case status and progress rendered on the object's detail surface

The system SHALL provide `ProcestCaseStatusPanel.vue`, registered as `procest-case-status` in the virtual-app runtime's component registry and referencable from a detail page's `sidebarProps.tabs` (or as a detail widget). For an object whose `linkProperty` holds a case reference, the panel SHALL render: the case identification and case-type name; the current status with its statustype description (`GET /apps/procest/api/zgw/zaken/v1/zaken/{uuid}` + `GET /apps/procest/api/zgw/zaken/v1/statussen?zaak={zaakUrl}`); a status-history timeline in chronological order; and the REQ-PWA-005 deep link. Responses SHALL be cached per case with a short TTL (default 30 s) to avoid refetch storms on tab switches. The panel SHALL render distinct states for: no case linked (with the REQ-PWA-003 "Start case" affordance when an attachment exists for the schema), case fetch returning 403 ("you don't have access to the linked case" — distinct from "no case"), case fetch returning 404 (stale link, with a re-reconcile affordance), and Procest absent (REQ-PWA-006). An **open-tasks block** SHALL render ONLY when the flagged Procest per-case task-list endpoint (explicit dependency — see proposal) is detected via a one-time feature probe; otherwise the block is absent entirely (no empty placeholder).

#### Scenario: Status panel renders a linked case

- **GIVEN** an object linked to a case currently in status "In behandeling" with two prior statuses
- **WHEN** the user opens the object's detail page and its "Case" tab
- **THEN** the panel shows the case identification, "In behandeling" with its statustype description, and a three-entry timeline in chronological order

#### Scenario: 403 renders a no-access state, not an error

- **GIVEN** an object linked to a case the viewing user may not read in Procest
- **WHEN** the panel fetches the case and receives 403
- **THEN** the panel renders the i18n no-access message (`openbuild.workflow.case.no-access`)
- **AND** the deep link is still offered (Procest will enforce its own access on arrival)
- **AND** no error is thrown to the console

#### Scenario: Tasks block hidden while the Procest tasks API is absent

- **GIVEN** the deployed Procest exposes no per-case task-list endpoint
- **WHEN** the panel renders a linked case
- **THEN** no tasks block (and no empty tasks placeholder) appears
- **AND** the feature probe result is cached so the missing endpoint is not re-probed per render

### Requirement: REQ-PWA-005 Deep links into Procest for case handling

The system SHALL provide a single shared helper `buildProcestCaseUrl(zaakUuid)` that produces the Procest frontend URL for a case, and an "Open in Procest" action on the ProcestCaseStatusPanel (and on the attachment's object rows where row actions are configured) that opens that URL in a new tab. All Procest deep links in openbuild MUST be built through this helper — no inline URL construction — so a Procest route change is a single-point fix. The helper SHALL be verified against the deployed Procest during apply; if Procest publishes no stable case route, that is recorded on the flagged Procest dependency issue and the helper targets the best-known route with a code comment referencing the issue.

#### Scenario: Deep link opens the case in Procest

- **GIVEN** a linked case with UUID `zaak-9`
- **WHEN** the user clicks "Open in Procest" on the status panel
- **THEN** a new tab opens on the Procest app's view for case `zaak-9`
- **AND** the URL was produced by `buildProcestCaseUrl`

#### Scenario: No inline deep-link construction

- **WHEN** the openbuild source tree is scanned for Procest frontend URLs
- **THEN** every occurrence outside `buildProcestCaseUrl` and its tests resolves through the helper

### Requirement: REQ-PWA-006 Capability check and graceful absence of Procest

When a manifest contains at least one `workflows[]` attachment, the save flow SHALL ensure `"procest"` is present exactly once in the manifest v2 `dependencies[]` array. At design time, when `useAppStatus('procest')` reports the app missing or disabled, the Workflows section SHALL render its add action disabled with the i18n hint `openbuild.workflow.hint.procest-missing` (existing attachments remain listed and detachable). At runtime on an instance without Procest: CnAppRoot's standard dependency gate applies for end users; if a surface renders regardless, the status panel SHALL show an "integration unavailable" state and the on-create trigger SHALL skip the case start with a single logged warning — object creation itself MUST proceed normally. No openbuild surface SHALL hard-fail, blank, or throw because Procest is absent.

#### Scenario: Dependency auto-added on save

- **WHEN** the builder saves a manifest containing its first workflow attachment
- **THEN** the persisted manifest's `dependencies` array contains `"procest"` exactly once
- **AND** re-saving does not duplicate the entry

#### Scenario: Designer degrades when Procest is missing

- **GIVEN** Procest is not installed
- **WHEN** the builder opens the Workflows section
- **THEN** the Add action is disabled with the missing-app hint
- **AND** an existing attachment can still be viewed and detached

#### Scenario: Runtime skips case start without breaking creation

- **GIVEN** a published app with an attachment, on an instance where Procest was disabled after publication, with the dependency gate bypassed (e.g. admin preview)
- **WHEN** a user creates an object of the attached schema
- **THEN** the object is created normally
- **AND** no request is sent to `/apps/procest/...` and one console warning notes the skipped case start

### Requirement: REQ-PWA-007 Integration contract pinned to Procest's existing public API surface

OpenBuild's Procest integration SHALL call exactly the following existing Procest routes and no others: `GET /apps/procest/api/zgw/catalogi/v1/zaaktypen` (case-type picker), `POST /apps/procest/api/zgw/zaken/v1/zaken` (case start), `GET /apps/procest/api/zgw/zaken/v1/zaken/{uuid}` (case detail), `GET /apps/procest/api/zgw/zaken/v1/statussen` (status history), and `POST /apps/procest/api/zgw/zaken/v1/zaken/_zoek` (kenmerk reconciliation) — plus, once it exists, the flagged per-case task-list endpoint. All calls use the caller's Nextcloud session; openbuild SHALL NOT bypass, re-implement, or cache-invalidate Procest's authorization, SHALL NOT import Procest PHP classes or read its tables, and SHALL NOT modify case data beyond the create in REQ-PWA-003. The contract SHALL be pinned by a Newman collection asserting each route's request/response shape against the dev instance, so Procest-side drift fails CI rather than production.

#### Scenario: Contract surface is closed

- **WHEN** the openbuild source tree is scanned for `/apps/procest/` references
- **THEN** every runtime call target is one of the five listed routes (or the feature-probed tasks endpoint)
- **AND** no Procest PHP namespace is imported anywhere in openbuild

#### Scenario: Newman pins the ZRC create contract

- **GIVEN** the Newman collection's case-start request with a seeded zaaktype
- **WHEN** the collection runs against the dev instance
- **THEN** the ZRC create returns 201 with `url` and `uuid` fields
- **AND** a follow-up `_zoek` by the kenmerk returns the created case

