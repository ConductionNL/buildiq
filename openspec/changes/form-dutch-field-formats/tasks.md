# Tasks: form-dutch-field-formats

- [ ] **T01**: Add `src/services/dutchFormats.js` with the eight formats of design D1, their labels, hints and client checks, and the shared rule data under `src/data/dutch-formats/` (REQ-BQDF-001). Verify: vitest with valid and invalid samples per format, including a BSN that fails the elfproef and an IBAN with a wrong check digit.
- [ ] **T02**: Add the "Formaat" select to `src/components/page-editor/fields/FieldValidationBuilder.vue`, disable the pattern when a format is set, and show an inherited format per design D2 (REQ-BQDF-001). Verify: vitest for the stored `format`, the disabled pattern and the inherited case; gate `nc-input-labels` passes.
- [ ] **T03**: Add the Dutch entries to the schema field editor's "Formaat" list under "Nederlandse formaten" (REQ-BQDF-001). Verify: vitest for the stored property `format`.
- [ ] **T04**: Add `lib/Service/DutchFormatValidator.php` reading the same rule data, and call it from the form save listener for fields with a Dutch `format`; normalise licence plate and postcode (REQ-BQDF-002). Verify: PHPUnit with the same samples as T01, and a constructed save event that is refused on a bad IBAN.
- [ ] **T05**: Playwright `tests/e2e/form-dutch-formats.spec.ts`: a maker sets IBAN on a field, publishes, and a colleague's wrong IBAN is refused with the message (REQ-BQDF-001, REQ-BQDF-002).
- [ ] **T06**: English and Dutch strings, and a section in `docs/form-logic-authoring.md`. Verify: `npm run lint` passes on the touched files.
- [ ] **T07**: Run `openspec validate form-dutch-field-formats --strict`.
