## 1. Leaf-contract verification (read-only, before any component work)

- [ ] 1.1 Verify the `manifest-form-logic` leaf's actual schema delta in the installed `@conduction/nextcloud-vue` (beta dist-tag) — `steps[]` items `{ id, title, description?, fields: [key…] }`, `fields[].visibleWhen` reusing `$defs/visibleWhen` (LOCAL `{ field, op?, value }`, ops `eq|neq|gt|gte|lt|lte`), `fields[].validation` `{ required, min, max, pattern, message }` — against design.md "Shared manifest contract"; resolve any drift in the leaf's favour before writing components. VERIFY AGAINST HEAD of the installed package, not this document.
- [ ] 1.2 Confirm the leaf validator's complete-partition rule (every declared field key in exactly one step when `steps[]` is non-empty — validation error otherwise, NO renderer fail-safe) and `CnFormPage`'s handling of legacy flat `required`/`pattern` (still honoured, deprecated) so editor warnings/normalisation match runtime behaviour.

## 2. Steps manager

- [ ] 2.1 Add `src/components/page-editor/fields/FormStepsManager.vue` — controlled component (`:steps` + `:fields` props in, `@update:steps` out): step list with per-step `title` input, optional `description` input, up/down reorder buttons, delete button; auto-derived editable kebab-case `id` unique within `steps[]`
- [ ] 2.2 In `FormStepsManager.vue` implement field assignment — per-step ordered key list (each entry removable back to the pool), a native select of unassigned keys + "Assign" button, and an "Unassigned fields" strip warning that unassigned keys are auto-assigned to the final step on save (leaf validator requires every field in exactly one step)
- [ ] 2.3 In `FormStepsManager.vue` implement the empty/backward-compat states — absent/empty `steps` renders the single-step state with an "add step" affordance; deleting the last step emits a steps value that removes the `steps` key (no `steps: []`); deleting a step returns its keys to the pool without touching field definitions
- [ ] 2.4 Mount the Steps fieldset in `src/components/page-editor/FormPageEditor.vue` (after the Fields fieldset), wired via `update('steps', …)` so the spread-write round-trip is preserved; add `'steps'` to `validatedConfigKeys` and an `<InlineFieldMark :error="markFor('steps')" />`

## 3. Conditions builder

