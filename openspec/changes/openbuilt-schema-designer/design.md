## Context

`openbuilt-versioning-model` (ADR-002) restructures the virtual-app runtime
into a logical `Application` plus N `ApplicationVersion` rows, each with its
own OR register `openbuilt-{appSlug}-{versionSlug}`. The archived
`openbuilt-schema-editor` spec assumed the single-register model
(`openbuilt-{slug}`); its deferred tasks 8.1–8.5 (AggregationEditor,
CalculationEditor, NotificationEditor, cross-schema validation, and widget
catalogue picker) were blocked on `@openregister/declarative-dsl` being
published.

Both blockers are resolved. This design re-issues the schema designer as a
version-aware, fully-specified surface aligned with ADR-002 and ADR-031, with
no deferred sub-editors.

## Goals / Non-Goals

**Goals:**

- Provide a visual schema authoring surface at
  `/builder/:slug/schemas[/:schemaId]` that citizen developers can use without
  writing JSON or deploying PHP.
- Support the complete field-editor vocabulary for the seven JSON Schema types
  (`string / number / integer / boolean / array / object / relation`) with
  type-specific validation inputs.
- Support the full declarative `x-openregister-*` sub-editor set: lifecycle,
  aggregations, calculations, notifications, relations, widgets.
- Scope every schema list and store operation to the active virtual app's
  current-version OR register (`openbuilt-{appSlug}-{versionSlug}`) per ADR-002.
- Enforce declarative-output-only: no UI affordance may emit a PHP class name,
  JavaScript callback, or file path (ADR-031).
- Live client-side validation on every edit; explicit Save via OR's runtime
  schema CRUD endpoint; confirm-before-destructive for field and schema deletion.

**Non-Goals:**

