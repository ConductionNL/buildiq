# Tasks: apps-copy-app-and-page

- [ ] **T01**: Port the de-namespace step of `src/services/templateCapture.js` to a PHP class used to build a template array from an app's current version (REQ-BQCP-001). Verify: PHPUnit with the same cases as the JS unit tests of `templateCapture.js`, so both agree.
- [ ] **T02**: Add `POST /api/applications/{slug}/copy` in `ApplicationsController` (admin gate, rate limit, owner or editor of the source) calling `installFromTemplateArray()`, and route it in `appinfo/routes.php` (REQ-BQCP-001, REQ-BQCP-002). Verify: PHPUnit for success, a non-editor refused, a taken slug refused (409).
- [ ] **T03**: Add "Copy app" to `ApplicationDetailActions.vue` and to the app cards, with a name and slug dialog in `src/modals/` (REQ-BQCP-001). Verify: vitest for the dialog; Playwright `tests/e2e/copy-app-and-page.spec.ts` copies a seeded app and opens the copy.
- [ ] **T04**: Add "Copy page" to each row of `PageListEditor.vue` with unique id and route suffixing (REQ-BQCP-003). Verify: vitest for id and route suffixing and insertion below the source.
- [ ] **T05**: Add "Copy form" to each row of `RegistrationFormList.vue` saving a draft that is never the default (REQ-BQCP-004). Verify: vitest; extend `tests/e2e/copy-app-and-page.spec.ts` with a form copy.
- [ ] **T06**: English and Dutch strings for the three actions and the dialog; update `docs/` on reusing work.
- [ ] **T07**: Run `openspec validate apps-copy-app-and-page --strict`.
