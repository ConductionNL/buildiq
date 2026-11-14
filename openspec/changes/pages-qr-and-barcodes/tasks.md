# Tasks: pages-qr-and-barcodes

- [x] **T01**: Add the "Scan" section to `src/components/page-editor/IndexPageEditor.vue` writing `config.scan` (REQ-BQQR-001). Verify: vitest case in `tests/components/page-editor/IndexPageEditor.spec.js`.
  - Done: the "Scan" section (switch, the schema's fields, open or filter) writes `config.scan = {enabled, field, onMatch}`; vitest cases in `IndexPageEditor.spec.js`.
- [x] **T02**: Add the `scan` field type with formats to `FormFieldBuilder.vue` (REQ-BQQR-002). Verify: vitest case in `tests/components/page-editor/FormFieldBuilder.deviceFields.spec.js`.
  - Done: `scan` in `FIELD_TYPES`, accepting all formats when picked and dropping `formats` for another type; vitest `tests/components/page-editor/FormFieldBuilder.scan.spec.js` (the `deviceFields` spec belongs to `forms-signature-audio-and-location`, not built yet).
- [x] **T03**: Add the `code` widget type with format and source to the detail page widget editor (REQ-BQQR-003). Verify: vitest case in `tests/components/page-editor/DetailPageEditor.spec.js`.
  - Done: a "Codes" section on the detail page editor (`fields/CodeWidgetBuilder.vue`) writes `code` widgets into `config.widgets`, keeping other widgets in place; vitest `CodeWidgetBuilder.spec.js` and a `DetailPageEditor.spec.js` case. The detail page's `widgets` key is the renderer contract T06 files with nextcloud-vue.
- [x] **T04**: Validate `scan` and `code` in `src/services/manifestValidation/` as in D5 (REQ-BQQR-001, REQ-BQQR-002, REQ-BQQR-003). Verify: a new `tests/services/pageCodesValidation.spec.js`.
  - Done: `src/services/manifestValidation/pageCodes.js`, wired into `useManifestValidator`; `tests/services/pageCodesValidation.spec.js`.
- [ ] **T05**: Add `camera` to `deviceFeatures[]` on manifest save when scanning is used, and apply the policy in `builder()`, `builderSlash()` and `builderPath()` (REQ-BQQR-004). Verify: PHPUnit cases for the derivation and each response.
  - Waits: `deviceFeatures[]` and the per-response policy come from `forms-signature-audio-and-location` (D4), which is not built yet.
- [ ] **T06**: File the renderer half with nextcloud-vue: the scanner, the `scan` index action and lookup, the `scan` form field and the `code` widget, with this spec as the contract. Verify: the nextcloud-vue change exists and cites REQ-BQQR-001 to REQ-BQQR-003.
- [ ] **T07**: Playwright `tests/e2e/page-codes.spec.ts` with a fake camera stream showing a QR code: the scan opens the record, and the code widget renders (REQ-BQQR-001, REQ-BQQR-003).
- [ ] **T08**: Strings and docs: English and Dutch for the scan section, the field type, the widget and the messages (`l10n/en.json`, `l10n/nl.json`), and a section in `docs/` on scanning and showing codes.
  - Partly: the editor strings of T01-T03 are in English and Dutch; the runtime messages and the docs section wait for the renderer (T06).
- [ ] **T09**: Run `openspec validate pages-qr-and-barcodes --strict`.