- Schema versioning, snapshot, or rollback — `openbuilt-versioning` (chain #6).
- Per-built-app RBAC on schema authoring — `openbuilt-rbac` (chain #7). Any
  authenticated user with read access to the Application object can edit its
  schemas in v1.
- Marketplace import of canned schemas — `openbuilt-templates-marketplace`.
- Code / app generation from a designed schema — `openbuilt-exporter`.
- Visual page / manifest designer — `openbuilt-page-designer` (chain #5).
- Undo / redo stack — deferred to `openbuilt-versioning`.
- Live split-pane preview of the designed schema rendering in the virtual app.

## Decisions

### Decision 1 — Component composition: one host + N leaf sub-editors

The designer is a single top-level view (`SchemaDesigner.vue`) that owns the
route, staged-state lifecycle, and Save action. Nine leaf sub-editors live
under `src/components/schema-designer/` and communicate via props-down /
events-up:

```
src/views/SchemaDesigner.vue
  src/components/schema-designer/
    SchemaListPanel.vue
    SchemaHeaderForm.vue
    FieldRow.vue + FieldTypePicker.vue
    LifecycleEditor.vue
    AggregationEditor.vue
    CalculationEditor.vue
    NotificationEditor.vue
    RelationEditor.vue
    WidgetEditor.vue
  src/dialogs/
    DeletePropertyDialog.vue
    DeleteSchemaDialog.vue
```

The view orchestrates; each sub-editor emits `update:value` for its
declarative sub-block of the schema. Staged state in `SchemaDesigner.vue`'s
`data()` is the single source of truth between edits and Save.

Dialogs for destructive operations live in `src/dialogs/` per ADR-004 (modal
isolation — every `NcDialog`-based component in its own file).

### Decision 2 — Store: `createObjectStore` (ADR-001 store rule)

`useSchemasStore` is created via
`createObjectStore({ register: 'openbuilt-{appSlug}-{versionSlug}',
schema: 'schema' })`. The `register` name is computed at runtime from the
route's `slug` param and the active `versionSlug` (resolved from the
`ApplicationVersion` record loaded by `BuilderHost.vue`).

No bespoke `defineStore` — the ADR-001 store rule prohibits hand-rolled Pinia
stores for OR-backed CRUD. The standard `list / get / create / update / delete`
methods from the generated store cover all designer operations. The schema
under edit is mirrored into a staged copy in `SchemaDesigner.vue`'s `data()`
so unsaved edits do not leak into the shared store cache.

### Decision 3 — Register scoping: version-aware, not app-slug-only

Per ADR-002, `BuilderHost.vue` already resolves the active `ApplicationVersion`
and exposes its `versionSlug` to child views. `SchemaDesigner.vue` reads
`versionSlug` from the parent's provide/inject (or from a route meta prop) and
passes it to `useSchemasStore`. This means switching the version switcher in
`BuilderHost.vue` causes the store to re-scope to the new version's register —
no manual refresh required.

### Decision 4 — Explicit Save, no auto-save

Per the archived spec Decision 3, the designer does not auto-save. Reasons:

- Intermediate states during schema authoring are frequently invalid; auto-
  saving would either spam OR's runtime endpoint with rejected payloads or
  suppress live validation until the user explicitly asks for it.
- OR's runtime schema PUT triggers a declarative-engine reload (chain #3);
  auto-saving on every keystroke amplifies that cost.
- `openbuilt-versioning` (chain #6) will turn each Save into a named snapshot;
  explicit Save is the natural primitive that versioning can wrap.

Live **validation** runs continuously; live **persistence** does not.

### Decision 5 — Declarative-output-only (ADR-031)

The designer is code; its output is declarative. Every sub-editor's fields are
fixed enums, typed pickers, or DSL-validated expressions — never free-text
strings that route into runtime behaviour. The table of sub-editors and their
declarative outputs is in the Declarative-vs-imperative section below.

An ADR-031 review gate is enforced as a CI grep on the diff of every apply PR
for this spec: any occurrence of `eval(`, `<?php`, `Function(`, `script:`,
`cb:`, `handler:`, or `phpClass:` in `src/components/schema-designer/**` or
`src/views/SchemaDesigner.vue` is a review-block.

### Decision 6 — Designer routes on the outer OpenBuilt router

The schema designer's routes register under the OpenBuilt outer router, not
inside the nested `CnAppRoot` inner router. The designer is a meta-tool — it
authors the data model *of* a virtual app, not content *within* it. Mounting
it inside the inner router would force every virtual app's manifest to declare
a `schemas` page type (polluting the manifest schema) and surface "edit your
own schemas" to end users of the published virtual app (wrong by default RBAC).

`BuilderHost.vue` exposes the Schemas menu entry in the outer builder shell;
the nested `CnAppRoot` is unaffected.

### Decision 7 — `@openregister/declarative-dsl` for DSL field validation

`CalculationEditor.vue` and `AggregationEditor.vue` accept expression strings
validated by `@openregister/declarative-dsl` (the same parser OR's engine uses
at schema-reload time). This guarantees the client-side rejection of free-text
PHP / JS is identical to the server-side rejection — no client/server
validation drift. Version of the package is aligned with whatever
`openregister-runtime-schema-api` exports.

### Decision 8 — Confirm-before-destructive via dedicated dialog components

Delete-field and delete-schema operations surface dedicated `NcDialog`-based
components (`DeletePropertyDialog.vue`, `DeleteSchemaDialog.vue`) in
`src/dialogs/` per ADR-004. They are never inlined into the parent component.

`DeletePropertyDialog.vue` requires a single explicit confirm click.
`DeleteSchemaDialog.vue` requires the user to type the schema slug exactly into
a text input before the Delete button activates (typed-slug confirmation
pattern; higher stakes operation).

### Decision 9 — WidgetEditor with catalogue picker (not free-text-with-warning)

The archived spec shipped `WidgetEditor.vue` as free-text-with-warning because
chain #5 (`openbuilt-page-designer`) had not yet published its widget catalogue.
At the time of this re-issue, chain #5's widget catalogue API is still pending.
The widget `id` field therefore renders as a free-text input with a visible
warning banner "no widget catalogue registered — enter the canonical widget ID
manually". Once chain #5 publishes the catalogue, a follow-up micro-spec wires
the picker. This is documented so the reviewer knows the free-text input is
intentional, not a gap.

## Seed Data

This spec introduces no new schemas to `openbuilt_register.json` — the
designer is a frontend-only capability that manages schemas within the user's
virtual app registers. It is therefore **exempt** from the mandatory seed-data
rule in ADR-001 (which applies to changes that introduce or modify schemas in
the app's own register template).

However, the Playwright test suite for this spec requires a pre-seeded virtual
app with known schemas. The following fixture schemas are installed into the
test virtual app's development-version register
(`openbuilt-demo-webshop-development`) as part of the `tests/fixtures/`
setup script, **not** via `openbuilt_register.json`:

### Fixture: `klant` (customer schema)

```json
{
  "title": "Klant",
  "slug": "klant",
  "version": "0.1.0",
  "description": "Klantgegevens voor de webshop",
  "properties": {
    "naam": { "type": "string", "description": "Volledige naam", "minLength": 2, "maxLength": 100 },
    "email": { "type": "string", "format": "email", "description": "E-mailadres" },
    "telefoon": { "type": "string", "pattern": "^(\\+31|0)[0-9]{9}$", "description": "Telefoonnummer" },
    "straat": { "type": "string", "description": "Straatnaam en huisnummer" },
    "postcode": { "type": "string", "pattern": "^[1-9][0-9]{3}[A-Z]{2}$", "description": "Postcode" },
    "gemeente": { "type": "string", "description": "Gemeentenaam" }
  },
  "required": ["naam", "email"]
}
```

### Fixture: `bestelling` (order schema with lifecycle)

```json
{
  "title": "Bestelling",
  "slug": "bestelling",
  "version": "0.1.0",
  "description": "Bestellingen in de webshop",
  "properties": {
    "ordernummer": { "type": "string", "description": "Uniek ordernummer" },
    "klant": { "type": "string", "description": "Relatie naar klant" },
    "totaalbedrag": { "type": "number", "minimum": 0, "description": "Totaalbedrag in euro" },
    "opmerkingen": { "type": "string", "description": "Aanvullende opmerkingen" }
  },
  "required": ["ordernummer", "klant", "totaalbedrag"],
  "x-openregister-lifecycle": {
    "states": [
      { "name": "ontvangen", "label": "Ontvangen", "initial": true },
      { "name": "in-behandeling", "label": "In behandeling" },
      { "name": "verzonden", "label": "Verzonden" },
      { "name": "afgehandeld", "label": "Afgehandeld" }
    ],
    "transitions": [
      { "from": "ontvangen", "to": "in-behandeling", "label": "Start verwerking",
        "on_transition": [{ "type": "audit-event-emit" }] },
      { "from": "in-behandeling", "to": "verzonden", "label": "Markeer als verzonden",
        "on_transition": [{ "type": "notification-send", "template": "bestelling-verzonden" }] },
      { "from": "verzonden", "to": "afgehandeld", "label": "Bevestig ontvangst" }
    ]
  },
  "x-openregister-aggregations": {
    "open_bestellingen": { "operation": "count", "source": "bestelling", "filter": "status != 'afgehandeld'" }
  }
}
```

### Fixture: `product` (product schema with calculation)

```json
{
  "title": "Product",
  "slug": "product",
  "version": "0.1.0",
  "description": "Productcatalogus",
  "properties": {
    "naam": { "type": "string", "description": "Productnaam" },
    "sku": { "type": "string", "description": "Artikelcode", "pattern": "^[A-Z]{3}-[0-9]{4}$" },
    "prijs": { "type": "number", "minimum": 0, "description": "Verkoopprijs excl. BTW" },
    "voorraad": { "type": "integer", "minimum": 0, "description": "Beschikbare voorraad" },
    "categorie": { "type": "string", "description": "Relatie naar categorie" }
  },
  "required": ["naam", "sku", "prijs"],
  "x-openregister-calculations": {
    "prijs_incl_btw": {
      "expression": "@self.prijs * 1.21",
      "depends_on": ["prijs"]
    },
    "is_uitverkocht": {
      "expression": "@self.voorraad <= 0",
      "depends_on": ["voorraad"]
    }
  }
}
```

### Fixture: `categorie` (category schema with relation)

```json
{
  "title": "Categorie",
  "slug": "categorie",
  "version": "0.1.0",
  "description": "Productcategorieën",
  "properties": {
    "naam": { "type": "string", "description": "Categorienaam" },
    "omschrijving": { "type": "string", "description": "Korte omschrijving" },
    "actief": { "type": "boolean", "default": true, "description": "Is de categorie zichtbaar" }
  },
  "required": ["naam"],
  "x-openregister-relations": {
    "producten": { "target": "product", "cardinality": "many", "inverse_of": "categorie" }
  }
}
```

These fixtures are loaded in `tests/fixtures/setup-demo-webshop.ts` before the
Playwright suite runs, and cleaned up in `teardown-demo-webshop.ts`.

## Declarative-vs-imperative decisions (ADR-031)

| Concern | Declarative? | Imperative? | Decision |
|---|---|---|---|
| Schema CRUD (create, list, update, delete) | `createObjectStore` wraps OR runtime schema API | — | **Declarative** via `createObjectStore`; no hand-rolled fetch |
| Field type + validation shape | `FieldTypePicker.vue` fixed enum → `properties.{name}` JSON Schema output | — | **Declarative** — type selector is a closed enum, no free-text type entry |
| Lifecycle states + transitions | `LifecycleEditor.vue` → `x-openregister-lifecycle` | — | **Declarative** — states/transitions/actions are typed records per ADR-031 |
| Lifecycle `on_transition.action.type` | Fixed enum (`audit-event-emit`, `notification-send`, `related-object-upsert`, `related-object-archive`, `webhook-dispatch`) | — | **Declarative** — closed vocabulary from OR's declarative engine |
| Aggregations | `AggregationEditor.vue` → `x-openregister-aggregations`; operation is a fixed enum | — | **Declarative** |
| Aggregation filter expression | DSL validated by `@openregister/declarative-dsl`; rejects free-text code | — | **Declarative** — DSL parser is the gate |
| Calculations | `CalculationEditor.vue` → `x-openregister-calculations`; formula validated by `@openregister/declarative-dsl` | — | **Declarative** — same DSL parser as aggregations |
| Calculation `depends_on` | Picker over the schema's existing property names | — | **Declarative** — no free-text property references |
| Notifications | `NotificationEditor.vue` → `x-openregister-notifications`; channel is a fixed enum; template is a picker | — | **Declarative** |
| Relations | `RelationEditor.vue` → `x-openregister-relations`; target is a picker over namespace schemas; cardinality is a fixed enum | — | **Declarative** |
| Widgets | `WidgetEditor.vue` → `x-openregister-widgets`; widget id is free-text-with-warning (chain #5 catalogue pending — Decision 9) | — | **Declarative** structure; widget id is provisional free-text pending chain #5 |
| Live client-side validation | Computed watchers in `SchemaDesigner.vue` + sub-editors; `@openregister/declarative-dsl` for DSL fields | — | **Imperative** Vue computed logic — no schema-extension fit; thin, focused, no business logic |
| Confirm-before-destructive | `DeletePropertyDialog.vue`, `DeleteSchemaDialog.vue` — typed-slug confirmation for schema deletion | — | **Imperative** pure UI guard — no schema-extension equivalent |
| Version-aware register scoping | `createObjectStore` re-created with updated register name when `versionSlug` changes | — | **Declarative** (store creation is configuration, not business logic) |

No declarative-to-imperative exceptions require justification under ADR-031
§Exceptions. The two imperative items (live validation logic; confirmation
dialogs) are UI-layer code with no schema-engine analogue.

## Reuse Analysis (ADR-001 deduplication check)

| Capability reused | Source | How it is used in this spec |
|---|---|---|
| Schema CRUD over OR's runtime API | `createObjectStore` from `@conduction/nextcloud-vue` | `useSchemasStore` wraps all schema list / get / create / update / delete; no hand-rolled fetch |
| OR runtime schema CRUD endpoints | `openregister-runtime-schema-api` (chain #3) | POST (create), PUT (update), DELETE (delete), GET list — all consumed through the store |
| Declarative DSL parsing + validation | `@openregister/declarative-dsl` npm package | Used client-side in `CalculationEditor.vue` + `AggregationEditor.vue` filter fields |
| Drag-to-reorder UX | Sortable.js (already in `@nextcloud/vue` / `@conduction/nextcloud-vue` deps) | `FieldRow.vue` reorder, `LifecycleEditor.vue` state list reorder |
| Confirmation dialogs | `NcDialog` from `@conduction/nextcloud-vue` | `DeletePropertyDialog.vue` + `DeleteSchemaDialog.vue` |
| Toast notifications | `NcNotificationToast` or equivalent from `@conduction/nextcloud-vue` | Save success + error toast in `SchemaDesigner.vue` |
| Version-switcher state | `ApplicationVersion` resolved by `BuilderHost.vue` and injected into route | Register scoping for `useSchemasStore`; no duplicate fetch of version state |

No new OR SchemaService, ObjectService subclass, or custom backend CRUD was
introduced. `SchemaService` and `ObjectService` are used only through the
standard `createObjectStore` abstraction (ADR-022).

## Risks / Trade-offs

- **Chain #3 endpoint shape drift.** The designer consumes endpoints from
  `openregister-runtime-schema-api` which must be merged before apply. If the
  request / response shapes differ from what REQ-OBSD-002 and REQ-OBSD-006
  assume, the Save path breaks. *Mitigation*: declare the OR runtime schema
  API contract in chain #3's OpenAPI document; add a contract integration test
  in this spec's test suite (task 5.5) that pins the diff against the published
  OpenAPI spec. Drift surfaces at CI, not at runtime.
- **`@openregister/declarative-dsl` version pinning.** If OR's engine accepts
  a DSL expression that an older version of the npm package rejects (or vice
  versa), the client-side validator gives a false positive or false negative.
  *Mitigation*: pin the package version to the same range exported by
  `openregister-runtime-schema-api` in its `package.json`; enforce the range in
  `openbuilt`'s `package.json` with a peer-dependency declaration.
- **Vue 2 reactivity on property-order tracking.** JSON Schema `properties` is
  an object map; Vue 2 does not track property insertion order. The field editor
  maintains a parallel ordered-array alongside the map to preserve reorder
  operations. If the map and the array diverge (e.g. due to a partial save
  failure), the next reload from the store will reset the array from the
  persisted map order. *Mitigation*: the divergence is self-healing on reload;
  a warning is logged when the array and map disagree at load time.
- **WidgetEditor free-text widget ID.** Until chain #5 publishes the widget
  catalogue, a user can enter any string as a widget ID. An invalid ID will only
  surface as an error when the page designer (chain #5) tries to render the
  widget. *Mitigation*: the warning banner in `WidgetEditor.vue` states "enter
  the canonical widget ID; unknown IDs will be ignored by the page renderer".
  This is documented in Decision 9 and the reviewer is aware.
- **Cross-schema relation validation deferred.** Removing a field from schema A
  that schema B's `RelationEditor` declares as `inverse_of` creates a dangling
  reference. In v1 the designer accepts the drift and OR's runtime endpoint
  surfaces the inconsistency on the next schema-reload. *Mitigation*: a
  follow-up micro-spec ("cross-schema validation on Save") adds a check that
  loads sibling schemas at Save time and blocks dangling-inverse-of removals.

## Migration Plan

This spec lands after both hard dependencies (`openbuilt-versioning-model` and
`openregister-runtime-schema-api`) are merged. No data migration is required:
the designer is a purely additive surface that manages schemas already
reachable through OR.

If the archived `openbuilt-schema-editor` apply PR was partially landed (i.e.
`src/components/schema-editor/` exists with the v1 sub-editors), the apply
agent MUST remove the old `schema-editor/` directory and replace it with the
new `schema-designer/` family. Tasks 7.1–7.2 in `tasks.md` cover this
explicitly.

## Open Questions

- **OQ-1 — Widget catalogue API shape (chain #5).** Once `openbuilt-page-
  designer` publishes the widget catalogue endpoint, `WidgetEditor.vue`'s
  free-text `widget` field should become a picker. The catalogue endpoint
  contract is unknown at spec-write time. *Resolution path*: chain #5 to
  publish a catalogue endpoint; a follow-up micro-spec wires the picker.
- **OQ-2 — Cross-schema `inverse_of` validation.** Blocked on the designer
  being able to load sibling schemas at Save time. *Resolution path*: deferred
  to the "cross-schema validation" follow-up described in the Risks section.
- **OQ-3 — Permission model for the Schemas menu entry.** Currently visible to
  any authenticated user with read access to the Application. Chain #7
  (`openbuilt-rbac`) will narrow this with a `openbuilt.schema.edit` permission
  key. Until then, the broad v1 default is documented in the in-app help string.
