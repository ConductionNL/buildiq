# Tasks: forms-are-valid-against-their-destination

## 1. Destination on every form

- [x] 1.1 `destination { register, schema }` on form pages (`config.submitEndpoint` derived from it), registration forms, external forms
  - Files: lib/Support/ManifestPageShape.php, src/services/formLibrary.js, register schemas for registrationForm
  - Done: form pages carry `config.destination`, which fills `register`/`schema`/`submitEndpoint`, or is read from them (`ManifestPageShape::withDestination`, test `ManifestPageShapeTest::testAFormNamesItsDestination`). A registration form's existing `register` + `schema` ARE its destination, so no second copy is stored (two fields that must agree would drift); external forms take the page's.

## 2. Validator calls

- [x] 2.1 Registration form save calls OpenRegister's form destination validator; `RegistrationFormTargetWarnings` maps findings
  - Spec ref: specs/form-destination-authoring/spec.md; amend `forms-per-case-type` REQ-OBRF-005 "Saving SHALL warn" to point here (done)
  - Test: unit test per finding code that buildiq surfaces; control with zero findings
  - Done: `FormDestinationCheck` (in-process, schema resolved inside the form's register, then OpenRegister's `FormDestinationValidator`, the service behind `POST /api/forms/validate`), `RegistrationFormTargetWarnings::findings()`. A draft saves with its findings, a published form with findings is refused 422 with every finding (decision 189). Tests: `FormDestinationCheckTest`, `RegistrationFormTargetWarningsTest` (spec scenarios against the real validator), `RegistrationFormAuthoringServiceTest`.
- [x] 2.2 Form page editor shows findings on the field; the pages save as a draft with findings, and publishing the app is refused on any finding (decision 189)
  - Done: `POST /api/forms/destination-check` (`FormDestinationController`, `ManifestFormDestinations`), called after every designer save; findings marked per field by `FormFieldBuilder`, others listed in `FormPageEditor`; `ApplicationPublishController` refuses 422 with the findings. Registration form rows marked in `FormLayoutBuilder` / `FormPresetsBuilder`. Board: design backlog `BqFormulierBevindingen` (decision 157); built with the existing inline marks, so the board refines layout only.
- [x] 2.3 External form provisioning: public-create check, honeypot field, provisioning only on zero findings
  - Done: `ExternalFormAccessDialog` checks the form before anything is provisioned, and again with audience `public` after the grant; the entry and the page config carry `honeypot: '_hp'`. Rendering and refusing the honeypot is CnFormPage's (task 3.1).
- [x] 2.4 `journey-designer`: note in its tasks that `writes[]` validation calls the same endpoint
- [x] 2.5 Announce buildiq's published registration forms through OpenRegister's `FormDestinationDependentsEvent`, so a breaking schema change unpublishes them (back to `draft`)
  - Done: `FormDestinationDependentsListener`, test `FormDestinationDependentsListenerTest`. Form pages are not announced yet: they live inside app manifests, and unpublishing one means unpublishing its app; owed as a follow-up.

## 3. nextcloud-vue

- [x] 3.1 `CnFormPage` renders a 422 `findings[]` answer per field, and a `honeypot` field (backward-compatible minor in ConductionNL/nextcloud-vue) Released in @conduction/nextcloud-vue 2.81.0 (#1461); buildiq requires ^2.81.0.

## 4. Verification

- [ ] 4.1 `composer check:strict`, `npm run lint`, `openspec validate forms-are-valid-against-their-destination --strict`
- [ ] 4.2 Live: save a draft registration form with a missing `caseType`, see the finding; publish it, see 422; change the `case` schema so a published form breaks, see it back in draft (live pass, decision 139)
