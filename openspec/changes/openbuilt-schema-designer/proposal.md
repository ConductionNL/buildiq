---
kind: code
depends_on: ["openbuilt-versioning-model", "openregister-runtime-schema-api"]
chain:
  - openbuilt-versioning-model           # per-version register naming; openbuilt-{appSlug}-{versionSlug}
  - openregister-runtime-schema-api      # runtime schema CRUD, declarative-engine reload, cache invalidation
  - openbuilt-schema-designer            # THIS spec — full visual schema designer with all sub-editors
---

## Why

The archived `openbuilt-schema-editor` spec (chain #4 of the 9-spec OpenBuilt
chain) shipped the structural skeleton: `SchemaListPanel`, `SchemaHeaderForm`,
`FieldRow` / `FieldTypePicker`, `LifecycleEditor`, `RelationEditor`, and
`WidgetEditor`. It explicitly deferred the `AggregationEditor`,
`CalculationEditor`, and `NotificationEditor` to a phase v1.1 window (tasks
8.1–8.5 in that spec), pending the `@openregister/declarative-dsl` npm package
from chain `openregister-runtime-schema-api`.

Two blockers have since resolved:

1. **ADR-002 (versioned app deployment model) has landed.** The per-virtual-app
   register is no longer `openbuilt-{slug}` but
   `openbuilt-{appSlug}-{versionSlug}`. The schema designer's store wiring,
   route parameters, and list-scoping logic must be version-aware.
2. **`@openregister/declarative-dsl` is now shipping alongside OR's runtime
   schema API.** Open question OQ-1 from the old spec (whether the DSL parser
   would be an npm package or each consumer re-implements it) is answered: the
   package is real and the three deferred sub-editors can be fully specified.

This spec is the re-issue of the schema designer with all eight sub-editors
fully specified, version-aware store wiring aligned with ADR-002, and no
deferred v1.1 tasks. It supersedes the archived `openbuilt-schema-editor` and
its unimplemented tasks 8.1–8.5.

The designer is code (`kind: code`, frontend Vue only). Its **output** is
exclusively declarative `x-openregister-*` JSON per ADR-031 — no free-text
PHP, no JavaScript callbacks, no service-class references. Every behaviour-
shaping field in every sub-editor is a typed declarative record from OR's
declarative vocabulary. This is the canonical example of ADR-031 applied to a
code spec: the editor itself is Vue code, but what the user authors through it
is consumed directly by OR's declarative engine.

## What Changes

- **BREAKING** `useSchemasStore` register scoping changes from
  `openbuilt-{slug}` to `openbuilt-{appSlug}-{versionSlug}` per ADR-002.
  The active version slug is passed from the builder route params; the store
  is re-registered on version change.
- **NEW** `src/views/SchemaDesigner.vue` — top-level schema list + designer
  surface, mounted at `/builder/:slug/schemas` (list mode) and
  `/builder/:slug/schemas/:schemaId` (detail mode) on the OpenBuilt outer
  router (not inside the nested `CnAppRoot` per Decision 6 of the archived
  design).
- **NEW** sub-component family under `src/components/schema-designer/`:
  - `SchemaListPanel.vue` — schema list scoped to the current virtual app's
    per-version OR register; columns: slug, title, version, property count,
    lifecycle-state count. **Add Schema**, per-row **Open / Rename / Delete**
    actions.
  - `SchemaHeaderForm.vue` — guided form capturing `slug` (kebab-case,
    namespace-unique), `title` (required), `description` (optional), `version`
    (semver, default `0.1.0`). Inline validation from the runtime endpoint.
  - `FieldRow.vue` + `FieldTypePicker.vue` — per-property editor: name, type
    enum (`string / number / integer / boolean / array / object / relation`),
    required, default, description, type-specific validation set, drag-to-
    reorder (with `↑`/`↓` keyboard alternative).
  - `LifecycleEditor.vue` — visual editor for `x-openregister-lifecycle`:
    states (name + label, `initial` radio), transitions (from → to, label,
    condition), typed `on_transition` actions (fixed enum:
    `audit-event-emit / notification-send / related-object-upsert /
    related-object-archive / webhook-dispatch`). No free-text PHP / JS.
  - `AggregationEditor.vue` — editor for `x-openregister-aggregations`:
    typed records `{ name, operation (count|sum|avg|min|max), source, filter? }`.
    Uses `@openregister/declarative-dsl` for filter-expression validation.
  - `CalculationEditor.vue` — editor for `x-openregister-calculations`:
    typed records `{ name, expression (formula DSL), depends_on[] }`. DSL
    parser from `@openregister/declarative-dsl` rejects free-text PHP / JS.
  - `NotificationEditor.vue` — editor for `x-openregister-notifications`:
    typed records `{ event, channel (email|webhook|in-app), template, recipient }`.
  - `RelationEditor.vue` — editor for `x-openregister-relations`: typed
    records `{ name, target (schema slug picker), cardinality (one|many),
    inverse_of? }`. Target picker sourced from `useSchemasStore.list`.
  - `WidgetEditor.vue` — editor for `x-openregister-widgets`: typed records
    `{ slot, widget (canonical widget id), config (typed map per widget) }`.
