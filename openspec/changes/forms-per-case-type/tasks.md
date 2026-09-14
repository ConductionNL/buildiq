## 1. Schema

- [ ] 1.1 Add `name`, `audience`, `isDefault` and `presets[]` to `registrationForm` in `lib/Settings/openbuild_register.json`; move uniqueness to the name tuple and add the one-default-per-audience rule (REQ-OBRF-004, REQ-OBRF-005)
- [ ] 1.2 Extend the seed: three forms for `dossiq/case` `caseType = bouwvergunning`, one per audience, the client one with a hidden `intakeChannel` preset

## 2. Builder

- [ ] 2.1 Form list per type in the applies-to panel with name, audience and default; "Add form" (REQ-OBRF-004)
- [ ] 2.2 Presets row per field in `FormPageEditor.vue` with value and hidden toggle; validate preset field names on save (REQ-OBRF-005)

## 3. Leaf

- [ ] 3.1 `audience` and `name` filters on `RegistrationFormLeafProvider::list()`; serve hidden presets out of `fields[]`, visible ones as `default`, and `presets[]` beside the form (REQ-OBRF-005, REQ-OBRF-006)

## 4. Quality

- [ ] 4.1 PHPUnit for uniqueness, the default rule, the served shape and the filters
- [ ] 4.2 Extend `tests/e2e/registration-form-builder.spec.ts` (three forms) and `tests/e2e/registration-form-leaf.spec.ts` (hidden preset, audience default)
- [ ] 4.3 Dutch and English strings; docs with screenshots; tell dossiq that `caseType.intakeFormRef` becomes a list served by the leaf
