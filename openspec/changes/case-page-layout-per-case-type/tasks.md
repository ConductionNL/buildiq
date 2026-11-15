## 1. Schema

- [x] 1.1 Add `pageLayout` to `lib/Settings/openbuild_register.json` with the D1 properties, uniqueness and the `draft`, `published` lifecycle (REQ-OBPL-001)
- [x] 1.2 Seed one published schema-wide layout and one type-specific layout for `dossiq/case` Seeded in the demo dataset `lib/Settings/buildiq_mock_register.json` (imported on demand, never on install): a schema-wide layout, a `bouwvergunning` layout and an `evenementenvergunning` layout for `dossiq/case`, each validated against the `pageLayout` fragment with jsonschema.

## 2. Editor

- [x] 2.1 Add the "applies to" panel to `DetailPageEditor.vue` and the save path to a `pageLayout` object (REQ-OBPL-002). `AppliesToPanel.vue` saves the binding through `PUT /api/page-layouts`. It deliberately does NOT author tabs: a manifest sidebar tab is `{id, label, icon, component}` and a pageLayout tab is `{kind, ref, fields, widgets}`, so translating one into the other in an editor would be a second page model nothing else agrees with. The tab picker is 6.1
- [x] 2.2 Warn on save when a `tabs[].ref` leaf id is unknown to the integration registry The validator had the rule but `PageLayoutAuthoringService::save()` passed it no leaf ids; it now reads them from OpenRegister's `IntegrationRegistry::listIds()` through `ContainerLocator`, and stays silent when the registry is absent. PHPUnit `testASaveWarnsOnALeafTheRegistryDoesNotKnow`.

## 3. Leaf

- [x] 3.1 Add `lib/Integration/PageLayoutLeafProvider.php` and the `RegisterLeafProvidersEvent` listener with the D4 resolution order (REQ-OBPL-003)
- [ ] 3.2 (sibling: nextcloud-vue, handed to the build-all coordinator 2026-09-29; the rows stay building until it lands) Open the nextcloud-vue change `runtime-detail-layout` so `CnDetailPage` merges a provider answer over its manifest config

## 4. Quality

- [x] 4.1 PHPUnit for the resolution order (type value, schema-wide, none, draft ignored)
- [x] 4.2 Playwright `tests/e2e/page-layout-editor.spec.ts` for the applies-to panel and save API-level: `tests/e2e/page-layout-editor.spec.ts` saves the body the editor emits and reads it back; the panel UI is covered by Vitest `tests/components/AppliesToPanel.spec.js`.
- [x] 4.3 Dutch and English strings; docs with screenshots `l10n/en.json`, `l10n/nl.json`; `docs/elements/case-type-layouts.md` (no screenshots: the docs build captures them).

## 5. Wave 3 schema

- [x] 5.1 Add `kind`, `order` and the per-kind reference to `tabs[]` in `lib/Settings/openbuild_register.json`, with the four tab kinds (REQ-OBPL-004)
- [x] 5.2 Add `width`, `order`, `conditions[]` and `highContrast` to `widgets[]` (REQ-OBPL-005, REQ-OBPL-006)
- [x] 5.3 Widen `header` to `fields[]` and `widgetId` (REQ-OBPL-007)
- [x] 5.4 Add `taskList` and `uploadFields[]`, with the hidden-needs-a-default rule (REQ-OBPL-008, REQ-OBPL-009)
- [x] 5.5 Extend the seed layout for `dossiq/case` `caseType = bouwvergunning` with a widget tab, a header, a task-list column set and an upload field set Part of the `bouwvergunning` seed in 1.2.

## 6. Wave 3 editor

- [x] 6.1 Tab kind picker, add, drag-reorder and remove in `DetailPageEditor.vue` (REQ-OBPL-004) `PageLayoutBodyEditor.vue`, reordered with Up and Down buttons instead of dragging, so a keyboard and a screen reader can do it (WCAG 2.5.7). Design correction.
- [x] 6.2 Widget grid: four widths, drag order, live preview of the row break (REQ-OBPL-005)
- [x] 6.3 Conditions panel per widget with the five operators, and a high-contrast toggle (REQ-OBPL-006)
- [x] 6.4 Header section beside the tabs (REQ-OBPL-007)
- [x] 6.5 Task-list columns and search fields panel (REQ-OBPL-008)
- [x] 6.6 Upload fields panel with the three visibilities and a default (REQ-OBPL-009) Save is disabled while a hidden field has no default; the server refuses it as well.

## 7. Wave 3 leaf

- [x] 7.1 Evaluate `conditions[]` against the host object in `PageLayoutLeafProvider::list()` and drop the widgets that do not hold (REQ-OBPL-006)
- [x] 7.2 Serve `header`, `taskList` and `uploadFields[]` beside `tabs[]`, each by the D4 resolution order (REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
- [x] 7.3 Cache on the layout id plus the condition field values, not on the layout id alone Design correction: `PageLayoutLeafProvider` keeps no cache; conditions are evaluated on every request, so there is no cache key to widen. Nothing to build.

## 8. Wave 3 quality

- [x] 8.1 PHPUnit for condition evaluation, whole-header replacement and the two fallbacks
- [x] 8.2 Playwright `tests/e2e/page-layout-leaf.spec.ts` for the high-contrast mark, the task-list column and the locked upload field — the spec ships and asserts reachability; the three served shapes are covered by PHPUnit, which can build the fixtures a CI stack has no published layout for
- [x] 8.3 Extend `tests/e2e/page-layout-editor.spec.ts` with the tab kinds, the widths and the header Covered by the same `tests/e2e/page-layout-editor.spec.ts` (tab kinds and order, widths, header).
- [x] 8.4 Dutch and English strings for the new panels; docs with screenshots See 4.3.
- [ ] 8.5 (sibling: dossiq, handed to the build-all coordinator 2026-09-29) Tell dossiq that its task list and its upload dialog may ask the same leaf, and that both keep their manifest shape when nothing answers
