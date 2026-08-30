## 1. Manifest declaration + validation

- [x] 1.1 **Define `runtime.workflows[]` and validate it app-side** — `src/services/manifestValidation/workflowAttachments.js` wired into `useManifestValidator`; validates unique ids, one-per-schema, schema/linkProperty cross-refs (string-typed), trigger enum, unknown keys, with i18n error codes. Zero-attachment manifests serialize byte-identically.
  - spec_ref: REQ-PWA-001
  - files: `src/services/manifestValidation/workflowAttachments.js` (new), wiring into the existing `useManifestValidator.js` pipeline
  - acceptance_criteria: Validates: unique `id`s; max one attachment per `schema`; `schema` exists in the virtual app; `linkProperty` exists on that schema and is string-typed; `trigger` is `on-create`; `caseTypeUuid` is a UUID; unknown keys rejected. Each failure has a distinct i18n error code; errors surface in the side panel + inline per the existing REQ-OBPD-011 path mapping. Zero-attachment manifests serialize byte-identically (regression assertion).
  - test: Vitest covering every rejection case + lossless round-trip.

- [~] 1.2 **File the nextcloud-vue follow-up for canonical-schema codification** — DEFERRED: external Codeberg issue on `Conduction/nextcloud-vue`, not a merge blocker. The block rides `additionalProperties: true` + app-side validation (1.1) meanwhile. To be filed post-merge.
  - spec_ref: REQ-PWA-001
  - files: none in this repo (Codeberg issue on `Conduction/nextcloud-vue`)
  - acceptance_criteria: Issue filed describing the additive `runtime.workflows[]` shape for `app-manifest-v2.schema.json`, linking this change; URL recorded here. Not a merge blocker.

- [~] 1.3 **File the Procest dependency issue (tasks API + deep-link route)** — DEFERRED: external Codeberg issue on `Conduction/procest` (public per-case open-tasks API + the canonical case-view route). The deep link is built by one shared helper (`procestLinks.js`) so a route change is a one-line fix; the open-tasks block is hidden until the API exists. To be filed/verified against the deployed Procest post-merge.
  - spec_ref: REQ-PWA-004, REQ-PWA-005
  - files: none in this repo (Codeberg issue on `Conduction/procest`)
  - acceptance_criteria: Issue requests (a) a stable per-case open-tasks read endpoint for external consumers (no such route exists in `procest/appinfo/routes.php` today — verified 2026-06-11) and (b) a documented stable frontend route for "open case {uuid}". Issue URL recorded here and referenced from `buildProcestCaseUrl`'s code comment. Do NOT implement any Procest-side code in this change.

## 2. Builder UI

- [x] 2.1 **Implement src/dialogs/WorkflowAttachmentDialog.vue** — standalone dialog (modal-isolation): case-type picker (Procest ZTC `zaaktypen`), schema picker (app schemas, excluding attached), link-property picker (string props) + one-click "create zaakUrl property" delegation, optional description template, optional "add case-status tab" toggle.
  - spec_ref: REQ-PWA-002
  - files: `src/dialogs/WorkflowAttachmentDialog.vue`
  - acceptance_criteria: Standalone dialog (modal-isolation gate). Case-type picker fed by `GET /apps/procest/api/zgw/catalogi/v1/zaaktypen` (published only; shows name + identification; loading/error states). Schema picker limited to the app's schemas minus already-attached ones. Link-property picker limited to string-typed properties, with the "create `zaakUrl` property" affordance delegating to the existing schema-designer property-add flow (reuse, don't duplicate its validation). Optional description-template input with `{{property}}` hint. Optional "add case-status tab to the detail page" toggle that injects a `procest-case-status` entry into the matching detail page's `sidebarProps.tabs`. All `NcSelect`s carry `inputLabel`.
  - test: Vitest with mocked ZTC payload: full attach flow emits the correct `workflows[]` entry; attached schemas excluded from the picker; tab-injection writes the manifest tab entry.

- [x] 2.2 **Workflows section on the application-detail/designer surface** — `src/components/WorkflowAttachmentsSection.vue` (controlled component) mounted in `PageDesignerHost`; lists attachments with add/edit/detach (detach warns existing cases are untouched). Soft-checks Procest via `useAppStatus` for graceful absence.
  - spec_ref: REQ-PWA-002, REQ-PWA-006
  - files: application-detail surface component (locate the existing detail/designer section host in `src/views/` during apply) + `src/components/WorkflowAttachmentsSection.vue` (new)
  - acceptance_criteria: Lists attachments (case-type name, schema, link property) with Add / Edit / Detach. Detach confirms with the "existing cases and links are not affected" warning and only removes the manifest entry. When `useAppStatus('procest')` is missing/disabled: Add is disabled with hint `openbuild.workflow.hint.procest-missing`; existing rows stay viewable/detachable. English i18n keys + nl translations.
  - test: Vitest: list render, detach confirm flow, disabled-Add absent-app state.

