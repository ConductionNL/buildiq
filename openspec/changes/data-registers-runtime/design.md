## Context

`data-registers-schema-declaration` (chain head, `kind: config`, merged on this
branch) added an optional `dataRegisters` array property to the `Application`
schema — each entry `{ register, label? }` names a shared, non-versioned
OpenRegister register the app binds to alongside its own per-version register
(`ApplicationVersion.register`, ADR-002). The head shipped the schema only
(`lib/Settings/register.d/20-data-registers.json`); zero PHP, zero Vue, zero
routes. This follower (`kind: code`, `depends_on:
[data-registers-schema-declaration]`) wires the four consumers the head's own
proposal named as out of scope for itself:

1. **Pickers** — `src/composables/useRegisterPicker.js`, consumed by
   `IndexPageEditor.vue`, `DetailPageEditor.vue`, `LogsPageEditor.vue`, and
   `ApplicationDetailActions.vue`. Today `fetchRegisters()` lists every OR
   register instance-wide and hoists the app's own per-version register to
   the top; it has no notion of the Application's declared `dataRegisters`.
2. **Promotion-skip regression coverage** — `lib/Service/VersionPromotionService.php`
   already never touches `Application.dataRegisters` (verified by reading
   `forwardSchemaSetToOR()`, `wipeTargetRegister()`, `copyRowsFromSource()` —
   every method reads `$source['register']` / `$target['register']`, the
   per-version field, exclusively). This spec adds the regression test that
   locks the invariant in; it does not change the service.
3. **Export** — `lib/Service/ExportService.php` bundles the per-version
   register/manifest into the exported tree today (`generateAppZip()` →
   `copyTemplate()` + `resolvePlaceholders()`); it has no notion of
   `dataRegisters` at all.
4. **Designer UI** — there is no UI path to populate `dataRegisters` on an
   Application. `src/modals/AppSettingsModal.vue` is the existing owner-facing
   settings surface (publish toggle, allow-user-overrides toggle), opened from
   `ApplicationDetailActions.vue` and persisted via the `applicationContext`
   mixin's `obPatchApp()` (a shallow-merge PUT to OR's
   `/apps/openregister/api/objects/openbuild/application/{uuid}` — ADR-022,
   no new backend route).

**Codebase verification performed for this design** (all read in full before
writing this document): `useRegisterPicker.js`; `IndexPageEditor.vue`,
`DetailPageEditor.vue`, `LogsPageEditor.vue`, `ApplicationDetailActions.vue`;
`src/views/PageDesigner.vue`; `src/builder.js`, `src/views/BuilderHost.vue`;
`src/composables/useApplicationVersion.js`; `src/mixins/applicationContext.js`;
`src/modals/AppSettingsModal.vue`, `src/dialogs/ExportDialog.vue`;
`lib/Service/VersionPromotionService.php`, `lib/Service/ExportService.php`,
`lib/Service/ExportJobService.php`, `lib/BackgroundJob/RunExportJob.php`,
`lib/Controller/ExportsController.php`; the `exportJob` schema block in
`lib/Settings/openbuild_register.json`; and the existing test files
`tests/composables/useRegisterPicker.spec.js`,
`tests/components/page-editor/IndexPageEditor.spec.js`,
`tests/Unit/Service/VersionPromotionServiceTest.php`,
`tests/Unit/Service/ExportServiceTest.php`.

## Goals / Non-Goals

**Goals:**
- Surface an Application's declared `dataRegisters` in the builder's
  register/schema pickers, labelled per `binding.label ?? binding.register`,
  via **one** logic change (the composable) rather than duplicating the merge
  in every consumer.
- Formally prove — spec Requirement + Scenario + PHPUnit test — that
  `VersionPromotionService` never reads or writes `Application.dataRegisters`.
- Let the exporter carry a bound data register's **schema** into the exported
  app tree by default, and its **row data** only when the admin opts in
  per binding.
- Give an owner a way to add/remove `dataRegisters` bindings without hand-
  editing the Application object via raw OR REST.