- **NEW** `src/store/schemas.js` wrapping `createObjectStore` scoped to the
  active virtual app's current-version register namespace (ADR-001 store rule:
  no bespoke `defineStore`).
- **NEW** `src/dialogs/DeletePropertyDialog.vue` — confirm-before-destructive
  dialog for field removal (warns data may become unreachable; single confirm
  click required). Lives in `src/dialogs/` per ADR-004 modal isolation rule.
- **NEW** `src/dialogs/DeleteSchemaDialog.vue` — confirm-before-destructive
  dialog for schema deletion (requires the user to type the schema slug exactly
  before Delete activates). Lives in `src/dialogs/` per ADR-004.
- **MODIFIED** `src/router/index.js` — two schema-designer routes registered
  under the OpenBuilt outer router.
- **MODIFIED** `src/views/BuilderHost.vue` — **Schemas** menu entry pointing
  to the outer schema-designer route, version-aware.

No new PHP. No new entries in `lib/Settings/openbuilt_register.json`.

## Capabilities

### New Capabilities

- `openbuilt-schema-designer` — The full visual schema authoring surface for
  citizen developers. Covers schema list, create, rename, delete; field editor
  for all seven JSON Schema types with type-specific validation; visual
  lifecycle, aggregation, calculation, notification, relation, and widget
  sub-editors, all producing declarative `x-openregister-*` JSON per ADR-031;
  live client-side validation with Save disabled on invalid state; explicit
  Save via OR's runtime schema CRUD endpoint; confirm-before-destructive for
  field and schema deletion.

### Modified Capabilities

- `openbuilt-runtime` — Gains the schema-designer routes on the outer router
  and a version-aware Schemas menu entry in `BuilderHost.vue`. No change to
  the nested `CnAppRoot` mount contract or the manifest endpoint.

## Impact

- **New Vue**:
  - `src/views/SchemaDesigner.vue`
  - `src/store/schemas.js`
  - `src/components/schema-designer/SchemaListPanel.vue`
  - `src/components/schema-designer/SchemaHeaderForm.vue`
  - `src/components/schema-designer/FieldRow.vue`
  - `src/components/schema-designer/FieldTypePicker.vue`
  - `src/components/schema-designer/LifecycleEditor.vue`
  - `src/components/schema-designer/AggregationEditor.vue`
  - `src/components/schema-designer/CalculationEditor.vue`
  - `src/components/schema-designer/NotificationEditor.vue`
  - `src/components/schema-designer/RelationEditor.vue`
  - `src/components/schema-designer/WidgetEditor.vue`
  - `src/dialogs/DeletePropertyDialog.vue`
  - `src/dialogs/DeleteSchemaDialog.vue`
- **Modified Vue**:
  - `src/router/index.js` — schema-designer route entries
  - `src/views/BuilderHost.vue` — Schemas menu entry (version-aware)
- **External dependency — `openregister-runtime-schema-api`** — provides
  `POST / PUT / DELETE` on
  `/index.php/apps/openregister/api/registers/{register}/schemas[/{slug}]`,
  declarative-engine reload on PUT, and cache invalidation. The designer
  MUST NOT bypass these endpoints per ADR-022.
- **External dependency — `@openregister/declarative-dsl`** npm package —
  used client-side by `CalculationEditor.vue` and `AggregationEditor.vue` for
  live DSL validation. Version aligned with `openregister-runtime-schema-api`.
- **No new PHP** — frontend-only spec.
- **Depends on** `openbuilt-versioning-model` (ADR-002 register naming) and
  `openregister-runtime-schema-api` (runtime schema CRUD). Both MUST be merged
  to `development` before this spec's apply phase opens.
- **Out of scope** (covered by sibling specs or follow-ons):
  - Schema versioning, snapshot, rollback — `openbuilt-versioning`
  - Per-built-app RBAC on schema authoring — `openbuilt-rbac`
  - Marketplace import of canned schemas — `openbuilt-templates-marketplace`
  - Code generation from a designed schema — `openbuilt-exporter`
  - Visual page / manifest designer — `openbuilt-page-designer`
  - Undo / redo stack — deferred to `openbuilt-versioning` (every explicit
    Save becomes a snapshot; rollback subsumes undo)