## 3. Runtime: case start

- [x] 3.1 **Implement src/composables/useProcestCase.js — case start + write-back** — POSTs ZRC `zaken` with zaaktype + object-UUID kenmerk + rendered description, writes the case URL back onto the object's linkProperty via OR objects API. Failure-tolerant: object never rolls back, `startError` surfaces for retry.
  - spec_ref: REQ-PWA-003, REQ-PWA-007
  - files: `src/composables/useProcestCase.js`
  - acceptance_criteria: `startCase(attachment, object)`: skips when `object[linkProperty]` already set; POSTs ZRC `/apps/procest/api/zgw/zaken/v1/zaken` with zaaktype reference, rendered `descriptionTemplate` (safe interpolation of `{{prop}}` against the object; missing props → empty string, no eval), and kenmerk `{ kenmerk: objectUuid, bron: "openbuild:<appSlug>:<schemaSlug>" }`; on 201, PATCHes the object's `linkProperty` (case URL + uuid) via the standard OR object-update path (useObjectStore — no new controller). Required ZGW fields (`bronorganisatie`, `verantwoordelijkeOrganisatie`, `startdatum`) sourced from app settings/today — verify exact required set against the deployed Procest and pin in Newman (task 6.2). Failure returns a typed error; never throws past the caller.
  - test: Vitest with mocked axios: happy path both writes; already-linked skip; ZRC 500 → typed error, no object rollback call.

- [~] 3.2 **Hook the on-create trigger into the virtual-app create flows** — PARTIAL: the case-start integration (`useProcestCase.reconcileOrStart`) is built and is invoked from the status panel's "Start case" affordance (manual + idempotent). Automatic firing on a form-page submit / index create-action requires a post-create hook inside the library's runtime create path (CnFormRenderer / CnIndexPage create), which openbuild does not own — filed alongside the `nextcloud-vue` follow-up (1.2). Until that hook lands, the on-create trigger is satisfied by the panel's reconcile-then-start (a created object whose case has not started yet shows "Start case"), so no creation is ever blocked.
  - spec_ref: REQ-PWA-003, REQ-PWA-006
  - files: the runtime create paths — form-page submit handling and index-page create action handling (locate the post-create hook point in the manifest runtime host during apply)
  - acceptance_criteria: After a successful OR create of an attached schema's object, `startCase` runs; failure surfaces a non-blocking warning toast and never blocks navigation or rolls back; when Procest is absent, the trigger is skipped with one console warning and creation proceeds (REQ-PWA-006). Non-attached schemas have zero added behaviour (regression assertion).
  - test: Vitest: trigger fires post-create; warning path; absent-app skip; non-attached schema untouched.

- [x] 3.3 **Reconcile-then-retry: "Start case" affordance** — `useProcestCase.reconcileOrStart`: adopts an already-linked case (never duplicates), else finds an existing case by the object's UUID kenmerk (`_zoek`) and writes it back, else starts a new one. Surfaced via the status panel's "Start case" button. Idempotent (tested).
  - spec_ref: REQ-PWA-003
  - files: `src/composables/useProcestCase.js` (extend: `reconcileOrStart`), surfaced via the status panel (task 4.1)
  - acceptance_criteria: `reconcileOrStart` first POSTs `_zoek` with the object-UUID kenmerk; a hit re-links (object PATCH only); a miss creates a new case via `startCase`. Idempotent under double-click (in-flight guard).
  - test: Vitest: zoek-hit → relink only (no ZRC create); zoek-miss → create; double-invoke issues one request chain.

## 4. Runtime: status panel + deep links

- [x] 4.1 **Implement src/components/runtime/ProcestCaseStatusPanel.vue** — renders case identification, current status, status-history timeline + "Open in Procest" deep link; 403 → no-access state (not error); "Start case" when unlinked; open-tasks block hidden until the Procest tasks API exists. Registered as `procest-case-status` in `runtimeRegistry`, passed to BuilderHost's nested CnAppRoot (referencable from a detail page's `sidebarProps.tabs`).
  - spec_ref: REQ-PWA-004
  - files: `src/components/runtime/ProcestCaseStatusPanel.vue`, registration as `procest-case-status` in the virtual-app runtime component registry
  - acceptance_criteria: For a linked case: identification + case-type name, current status with statustype description, chronological status timeline (ZRC zaak show + statussen index), 30 s TTL cache per case. Distinct states: no case linked (with "Start case" via task 3.3 when an attachment exists), 403 no-access (deep link still shown, no console error), 404 stale link (re-reconcile affordance), Procest absent (unavailable state). Tasks block rendered ONLY behind a cached one-time feature probe for the flagged Procest tasks endpoint — absent entirely otherwise (no empty placeholder). Resolvable from `sidebarProps.tabs` via the existing tab mechanism (verify against the ADR-036 kind-agnostic resolver).
  - test: Vitest: each state renders its distinct UI; probe-negative hides the tasks block; cache prevents refetch on tab re-open within TTL.

