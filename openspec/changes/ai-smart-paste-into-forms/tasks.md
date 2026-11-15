# Tasks: ai-smart-paste-into-forms

- [ ] **T01**: Add the smart paste checks of D2 to `src/services/manifestValidation/formLogic.js` and `smartPaste` to `validatedConfigKeys()` in `FormPageEditor.vue` (REQ-BQSP-002). Verify: vitest cases in `tests/services/formLogicValidation.spec.js` for an unknown key, an unfillable type, no fields, and `public` mode.
- [ ] **T02**: Add the "Fill from pasted text" fieldset to `src/components/page-editor/FormPageEditor.vue` with the switch, the fillable field checklist, the hint, and the disabled state in `public` mode (REQ-BQSP-001). Verify: vitest `tests/components/page-editor/FormPageEditor.smartPaste.spec.js`.
- [ ] **T03**: Show the control with a fixed sample answer in `src/components/page-editor/PreviewSandbox.vue` (REQ-BQSP-003). Verify: a case in `tests/components/page-editor/PreviewSandbox.spec.js`.
- [ ] **T04**: File the renderer half with nextcloud-vue: `config.smartPaste` in the manifest schema and the `CnFormPage` control of D4, with this spec as the contract. Verify: the nextcloud-vue change exists and cites REQ-BQSP-003.
- [ ] **T05**: File the fill endpoint with hermiq as a governed AI feature, with the request and response shape of D4. Verify: the hermiq change exists and cites REQ-BQSP-003.
- [ ] **T06**: Playwright `tests/e2e/form-smart-paste.spec.ts`: author smart paste on a form, and, once the renderer and endpoint ship, fill the form from pasted text with a stub endpoint (REQ-BQSP-001, REQ-BQSP-003).
- [ ] **T07**: Strings and docs: English and Dutch for the fieldset, the hint placeholder and the refusals (`l10n/en.json`, `l10n/nl.json`), and a paragraph in `docs/` on letting users fill a form from pasted text.
- [ ] **T08**: Run `openspec validate ai-smart-paste-into-forms --strict`.
