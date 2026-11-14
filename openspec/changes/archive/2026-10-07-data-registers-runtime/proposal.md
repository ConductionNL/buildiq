---
kind: code
depends_on: [data-registers-schema-declaration]
---

## Why

`data-registers-schema-declaration` (chain head, merged on this branch) added the
optional `dataRegisters` array property to the `Application` schema — the
declarative surface that lets an admin name shared, non-versioned OpenRegister
registers (e.g. `spectr`'s ~30-schema canonical dataset) the app binds to
alongside its own per-version register. That spec shipped **zero** PHP/Vue code
by design (per ADR-032, a `kind: config` head only declares schema). Today,
declaring a `dataRegisters` binding on an `Application` object has no visible
effect anywhere in Buildiq: the builder pickers don't know it exists, the
exporter doesn't bundle it, and there is no UI to add or remove a binding in
the first place. `spectr`'s data-register work — the concrete consumer named in
the head's proposal — stays blocked until this follower lands.

This spec is that follower (`kind: code`, `depends_on:
[data-registers-schema-declaration]`). It wires the four places in Buildiq
that need to become `dataRegisters`-aware:

1. The builder's register/schema pickers surface the Application's declared
   data registers, labelled, alongside its per-version register.
2. A regression test formally locks in that version promotion never reads or
   writes `Application.dataRegisters` (already true by construction per the
   head's design.md — this spec adds the proof, not the guarantee).
3. The exporter bundles bound data registers' schema definitions into the
   exported app tree, with a per-binding opt-in to also bundle their row data.
4. The Application settings surface gains a field to add/remove
   `dataRegisters` bindings — today there is no UI path to populate the
   property the head spec declared.

## What Changes

- **Pickers**: `useRegisterPicker.js` accepts the Application's declared
  `dataRegisters` and labels/hoists matching entries in `fetchRegisters()`'s
  result (per `binding.label ?? binding.register`). The four verified
  consumers — `IndexPageEditor.vue`, `DetailPageEditor.vue`,
  `LogsPageEditor.vue`, `ApplicationDetailActions.vue` — and their common
  parent `PageDesigner.vue` (which resolves the Application record and passes
  `appSlug` down today) thread the binding array through. This is a single
  logic change in the composable; the four call sites + one parent only add
  mechanical prop-passing (see design.md's Decision 1 for why this is not six
  separate merges).
- **Promotion-skip regression coverage**: `openspec/specs/version-promotion/`
  gains an ADDED Requirement + Scenario asserting `VersionPromotionService`
  never touches `Application.dataRegisters`, backed by a new PHPUnit
  regression test in `VersionPromotionServiceTest.php`. No production code
  changes — `forwardSchemaSetToOR()`, `wipeTargetRegister()`, and
  `copyRowsFromSource()` already operate exclusively on
  `ApplicationVersion.register` (verified by reading the current
  implementation); this is a proof, not a fix.
- **Export**: the exporter always bundles the **schema definitions** of every
  register named in the source Application's `dataRegisters` into the
  exported tree (reference-only — not auto-imported by the exported app's own
  install process, preserving the "not owned by this app" contract). A new
  **per-binding `includeData` toggle** in `ExportDialog.vue` (default off,
  i.e. schema-defs-only) additionally bundles that binding's row data as a
  reference fixture when explicitly opted in. The `exportJob` schema gains a
  `dataRegisters` property (mirroring the existing `includeSeedData` field)
  so the async `RunExportJob` background job can read the per-export choice.
- **Designer UI**: `AppSettingsModal.vue` gains a "Data registers" section —
  add/remove rows of `{ register slug, optional label }` — wired through
  `ApplicationDetailActions.vue`'s existing `obPatchApp()` helper (a plain OR
  REST PUT; no new backend route).
- **NOT in scope**: `src/builder.js` and `src/views/BuilderHost.vue`. Both
  were read in full — neither contains a register/schema picker or a
  `dataSources`-loading routine to extend today (each simply hands an
  already-resolved `manifest` / `bundled-manifest` to `CnAppRoot`). There is
  no code at either call site for this spec to merge `dataRegisters` into;
  see design.md's Decision 5 and this change's `DEFERRED_QUESTIONS`.
- **NO schema change to `Application`** — the head already declared
  `dataRegisters`; this spec only adds code that reads it (plus one small,
  already-imperative `exportJob` schema property, exactly analogous to the
  pre-existing `includeSeedData` field on that same schema).

### Capabilities

#### New Capabilities

_(none — this spec extends three existing capabilities' code surfaces; it
introduces no new capability domain)_

#### Modified Capabilities

- `version-promotion`: ADDED Requirement — `VersionPromotionService` never
  reads or writes `Application.dataRegisters`; a regression test locks this
  invariant in. No existing requirement's behavior changes.
- `buildiq-exporter`: ADDED Requirement — the exporter bundles bound data
  registers' schema definitions (always) and row data (opt-in per binding via
  `includeData`) into the exported app tree.
- `page-designer-ui`: ADDED Requirement — register/schema-backed sub-editors
  surface the Application's declared `dataRegisters`, labelled, alongside the
  per-version register.

## Impact

- **Changed files**:
  - `src/composables/useRegisterPicker.js` — accept + apply `dataRegisters`.
  - `src/components/page-editor/IndexPageEditor.vue`,
    `DetailPageEditor.vue`, `LogsPageEditor.vue` — new `dataRegisters` prop,
    threaded into `useRegisterPicker(...)`.
  - `src/views/PageDesigner.vue` — resolve the Application's `dataRegisters`
    and pass them to the active sub-editor.
  - `src/components/ApplicationDetailActions.vue` — pass
    `obApp.dataRegisters` into the `openSaveAsTemplate()` picker call and into
    `ExportDialog`; wire the new settings-modal section to `obPatchApp()`.
  - `src/modals/AppSettingsModal.vue` — add/remove `dataRegisters` bindings.
  - `src/dialogs/ExportDialog.vue` — per-binding `includeData` toggle.
  - `lib/Service/ExportService.php` — bundle bound data registers' schema
    defs (+ optional row data) into the exported tree.
  - `lib/Service/ExportJobService.php`, `lib/Controller/ExportsController.php`
    — accept/persist the per-export `dataRegisters` choice.
  - `lib/Settings/register.d/` — a new fragment adding `exportJob.dataRegisters`
    (mirrors `includeSeedData`; does **not** touch `Application`).
  - `lib/Service/VersionPromotionService.php` — read only (regression test
    proves the existing code; no production-code edit expected).
- **No breaking changes** — every new prop/property is optional and additive;
  every existing Application/ExportJob object with no `dataRegisters` field
  behaves exactly as it does today.
- **OpenRegister** — no new register, no schema change to `Application`
  (already shipped by the head); one additive property on the already
  app-owned `exportJob` schema.
- **Foundational ADRs honoured** — ADR-022 (designer UI change is a plain OR
  REST PUT via the existing `obPatchApp()` helper — no new CRUD wrapper),
  ADR-031 (declarative-vs-imperative classification in design.md — pickers +
  export packaging are the sanctioned imperative surfaces the head's design.md
  already carved out), ADR-032 (this is the `kind: code` follower closing the
  2-spec chain the head opened), ADR-037 (the `exportJob` fragment ships as
  its own `register.d/` file, not a monolith edit).
