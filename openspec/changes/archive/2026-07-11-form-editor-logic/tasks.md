## 1. Leaf-contract verification (read-only, before any component work)

- [x] 1.1 Verify the `manifest-form-logic` leaf's actual schema delta in the installed `@conduction/nextcloud-vue` (beta dist-tag) — `steps[]` items `{ id, title, description?, fields: [key…] }`, `fields[].visibleWhen` reusing `$defs/visibleWhen` (LOCAL `{ field, op?, value }`, ops `eq|neq|gt|gte|lt|lte`), `fields[].validation` `{ required, min, max, pattern, message }` — against design.md "Shared manifest contract"; resolve any drift in the leaf's favour before writing components. VERIFY AGAINST HEAD of the installed package, not this document.
  - **Verified against installed `1.0.0-beta.173`** (`node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`): contract matches design.md exactly — `steps[]` items are `{id, title, description?, fields[]}` (`additionalProperties: false`, `required: [id, title, fields]`); `fields[].visibleWhen` is `$ref: $defs/visibleWhen`; `fields[].validation` is `$ref: $defs/fieldValidation` = `{required?, min?, max?, pattern?, message?}`. **New drift found and noted (not blocking, no code change needed):** the schema additionally constrains `steps` itself with `minItems: 1` and each step's `fields[]` with `minItems: 1` — an editor-added step transiently has `fields: []` until the developer assigns at least one key, which is schema-invalid until then (surfaces as an inline `steps` error, consistent with the no-fail-safe correction below, not a defect).
- [x] 1.2 Confirm the leaf validator's complete-partition rule (every declared field key in exactly one step when `steps[]` is non-empty — validation error otherwise, NO renderer fail-safe) and `CnFormPage`'s handling of legacy flat `required`/`pattern` (still honoured, deprecated) so editor warnings/normalisation match runtime behaviour.
  - **Confirmed** in `node_modules/@conduction/nextcloud-vue/src/utils/validateManifest.js` section 7 ("Form logic"): the canonical `validateManifest` (now installed) already performs the complete-partition check itself — unassigned field keys and doubly-assigned field keys are both hard errors, plus duplicate step ids and dangling step/condition field references, plus `min<=max` and pattern-compile checks. **No renderer fail-safe exists** — confirmed by reading `CnFormPage.vue` and `utils/formValidation.js` in full: there is no partition auto-fix at render time, matching the prompt's correction.
  - **Drift found and resolved in the leaf's favour:** contrary to design.md/tasks.md's assumption, `CnFormPage` does **NOT** honour the legacy flat `field.required` / `field.pattern` keys at all — `utils/formValidation.js`'s `validateFieldValue()` only reads `field.validation`, and `CnFormPage.vue` never touches `field.required`/`field.pattern`. Flat-only fields are silently unenforced at runtime. This makes the editor's prefill-and-migrate behaviour (Decision 4) more important, not less — the migration is a correctness fix, not cosmetic — but the wording "still honoured, deprecated" is inaccurate and is corrected here.

## 2. Steps manager

- [x] 2.1 Add `src/components/page-editor/fields/FormStepsManager.vue` — controlled component (`:steps` + `:fields` props in, `@update:steps` out): step list with per-step `title` input, optional `description` input, up/down reorder buttons, delete button; auto-derived editable kebab-case `id` unique within `steps[]`
- [x] 2.2 In `FormStepsManager.vue` implement field assignment — per-step ordered key list (each entry removable back to the pool), a native select of unassigned keys + "Assign" button, and an "Unassigned fields" strip warning that unassigned keys are auto-assigned to the final step on save (leaf validator requires every field in exactly one step)
- [x] 2.3 In `FormStepsManager.vue` implement the empty/backward-compat states — absent/empty `steps` renders the single-step state with an "add step" affordance; deleting the last step emits a steps value that removes the `steps` key (no `steps: []`); deleting a step returns its keys to the pool without touching field definitions
- [x] 2.4 Mount the Steps fieldset in `src/components/page-editor/FormPageEditor.vue` (after the Fields fieldset), wired via `update('steps', …)` so the spread-write round-trip is preserved; add `'steps'` to `validatedConfigKeys` and an `<InlineFieldMark :error="markFor('steps')" />`
  - Additionally wired `assignUnassignedFieldsToFinalStep()` (new export in `formLogic.js`) into `PageDesignerHost.save()` (right after the existing dependency-reconciliation block, same free-ride pattern) so the save-time auto-assign half of REQ-OBFEL-001's "deleting a step" scenario is not just a live-editor note but an actual write.