- [ ] 3.1 Add `src/components/page-editor/fields/VisibleWhenBuilder.vue` — controlled component (`:model-value` = the field's `visibleWhen`, `:field-options` = sibling keys, out `@update:modelValue`): field picker (native select over sibling `config.fields[].key`, excluding the edited field), op picker (`eq|neq|gt|gte|lt|lte`, default `eq`, omitted from the written object when `eq`), value input with `true`/`false`/numeric coercion; clearing emits `null` so the caller deletes the key
- [ ] 3.2 In `VisibleWhenBuilder.vue` implement the advanced-shape passthrough — when the incoming `visibleWhen` carries `endpoint` or `source`, render the read-only "Advanced condition — edit in Raw JSON" summary and never emit a rewrite of that object
- [ ] 3.3 Add an expandable per-row details area to `src/components/page-editor/fields/FormFieldBuilder.vue` (disclosure button per field row) hosting the Conditions section; wire `VisibleWhenBuilder` through `updateField(index, 'visibleWhen', …)` (delete the key on `null`), keeping the existing spread so unknown per-field keys survive

## 4. Validation builder

- [ ] 4.1 Add `src/components/page-editor/fields/FieldValidationBuilder.vue` — controlled component authoring `{ required, min, max, pattern, message }`: required checkbox, numeric min/max inputs, pattern input compiled with `new RegExp()` on input (inline invalid mark, invalid pattern never emitted), message text input
- [ ] 4.2 In `FieldValidationBuilder.vue` implement legacy prefill + per-field normalisation — prefill from `field.validation`, falling back to flat `field.required`/`field.pattern`; on first write emit the merged `validation` object plus removal of the migrated flat keys for that field only (untouched fields keep flat keys byte-for-byte)
- [ ] 4.3 Host the Validation section in `FormFieldBuilder.vue`'s details area, replacing the inline flat `required` checkbox and `pattern` input in the collapsed row (row shows a compact summary instead, e.g. "required · pattern · 1 condition")

## 5. Dangling-reference warnings (live, in-editor)

- [ ] 5.1 In `FormFieldBuilder.vue`, compute per-field dangling LOCAL `visibleWhen.field` references against the current `modelValue` keys on every render and paint an `InlineFieldMark`-style `role="alert"` warning ("Condition references removed field '<key>'") next to the Conditions section — never mutate or delete the stale `visibleWhen`
- [ ] 5.2 In `FormStepsManager.vue`, compute dangling step `fields[]` entries against `:fields` and paint the same warning on the step row — never drop the stale entry

## 6. Manifest validation service

- [ ] 6.1 Add `src/services/manifestValidation/formLogic.js` — `validateFormLogic(manifest)` over every `type: "form"` page: step title/fields shape, duplicate step ids, multi-step field assignment, dangling step references, dangling LOCAL `visibleWhen.field`, op allow-list, `validation` coherence (`required` boolean, numeric `min`/`max`, `min` ≤ `max`, `new RegExp(pattern)` compiles), warning-level entry for flat+structured validation duplicates; all entries JSON-Pointer-addressed under `/pages/<n>/config/...`
- [ ] 6.2 Wire `validateFormLogic` into `src/composables/useManifestValidator.js` beside `validateSchedules` (same `.concat(...)` chain) so the right-pane list and the `steps`/`fields` inline marks agree

## 7. i18n & quality

- [ ] 7.1 Wrap all user-facing strings in the new/changed components in `t('openbuild', …)` with English source keys + Dutch translations (hydra ADR-007)
- [ ] 7.2 Pass `eslint` and `stylelint` on all new/changed files

## 8. Vitest

- [ ] 8.1 Add `tests/components/FormStepsManager.spec.js` — add/reorder/delete emits, id slug uniqueness, assignment to/from the pool, last-step-delete removes the key, absent-steps single-step state, dangling step-reference warning renders and the stale entry survives
- [ ] 8.2 Add `tests/components/VisibleWhenBuilder.spec.js` — field options exclude the edited field, default-`eq` omission, boolean/number value coercion, clear emits `null`, advanced endpoint/source passthrough never rewrites
- [ ] 8.3 Add `tests/components/FieldValidationBuilder.spec.js` — structured write, legacy flat prefill + per-field normalisation (flat keys removed only on the edited field), non-compiling pattern marked invalid and never emitted
- [ ] 8.4 Add `tests/components/FormFieldBuilder.logic.spec.js` — details-area disclosure, `visibleWhen` delete-on-null through `updateField`, unknown per-field keys survive condition/validation edits, dangling-condition warning appears when a referenced field is removed
- [ ] 8.5 Add `tests/components/FormPageEditor.steps.spec.js` — Steps fieldset mounts, `update('steps', …)` round-trip preserves unknown config keys, `validatedConfigKeys` includes `steps`, `markFor('steps')` renders an inline mark when the injected validator reports a steps error
- [ ] 8.6 Add `tests/services/formLogicValidation.spec.js` — every REQ-OBFEL-005 rule: dangling/duplicate step refs, unknown condition field, off-allow-list op, `min > max`, bad pattern, flat+structured warning, clean manifest (and no-form-page manifest) pass
- [ ] 8.7 `npm run test` green

## 9. Playwright e2e (`tests/e2e/form-editor-logic.spec.ts`, REQ-id-titled like `page-designer.spec.ts`)

- [ ] 9.1 Add `tests/e2e/form-editor-logic.spec.ts` scaffolding — seed a form page on the e2e app, open `/apps/openbuild/applications/<slug>/design`, select the form page (reuse `page-designer.spec.ts` global-setup auth; same #41 quarantine annotation until that issue closes)
- [ ] 9.2 e2e "REQ-OBFEL-001: add/assign/reorder/delete steps" — add two steps with titles + assigned fields, reorder, delete one, delete the last; assert via the Raw JSON tab: `steps[]` written with id/title/fields refs, order swapped, deleted step's keys back in the pool, `steps` key gone after last delete, `fields[]` untouched (covers all four REQ-OBFEL-001 scenarios incl. absent-steps single-step state before the first add)
- [ ] 9.3 e2e "REQ-OBFEL-002: condition builder writes LOCAL visibleWhen" — author `wantsContact eq true` on `email` (assert boolean value + omitted op in Raw JSON), author a `gt 3` condition (assert numeric coercion), clear it (assert key removed), and author an `endpoint` condition in Raw JSON then confirm the Design tab shows the read-only advanced summary and an unrelated field edit leaves it byte-for-byte unchanged (covers all four REQ-OBFEL-002 scenarios)
- [ ] 9.4 e2e "REQ-OBFEL-003: validation builder writes the structured object" — author required/min/max/pattern/message and assert the `validation` object in Raw JSON; seed a field with legacy flat `required`/`pattern` via Raw JSON, edit its validation, assert normalisation (flat keys gone on that field, intact on an untouched sibling); enter pattern `[a-` and assert the inline invalid mark with nothing written (covers all three REQ-OBFEL-003 scenarios)
- [ ] 9.5 e2e "REQ-OBFEL-004: dangling references warn live without deletion" — remove a field referenced by a sibling's condition and by a step; assert both `role="alert"` warnings appear immediately and Raw JSON still carries the stale `visibleWhen` and step entry (covers both REQ-OBFEL-004 scenarios)
- [ ] 9.6 e2e "REQ-OBFEL-006: raw JSON round-trip + save" — author `steps[]`, an advanced `visibleWhen`, a `validation` object and an unknown custom key via Raw JSON, edit the submit label in Design, assert byte-for-byte survival in Raw JSON; then save and assert the ApplicationVersion PUT payload carries the new shapes with every other top-level manifest key unchanged (covers both REQ-OBFEL-006 scenarios)

## 10. Docs

- [ ] 10.1 Add `docs/form-logic-authoring.md` (Docusaurus) — authoring steps, conditions and validation in the page designer: the steps manager, the condition builder (LOCAL shape + "advanced conditions live in Raw JSON"), validation rules incl. the legacy flat-key migration, the dangling-reference warning, and the note that rendering requires a nextcloud-vue build containing the `manifest-form-logic` leaf
- [ ] 10.2 Link the new page from `docs/intro.md` (or the sidebar config in `docs/docusaurus.config.js`) beside the existing page-designer docs

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate form-editor-logic --strict` and resolve any structural errors.
- Confirm persistence rides `PageDesignerHost.save()` (ApplicationVersion PUT) — no new endpoint/store/save method is added for form logic.
- Confirm the canonical `validateManifest` / `check:manifest` tolerates `steps` / `visibleWhen` / `validation` additively on the installed nextcloud-vue build; app-side `formLogic.js` is authoritative until the `manifest-form-logic` leaf ships off `beta`.
- Keep the editor byte-consistent with the leaf's schema contract — any drift found in task 1.1 is resolved in the leaf's favour and reflected here before implementation continues.

## Acceptance Criteria

- The form page sub-editor shows a Steps section: steps carry a unique kebab-case `id`, required `title`, optional `description`, and ordered field-key refs; add/reorder/delete work; unassigned keys are listed with the final-step note; absent `steps` = single-step state; deleting the last step removes the key.
- Each field row expands to a Conditions section (LOCAL `visibleWhen` builder — sibling-key field picker, `eq|neq|gt|gte|lt|lte` op defaulting to omitted `eq`, coerced value; advanced `endpoint`/`source` shapes shown read-only and never rewritten) and a Validation section (`{ required, min, max, pattern, message }`, legacy flat `required`/`pattern` prefilled and normalised per field on first edit, non-compiling patterns blocked inline).
- Deleting a field referenced by a condition or step warns immediately (`role="alert"`) and never auto-deletes the stale reference.
- `validateFormLogic` reports dangling/duplicate step refs, dangling condition fields, off-allow-list ops, incoherent validation, and the flat+structured duplicate warning, all JSON-Pointer-addressed; wired into `useManifestValidator`; `FormPageEditor.validatedConfigKeys` includes `steps`.
- Raw JSON round-trip is lossless: unknown config/field/step keys and advanced conditions survive every editor interaction; saves ride the existing ApplicationVersion PUT with all other top-level manifest keys unchanged.
- Vitest component + service specs and the Playwright spec pass; eslint/stylelint clean; all strings i18n-wrapped with English keys.
