# Tasks: data-generated-number-field

- [ ] **T01**: `src/components/schema-editor/GeneratedNumberSettings.vue`, shown in `FieldEditor.vue` for a field of type string: a switch, counter name (default the schema slug), format (default `{seq:5}`), restart each year (`resetOn: "year"` or `"never"`), and a live example rendered client-side for today's date and counter 1 (REQ-OBSD-009). Verify: vitest `tests/components/schema-editor/GeneratedNumberSettings.spec.js` for the example (`INV-{year}-{seq:4}` shows `INV-2026-0001`) and the stored keyword.
- [ ] **T02**: `FieldEditor.vue` property mapping (the `x-openregister-relation` round trip at `:718-773`): read `x-openregister-generated` into the field and write it back only when the switch is on; add the key to the list of preserved `x-openregister-*` keys (REQ-OBSD-009). Verify: vitest round trip, and a switch turned off removes the key.
- [ ] **T03**: A format without `{seq` is refused in the designer with the reason OpenRegister would give (REQ-OBSD-009). Verify: vitest.
- [ ] **T04**: English and Dutch strings in `l10n/en.json` and `l10n/nl.json`.
- [ ] **T05**: Live check: give the seeded app's schema a number field `INV-{year}-{seq:4}`, create two records in the built app, and see `INV-2026-0001` and `INV-2026-0002`.
- [ ] **T06**: When done, set `data-auto-number` to `built` in `openspec/parity/capabilities.json` and archive this change.