## 3. Conditions builder

- [x] 3.1 Add `src/components/page-editor/fields/VisibleWhenBuilder.vue` — controlled component (`:model-value` = the field's `visibleWhen`, `:field-options` = sibling keys, out `@update:modelValue`): field picker (native select over sibling `config.fields[].key`, excluding the edited field), op picker (`eq|neq|gt|gte|lt|lte`, default `eq`, omitted from the written object when `eq`), value input with `true`/`false`/numeric coercion; clearing emits `null` so the caller deletes the key
- [x] 3.2 In `VisibleWhenBuilder.vue` implement the advanced-shape passthrough — when the incoming `visibleWhen` carries `endpoint` or `source`, render the read-only "Advanced condition — edit in Raw JSON" summary and never emit a rewrite of that object
- [x] 3.3 Add an expandable per-row details area to `src/components/page-editor/fields/FormFieldBuilder.vue` (disclosure button per field row) hosting the Conditions section; wire `VisibleWhenBuilder` through `updateField(index, 'visibleWhen', …)` (delete the key on `null`), keeping the existing spread so unknown per-field keys survive
  - Implemented as a dedicated `updateVisibleWhen(index, value)` method (same delete-on-null + shallow-spread contract as `updateField`) rather than routing through the generic `updateField`, since `updateField`'s empty/false-drops-the-key rule doesn't apply to an object value. Gated behind a new `show-logic` prop (default false) so the SAME shared component, still used unmodified by `SettingsSectionBuilder`, does not gain Conditions/Validation there — form-page-only by design (Non-Goals).

## 4. Validation builder

- [x] 4.1 Add `src/components/page-editor/fields/FieldValidationBuilder.vue` — controlled component authoring `{ required, min, max, pattern, message }`: required checkbox, numeric min/max inputs, pattern input compiled with `new RegExp()` on input (inline invalid mark, invalid pattern never emitted), message text input
- [x] 4.2 In `FieldValidationBuilder.vue` implement legacy prefill + per-field normalisation — prefill from `field.validation`, falling back to flat `field.required`/`field.pattern`; on first write emit the merged `validation` object plus removal of the migrated flat keys for that field only (untouched fields keep flat keys byte-for-byte)
- [x] 4.3 Host the Validation section in `FormFieldBuilder.vue`'s details area, replacing the inline flat `required` checkbox and `pattern` input in the collapsed row (row shows a compact summary instead, e.g. "required · pattern · 1 condition")

## 5. Dangling-reference warnings (live, in-editor)

- [x] 5.1 In `FormFieldBuilder.vue`, compute per-field dangling LOCAL `visibleWhen.field` references against the current `modelValue` keys on every render and paint an `InlineFieldMark`-style `role="alert"` warning ("Condition references removed field '<key>'") next to the Conditions section — never mutate or delete the stale `visibleWhen`
- [x] 5.2 In `FormStepsManager.vue`, compute dangling step `fields[]` entries against `:fields` and paint the same warning on the step row — never drop the stale entry

## 6. Manifest validation service

- [x] 6.1 Add `src/services/manifestValidation/formLogic.js` — `validateFormLogic(manifest)` over every `type: "form"` page: step title/fields shape, duplicate step ids, multi-step field assignment, dangling step references, dangling LOCAL `visibleWhen.field`, op allow-list, `validation` coherence (`required` boolean, numeric `min`/`max`, `min` ≤ `max`, `new RegExp(pattern)` compiles), warning-level entry for flat+structured validation duplicates; all entries JSON-Pointer-addressed under `/pages/<n>/config/...`
- [x] 6.2 Wire `validateFormLogic` into `src/composables/useManifestValidator.js` beside `validateSchedules` (same `.concat(...)` chain) so the right-pane list and the `steps`/`fields` inline marks agree

## 7. i18n & quality

- [x] 7.1 Wrap all user-facing strings in the new/changed components in `t('openbuild', …)` with English source keys + Dutch translations (hydra ADR-007)
  - Added 35 new keys to `l10n/en.json` and `l10n/nl.json` (real Dutch, not English fallback). `node tests/l10n/check-l10n.js` confirms zero of THIS change's keys are missing (134 -> 99 pre-existing gaps from other already-merged changes' files — WikiPageEditor/MapPageEditor/RoadmapPageEditor/AccessEditor/SearchPageEditor/builder.js/PageDesigner.vue/SchemaDesigner.vue — untouched, out of scope, not introduced by this change). `check-l10n-parity.js` (all 36 EU+RU+TR locales) is pre-existing badly broken app-wide (nl already 238 keys short, de/fr 495 short, before this change) and is not wired into any npm script; out of scope to remediate here.
