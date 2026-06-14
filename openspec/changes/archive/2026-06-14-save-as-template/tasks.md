## 1. Capture service

- [x] 1.1 **Implement src/services/templateCapture.js — capture + de-namespace**
  - spec_ref: REQ-SAT-002, REQ-SAT-006
  - files: `src/services/templateCapture.js` (new)
  - acceptance_criteria: `captureTemplate(application, schemas, metadata)` returns the `ApplicationTemplate` record shape: deep-copied manifest; `companionSchemas[]` from the app's schemas with the `<appSlug>-` prefix stripped and EVERY manifest reference rewritten (page `config`, data sources, `runtime.workflows[].schema`, `runtime.documents[].schema` — enumerate all reference sites from the manifest schema during apply); unprefixed schemas captured unchanged + marked `shared` in the returned summary; de-namespace collision returns a typed error naming both schemas (no partial result); `isSeeded: false`; `version` from the app; NEVER includes object rows. Pure function — no I/O — so the round-trip property is unit-testable.
  - test: Vitest: round-trip property (capture then apply REQ-OBTC-005's prefix transform ⇒ clean rename, fixture from a real template manifest); shared-schema pass-through; collision typed error; no-rows assertion.

- [x] 1.2 **OR write path: create / update-in-place via useObjectStore**
  - spec_ref: REQ-SAT-004, REQ-SAT-006
  - files: `src/services/templateCapture.js` (extend), existing `useObjectStore` wiring
  - acceptance_criteria: Create posts the record to the `ApplicationTemplate` schema via OR REST; update-in-place replaces `manifest` + `companionSchemas`, refreshes metadata, bumps `version` minor — implemented as a standard OR object update on the existing record (no delete+recreate, UUID stable). Slug-collision resolution: seeded slug ⇒ typed `seeded-slug` error; org-local + writable ⇒ update offer; org-local + not writable ⇒ `slug-taken` error (writability read from OR's standard per-object rights, no openbuild-local role logic). Zero new PHP anywhere (assert `lib/` + `appinfo/routes.php` untouched in the PR).
  - test: Vitest with mocked store: create payload; update keeps UUID + bumps 1.0.0→1.1.0; all three collision branches.

## 2. Builder UI

- [x] 2.1 **Implement src/dialogs/SaveAsTemplateDialog.vue**
  - spec_ref: REQ-SAT-001, REQ-SAT-002, REQ-SAT-003, REQ-SAT-004
  - files: `src/dialogs/SaveAsTemplateDialog.vue`
  - acceptance_criteria: Standalone dialog (modal-isolation gate). Metadata form: title (prefilled), auto-suggested editable kebab-case slug, description, useCase, category picker over the REQ-OBTC-001 enum, optional sourceUrl. Capture summary lists manifest + each companion schema with de-namespaced slug, shared-schema flags, and renders the collision hard-block. Validation gate: canonical `validateManifest` + app-side layer run against the captured de-namespaced manifest; errors disable Save and render via the existing validation-display path. Update-in-place confirm flow when the slug hits an own org-local template; `seeded-slug` and `slug-taken` errors inline. All `NcSelect`s carry `inputLabel`; English i18n keys under `openbuild.templates.saveAs.*` + nl translations.
  - test: Vitest: happy create flow emits the capture call with form metadata; validation-failure disables Save; collision branches render their distinct states; update confirm path.

- [x] 2.2 **"Save as template" action on the application-detail surface**
  - spec_ref: REQ-SAT-001
  - files: application-detail surface component (locate the existing action host in `src/views/` during apply)
  - acceptance_criteria: Action visible only for editor/owner (reuse the surface's existing rbac-driven action gating — same source of truth as edit actions, no new role logic); opens the dialog with the current app + schemas. Hidden for viewers.
  - test: Vitest: visibility per role; dialog receives the app context.

## 3. Gallery management

- [x] 3.1 **Org-local badge + Edit-metadata/Delete actions in the gallery**
  - spec_ref: REQ-SAT-005
  - files: `src/views/TemplateGallery.vue` (extend), `src/dialogs/EditTemplateMetadataDialog.vue` (new, modal-isolation), delete confirm via existing confirm pattern
  - acceptance_criteria: `isSeeded: false` cards render the "Organisation template" badge; Edit/Delete render ONLY when OR reports the caller may write the record (per-object rights from the OR response — no openbuild-local check). Edit dialog covers metadata fields only (title/description/useCase/category/sourceUrl), never manifest/companions. Delete confirm states clones are unaffected and removes only the template record. Seeded cards byte-identical to current rendering (REQ-OBTC-008 regression). "Use this template" path untouched for both kinds.
  - test: Vitest: badge + rights-gated actions; metadata-only edit payload; delete confirm flow; seeded-card regression snapshot.

## 4. Verification

- [~] 4.1 **Playwright e2e: save, gallery, clone round-trip, update, delete**
  - spec_ref: REQ-SAT-001, REQ-SAT-002, REQ-SAT-004, REQ-SAT-005
  - files: `tests/e2e/save-as-template.spec.ts`
  - acceptance_criteria: UI-driven against localhost:8080: clone a seeded template into app A (fixture); customise; "Save as template" via the dialog; assert the gallery shows the org-local badge card; clone the new template into app B via the gallery and assert app B loads with correctly re-prefixed schema references (round-trip pin, REQ-SAT-002); update-in-place from app A and assert the gallery card's version bumped while app B is untouched; delete the template and assert apps A+B still load. Viewer-account check: no save action, no manage actions. Gate-19 annotations updated; API-shape assertions live in Newman, not here.

- [x] 4.2 **Newman: pin the OR-level template contracts**
  - spec_ref: REQ-SAT-004, REQ-SAT-006
  - files: `tests/integration/openbuild.postman_collection.json` (extend)
  - acceptance_criteria: Collection covers: ApplicationTemplate create via OR REST with `isSeeded: false` (201 + org scoping); update-in-place keeps UUID; unauthorized write to a foreign template rejected by OR (RBAC pin, REQ-SAT-006); `from-template` clone of a user template returns 201 with `templateOrigin` populated (existing endpoint, new record kind). Runs in the existing Newman CI lane.

- [x] 4.3 **Quality gates + regression**
  - spec_ref: All
  - files: all touched files
  - acceptance_criteria: `npm run lint` + vitest green; hydra gates pass (modal-isolation for both dialogs, nc-input-labels, e2e-coverage; redundant-controller N/A — zero new PHP, assert in PR); fix pre-existing issues encountered in touched files in the same batch. Regression: seeded-template gallery rendering and the existing clone flow are snapshot/behaviour-identical to baseline.
