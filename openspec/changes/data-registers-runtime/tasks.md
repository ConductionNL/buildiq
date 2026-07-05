## 1. Picker merge (single composable change)

- [x] 1.1 `src/composables/useRegisterPicker.js`: accept `opts.dataRegisters` (array of `{register, label?}`, default `[]`); in `fetchRegisters()`, label matching entries (`binding.label ?? binding.register`) and hoist them after the per-app register, per design.md Decision 1 — when `dataRegisters` is absent/empty, output must stay byte-identical to today
- [ ] 1.2 Extend `tests/composables/useRegisterPicker.spec.js`: labelled match, slug-fallback when `label` absent, hoist ordering (per-app register, then matched bindings, then the rest), and a no-`dataRegisters`-passed regression case

## 2. Wire the four verified consumers + PageDesigner

- [ ] 2.1 `IndexPageEditor.vue`, `DetailPageEditor.vue`, `LogsPageEditor.vue`: add a `dataRegisters: { type: Array, default: () => [] }` prop and pass it into the existing `useRegisterPicker({ appSlug: props.appSlug })` call in `setup()`
- [ ] 2.2 `src/views/PageDesigner.vue`: add an `applicationDataRegisters` data field populated by a small fetch (`GET /apps/openregister/api/objects/openbuild/application?slug=<slug>&_limit=1`, same call shape `useApplicationVersion.js` already uses) in `created()`; pass `:data-registers="applicationDataRegisters"` on the `<component :is="subEditorFor(...)">` binding, next to the existing `:app-slug="slug"`
- [ ] 2.3 `ApplicationDetailActions.vue`: in `openSaveAsTemplate()`, extend `useRegisterPicker({ appSlug: this.obApp.slug })` to also pass `dataRegisters: this.obApp.dataRegisters || []`
- [ ] 2.4 Extend `tests/components/page-editor/IndexPageEditor.spec.js`: mounting with a `data-registers` prop passes it through to the mocked `useRegisterPicker` factory call

## 3. Promotion-skip regression coverage

- [x] 3.1 Confirm (already verified in design.md) `VersionPromotionService.php` needs no production-code change — no task, proof only
- [x] 3.2 Add a PHPUnit regression test to `tests/Unit/Service/VersionPromotionServiceTest.php`: an Application/source carrying a `dataRegisters` binding promotes under all three strategies without any mock call referencing the bound register's slug — only `source['register']` / `target['register']` are touched

## 4. Export: schema-defs + per-binding includeData toggle

- [x] 4.1 Add a `register.d/` fragment declaring `exportJob.dataRegisters` (array of `{register, includeData}`, default `[]`) — mirrors `includeSeedData`; no touch to `Application`
- [x] 4.2 `ExportService.php`: add `bundleDataRegisterSchemas()`, called from `generateAppZip()`, writing `lib/Settings/data-registers/<register-slug>.schema.json` for every bound register (schema defs only, never merged into `<app>_register.json`) and `<register-slug>.seed-data.json` additionally when that binding's `includeData` is true
- [x] 4.3 `ExportJobService::queue()` / `ExportsController::submit()`: accept and persist the request's `dataRegisters` array onto the `ExportJob` record (same pattern as `includeSeedData`); `RunExportJob` forwards it from `loadJob()` into `generateAppZip()`
- [x] 4.4 `ExportDialog.vue`: render one `NcCheckboxRadioSwitch` per binding in the source Application's `dataRegisters` (label `binding.label ?? binding.register`), unchecked by default; submit payload mirrors the bindings 1:1 with the resolved `includeData` flags
- [x] 4.5 `ApplicationDetailActions.vue`: pass `:data-registers="obApp.dataRegisters || []"` into `<ExportDialog>`
- [x] 4.6 Add PHPUnit tests to `tests/Unit/Service/ExportServiceTest.php`: schema-defs file is always written for a bound register; seed-data file is written only when `includeData` is true; no `data-registers/` directory when `dataRegisters` is empty

## 5. Designer UI: add/remove dataRegisters bindings

- [x] 5.1 `AppSettingsModal.vue`: add a "Data registers" section — list of `{register, label?}` rows with add/remove controls (register slug `NcTextField`, optional label `NcTextField`), emitting `update:data-registers` with the full array on any change
- [x] 5.2 `ApplicationDetailActions.vue`: wire `AppSettingsModal`'s `update:data-registers` to `this.obPatchApp({ dataRegisters })`, matching the existing `update:allow-overrides` → `setAllowOverrides()` pattern

## 6. Spec-delta bookkeeping

- [x] 6.1 Append this change to the `**OpenSpec changes**` list and set `**Status**: in-progress` on `openspec/specs/version-promotion/spec.md` (update its `status:` frontmatter key), `openspec/specs/openbuild-exporter/spec.md`, and `openspec/specs/page-designer-ui/spec.md` — verified already pre-seeded on all three (change-list entry + in-progress status present) when this task began; no edit needed

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate data-registers-runtime --strict` and resolve any structural errors.
- Run `npm run test` (vitest) for the composable + component test changes.
- Run the PHP test suite (`phpunit` / `composer test`, per this repo's existing scripts) for `VersionPromotionServiceTest.php` and `ExportServiceTest.php`.
- Confirm every existing Application/ExportJob object with no `dataRegisters` field still round-trips unchanged through every touched surface (pickers, export, settings modal).
- Confirm the exported tree for an Application with `dataRegisters` bindings contains no reference to those registers in `appinfo/info.xml`'s `<repair-steps>`.

## Acceptance Criteria

- An Application's declared `dataRegisters` are labelled and hoisted (after the per-app register) in every register picker fed by `useRegisterPicker.js`, with zero behaviour change for an Application carrying no bindings.
- A PHPUnit regression test proves `VersionPromotionService` never references a bound data register's slug under any of the three promotion strategies.
- Exporting an Application with `dataRegisters` bundles each binding's schema definitions unconditionally, and its row data only when that binding's `includeData` was explicitly toggled on in the export dialog.
- Neither bundled data-register file is wired into the exported app's own `<repair-steps>` — they are reference-only.
- An owner can add and remove `dataRegisters` bindings from the Application settings modal, persisted via the existing `obPatchApp()` OR REST PUT — no new backend route.
- `src/builder.js` and `src/views/BuilderHost.vue` are unmodified by this change (see design.md Decision 3 and `DEFERRED_QUESTIONS`).
- No change to the `Application` schema; the only schema touch is the additive `exportJob.dataRegisters` property.
