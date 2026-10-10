# Tasks: forms-are-valid-against-their-destination

## 1. Destination on every form

- [ ] 1.1 `destination { register, schema }` on form pages (`config.submitEndpoint` derived from it), registration forms, external forms
  - Files: lib/Support/ManifestPageShape.php, src/services/formLibrary.js, register schemas for registrationForm

## 2. Validator calls

- [ ] 2.1 Registration form save calls OpenRegister `POST /api/forms/validate`; `RegistrationFormTargetWarnings` maps findings
  - Spec ref: specs/form-destination-authoring/spec.md; amend `forms-per-case-type` REQ-OBRF-005 "Saving SHALL warn" to point here
  - Test: unit test per finding code that buildiq surfaces; control with zero findings
- [ ] 2.2 Form page editor shows findings on the field (board BqPaginaOntwerperFouten); publish blocked in refuse mode
- [ ] 2.3 External form provisioning: public-create check, honeypot field, provisioning only on zero findings
- [ ] 2.4 `journey-designer`: note in its tasks that `writes[]` validation calls the same endpoint

## 3. nextcloud-vue

- [ ] 3.1 `CnFormPage` renders a 422 `findings[]` answer per field (backward-compatible minor in ConductionNL/nextcloud-vue)

## 4. Verification

- [ ] 4.1 `composer check:strict`, `npm run lint`, `openspec validate forms-are-valid-against-their-destination --strict`
