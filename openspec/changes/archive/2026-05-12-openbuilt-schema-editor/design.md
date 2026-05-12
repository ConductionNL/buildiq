## Context

`bootstrap-openbuilt` (chain #1) ships a JSON `<textarea>` for editing
a virtual app's manifest blob and seeds a `hello-world` Application
over a static `hello-message` schema declared in
`lib/Settings/openbuilt_register.json`. The "static schema file"
pattern is unworkable for citizen developers: it requires a backend
deploy to evolve the data model, and the JSON-textarea editor offers
no help shaping `properties`, validation, or `x-openregister-*`
declarative metadata. Chain #3
(`openregister-runtime-schema-api`, lives in the OpenRegister repo)
solves the backend half by exposing POST / PUT / DELETE endpoints on
schemas plus declarative-engine reload + cache invalidation. This
spec (chain #4) ships the frontend half — the **visual schema
designer** — that consumes those endpoints and lets a non-technical
user author Schemas, fields, validation, lifecycle, aggregations,
calculations, notifications, relations, and widgets through a guided
UI.

## Goals / Non-Goals

**Goals**

- Ship a Vue surface at `/builder/:slug/schemas[/:schemaId]` that
  lets a citizen developer add / rename / delete schemas in their
  virtual app's register namespace.
- Ship a field editor that covers the core JSON Schema property
  shapes — `string`, `number`, `integer`, `boolean`, `array`,
  `object`, `relation` — with type-specific validation widgets.
- Ship visual sub-editors for every `x-openregister-*` declarative
  block (lifecycle, aggregations, calculations, notifications,
  relations, widgets) per ADR-031 so the editor itself is the
  canonical authoring surface for declarative behaviour.
- Round-trip every change through OR's runtime schema CRUD endpoints
  (chain #3) — no app-local DB writes, no PHP, no per-schema
  controllers in OpenBuilt.
- Use the workspace's canonical store pattern (`createObjectStore` —
  see memory rule "Store pattern guidance") rather than ship a bespoke
  Pinia store.

**Non-Goals (deferred to chain)**

- Visual page / manifest designer (chain #5 — `openbuilt-page-editor`).
  The widget editor in this spec produces the metadata the page
  editor *consumes*, but the page editor itself is out of scope.
- Schema versioning, draft / publish, snapshot / rollback (chain #6 —
  `openbuilt-versioning`). The version field captured on the schema
  is a free-edit semver string for v1 of the editor.
- Per-built-app RBAC on schema authoring (chain #7 — `openbuilt-rbac`).
  Authoring rights inherit from OR's organisation scoping until #7
  lands.
- Marketplace import of canned schemas (chain #8 —
  `openbuilt-templates-marketplace`).
- Code generation from a designed schema (chain #9 —
  `openbuilt-export-to-real-app`). The Phase-2 export reads the same
  declarative JSON the designer writes; no extra surface is required
  here.
- Undo / redo. See Decision 4.
- Live preview alongside the designer ("see your schema render
  immediately"). The user navigates back to
  `/builder/:slug/...` to preview; an in-designer split-pane preview
  is a follow-up.

## Decisions

### Decision 1 — Component composition: one host + N sub-editors, not one mega-SFC

The designer is composed of one top-level view (`SchemaDesigner.vue`)
that owns the route, the staged-state lifecycle, and the Save action,
plus a family of leaf sub-editors:

```
src/views/SchemaDesigner.vue
src/components/schema-editor/
  SchemaListPanel.vue
  SchemaHeaderForm.vue
  FieldRow.vue
  FieldTypePicker.vue
  LifecycleEditor.vue
  AggregationEditor.vue
  CalculationEditor.vue
  NotificationEditor.vue
  RelationEditor.vue
  WidgetEditor.vue
```

The top view orchestrates; each sub-editor owns its declarative
sub-block of the schema and emits `update:value` events upward.
State flows down via props; changes flow up via events; the staged
store is the single source of truth (see Decision 2).

**Alternatives considered**

- *One mega-SFC `SchemaDesigner.vue` holding every editor inline.*
  Rejected. Hard to review per sub-block, impossible to test in
  isolation, and would couple unrelated editors (lifecycle vs
  aggregation) into one render pass. Violates the spirit of ADR-017
  (component composition).
- *One sub-editor per declarative block, each owning its own store
  slice.* Rejected for v1 — the cross-cutting concerns (a relation
  added in the field editor affects what the relation editor sees,
  a transition added in the lifecycle editor unlocks a
  `notification.event` option) are easier to coordinate through one
  staged store than through 7 store slices. Revisit if the staged
  store grows past ~500 lines.

### Decision 2 — State management: `createObjectStore` (memory rule), not a bespoke Pinia store

Per the workspace memory rule "Store pattern guidance" the designer
SHALL use `createObjectStore` from `@conduction/nextcloud-vue` to
materialise its store layer over OR's REST surface. Concretely:

- A `useSchemasStore = createObjectStore({ register, schema:
  'schema' })` wires up `list / get / create / update / delete` over
  OR's runtime schema CRUD endpoints (chain #3) with the standard
  loading / error / pagination machinery.
- The schema being edited is mirrored into a **staged copy** in the
  component-local `data()` of `SchemaDesigner.vue` so unsaved edits
  do not leak into the shared store cache. Save copies the staged
  state into the store via `store.update(staged)`.

**Alternatives considered**

- *Bespoke `src/store/schemas.js` Pinia module.* Rejected per the
  memory rule and because every other Conduction app has migrated
  off bespoke stores. Repeating the pattern here would be a future
  cleanup task we'd have to file against ourselves.
- *No store, direct fetch from each sub-editor.* Rejected. Sub-editors
  would race each other, and the field editor needs to know about
  related schemas (for `RelationEditor.vue`) which only the list
  endpoint provides.

### Decision 3 — Save semantics: explicit Save, no auto-save

Edits stage in the local copy; the user clicks **Save** to persist.
This is deliberate even though "auto-save" feels modern:

- Authoring schemas is a multi-step operation (add a property, declare
  its validation, mark it required, add a related calculation). An
  intermediate state is often invalid — auto-save would either
  refuse-and-toast on every keystroke (terrible UX) or persist
  invalid blobs (which OR's runtime endpoint will reject anyway,
  producing the same toast storm).
- OR's runtime schema PUT triggers a declarative-engine reload
  (chain #3) which is not free. Auto-saving every keystroke would
  amplify that cost by 10-100x.
- Versioning (chain #6) will turn each Save into a snapshot. Explicit
  Save aligns with "commit-like" mental model the versioning spec
  will lean on.

Live **validation** runs continuously (REQ-OBSD-006). Live
**persistence** does not.

**Alternatives considered**

- *Debounced auto-save.* Rejected on the engine-reload-cost argument
  above.
- *Auto-save with a draft-vs-published distinction baked in here.*
  Rejected — that's the work of chain #6 (versioning); doing a
  half-version-here would force a rewrite when #6 lands.

### Decision 4 — Undo / redo deferred to chain #6 (versioning)

The first version of the designer ships with explicit Save and a
"Discard staged edits" button on the SchemaDesigner view, but no
in-memory undo / redo stack. Chain spec #6 (`openbuilt-versioning`)
will ship the snapshot-based version history that subsumes the undo
problem: every Save is a snapshot; "undo" becomes "revert to
snapshot N-1". Shipping an in-memory undo stack now would create a
parallel concept the versioning spec then has to deprecate.

**Alternatives considered**

- *Local in-memory undo / redo stack on the staged state.* Rejected.
  See above; throws away work in chain #6.
- *Local IndexedDB-backed undo across page reloads.* Rejected for the
  same reason; chain #6's snapshots are persistent and per-server,
  which is the right semantic.

### Decision 5 — Declarative-vs-imperative (ADR-031)

This spec is `kind: code`, but its **output** is declarative. The
designer SHALL produce JSON Schemas with `x-openregister-*` blocks
that validate against OR's declarative vocabulary, and SHALL NOT
provide any UI affordance that emits an imperative artefact (no
PHP service class names, no JavaScript callbacks, no file paths).

This is the canonical example of ADR-031 applied to a code spec: the
editor itself is code (Vue components), but every behaviour-shaping
field the user sees is a typed declarative record. Concretely:

| Sub-editor | Declarative output | Imperative escape hatch |
|---|---|---|
| `FieldRow.vue` | JSON Schema `properties.{name}` | None — `type` selector is a fixed enum |
| `LifecycleEditor.vue` | `x-openregister-lifecycle` | None — `on_transition.action.type` is a typed enum |
| `AggregationEditor.vue` | `x-openregister-aggregations` | None — `operation` is a fixed enum (`count / sum / avg / min / max`) |
| `CalculationEditor.vue` | `x-openregister-calculations.expression` (DSL) | None — DSL parser rejects free-text PHP / JS |
| `NotificationEditor.vue` | `x-openregister-notifications` | None — `channel` + `template` are pickers over registered options |
| `RelationEditor.vue` | `x-openregister-relations` | None — target is a picker over namespace schemas |
| `WidgetEditor.vue` | `x-openregister-widgets` | None — widget id is a picker over registered widgets (chain #5) |

An **ADR-031 review gate** SHALL apply to the apply PR for this spec:
the reviewer SHALL grep the diff for `eval`, `<?php`, `Function(`,
`script:`, `cb:`, `handler:`, and any free-text field that maps to
runtime behaviour. Any match is a review-block.

**Alternatives considered**

- *Ship an "Advanced (free-text)" tab on each sub-editor.* Rejected.
  Once the escape hatch exists, citizen developers will paste OR's
  internal class names into it, and we lose the declarative
  guarantee chain #9 (export) depends on.
- *Defer the lifecycle / aggregation / calculation editors and ship
  only field editing in v1.* See Decision 7 — that is exactly what
  the phased delivery does.

### Decision 6 — Designer routes live on the outer OpenBuilt router

The schema designer's routes register under the OpenBuilt **outer**
router, not the nested CnAppRoot's inner router. The Schemas surface
authors the data model OF a virtual app, not content WITHIN it; it's
a meta-tool. Mounting it inside the inner router would force every
virtual app's manifest to declare a `schemas` page type, which:

- Couples the manifest schema to a build-time concept, and
- Surfaces "edit your own schemas" as a normal page to end users
  of the published virtual app, which is wrong by RBAC default.

`BuilderHost.vue` gains a Schemas menu entry that links to the outer
route; the nested CnAppRoot stays focused on rendering the virtual
app's user-facing pages per ADR-024.

**Alternatives considered**

- *Mount schema designer inside the nested CnAppRoot via a new
  `schemas` page type added to `@conduction/nextcloud-vue`.*
  Rejected. Pollutes the manifest schema. The "edit your own
  schemas" surface is not a runtime page of a built app.

### Decision 7 — Phased delivery: field editor + lifecycle in v1; calculations / aggregations / notifications can ship in v1.1

The UX surface in this spec is large. To bound v1 we declare phases:

- **v1 (this spec, this apply PR)** — schema list + add / rename /
  delete, field editor for `string / number / integer / boolean /
  array / object / relation`, full lifecycle editor, relation
  editor, widget editor, confirm-before-destructive, live
  validation, explicit Save against chain #3's endpoints.
- **v1.1 (follow-up PR or chain #4.1)** — full aggregation editor +
  calculation editor + notification editor with their typed DSLs.
  v1 ships *stub* panels for these three that surface a "coming in
  v1.1" message and let the user view (read-only) the JSON of an
  already-declared block, but not author one. This avoids
  half-implementing the DSL parsers.

The REQs for v1.1 (REQ-OBSD-005) ship in this spec's `spec.md` so
they are visible during review, but their **tasks** are split into a
clearly-labelled `## 8. Phase v1.1` section in `tasks.md` so the
apply phase can land v1 cleanly first.

**Alternatives considered**

- *Ship everything in one PR.* Rejected; risk of half-implemented DSL
  in the apply window.
- *Defer v1.1 to a separate spec (`openbuilt-schema-editor-advanced`).*
  Could work, but the REQs are conceptually one capability; splitting
  the spec doubles the chain size for no review benefit. Phased
  tasks within one spec is the cheaper organisation.

## Risks / Trade-offs

- **Risk — Chain #3's endpoint shape drifts during apply.** The
  designer consumes endpoints that don't exist yet (chain
  `openregister-runtime-schema-api` is parallel-in-flight). If chain
  #3's POST / PUT request body shape lands different from the JSON
  Schema body this spec assumes, the designer's Save path breaks. →
  *Mitigation*: declare the endpoint contract in chain #3's spec
  (the designer follows whatever chain #3 publishes); add a
  contract integration test in v1 that mocks the chain #3 endpoint
  off its OpenAPI spec.
- **Risk — Half-implemented DSL parsers in v1.1 sub-editors.** The
  calculation / aggregation / notification editors each need a small
  parser to surface live validation feedback. If the parser is
  lenient where OR is strict (or vice versa), the designer accepts
  blobs OR rejects on save (terrible UX). → *Mitigation*: the v1.1
  apply task SHALL re-use the same DSL parser source as chain #3
  (publishable as `@openregister/declarative-dsl` if not already);
  the designer imports it rather than re-implementing it.
- **Risk — UX surface is large; reviewers will struggle to spot
  ADR-031 escape hatches.** → *Mitigation*: the explicit grep gate
  in Decision 5 plus the existing `hydra-gate-forbidden-patterns`
  skill catch `eval` / `<?php` / `Function(` in the diff
  automatically; reviewers cover the typed-record / picker-over-enum
  invariants on the Vue side.
- **Trade-off — Explicit Save vs auto-save.** See Decision 3. Loses
  "feels alive" UX, gains alignment with chain #6 (versioning) and
  saves engine-reload churn.
- **Trade-off — Component fan-out (10 SFCs vs 1 mega-SFC).** Chosen
  for review and testability per Decision 1; pays a small file-count
  cost.
- **Trade-off — Phased delivery splits an apply window.** Decision 7
  bounds the risk; the REQ stays single-spec so versioning /
  marketplace specs downstream don't have to discover a v1.1 split.

## Migration Plan

This spec is additive on top of `bootstrap-openbuilt`. No production
data exists in the OpenBuilt namespace yet at the time of apply
(chain spec #1 is on a PR; OpenBuilt is unreleased), so there is no
migration work.

Deployment steps:

1. Land chain #3 (`openregister-runtime-schema-api`) first — its
   endpoints are a hard `depends_on` of this spec's apply phase.
2. Land this spec's apply PR on a feature branch off `development`,
   targeting `development`.
3. CI runs PHPUnit (irrelevant — no PHP shipped), ESLint, Playwright
   (designer happy-path + ADR-031 grep gate).
4. Merge into `development`. The designer becomes reachable at
   `/index.php/apps/openbuilt/builder/hello-world/schemas` on the
   next deploy.
5. **Rollback** — revert the merge commit. The designer disappears;
   schemas already authored via the designer stay in OR (they are
   plain OR objects per ADR-022). Re-applying the merge later is
   safe.

## Open Questions

- **OQ-1 — Declarative DSL package source.** Is the
  calculation / aggregation / notification DSL parser published as an
  npm package that both OR and OpenBuilt consume, or does each side
  re-implement? *Provisional decision*: chain #3 to publish
  `@openregister/declarative-dsl`; OpenBuilt consumes. If chain #3
  hasn't extracted it yet, the v1.1 task on this spec covers the
  extraction and v1 ships only field + lifecycle + relation +
  widget editors as documented in Decision 7.
- **OQ-2 — Default register namespace per virtual app.** Does each
  virtual app get its own OR register (one register per slug), or do
  all virtual apps share the `openbuilt` register with a
  `applicationUuid` field on every object? *Provisional decision*:
  one register per slug, named `openbuilt-{slug}`. Cleaner deletion
  semantics on archive, simpler RBAC story for chain #7. Verify with
  OR before apply.
- **OQ-3 — Widget id catalogue source.** `WidgetEditor.vue` picks
  from a registered widget catalogue (chain #5). Until chain #5
  publishes the catalogue, v1 of the widget editor accepts a
  free-text widget id (with a warning). *Provisional decision*: ship
  free-text-with-warning in v1; chain #5 narrows to a picker once
  the catalogue exists.
- **OQ-4 — Cross-schema validation on Save.** If the user removes a
  `relation` from schema A that schema B's `RelationEditor` declares
  as `inverse_of`, do we block the Save, or accept the drift and let
  OR's runtime endpoint surface the inconsistency? *Provisional
  decision*: block the Save with a clear cross-reference error.
  This is a v1.1 task because it requires the designer to load
  related schemas at Save time.
- **OQ-5 — Permission model for accessing the designer.** Should the
  Schemas menu entry require an `openbuilt.schema.edit` permission
  key now, or wait for chain #7's per-built-app RBAC? *Provisional
  decision*: wait for chain #7; in v1 anyone with read access to the
  Application object can edit its schemas. Document this clearly in
  the in-app help string so admins understand the v1 default.
