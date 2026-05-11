---
kind: code
depends_on: [openregister-runtime-schema-api]
chain:
  - bootstrap-openbuilt              # foundation
  - openregister-runtime-schema-api  # blocker: provides runtime schema CRUD
  - openbuilt-schema-editor          # THIS spec
---

## Why

OpenBuilt's foundational spec (`bootstrap-openbuilt`) ships a single
JSON `<textarea>` for editing an Application's manifest blob. That is
deliberately "integrator-only" UX — citizen developers cannot hand-author
JSON, and the `lib/Settings/{app}_register.json` pattern locks the
schema model away in a deploy-time file that only a backend developer
can change. This spec ships the **visual schema designer**: a Vue
surface that lets a non-technical author add Schemas, edit field
shapes, declare validation, and author declarative `x-openregister-*`
behaviour (lifecycle, aggregations, calculations, notifications,
relations, widgets) through a guided UI — and persists every change
to OR's runtime schema CRUD endpoints (chain spec #3,
`openregister-runtime-schema-api`).

This spec is **spec #4 of the 9-spec OpenBuilt chain** (ADR-032). It is
the first spec in the chain that gives an end user direct authoring
power over the data model of their virtual app, replacing the
textarea-as-fallback editing pattern of spec #1 with a structured UI.
Without it, every chain spec downstream of #4 (page-editor #5,
versioning #6, RBAC #7, marketplace #8, export #9) either ships
half-blind (no way for the user to evolve the schemas they author
pages over) or duplicates this work inline. The editor itself is code,
but its **output** — the schemas it writes — is declarative
`x-openregister-*` JSON honouring ADR-031 in full.

## What Changes

- **NEW** Vue view `src/views/SchemaDesigner.vue` — the top-level
  schema list + designer surface, mounted at
  `/builder/:slug/schemas` and `/builder/:slug/schemas/:schemaId`
  inside a virtual app's OpenBuilt route tree.
- **NEW** sub-component family under `src/components/schema-editor/`:
  - `SchemaListPanel.vue` — lists schemas in the current virtual app's
    OR register namespace, with add / rename / delete actions.
  - `SchemaHeaderForm.vue` — slug, title, description, version
    (semver), summary editor.
  - `FieldRow.vue` + `FieldTypePicker.vue` — per-property editor for
    name, type (string / number / integer / boolean / array / object /
    relation), required, default, description, and the type-specific
    validation set (pattern / min / max / format / enum / items).
  - `LifecycleEditor.vue` — visual editor for
    `x-openregister-lifecycle` (states + transitions + on_transition
    actions) per ADR-031.
  - `AggregationEditor.vue` — editor for `x-openregister-aggregations`
    (count / sum / avg / min / max over related collections).
  - `CalculationEditor.vue` — editor for `x-openregister-calculations`
    (derived properties via formula or relation rollup).
  - `NotificationEditor.vue` — editor for `x-openregister-notifications`
    (event → channel + template).
  - `RelationEditor.vue` — editor for `x-openregister-relations`
    (one-to-one / one-to-many / many-to-many, inverse-of).
  - `WidgetEditor.vue` — editor for `x-openregister-widgets`
    (per-page widget bindings that surface in CnPageRenderer).
- **NEW** Pinia store layer via `createObjectStore` (memory rule —
  no custom store) at `src/store/schemas.js`, scoped to the virtual
  app's register namespace and consuming OR's runtime schema CRUD
  endpoints (chain spec #3).
- **MODIFIED** `src/router/index.js` — adds the two new schema-editor
  routes underneath the existing `/builder/:slug/*` host.
- **MODIFIED** `src/views/BuilderHost.vue` — gains a `Schemas` menu
  entry pointing into the designer for the current virtual app.
  Behaviour change is purely additive; the nested-CnAppRoot mount
  contract from `bootstrap-openbuilt` is unchanged.

The designer reads / writes via chain spec #3's runtime schema CRUD
endpoints. Until chain spec #3 lands, this spec's apply phase is
blocked; the artifacts (proposal / spec / design / tasks) ship now to
unblock parallel review.

## Capabilities

### New Capabilities

- `openbuilt-schema-designer`: The visual schema authoring surface.
  Owns the SchemaDesigner view, the field / lifecycle / aggregation /
  calculation / notification / relation / widget sub-editors, and the
  designer-scoped Pinia store layer. The designer is the canonical UI
  for authoring declarative `x-openregister-*` behaviour per ADR-031;
  it produces declarative JSON and never invents new imperative
  surfaces.

### Modified Capabilities

- `openbuilt-runtime`: Adds the schema-designer routes to the OpenBuilt
  router and a `Schemas` menu entry to `BuilderHost.vue`. No change to
  the nested-CnAppRoot mount contract or the manifest endpoint.

## Impact

- **New code** — `src/views/SchemaDesigner.vue`, the
  `src/components/schema-editor/*` family (~9 SFCs),
  `src/store/schemas.js`, two new router entries.
- **Modified code** — `src/router/index.js` (route registrations);
  `src/views/BuilderHost.vue` (Schemas menu entry).
- **External dependency on chain spec #3** —
  `openregister-runtime-schema-api` provides the POST / PUT / DELETE
  endpoints on `/index.php/apps/openregister/api/registers/{register}/schemas[/{slug}]`
  plus declarative-engine reload on save and cache invalidation. The
  designer SHALL NOT bypass these endpoints (no direct DB writes;
  ADR-022).
- **No new PHP** — this spec is `kind: code` and purely frontend. No
  controllers, no services, no repair steps.
- **No breaking changes** — this is additive on top of
  `bootstrap-openbuilt`.
- **Foundational ADRs honoured** — ADR-022 (consume OR abstractions),
  ADR-024 (manifest renderer), ADR-031 (declarative output;
  `x-openregister-*` JSON, never imperative state-machine services),
  ADR-032 (`kind: code`, single-surface, `depends_on`).
