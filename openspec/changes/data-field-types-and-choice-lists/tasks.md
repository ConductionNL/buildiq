# Tasks: data-field-types-and-choice-lists

- [ ] **T01**: Keep unedited property keys through a designer save: carry the loaded property on the field row and let `propertyFromField()` overwrite only the keys the editor owns (REQ-BQFT-004). Verify: vitest for `schemaToFields()`/`fieldsToSchema()` round trip keeping `enum`, `title` and an `x-` key, and still clearing slots on a type change.
- [ ] **T02**: Replace the type picker with the named types of design D1, and map each both ways in `fieldFromProperty()` and `propertyFromField()` (REQ-BQFT-001). Verify: vitest table test, one row per named type, loading and saving.
- [ ] **T03**: Add the inline choice editor (ordered value and label rows) writing `enum` and `x-enum-labels` (REQ-BQFT-002). Verify: vitest for the editor and the stored property.
- [ ] **T04**: Add "Use a shared list": a picker over OpenRegister concept schemes that writes `conceptScheme` and removes `enum`, showing the scheme's options read-only (REQ-BQFT-002). Verify: vitest with a mocked scheme list; PHPUnit is not needed, OpenRegister validates.
- [ ] **T05**: Add the user and several-users types writing `referenceType: nextcloud-user` (REQ-BQFT-003). Verify: vitest; Playwright `tests/e2e/schema-designer-field-types.spec.ts` creates a schema with a date, a choice and a user field, publishes an app with a form on it, and picks a user in the published form.
- [ ] **T06**: English and Dutch strings for every type name and the choice editor; update `docs/` on modelling data.
- [ ] **T07**: Run `openspec validate data-field-types-and-choice-lists --strict`.
