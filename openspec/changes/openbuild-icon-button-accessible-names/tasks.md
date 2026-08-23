## 1. Schema Designer sub-editors

- [x] 1.1 `src/components/schema-editor/LifecycleEditor.vue:56` — add `:aria-label="t('buildiq', 'Remove state')"` to the remove-state `NcButton`.
- [x] 1.2 `src/components/schema-editor/LifecycleEditor.vue:108` — add `:aria-label="t('buildiq', 'Remove transition')"` to the remove-transition `NcButton`.
- [x] 1.3 `src/components/schema-editor/LifecycleEditor.vue:147` — add `:aria-label="t('buildiq', 'Remove action')"` to the remove-action `NcButton`.
- [x] 1.4 `src/components/schema-editor/WidgetEditor.vue:49` — add `:aria-label="t('buildiq', 'Remove widget')"` to the remove-widget `NcButton`.
- [x] 1.5 `src/components/schema-editor/RelationEditor.vue:53` — add `:aria-label="t('buildiq', 'Remove relation')"` to the remove-relation `NcButton`.

## 2. Walkthrough Designer

- [x] 2.1 `src/components/walkthrough-editor/WalkthroughDesigner.vue:102` — add `:aria-label="t('buildiq', 'Add option')"` to the add-option `NcButton` (matches the existing `'Remove option'` label on its sibling at line 111).

## 3. i18n

- [x] 3.1 Confirm the new English source strings ('Remove state', 'Remove transition', 'Remove action', 'Remove widget', 'Remove relation', 'Add option') are picked up by the l10n extraction pipeline; run whatever `npm run l10n:*` / string-extraction script this app uses and verify the keys land in `l10n/en.json` (or the app's `.po`/Transifex source) with no Dutch text used as the key.

## 4. Verification

- [ ] 4.1 Manual keyboard + screen-reader spot check (or axe-core/Playwright a11y assertion if available) on the Lifecycle editor, Widget editor, Relation editor, and Walkthrough Designer: each of the six buttons now exposes a non-empty accessible name. DEFERRED — needs a live instance/AT; verified statically that all six NcButtons now carry a non-empty :aria-label bound to a translated string.
- [x] 4.2 Visual regression check — `aria-label` is not rendered, so no screenshot diff is expected; confirm build/lint pass (`npm run lint`, `npm run build`).
