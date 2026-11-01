# Tasks: pages-task-panel-beside-case-tabs

- [ ] **T01**: Add `panel` with `placement` (`side`, `tab`, `none`), `tabs[]` and `collapsed` to `taskList` in `lib/Settings/register.d/51-page-layouts.json`, and bump the register version (REQ-BQTP-001). Verify: PHPUnit on the register import accepts a layout with a side panel and refuses an unknown `placement`.
- [ ] **T02**: Refuse a save whose `taskList.panel.tabs[]` names a tab id the layout does not declare, in the path `PageLayoutAuthoringService` saves through, naming the id (REQ-BQTP-002). Verify: PHPUnit `PageLayoutAuthoringServiceTest` case for an unknown tab id.
- [ ] **T03**: Serve `taskList.panel` through `PageLayoutPresenter::serve()` with the existing fall-through, and keep `placement: none` distinct from an absent panel (REQ-BQTP-003). Verify: PHPUnit on `PageLayoutLeafProvider::list()` for type layout, schema-wide fall-back and `none`.
- [ ] **T04**: In the task-list panel of the detail-page editor (task 6.5 of `case-page-layout-per-case-type`), add a placement picker and a tab checklist fed from the layout's `tabs[]` (REQ-BQTP-001, REQ-BQTP-002). Verify: vitest spec for the panel under `src/components/page-editor/fields/__tests__/`, and extend `tests/e2e/page-layout-authoring.spec.ts` to place the panel beside two tabs and publish.
- [ ] **T05**: Extend `tests/e2e/page-layout-leaf.spec.ts` to assert the served `taskList.panel` for a published `bouwvergunning` layout (REQ-BQTP-003).
- [ ] **T06**: Tell dossiq the shape: add the consumer task to dossiq's competitor-parity umbrella through its lane, with the leaf id and an example response.
- [ ] **T07**: English and Dutch strings for the picker and the checklist (`l10n/en.json`, `l10n/nl.json`), and a paragraph in `docs/` on placing the task panel.
- [ ] **T08**: Run `openspec validate pages-task-panel-beside-case-tabs --strict`.
