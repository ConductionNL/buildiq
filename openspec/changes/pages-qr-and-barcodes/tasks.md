# Tasks: pages-qr-and-barcodes

- [ ] **T01**: Add the "Scan" section to `src/components/page-editor/IndexPageEditor.vue` writing `config.scan` (REQ-BQQR-001). Verify: vitest case in `tests/components/page-editor/IndexPageEditor.spec.js`.
- [ ] **T02**: Add the `scan` field type with formats to `FormFieldBuilder.vue` (REQ-BQQR-002). Verify: vitest case in `tests/components/page-editor/FormFieldBuilder.deviceFields.spec.js`.
- [ ] **T03**: Add the `code` widget type with format and source to the detail page widget editor (REQ-BQQR-003). Verify: vitest case in `tests/components/page-editor/DetailPageEditor.spec.js`.
- [ ] **T04**: Validate `scan` and `code` in `src/services/manifestValidation/` as in D5 (REQ-BQQR-001, REQ-BQQR-002, REQ-BQQR-003). Verify: a new `tests/services/pageCodesValidation.spec.js`.
- [ ] **T05**: Add `camera` to `deviceFeatures[]` on manifest save when scanning is used, and apply the policy in `builder()`, `builderSlash()` and `builderPath()` (REQ-BQQR-004). Verify: PHPUnit cases for the derivation and each response.
- [ ] **T06**: File the renderer half with nextcloud-vue: the scanner, the `scan` index action and lookup, the `scan` form field and the `code` widget, with this spec as the contract. Verify: the nextcloud-vue change exists and cites REQ-BQQR-001 to REQ-BQQR-003.
- [ ] **T07**: Playwright `tests/e2e/page-codes.spec.ts` with a fake camera stream showing a QR code: the scan opens the record, and the code widget renders (REQ-BQQR-001, REQ-BQQR-003).
- [ ] **T08**: Strings and docs: English and Dutch for the scan section, the field type, the widget and the messages (`l10n/en.json`, `l10n/nl.json`), and a section in `docs/` on scanning and showing codes.
- [ ] **T09**: Run `openspec validate pages-qr-and-barcodes --strict`.
