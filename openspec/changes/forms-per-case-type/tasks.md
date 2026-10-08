## 1. Schema

- [x] 1.1 Add `name`, `audience`, `isDefault` and `presets[]` to `registrationForm` in `lib/Settings/openbuild_register.json`; move uniqueness to the name tuple and add the one-default-per-audience rule (REQ-OBRF-004, REQ-OBRF-005)
- [ ] 1.2 Extend the seed: three forms for `dossiq/case` `caseType = bouwvergunning`, one per audience, the client one with a hidden `intakeChannel` preset

## 2. Builder

- [x] 2.1 Form list per type in the applies-to panel with name, audience and default; "Add form" (REQ-OBRF-004). `RegistrationFormList.vue` sits under the applies-to panel and reads the type from it. The endpoint answers a whole schema, so the filter to one type happens in the panel
- [x] 2.2 Presets with a value and a hidden toggle, in `FormPresetsBuilder.vue` inside the registration-form editor rather than in `FormPageEditor.vue`: presets belong to the form object, not to a manifest page. Preset field names are validated on save against the target schema, which `RegistrationFormTargetSchemaReader` now reads (REQ-OBRF-005)
- [x] 2.3 A save path that runs the rules: `PUT /api/registration-forms` calls `RegistrationFormValidator`, which until now had no caller anywhere in `lib/`, so every one of its refusals was written and enforced nowhere. Admin-only. The panel that posts to it is 2.1

## 3. Leaf

- [x] 3.1 `audience` and `name` filters on `RegistrationFormLeafProvider::list()`; serve hidden presets out of `fields[]`, visible ones as `default`, and `presets[]` beside the form (REQ-OBRF-005, REQ-OBRF-006)

## 4. Quality

- [x] 4.1 PHPUnit for uniqueness, the default rule, the served shape and the filters
- [x] 4.2 Extend `tests/e2e/registration-form-builder.spec.ts` (three forms) and `tests/e2e/registration-form-leaf.spec.ts` (hidden preset, audience default) — the leaf spec ships; the builder spec waits on the builder UI in section 2
- [x] 4.3 Dutch and English strings shipped for every string the builder adds, in `l10n/en.json` and `l10n/nl.json`, and the schema strings too. Docs at `docs/registration-form-builder.md`, without screenshots: they would show a builder that changes in the next task and a stale screenshot reads as a bug report. Telling dossiq is 8.4

## 5. Wave 3 schema

- [x] 5.1 Add `channel`, `isPublic` and `confirmationText` to `registrationForm`, with the default rule spanning audience and channel (REQ-OBRF-007)
- [x] 5.2 Validate `channel` against the target schema's channel property and refuse an unknown value (REQ-OBRF-007). `RegistrationFormTargetSchemaReader` reads the consuming schema, so the rule now refuses in practice. The form nominates the property in `channelProperty`; buildiq never guesses which property is the channel. A schema that cannot be read leaves the save working and returns a warning saying the check did not run
- [x] 5.3 Add `sections[]` and `order` plus `section` on `fields[]`, with the unknown-section refusal (REQ-OBRF-008)
- [ ] 5.4 Extend the seed: a `portal` client form and a `desk` internal form for `dossiq/case` `caseType = bouwvergunning`, the portal one public with a confirmation text

## 6. Wave 3 builder

- [x] 6.1 Channel picker in the form editor, read from the enum of the property the form nominates in `channelProperty`. It sits on the form and not on the applies-to panel because a channel is a property of one form, not of the screen (REQ-OBRF-007)
- [x] 6.2 Sections and field ordering in `FormLayoutBuilder.vue`. Move up and down rather than drag: it is keyboard-reachable by default, where a drag surface needs a second keyboard path built beside it (REQ-OBRF-008)
- [x] 6.3 Public toggle and confirmation text on the form (REQ-OBRF-009)

## 7. Wave 3 leaf

- [x] 7.1 `channel` filter on `RegistrationFormLeafProvider::list()`, defaults first, then the channel-less forms (REQ-OBRF-009)
- [x] 7.2 Serve `channel`, `isPublic`, `confirmationText` and the ordered sectioned `fields[]` (REQ-OBRF-008, REQ-OBRF-009)

## 8. Wave 3 quality

- [x] 8.1 PHPUnit for the audience and channel default rule, the channel validation and the serving order
- [ ] 8.2 Extend `tests/e2e/registration-form-authoring.spec.ts` (the file the earlier tasks call `registration-form-builder.spec.ts`; it does not exist under that name) with a channel per form, and `tests/e2e/registration-form-leaf.spec.ts` with the channel filter and the ordered sections. The target-schema route's reachability and its anonymous refusal are covered
- [x] 8.3 Dutch and English strings; docs at `docs/registration-form-builder.md`. No screenshots, same reason as 4.3
- [ ] 8.4 Tell dossiq to write `case.intakeChannel` from the served form, and tell portaliq that `isPublic` and `confirmationText` arrive on the leaf
- [ ] 8.5 Release note: a form now lists only the fields an admin put on it, not every property in fetch order
