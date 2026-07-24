## 1. Schema

- [x] 1.1 Add the `ComponentBlock` schema to `lib/Settings/openbuild_register.json` (uuid, slug, name, description, category, schemaDependencies, fragment, sourceApplicationSlug, createdBy); bump schema version.
  - Implemented as an ADR-037 register fragment (`lib/Settings/register.d/60-component-blocks.json`) per build instructions — do NOT edit the register monolith directly. New disjoint `componentBlock` schema key (key-union merge, no existing schema touched); schema-level `version: "0.1.0"` set on the new schema object (mirrors `ApplicationTemplate`'s per-schema versioning — the fragment-content-hash mechanism in `SettingsService::doLoadConfiguration()` already re-versions the overall import automatically whenever any fragment changes, so no monolith `info.version` bump was needed).

## 2. Capture (save as block)

- [x] 2.1 `src/dialogs/SaveBlockDialog.vue` — name/description/category inputs + dependency summary, reusing `save-as-template`'s `deNamespaceSlug` logic against a selected widget/section subtree.
- [x] 2.2 Widget selection affordance and page-section (multi-widget) selection affordance in `PageDesigner.vue`, both feeding `SaveBlockDialog`.
  - acceptance: saved fragment never contains object data, only structure
  - Implemented as `src/components/page-editor/WidgetSelectionPanel.vue`, mounted in `PageDesigner.vue`'s centre pane below the sub-editor. Checkbox-select one or more of `selectedPage.widgets[]` (the uniform v2 widgetEntry array every page type shares); one selection captures a single-widget block, several capture a section block via `buildSectionFragment`.

## 3. Library panel

- [x] 3.1 Block-library panel (org-scoped list, category filter, fragment preview) reachable from the page designer.
  - `src/components/page-editor/BlockLibraryPanel.vue`, rendered inside an `NcAppSidebar` in `PageDesigner.vue` (toolbar "Blocks" button), per design.md's Open Question resolution.

## 4. Insert + remap

- [x] 4.1 `BlockInsertService` — deep-copy the fragment, mint fresh `widgetEntry.id`/`page.id` for every copied node.
  - `src/services/blockInsert.js#insertBlock`. Insert merges the returned widgets onto the target page via the app's existing `mergeManifestDelta` keyed-merge engine (widgets[] merges by id) — reusing the manifest-delta utilities rather than hand-splicing the manifest.
- [x] 4.2 `src/dialogs/BlockRemapDialog.vue` — opens when `schemaDependencies` don't exact-match the target app; unresolved bindings insert as a visible "needs remap" placeholder, never silently dropped.
- [x] 4.3 Insert never creates a live reference back to the source block (verify: editing source after insert does not affect an existing copy).
  - Verified by unit test (`tests/vitest/blockInsert.spec.js` — "editing the source block after insert does not affect an inserted copy"): insert reads and deep-clones the fragment once; mutating the source object afterward does not affect the already-produced widget.

## 5. Export/import

- [x] 5.1 Export a block as standalone JSON (`schemaVersion`, `kind: "component-block"`, `uuid`/`createdBy` stripped) via the existing `ExportService`/`ExportsController` download pattern.
  - Implemented client-side (`src/services/blockExport.js`) using the SAME download shape the app's other "export as JSON" flow already uses (`RuleSetsPage.vue#exportRuleSet` — Blob + `URL.createObjectURL` + anchor click), rather than routing a single-object download through the async `ExportJobService`/`ExportsController` job pipeline built for whole-Application zip/GitHub exports. Documented deviation — see PR description / final report.
- [x] 5.2 Import validates the shape, creates a new `ComponentBlock`, and triggers the remap flow (4.2) when the importing organisation's schemas don't match.

## 6. Template catalogue integration

- [x] 6.1 Add the "Blocks" filter to `TemplateGallery.vue`; block cards omit the "Use this template" clone action.

## 7. Tests

- [x] 7.1 Vitest: capture (de-namespace), insert (id-mint, no-collision-on-double-insert), remap-dialog trigger conditions.
  - `tests/vitest/blockCapture.spec.js` (11 tests) + `tests/vitest/blockInsert.spec.js` (21 tests) — 32 new tests, all passing.
- [x] 7.2 Newman: `ComponentBlock` CRUD via standard OR REST; export/import round-trip.
  - `tests/integration/openbuild-component-blocks.postman_collection.json`, mirroring the existing `openbuild-templates-marketplace.postman_collection.json` pattern (per-spec collection, not wired into the `test:newman` npm script — same as its precedent). Not executed against a live instance in this build (no deploy to the shared dev instance).
- [x] 7.3 Playwright: save a widget as a block, insert it into a different app, resolve the remap prompt, confirm it renders bound to the chosen schema.
  - `tests/e2e/component-blocks.spec.ts`, QUARANTINED (`test.describe.skip`) under the same tracked issue (Conduction/openbuild#41 — admin UI does not render the page-designer/application-detail surfaces in this build) as `save-as-template.spec.ts` and `template-gallery.spec.ts`. `@e2e` scenario-traceability tags included for gate-19.

## 8. Verify

- [x] 8.1 `composer check:strict` and hydra mechanical gates (redundant-controller, spec-coverage) green on the diff.
  - PHPUnit: 699/699 passing (no PHP files changed — schema is a JSON fragment). No PHP quality tools apply (composer phpcs/phpmd/psalm/phpstan scope `lib/*.php`, none touched). Hydra gates scoped to diff vs `origin/development`: 37/39 green; gate-30 (`effective-manifest-crossref`) and gate-46 (`spec-anchor-existence`) fail identically on pristine `origin/development` HEAD (verified) — pre-existing repo-wide debt, not introduced by this change.
- [x] 8.2 `openspec validate "component-blocks"` passes and `openspec status` shows all artifacts complete before archiving.
