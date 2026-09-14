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

## 5. Wave 3 schema

- [ ] 5.1 Add `channel`, `isPublic` and `confirmationText` to `registrationForm`, with the default rule spanning audience and channel (REQ-OBRF-007)
- [ ] 5.2 Validate `channel` against the target schema's channel property and refuse an unknown value (REQ-OBRF-007)
- [ ] 5.3 Add `sections[]` and `order` plus `section` on `fields[]`, with the unknown-section refusal (REQ-OBRF-008)
- [ ] 5.4 Extend the seed: a `portal` client form and a `desk` internal form for `dossiq/case` `caseType = bouwvergunning`, the portal one public with a confirmation text

## 6. Wave 3 builder

- [ ] 6.1 Channel picker in the applies-to panel, read from the target schema's channel enum (REQ-OBRF-007)
- [ ] 6.2 Sections panel in `FormPageEditor.vue` with drag ordering of fields inside a section (REQ-OBRF-008)
- [ ] 6.3 Public toggle and confirmation text on the form (REQ-OBRF-009)

## 7. Wave 3 leaf

- [ ] 7.1 `channel` filter on `RegistrationFormLeafProvider::list()`, defaults first, then the channel-less forms (REQ-OBRF-009)
- [ ] 7.2 Serve `channel`, `isPublic`, `confirmationText` and the ordered sectioned `fields[]` (REQ-OBRF-008, REQ-OBRF-009)

## 8. Wave 3 quality

- [ ] 8.1 PHPUnit for the audience and channel default rule, the channel validation and the serving order
- [ ] 8.2 Extend `tests/e2e/registration-form-builder.spec.ts` with a channel per form and `tests/e2e/registration-form-leaf.spec.ts` with the channel filter and the ordered sections
- [ ] 8.3 Dutch and English strings; docs with screenshots
- [ ] 8.4 Tell dossiq to write `case.intakeChannel` from the served form, and tell portaliq that `isPublic` and `confirmationText` arrive on the leaf
- [ ] 8.5 Release note: a form now lists only the fields an admin put on it, not every property in fetch order
