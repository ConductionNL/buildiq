# Tasks: forms-signature-audio-and-location

- [x] **T01**: Add `signature`, `audio` and `location` to `FIELD_TYPES` in `src/components/page-editor/fields/FormFieldBuilder.vue` with their option controls (REQ-BQSA-001). Verify: vitest `tests/components/page-editor/FormFieldBuilder.deviceFields.spec.js`.
- [x] **T02**: Add `file` to `SUPPORTED_TYPES` in `src/components/schema-editor/FieldEditor.vue`, and the "Add the property" action from the form field builder through the schema designer's save path (REQ-BQSA-002). Verify: vitest case in `tests/components/schema-editor/FieldEditor.spec.js`.
  `file` was already in `SUPPORTED_TYPES`. The form field builder now names the properties a device field writes that
  the target schema lacks and offers "Add the property to <schema>"; `FormPageEditor` adds them through
  `addSchemaProperties` (the same additive schema write the form library uses, never replacing a property) and puts
  the stored schema back in its list.
- [x] **T03**: Validate the new field types in `src/services/manifestValidation/formLogic.js`: target properties, audio length against the inline cap, signature modes, and no audio or location on `public` forms (REQ-BQSA-002, REQ-BQSA-004). Verify: cases in `tests/services/formLogicValidation.spec.js`.
  Audio length, signature modes and public-mode refusals live in `deviceFieldProblems` (client validation). The target
  property checks run in the destination check that already refuses publishing: `ManifestFormDestinations` maps
  `signature` and `audio` to a `file` property and a location field to number properties for its latitude, longitude
  and accuracy, so a missing or wrongly typed property is a finding on that field.
- [x] **T04**: Add "Show my position" and "Center on my position" to `src/components/page-editor/MapPageEditor.vue` (REQ-BQSA-003). Verify: vitest case in `tests/components/page-editor/MapPageEditor.spec.js`.
- [x] **T05**: Derive `deviceFeatures[]` on manifest save and store it on `ApplicationVersion`; set a `FeaturePolicy` in `DashboardController::builder()`, `builderSlash()` and `builderPath()` that adds `'self'` for those features only (REQ-BQSA-004). Verify: PHPUnit cases for the derivation and for the response policy with and without features.
- [ ] **T06**: File the renderer half with nextcloud-vue: the three form field types in `cnFormFieldRenderer.js`, the manifest schema values, and `showUserLocation` and `centerOnUser` on `CnMapPage`, with this spec as the contract. Verify: the nextcloud-vue change exists and cites REQ-BQSA-001 and REQ-BQSA-003.
- [ ] **T07**: Playwright `tests/e2e/form-device-fields.spec.ts` with a granted geolocation and a fake microphone: a signature, a clip and a position land on a record (REQ-BQSA-001, REQ-BQSA-002, REQ-BQSA-004).
- [x] **T08**: Strings and docs: English and Dutch for the field types, options, the property offer and the refusals (`l10n/en.json`, `l10n/nl.json`), and a section in `docs/` on signatures, spoken notes and positions in forms.
- [ ] **T09**: Run `openspec validate forms-signature-audio-and-location --strict`.
