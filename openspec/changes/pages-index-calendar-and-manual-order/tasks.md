# Tasks: pages-index-calendar-and-manual-order

- [ ] **T01**: `src/components/page-editor/fields/IndexViewModesBuilder.vue`, mounted in `IndexPageEditor.vue`: checkboxes for `table`, `cards` and `calendar` writing `config.viewModes` (table always kept), and with calendar three `NcSelect`s over the schema's date and date-time properties (`config.calendar.dateField`, `endDateField`) and string properties (`titleField`) (REQ-PEC-008). Verify: vitest `tests/components/page-editor/IndexViewModesBuilder.spec.js`: calendar without a date field is a finding; unticking calendar removes `config.calendar`.
- [ ] **T02**: A "Users can order rows by dragging" switch in `IndexPageEditor.vue` writing `manualOrder: true` on the page object (not in `config`), and removing the key when off (REQ-PEC-009). Verify: vitest that the key lands on the page.
- [ ] **T03**: `src/services/manifestValidation/`: a page listing `calendar` in `viewModes` without `calendar.dateField`, or naming a field the schema lacks, is a designer finding (REQ-PEC-008). Verify: vitest.
- [ ] **T04**: English and Dutch strings in `l10n/en.json` and `l10n/nl.json`.
- [ ] **T05**: Playwright `tests/e2e/index-views.spec.ts`: turn on calendar on the seeded app's index page with a date field and drag order; in the built app switch to Calendar and see a record on its date; drag a row and reload to see the order kept.
- [ ] **T06**: When done, set both rows to `built` in `openspec/parity/capabilities.json` and archive this change.
