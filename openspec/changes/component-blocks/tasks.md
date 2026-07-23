## 1. Schema

- [ ] 1.1 Add the `ComponentBlock` schema to `lib/Settings/openbuild_register.json` (uuid, slug, name, description, category, schemaDependencies, fragment, sourceApplicationSlug, createdBy); bump schema version.

## 2. Capture (save as block)

- [ ] 2.1 `src/dialogs/SaveBlockDialog.vue` — name/description/category inputs + dependency summary, reusing `save-as-template`'s `deNamespaceSlug` logic against a selected widget/section subtree.
- [ ] 2.2 Widget selection affordance and page-section (multi-widget) selection affordance in `PageDesigner.vue`, both feeding `SaveBlockDialog`.
  - acceptance: saved fragment never contains object data, only structure

## 3. Library panel

- [ ] 3.1 Block-library panel (org-scoped list, category filter, fragment preview) reachable from the page designer.

## 4. Insert + remap

- [ ] 4.1 `BlockInsertService` — deep-copy the fragment, mint fresh `widgetEntry.id`/`page.id` for every copied node.
- [ ] 4.2 `src/dialogs/BlockRemapDialog.vue` — opens when `schemaDependencies` don't exact-match the target app; unresolved bindings insert as a visible "needs remap" placeholder, never silently dropped.
- [ ] 4.3 Insert never creates a live reference back to the source block (verify: editing source after insert does not affect an existing copy).

## 5. Export/import

- [ ] 5.1 Export a block as standalone JSON (`schemaVersion`, `kind: "component-block"`, `uuid`/`createdBy` stripped) via the existing `ExportService`/`ExportsController` download pattern.
- [ ] 5.2 Import validates the shape, creates a new `ComponentBlock`, and triggers the remap flow (4.2) when the importing organisation's schemas don't match.

## 6. Template catalogue integration

- [ ] 6.1 Add the "Blocks" filter to `TemplateGallery.vue`; block cards omit the "Use this template" clone action.

## 7. Tests

- [ ] 7.1 Vitest: capture (de-namespace), insert (id-mint, no-collision-on-double-insert), remap-dialog trigger conditions.
- [ ] 7.2 Newman: `ComponentBlock` CRUD via standard OR REST; export/import round-trip.
- [ ] 7.3 Playwright: save a widget as a block, insert it into a different app, resolve the remap prompt, confirm it renders bound to the chosen schema.

## 8. Verify

- [ ] 8.1 `composer check:strict` and hydra mechanical gates (redundant-controller, spec-coverage) green on the diff.
- [ ] 8.2 `openspec validate "component-blocks"` passes and `openspec status` shows all artifacts complete before archiving.
