## 1. Schema

- [ ] 1.1 Add `pageLayout` to `lib/Settings/openbuild_register.json` with the D1 properties, uniqueness and the `draft`, `published` lifecycle (REQ-OBPL-001)
- [ ] 1.2 Seed one published schema-wide layout and one type-specific layout for `dossiq/case`

## 2. Editor

- [ ] 2.1 Add the "applies to" panel to `DetailPageEditor.vue` and the save path to a `pageLayout` object (REQ-OBPL-002)
- [ ] 2.2 Warn on save when a `tabs[].ref` leaf id is unknown to the integration registry

## 3. Leaf

- [ ] 3.1 Add `lib/Integration/PageLayoutLeafProvider.php` and the `RegisterLeafProvidersEvent` listener with the D4 resolution order (REQ-OBPL-003)
- [ ] 3.2 Open the nextcloud-vue change `runtime-detail-layout` so `CnDetailPage` merges a provider answer over its manifest config

## 4. Quality

- [ ] 4.1 PHPUnit for the resolution order (type value, schema-wide, none, draft ignored)
- [ ] 4.2 Playwright `tests/e2e/page-layout-editor.spec.ts` for the applies-to panel and save
- [ ] 4.3 Dutch and English strings; docs with screenshots

## 5. Wave 3 schema

- [ ] 5.1 Add `kind`, `order` and the per-kind reference to `tabs[]` in `lib/Settings/openbuild_register.json`, with the four tab kinds (REQ-OBPL-004)
- [ ] 5.2 Add `width`, `order`, `conditions[]` and `highContrast` to `widgets[]` (REQ-OBPL-005, REQ-OBPL-006)
- [ ] 5.3 Widen `header` to `fields[]` and `widgetId` (REQ-OBPL-007)
- [ ] 5.4 Add `taskList` and `uploadFields[]`, with the hidden-needs-a-default rule (REQ-OBPL-008, REQ-OBPL-009)
- [ ] 5.5 Extend the seed layout for `dossiq/case` `caseType = bouwvergunning` with a widget tab, a header, a task-list column set and an upload field set

## 6. Wave 3 editor

- [ ] 6.1 Tab kind picker, add, drag-reorder and remove in `DetailPageEditor.vue` (REQ-OBPL-004)
- [ ] 6.2 Widget grid: four widths, drag order, live preview of the row break (REQ-OBPL-005)
- [ ] 6.3 Conditions panel per widget with the five operators, and a high-contrast toggle (REQ-OBPL-006)
- [ ] 6.4 Header section beside the tabs (REQ-OBPL-007)
- [ ] 6.5 Task-list columns and search fields panel (REQ-OBPL-008)
- [ ] 6.6 Upload fields panel with the three visibilities and a default (REQ-OBPL-009)

## 7. Wave 3 leaf

- [ ] 7.1 Evaluate `conditions[]` against the host object in `PageLayoutLeafProvider::list()` and drop the widgets that do not hold (REQ-OBPL-006)
- [ ] 7.2 Serve `header`, `taskList` and `uploadFields[]` beside `tabs[]`, each by the D4 resolution order (REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
- [ ] 7.3 Cache on the layout id plus the condition field values, not on the layout id alone

## 8. Wave 3 quality

- [ ] 8.1 PHPUnit for condition evaluation, whole-header replacement and the two fallbacks
- [ ] 8.2 Playwright `tests/e2e/page-layout-leaf.spec.ts` for the high-contrast mark, the task-list column and the locked upload field
- [ ] 8.3 Extend `tests/e2e/page-layout-editor.spec.ts` with the tab kinds, the widths and the header
- [ ] 8.4 Dutch and English strings for the new panels; docs with screenshots
- [ ] 8.5 Tell dossiq that its task list and its upload dialog may ask the same leaf, and that both keep their manifest shape when nothing answers