- Keep every change additive and backward compatible: an Application with no
  `dataRegisters` (every Application that predates the chain head) behaves
  identically to today at every one of these four surfaces.

**Non-Goals:**
- No change to the `Application` schema itself — the head already shipped it.
  This spec's only schema touch is one new property on the already
  app-owned, already-imperative `exportJob` schema (see Decision 5).
- No validation that a `dataRegisters[].register` slug resolves to a real,
  reachable OR register at picker-render or export time — a dangling
  reference resolves to "not found in the fetched list" (picker) or "no
  schemas bundled" (export), exactly the existing failure mode for a deleted
  `ApplicationVersion.register` (head design.md's own Non-Goals precedent).
- No change to `src/builder.js` or `src/views/BuilderHost.vue` — see
  Decision 3. Neither file has a register/schema picker or a
  `dataSources`-loading routine today; there is nothing in either file for
  this spec to extend.
- No new RBAC mechanism. Access to a bound register's own objects continues
  to be governed exclusively by that register's own schemas'
  `authorization` blocks (head design.md's RBAC section, unchanged here).
- No auto-import of a bundled data register's schema or row data into the
  **exported** app's install process (no new `<repair-step>`) — see
  Decision 5's non-ownership rationale.

## Decisions

### Decision 1: Picker merge lives entirely inside `useRegisterPicker.js`

`useRegisterPicker(opts)` gains one new option, `opts.dataRegisters` (array of
`{ register, label? }`, default `[]`). `fetchRegisters()` is extended, after
its existing per-app-register hoist, to:

1. Build a `Map<registerSlug, label>` from `dataRegisters` (`label ??
   register`).
2. For every fetched register entry whose `slug`/`id` matches a key in that
   map, set a `label` field on the entry to the resolved label (the raw
   `title`/`slug` remains untouched — pickers that don't know about the new
   field keep rendering exactly as before; consumers that want the friendlier
   name read `entry.label || entry.title || entry.slug`).
3. Re-sort so the order is: per-app register first (existing behaviour,
   unchanged), then entries matching a `dataRegisters` binding (in the order
   the Application declared them), then everything else in the order OR
   returned it.
4. When `opts.dataRegisters` is absent or `[]` (every existing call site,
   until wired), steps 1-3 are no-ops and `fetchRegisters()` returns
   byte-identical output to today — this is a regression-safe default, not a
   breaking change to the composable's contract.

**Wiring is mechanical, not logic-bearing**, at five call sites:
- `IndexPageEditor.vue`, `DetailPageEditor.vue`, `LogsPageEditor.vue`: add a
  `dataRegisters: { type: Array, default: () => [] }` prop; pass
  `dataRegisters: props.dataRegisters` into the existing
  `useRegisterPicker({ appSlug: props.appSlug })` call in `setup()`.
- `PageDesigner.vue` (the parent that already passes `:app-slug="slug"` to
  whichever sub-editor `subEditorFor(selectedPage.type)` resolves — see
  Decision 2 for how it obtains the array): add `:data-registers="..."` next
  to the existing `:app-slug="slug"` binding.
- `ApplicationDetailActions.vue`: in `openSaveAsTemplate()`, extend
  `useRegisterPicker({ appSlug: this.obApp.slug })` to also pass
  `dataRegisters: this.obApp.dataRegisters || []` — `this.obApp` already
  carries the field once the head's schema is live; no new fetch needed at
  this call site.

**Alternatives considered:**
- *Duplicate the label/hoist logic in each of the three page-editor
  components* — rejected: the task itself flags this as the anti-pattern to
  avoid (6 edits instead of 1); it would also mean three independent
  copies of the same sort/label algorithm to keep in sync on the next tweak.
- *Merge inside `ApplicationDetailActions.vue` only, since it already holds
  `this.obApp`, and have the three page editors read from a shared store
  instead of the composable* — rejected: this repo has no Pinia store for
  Application state on the page-designer route (ADR-004's "no custom stores"
  rule plus the existing `useRegisterPicker` composable is already the
  established single source of truth for register/schema option lists per
  `page-designer-ui`'s own spec — REQ text "Register/schema backed editors
  SHALL fetch their option lists"). Changing that contract is far larger than
  this spec's scope.

### Decision 2: `PageDesigner.vue` resolves the Application record with a small, dedicated fetch

`PageDesigner.vue` today resolves `applicationVersion` via
`useApplicationVersion(this.slug, versionSlug)` — that composable's public
return shape is `{ applicationVersion, loading, error }`; it never exposes
the **parent** Application record (it fetches the Application internally, in
one branch only, purely to read `productionVersion`, and does not return it).
Rather than widen `useApplicationVersion`'s contract — which is shared by
"all four builder views" per its own header comment, only one of which
(`PageDesigner`) needs `dataRegisters` — this spec adds a small, self-contained
fetch in `PageDesigner.vue`: `GET
/apps/openregister/api/objects/openbuild/application?slug=<slug>&_limit=1`
(the exact call shape `useApplicationVersion.js` already uses internally),
storing the result's `dataRegisters` (default `[]`) in a new
`applicationDataRegisters` data field, invoked once in `created()` alongside
the existing version resolution.

**Alternatives considered:**
- *Extend `useApplicationVersion()` to also return `application`* — rejected:
  its `fetchBySlug()` branch (used whenever `?_version=` is present) never
  fetches the Application today; adding it there too widens a
  four-consumer-shared composable's contract for the benefit of exactly one
  of those four consumers. A future spec that finds a second real need for
  the Application record at that layer can revisit this trade-off with two
  data points instead of one.
- *Have `ApplicationDetailActions.vue` pass `dataRegisters` down via route
  query or Vuex-style global state* — rejected: `PageDesigner.vue` is reached
  directly by route (`/builder/{slug}/pages`), not always navigated to from
  `ApplicationDetailActions.vue`; a route-independent fetch is the only
  option that works regardless of entry point, and matches the existing
  pattern (`PageDesigner` already independently resolves slug + version from
  the route rather than expecting a parent to hand it state).

### Decision 3: `src/builder.js` and `src/views/BuilderHost.vue` are explicitly NOT touched

The task brief that scoped this spec named "the two builder-host dataSources
loaders (`src/builder.js`, `src/views/BuilderHost.vue` `loadDataSources`)" as
in-scope alongside the four picker consumers. Both files were read in full
(current branch **and** `origin/development` HEAD — `git show
origin/development:src/builder.js` / `:src/views/BuilderHost.vue`) as part of
this design. Neither contains a `dataSources` prop, a `loadDataSources`
function, or any register/schema-picker logic:

- `src/builder.js` fetches the resolved manifest once
  (`GET /api/applications/{slug}/manifest`) and hands it straight to
  `h(CnAppRoot, { props: { manifest, registry, pageTypes, ... } })`.
- `src/views/BuilderHost.vue` hands `bundled-manifest` +
  `registry` + `options` (an `{ endpoint }` object) to a nested `<CnAppRoot>`.

Both hosts render an **already-resolved** manifest — the register + schema
each page binds to was baked in at design time by the page editors (Decision
1's surface); neither host re-opens a register picker at runtime. There is
therefore no code at either call site for this spec to merge `dataRegisters`
into. Inventing a new `dataSources`/`cnDataSources` cross-repo contract
(threading a fresh prop through `CnAppRoot` from `@conduction/nextcloud-vue`)
to give these two hosts a picker they don't otherwise have would be a
materially larger, separate architectural change spanning another repo —
exactly the scope creep ADR-032 warns against for a `kind: code` spec sized
around "wire the schema the head declared." This is logged as a
`DEFERRED_QUESTIONS` entry below rather than silently dropped.

### Decision 4: Promotion-skip is a regression test, not a code change

`VersionPromotionService::forwardSchemaSetToOR()`, `wipeTargetRegister()`, and
`copyRowsFromSource()` each resolve their target exclusively via
`$source['register']` / `$target['register']` — the per-version
`ApplicationVersion.register` field — and never read `$source['dataRegisters']`
or touch anything named `dataRegisters` (confirmed by reading the full
current implementation). The invariant "promotion never copies a
data-register row" therefore already holds with zero lines changed in this
service, exactly as the head's design.md predicted. This spec's job is to:

1. Add an ADDED Requirement + Scenario to `openspec/specs/version-promotion/`
   stating the invariant formally (see `specs/version-promotion/spec.md` in
   this change).
2. Add a PHPUnit regression test to the existing
   `tests/Unit/Service/VersionPromotionServiceTest.php` that constructs a
   source ApplicationVersion whose parent Application carries a non-empty
   `dataRegisters`, runs `promote()` with each of the three strategies, and
   asserts the mocked `ObjectService`/`RegisterMapper` are never invoked with
   the bound data register's slug — only with `source['register']` /
   `target['register']`.

No production code in `VersionPromotionService.php` changes. This keeps the
regression test honest: it is provable to fail if a future change
accidentally starts reading `Application.dataRegisters` inside the promotion
flow.

### Decision 5: Export bundles schema defs unconditionally, row data per-binding opt-in

Per the head design.md's Open Questions resolution (Ruben, 2026-07-05):
"Each `dataRegisters` binding gets an `includeData` choice in the export
flow (default: schema-defs-only)." Concretely:

- `ExportService::generateAppZip()` gains a step,
  `bundleDataRegisterSchemas()`, called after `copyTemplate()` /
  `resolvePlaceholders()`: for every entry in the source Application's
  `dataRegisters`, resolve the named register (via `RegisterMapper`, already
  a `VersionPromotionService` dependency — same injection pattern), read its
  schema set, and write ONE reference file per binding into the exported tree
  at `lib/Settings/data-registers/<register-slug>.schema.json` — the JSON
  Schema definitions only, clearly namespaced away from the app's own
  `<app>_register.json` (it is not merged into the app's own
  `components.schemas`, because the exported app does not own this register
  any more than the source virtual app did).
- **Schema defs are bundled for every binding, unconditionally** — there is
  no "exclude this register from the export entirely" toggle; the head's
  resolved decision language ("default: schema-defs-only") establishes
  schema-defs as the floor, not an opt-in.
- **Row data is opt-in per binding.** `exportJob` gains a new property,
  `dataRegisters` (array of `{ register, includeData }`), mirroring the
  existing `includeSeedData` boolean field's role (export-flow state
  persisted on the async job record, not Application configuration — see the
  head's design.md Open Questions: "the toggle is export-flow state, not
  Application configuration"). `ExportDialog.vue` renders one
  `NcCheckboxRadioSwitch` per binding the source Application declares
  (labelled `binding.label ?? binding.register`), unchecked by default; on
  submit, the payload's `dataRegisters` entries mirror the Application's
  bindings 1:1, each carrying the resolved `includeData` flag.
  `ExportJobService::queue()` persists the array onto the `ExportJob` record
  (same pattern as `includeSeedData` today); `RunExportJob` reads it back via
  `loadJob()` and forwards it to `generateAppZip()`. When `includeData` is
  true for a binding, `bundleDataRegisterSchemas()` additionally writes
  `lib/Settings/data-registers/<register-slug>.seed-data.json` — the
  register's current rows in the same `{ "_comment", "objects": [...] }`
  shape the head's own `seed-data.json` fixture uses.
- **Neither file is wired into a `<repair-step>` or auto-import.** They are
  reference material for whoever maintains the exported app next — exactly
  as the running virtual app itself never auto-copies a bound register's
  rows into its own namespace. Auto-importing here would silently re-create
  the exact anti-pattern (a copy of canonical shared data forking on every
  export) that motivated the head spec's non-ownership model in the first
  place.

**Alternatives considered:**
- *Fold the bound register's schema straight into `<app>_register.json`* —
  rejected: that file already represents "schemas this app owns and the
  exported app's own repair step imports on install." Merging a shared,
  externally-fed register's schema into it would make the exported app
  falsely appear to own/provision that register, silently reversing the
  head's core non-ownership decision (Decision 1's "Why not reuse
  `ApplicationVersion.register`'s ... pattern" argument applies identically
  here).
- *One export-wide "include all data-register data" toggle instead of
  per-binding* — rejected: explicitly overridden by Ruben's 2026-07-05
  decision recorded in the head's design.md; a municipality app binding both
  `brp-personen` (sensitive, must stay schema-only) and a smaller reference
  register illustrates why per-binding granularity matters.

### Decision 6: Designer UI extends `AppSettingsModal.vue`, not a new modal

`AppSettingsModal.vue` is already the owner-facing settings surface for
Application-level toggles (`published`, `allowUserOverrides`), already
modal-isolated per ADR-004, already wired through
`ApplicationDetailActions.vue`'s `obPatchApp()` PUT. Adding a "Data
registers" section (list of `{ register, label? }` rows with add/remove, no
existence validation — matching the head's own save-time Non-Goal) is a
natural extension of an existing, single-purpose settings surface rather than
a new file. `ApplicationDetailActions.vue` binds the modal's
`update:data-registers` event to `this.obPatchApp({ dataRegisters })` —
identical shape to the existing `update:allow-overrides` →
`setAllowOverrides()` wiring.

**Alternatives considered:**
- *A dedicated `DataRegistersModal.vue`* — rejected: `AppSettingsModal.vue`
  is already exactly this kind of surface (simple property toggles/edits on
  the Application object, one PUT on save-per-field); a second modal for one
  more property section fragments the owner's settings experience across two
  places for no isolation benefit (ADR-004's modal-isolation rule targets
  inline markup inside a parent, not "one modal per property" granularity).

## Declarative-vs-imperative decision (ADR-031)

- **Pickers** (`useRegisterPicker.js` + its five call sites) render/populate
  UI option lists — the same class the head's design.md already carves out
  as never a declarative candidate ("There is no `x-openregister-*` extension
  for 'render a dropdown'").
- **Export bundling** (`ExportService::bundleDataRegisterSchemas()`) matches
  ADR-031's "What apps SHOULD still write in PHP" bullet the head's design.md
  already cited for `ExportService`: "Document/PDF/document-template
  generation ... The schema engine has no opinion on rendered output." A
  reference JSON file inside a ZIP is rendered output, identically classified
  to the file this class already produces.
- **Promotion-skip** needs no new code (Decision 4) — `VersionPromotionService`
  is already an ADR-031 §Exceptions file per its own docblock ("every branch
  in this file is classified imperative"). This spec adds a test, not a
  behaviour.
- **Designer UI** (`AppSettingsModal.vue` section + `ApplicationDetailActions.vue`
  wiring) is UI + a plain OR REST PUT via the pre-existing `obPatchApp()`
  helper — no new service class, no new route, no business logic beyond
  "PUT this array back." This mirrors how `allowUserOverrides` (already on
  the same modal) is wired.
- **The `exportJob.dataRegisters` schema property itself is declarative** —
  a schema-only patch to the already app-owned, already-imperative-in-purpose
  `exportJob` schema, exactly like `includeSeedData` before it. No service
  class is introduced by the property; `ExportJobService::queue()` already
  has the exact `(bool) ($payload['includeSeedData'] ?? false)` pattern this
  spec's `dataRegisters` field reuses.

No exception justification is needed beyond what the head's design.md already
established — this spec's imperative surfaces (pickers, export packaging) are
the same two classes the head pre-cleared for its follower.

## Seed Data

**No new OpenRegister schema is introduced or modified on `Application`** —
the head already shipped `dataRegisters`, and this spec adds no property to
it. The head's own `openspec/changes/data-registers-schema-declaration/seed-data.json`
(the `spectr` Application and the generic-municipality Application, both
carrying populated `dataRegisters` arrays) already provides realistic
fixtures for this spec's tests and manual QA — no new fixture is authored
here; this spec's PHPUnit/vitest tests construct their own minimal in-memory
`dataRegisters` arrays inline (standard practice for the existing
`VersionPromotionServiceTest.php` / `ExportServiceTest.php` / composable
specs, none of which read from a shared JSON fixture file today).

The one schema this spec DOES touch — `exportJob` gains `dataRegisters`
(array of `{ register, includeData }`) — is transient, per-request job
state written by the export flow itself (`ExportJobService::queue()`), not
admin-authored reference data an operator would hand-seed. The pre-existing
sibling property `includeSeedData` on the same schema has no seed-data
fixture anywhere in this repo for the same reason; this spec follows that
precedent rather than stubbing an artificial "example ExportJob" fixture
that no real workflow would create by hand.

## Risks / Trade-offs

- **[Risk]** A picker or export surface could be tempted to treat a
  `dataRegisters` entry whose slug doesn't resolve in OR as an error rather
  than a silent no-op. → **Mitigation**: explicitly out of scope (Non-Goals);
  matches the existing, already-accepted failure mode for a deleted
  `ApplicationVersion.register`.
- **[Risk]** Bundling a data register's row data into an export ZIP
  (`includeData: true`) could leak sensitive shared data (e.g. a
  municipality's `brp-personen`) into a downloadable/GitHub-pushed artifact
  if an owner opts in without understanding the register is shared, not
  app-owned. → **Mitigation**: default is off; the per-binding label (from
  the head's schema) is shown next to the toggle so the owner sees exactly
  which shared register they are about to include row data for, not a bare
  slug; RBAC on the referenced register's own schemas still gates who can
  read that data in the first place (ADR-022) — this spec does not widen
  access, only what an already-authorised exporter may bundle.
- **[Risk]** Widening `useRegisterPicker.js`'s `fetchRegisters()` return
  shape (adding a `label` field) could collide with a consumer that already
  uses a property named `label` on register entries for something else. →
  **Mitigation**: `fetchRegisters()`'s current return shape is OR's raw
  register list (`{ id, slug, title, schemas, ... }` — no existing `label`
  key per the current implementation and its own test fixtures); grepped
  every current consumer's template/render code for `.label` reads on a
  register entry — none exist today.
- **[Trade-off]** The exported app's `lib/Settings/data-registers/*.json`
  reference files are not consumed by any code in the exported app itself
  (no repair step, no runtime read) — they exist purely as documentation for
  a human maintaining the exported app. → Accepted: auto-consuming them would
  require the exported app to either provision its own copy of a shared
  register (reintroducing the exact per-app-copy problem `dataRegisters` was
  designed to avoid) or take a runtime dependency back on the source
  register's continued existence outside OpenBuild's own lifecycle — both
  are bigger decisions than this spec's scope; the head's own design.md
  leaves the same door open ("Open Questions" notes no cap or deeper
  integration is addressed yet).

## Migration Plan

None required for `Application` — no schema change there. For `exportJob`,
the new `dataRegisters` property is optional and additive (mirrors
`includeSeedData`'s original rollout); every existing `ExportJob` object
without it remains schema-valid, and `ExportJobService::queue()` defaults it
to `[]` when the request payload omits it (identical fallback pattern to the
existing `includeSeedData` read). No backfill of historical `ExportJob`
records is needed — completed/failed jobs are not re-processed.

Rollback is equally trivial: reverting the `exportJob` register.d fragment
and the four code surfaces independently is safe in any order, since each is
additive and none introduces a required field or a breaking change to an
existing contract.

## Open Questions

- Should the exported app's `lib/Settings/data-registers/*.json` reference
  files eventually be consumable by a future "re-attach to a shared
  register" repair step for exported apps that want to keep receiving live
  data post-export? Not addressed here — no consumer has asked for it yet;
  flagged for a future spec if `spectr`'s own export path surfaces the need.
- Should `dataRegisters[].includeData` also appear as a picker-visible hint
  inside the builder itself (e.g. "this register's row data will be
  exported") before the owner ever opens the export dialog? Not addressed
  here — the export dialog is the only surface that currently needs to know
  about `includeData`; deferred until real usage shows the builder-side hint
  is needed.
