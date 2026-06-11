## 1. Manifest declaration + validation

- [ ] 1.1 **Define `runtime.documents[]` and validate it app-side**
  - spec_ref: REQ-DDT-001
  - files: `src/services/manifestValidation/documentAttachments.js` (new), wiring into the existing `useManifestValidator.js` pipeline
  - acceptance_criteria: Validates: unique `id`s; unique *(schema, label)* pairs; `schema` exists in the virtual app; `templateId` is a UUID; `label` non-empty; `format` within the pinned value set (single source-of-truth constant shared with the dialog picker, task 2.1); unknown keys rejected. Each failure has a distinct i18n error code surfacing inline per the existing validation-path mapping. Zero-attachment manifests serialize byte-identically (regression assertion).
  - test: Vitest covering every rejection case + multi-attachment-per-schema acceptance + lossless round-trip.

- [ ] 1.2 **File the nextcloud-vue follow-up for canonical-schema codification**
  - spec_ref: REQ-DDT-001
  - files: none in this repo (Codeberg issue on `Conduction/nextcloud-vue`)
  - acceptance_criteria: Issue filed describing the additive `runtime.documents[]` shape for `app-manifest-v2.schema.json`, linking this change (and the two sibling runtime-block issues); URL recorded here. Not a merge blocker.

- [ ] 1.3 **Verify the Docudesk surface + file the dependency issue if gaps confirmed**
  - spec_ref: REQ-DDT-002, REQ-DDT-006
  - files: none in this repo (Codeberg issue on `Conduction/docudesk` if needed)
  - acceptance_criteria: Against the deployed Docudesk: (a) confirm whether `GET api/templates/{id}` exposes placeholder metadata; (b) pin the exact `templates/{id}/preview` request/response shape; (c) pin the supported `options.format` value set. If (a) is absent or (c) undocumented, file the issue requesting placeholder metadata + format documentation; URL recorded here. Do NOT implement any Docudesk-side code in this change.

## 2. Builder UI

