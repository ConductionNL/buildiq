# Tasks: pages-action-buttons

- [ ] **T01**: Rewrite `ActionBuilder.vue` around the `type` discriminator with sub-forms for `object-op`, `run-node`, `open-form`, `open-page`, `navigate` and `export`, validated against `$defs.action` (REQ-BQAB-001). Verify: vitest `src/components/page-editor/fields/__tests__/ActionBuilder.spec.js`, one case per type, each saved action validating against the schema.
- [ ] **T02**: Migrate legacy `target` actions on load as design D3 describes (REQ-BQAB-004). Verify: vitest with a manifest carrying the three legacy targets.
- [ ] **T03**: Add a "Buttons" section to `DetailPageEditor.vue` writing `config.headerActions` with the same builder (REQ-BQAB-002). Verify: vitest; Playwright `tests/e2e/page-actions.spec.ts` adds a "Start review" button that runs a flow node and asserts the node ran for the open record.
- [ ] **T04**: Add a "Status buttons" section writing `config.lifecycleActions`, live by default, with an optional transition list; refuse an `object-op` on the lifecycle field (REQ-BQAB-003). Verify: vitest for the refusal; Playwright case moving a record from `submitted` to `approved` with a confirm.
- [ ] **T05**: Author `visibleWhen` on an action with `VisibleWhenBuilder.vue` (REQ-BQAB-005). Verify: vitest; Playwright case where the button shows only for records in status `submitted`.
- [ ] **T06**: English and Dutch strings for the typed editor and both sections; update `docs/` on page buttons.
- [ ] **T07**: Run `openspec validate pages-action-buttons --strict`.