- [x] 7.2 Pass `eslint` and `stylelint` on all new/changed files
  - `npx eslint` on all 8 new/changed files: 0 errors, 40 pre-existing-convention warnings (`@spec` tag / missing `@param` — same warnings the rest of the codebase already carries). `npx stylelint` on the 5 `.vue` files: 0 errors.

## 8. Vitest

- [x] 8.1 Add `tests/components/FormStepsManager.spec.js` — add/reorder/delete emits, id slug uniqueness, assignment to/from the pool, last-step-delete removes the key, absent-steps single-step state, dangling step-reference warning renders and the stale entry survives
  - Placed at `tests/components/page-editor/FormStepsManager.spec.js` (nested), matching where every other page-editor-adjacent spec actually lives (`tests/components/page-editor/*.spec.js`), not the flat path this task line names — deviation resolved in favour of the established repo convention.
- [x] 8.2 Add `tests/components/VisibleWhenBuilder.spec.js` — field options exclude the edited field, default-`eq` omission, boolean/number value coercion, clear emits `null`, advanced endpoint/source passthrough never rewrites
  - Same `tests/components/page-editor/` placement deviation as 8.1.
- [x] 8.3 Add `tests/components/FieldValidationBuilder.spec.js` — structured write, legacy flat prefill + per-field normalisation (flat keys removed only on the edited field), non-compiling pattern marked invalid and never emitted
  - Same `tests/components/page-editor/` placement deviation as 8.1.
- [x] 8.4 Add `tests/components/FormFieldBuilder.logic.spec.js` — details-area disclosure, `visibleWhen` delete-on-null through `updateField`, unknown per-field keys survive condition/validation edits, dangling-condition warning appears when a referenced field is removed
  - Same `tests/components/page-editor/` placement deviation as 8.1.
- [x] 8.5 Add `tests/components/FormPageEditor.steps.spec.js` — Steps fieldset mounts, `update('steps', …)` round-trip preserves unknown config keys, `validatedConfigKeys` includes `steps`, `markFor('steps')` renders an inline mark when the injected validator reports a steps error
  - Same `tests/components/page-editor/` placement deviation as 8.1.
- [x] 8.6 Add `tests/services/formLogicValidation.spec.js` — every REQ-OBFEL-005 rule: dangling/duplicate step refs, unknown condition field, off-allow-list op, `min > max`, bad pattern, flat+structured warning, clean manifest (and no-form-page manifest) pass
- [x] 8.7 `npm run test` green
  - This change's 6 new spec files: 57/57 passing. Full suite: 1058/1085 passing; the 27 pre-existing failures (4 files: `Step1Basics.spec.js`, `Step4Review.spec.js`, `DashboardAppsListWidget.spec.js`, `vitest/manifest.spec.js`) are confirmed pre-existing and unrelated — none of those 4 files appear in this change's `git status` diff (icon-catalogue `fromMdiJs`, dashboard-widget mock gap, manifest-registry drift).