- [ ] 2.1 **Implement src/dialogs/DocumentTemplateAttachmentDialog.vue**
  - spec_ref: REQ-DDT-002
  - files: `src/dialogs/DocumentTemplateAttachmentDialog.vue`
  - acceptance_criteria: Standalone dialog (modal-isolation gate). Template picker fed by `GET /apps/docudesk/api/templates` (loading/error states). Schema picker over the app's schemas. Required label input; format picker limited to the task-1.3 pinned set; filename-template input with `{{property}}` hints. Preview affordance calls `POST api/templates/{id}/preview` and presents the result without saving. Edit refreshes `templateName` via `GET api/templates/{id}` and shows `openbuild.document.warning.template-missing` on 404. Optional "add document actions to the detail page" toggle injects `docudesk-document-actions` into the matching detail page's `sidebarProps.tabs` (same mechanism as the procest sibling's toggle). All `NcSelect`s carry `inputLabel`; English i18n keys + nl translations.
  - test: Vitest with mocked template payloads: full attach flow emits the correct entry; preview call wiring; missing-template warning; tab-injection writes the manifest entry.

- [ ] 2.2 **Documents section on the application-detail/designer surface**
  - spec_ref: REQ-DDT-002, REQ-DDT-005
  - files: application-detail surface component (same section host as the sibling's Workflows section — share layout patterns) + `src/components/DocumentAttachmentsSection.vue` (new)
  - acceptance_criteria: Lists attachments (template name, schema, label) with Add / Edit / Detach; detach removes only the manifest entry. When `useAppStatus('docudesk')` is missing/disabled: Add disabled with hint `openbuild.document.hint.docudesk-missing`; existing rows stay viewable/detachable.
  - test: Vitest: list render, detach flow, disabled-Add absent-app state.

## 3. Runtime: generation + detail surface

- [ ] 3.1 **Implement src/composables/useDocudeskDocument.js — generate + download**
  - spec_ref: REQ-DDT-003, REQ-DDT-006
  - files: `src/composables/useDocudeskDocument.js`
  - acceptance_criteria: `generate(attachment, object)`: resolves one `dataRefs` entry `{ register, schema, id }` from the runtime's active (version-routed) data context — never a serialized object payload; renders the filename (safe `{{prop}}` interpolation, missing → empty, no eval; default `<label>-<objectUuid>.<ext>`); POSTs `correspondence/generate`; hands the response to the browser as a download (blob + object-URL, revoked after click). In-flight guard per attachment+object (double-click ⇒ one request). Typed errors: 403 → `no-access`, other → `generate-failed`; never throws past the caller, never mutates the object.
  - test: Vitest with mocked axios: request shape (dataRefs + filename); download invocation; 403 vs 500 typed errors; double-click single request.

- [ ] 3.2 **Implement src/components/runtime/DocumentActions.vue + registry entry**
  - spec_ref: REQ-DDT-004, REQ-DDT-005
  - files: `src/components/runtime/DocumentActions.vue`, registration as `docudesk-document-actions` in the virtual-app runtime component registry
  - acceptance_criteria: One button per attachment for the object's schema, declared order, per-button busy/error states wired to task 3.1. Renders nothing (no placeholder/heading) when the schema has no attachments. Docudesk absent ⇒ unavailable state, zero requests to `/apps/docudesk/...`. Resolvable from `sidebarProps.tabs` via the existing tab mechanism (verify against the ADR-036 kind-agnostic resolver).
  - test: Vitest: ordered buttons; isolated busy state; empty render; absent-app state issues no request.

## 4. Dependency management

- [ ] 4.1 **Auto-manage `docudesk` in manifest dependencies[] on save**
  - spec_ref: REQ-DDT-005
  - files: the shared `ensureDependency(appId)` utility introduced by the sibling changes (extend its callers; if neither sibling has landed yet, create it here and note the share in the PR)
  - acceptance_criteria: ≥1 attachment → `"docudesk"` present exactly once after save; idempotent on resave; one shared implementation across procest/openconnector/docudesk integrations (no copies). Save remains an OR REST PUT (no new controller).
  - test: Vitest: add/save adds once; resave idempotent.

## 5. Verification

- [ ] 5.1 **Playwright e2e: attach, preview, generate, download, absence**
  - spec_ref: REQ-DDT-002, REQ-DDT-003, REQ-DDT-004, REQ-DDT-005
  - files: `tests/e2e/docudesk-document-templates.spec.ts`
  - acceptance_criteria: UI-driven against localhost:8080 with Docudesk enabled: seed a Docudesk template (occ/REST fixture setup allowed); attach it to a seeded virtual-app schema via the dialog (including the preview affordance); open an object's detail page and click the generate button; assert a download event with the rendered filename; disable docudesk and assert the designer's disabled-Add hint + the runtime unavailable state. Gate-19 annotations updated; API-shape assertions live in Newman, not here.

- [ ] 5.2 **Newman: pin the Docudesk integration contract**
  - spec_ref: REQ-DDT-006, REQ-DDT-003
  - files: `tests/integration/openbuild.postman_collection.json` (extend)
  - acceptance_criteria: Collection covers: templates index 200 shape; template show 200; preview request/response per the task-1.3 pinned shape; `correspondence/generate` success with `dataRefs: [{register, schema, id}]` (request body exactly what `generate` sends — keep in lockstep) + unknown-templateId 4xx shape; format values asserted against the pinned set. Runs in the existing Newman CI lane so Docudesk-side drift fails CI.

- [ ] 5.3 **Quality gates + regression**
  - spec_ref: All
  - files: all touched files
  - acceptance_criteria: `npm run lint` + vitest green; hydra gates pass (modal-isolation for the dialog, nc-input-labels, e2e-coverage; no new PHP); fix pre-existing issues encountered in touched files in the same batch. Regression: a virtual app without attachments renders and serializes identically to baseline (snapshot test) and detail pages add zero extra requests.
