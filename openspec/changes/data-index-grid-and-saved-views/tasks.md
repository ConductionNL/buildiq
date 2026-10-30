# Tasks: data-index-grid-and-saved-views

- [ ] **T01**: Add `src/components/runtime/RecordGridPage.vue` over `CnDataMatrix`: load records for the register and schema, save one property per `cell-edit`, restore the old value on a failed save (REQ-BQIX-001, REQ-BQIX-002). Verify: vitest `src/components/runtime/__tests__/RecordGridPage.spec.js` with a mocked object store: a save, a refused save, a number cell.
- [ ] **T02**: Take the ADR-033 lock with `useObjectLock` before a row's first edit and make a row locked by someone else read-only with the holder's name (REQ-BQIX-002). Verify: vitest case with a lock conflict.
- [ ] **T03**: Register `record-grid` (kind `page`, ADR-049 `_note`) in `src/runtimeRegistry.js` (REQ-BQIX-001). Verify: the registry unit test lists it.
- [ ] **T04**: Offer "Grid" in `PageListEditor.vue` writing the custom-page shape, and add `GridPageEditor` for register, schema and editable columns (REQ-BQIX-001). Verify: vitest for the stored page shape; Playwright `tests/e2e/record-grid.spec.ts` builds a grid page, publishes, edits a cell in the published app and reloads to see it kept.
- [ ] **T05**: Add the "Saved views" section to `IndexPageEditor.vue`: the `allowSavedViews` switch and a list of views for everyone written as public OpenRegister views in the page's scope (REQ-BQIX-003). Verify: vitest for the switch and the view payload; Playwright case in `tests/e2e/record-grid.spec.ts` or a new `tests/e2e/index-saved-views.spec.ts` opens the published index page and applies a maker-defined view.
- [ ] **T06**: English and Dutch strings for the grid page, its editor and the views section, and a section in `docs/`.
- [ ] **T07**: Run `openspec validate data-index-grid-and-saved-views --strict`.