## 9. Playwright e2e (`tests/e2e/form-editor-logic.spec.ts`, REQ-id-titled like `page-designer.spec.ts`)

- [x] 9.1 Add `tests/e2e/form-editor-logic.spec.ts` scaffolding — seed a form page on the e2e app, open `/apps/openbuild/applications/<slug>/design`, select the form page (reuse `page-designer.spec.ts` global-setup auth; same #41 quarantine annotation until that issue closes)
  - Written and validated with `npx playwright test --list` (parses, enumerates 5 tests) — NOT run against the shared dev instance and nothing deployed, per instructions. `test.describe.skip` carries the same #41 quarantine comment as `page-designer.spec.ts`.
- [x] 9.2 e2e "REQ-OBFEL-001: add/assign/reorder/delete steps" — add two steps with titles + assigned fields, reorder, delete one, delete the last; assert via the Raw JSON tab: `steps[]` written with id/title/fields refs, order swapped, deleted step's keys back in the pool, `steps` key gone after last delete, `fields[]` untouched (covers all four REQ-OBFEL-001 scenarios incl. absent-steps single-step state before the first add)
- [x] 9.3 e2e "REQ-OBFEL-002: condition builder writes LOCAL visibleWhen" — author `wantsContact eq true` on `email` (assert boolean value + omitted op in Raw JSON), author a `gt 3` condition (assert numeric coercion), clear it (assert key removed), and author an `endpoint` condition in Raw JSON then confirm the Design tab shows the read-only advanced summary and an unrelated field edit leaves it byte-for-byte unchanged (covers all four REQ-OBFEL-002 scenarios)
- [x] 9.4 e2e "REQ-OBFEL-003: validation builder writes the structured object" — author required/min/max/pattern/message and assert the `validation` object in Raw JSON; seed a field with legacy flat `required`/`pattern` via Raw JSON, edit its validation, assert normalisation (flat keys gone on that field, intact on an untouched sibling); enter pattern `[a-` and assert the inline invalid mark with nothing written (covers all three REQ-OBFEL-003 scenarios)
- [x] 9.5 e2e "REQ-OBFEL-004: dangling references warn live without deletion" — remove a field referenced by a sibling's condition and by a step; assert both `role="alert"` warnings appear immediately and Raw JSON still carries the stale `visibleWhen` and step entry (covers both REQ-OBFEL-004 scenarios)
- [x] 9.6 e2e "REQ-OBFEL-006: raw JSON round-trip + save" — author `steps[]`, an advanced `visibleWhen`, a `validation` object and an unknown custom key via Raw JSON, edit the submit label in Design, assert byte-for-byte survival in Raw JSON; then save and assert the ApplicationVersion PUT payload carries the new shapes with every other top-level manifest key unchanged (covers both REQ-OBFEL-006 scenarios)

## 10. Docs

- [x] 10.1 Add `docs/form-logic-authoring.md` (Docusaurus) — authoring steps, conditions and validation in the page designer: the steps manager, the condition builder (LOCAL shape + "advanced conditions live in Raw JSON"), validation rules incl. the legacy flat-key migration, the dangling-reference warning, and the note that rendering requires a nextcloud-vue build containing the `manifest-form-logic` leaf
- [x] 10.2 Link the new page from `docs/intro.md` (or the sidebar config in `docs/docusaurus.config.js`) beside the existing page-designer docs
  - Linked from `docs/intro.md`'s reference-pages sentence. `docs/sidebars.js` is `{type: 'autogenerated', dirName: '.'}`, so the new page is automatically in the sidebar too — no separate sidebar-config edit needed.

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