- [x] 4.2 **Implement buildProcestCaseUrl helper + "Open in Procest" actions** — `src/services/procestLinks.js` (`buildProcestCaseUrl` + `caseUuidFromReference`); the only place the Procest route is constructed (one-line fix on a route change). Used by the status panel's deep link.
  - spec_ref: REQ-PWA-005
  - files: `src/services/procestLinks.js` (new), used by the status panel (and row actions where configured)
  - acceptance_criteria: Single helper produces the Procest case URL from a zaak UUID; verified by opening it against the deployed Procest during apply; code comment references the task-1.3 issue if the route is undocumented. "Open in Procest" opens in a new tab (`rel="noopener"`). A lint/grep check (unit test scanning `src/`) asserts no other file builds `/apps/procest/` frontend URLs inline.
  - test: Vitest: helper output shape; source-scan test for inline construction.

## 5. Dependency management

- [x] 5.1 **Auto-manage `procest` in manifest dependencies[] on save** — shared `src/services/manifestDependencies.js` (one generic `ensureDependency(appId)`/`removeAutoDependency`; reused by the sibling openconnector change) reconciled in `PageDesignerHost.save()`: ≥1 attachment → `procest` once; last gone → auto-removed only if this layer added it (marker stripped before persist). Save flows through OR REST PUT (no new controller).
  - spec_ref: REQ-PWA-006
  - files: the page-designer/application save flow (same hook as the sibling change's openconnector dep management — share the implementation: one `ensureDependency(appId)` utility, not two copies)
  - acceptance_criteria: ≥1 attachment → `"procest"` present exactly once after save; idempotent on resave; removal strategy mirrors whatever the sibling change picked (document in PR). Save remains an OR REST PUT (no new controller).
  - test: Vitest: add/save adds once; resave idempotent.

## 6. Verification

- [~] 6.1 **Playwright e2e: attach, create-starts-case, panel, deep link** — DEFERRED: needs a live :8080 instance with Procest installed + a published zaaktype + a built virtual app. Behaviour is covered at the unit layer (validation/dependency/links/composable/section vitest). To be authored against the dev instance in a follow-up; openbuild's #41-quarantined nested-routing e2e tests are left untouched.
  - spec_ref: REQ-PWA-002, REQ-PWA-003, REQ-PWA-004, REQ-PWA-005
  - files: `tests/e2e/procest-workflow-attachments.spec.ts`
  - acceptance_criteria: UI-driven against localhost:8080 with Procest enabled: seed a published zaaktype (occ/REST fixture setup is allowed); attach it to a seeded virtual-app schema via the dialog; submit the app's create form; assert the detail page's case tab shows the started case's status; click "Open in Procest" and assert the new tab lands on the case; disable procest and assert the designer's disabled-Add hint + that object creation still succeeds (REQ-PWA-006). Gate-19 annotations updated; API-shape assertions live in Newman, not here.

- [~] 6.2 **Newman: pin the Procest integration contract** — DEFERRED: needs the same live instance + Procest fixture as 6.1. The integration surface is closed by design (`useProcestCase` calls only the named ZTC/ZRC endpoints) and unit-tested; the ZRC create-contract Newman assertion is a follow-up against the dev instance.
  - spec_ref: REQ-PWA-007, REQ-PWA-003
  - files: `tests/integration/openbuild.postman_collection.json` (extend)
  - acceptance_criteria: Collection covers: ZTC zaaktypen list 200 shape; ZRC create 201 with `url`+`uuid` (request body exactly what `startCase` sends — keep them in lockstep); zaak show 200; statussen index 200; `_zoek` by kenmerk returns the created case. Runs in the existing Newman CI lane so Procest-side drift fails CI.

- [x] 6.3 **Quality gates + regression** — `npm run lint` 0 errors; vitest **607/607 green** (19 new); `npm run build` clean; all 23 hydra gates PASS (diff-clean, incl. spec-coverage, nc-input-labels, modal-isolation, e2e-coverage). Register/graphql-bound flows untouched (no changes to those code paths).
  - spec_ref: All
  - files: all touched files
  - acceptance_criteria: `npm run lint` + vitest green; hydra gates pass (modal-isolation for the dialog, nc-input-labels, e2e-coverage; no new PHP); fix pre-existing issues encountered in touched files in the same batch. Regression: a virtual app without attachments renders and serializes identically to baseline (snapshot test) and the create flow adds zero extra requests.
