## 0. Pre-apply housekeeping

- [ ] 0.1 **Verify hard dependencies are merged**
  - spec_ref: proposal.md §depends_on
  - Confirm `openbuilt-versioning-model` is merged to `development`.
  - Confirm `openregister-runtime-schema-api` is merged to `development` and its
    published OpenAPI document is reachable at the OR runtime schema CRUD endpoint.
  - acceptance_criteria: `curl -s /index.php/apps/openregister/api/registers` returns
    200 with a JSON body; OR runtime schema CRUD routes resolve.

- [ ] 0.2 **Remove archived schema-editor components if present**
  - spec_ref: design.md §Migration Plan
  - files: `src/components/schema-editor/` (DELETE if present),
    `src/store/schemas.js` (REPLACE — re-created in task 1.1)
  - If the archived `openbuilt-schema-editor` apply PR was partially landed,
    delete the old `src/components/schema-editor/` directory in its entirety
    before creating the new `src/components/schema-designer/` family.
  - acceptance_criteria: `git ls-files src/components/schema-editor/` returns empty;
    no `schema-editor` references remain in `src/router/index.js` or
    `src/views/BuilderHost.vue`.

## 1. Store wiring

- [ ] 1.1 **Create `src/store/schemas.js` via `createObjectStore`**
  - spec_ref: REQ-OBSD-001, REQ-OBSD-002, REQ-OBSD-006; design.md Decision 2
  - files: `src/store/schemas.js` (NEW)
  - Export a factory function `useSchemasStore(appSlug, versionSlug)` that returns
    `createObjectStore({ register: \`openbuilt-\${appSlug}-\${versionSlug}\`, schema: 'schema' })`.
  - The factory is called by `SchemaDesigner.vue` on mount and on `versionSlug` change;
    the returned store instance is local to the designer view, not a singleton.
  - No hand-rolled fetch calls; no bespoke `defineStore`. ONLY `createObjectStore`.
  - acceptance_criteria: `git grep "defineStore" src/store/schemas.js` returns empty;
    `git grep "createObjectStore" src/store/schemas.js` returns one match.
    Vitest: mounting `SchemaDesigner.vue` with `appSlug="demo"` and `versionSlug="development"`
    calls `createObjectStore` with register `openbuilt-demo-development`.

## 2. Schema list panel and list-mode route

- [ ] 2.1 **Create `src/components/schema-designer/SchemaListPanel.vue`**
  - spec_ref: REQ-OBSD-001, REQ-OBSD-008
  - files: `src/components/schema-designer/SchemaListPanel.vue` (NEW)
  - Renders one row per schema in `useSchemasStore.list` with columns:
    slug, title, version, property count (derived from `Object.keys(schema.properties || {}).length`),
    lifecycle-state count (derived from `schema['x-openregister-lifecycle']?.states?.length ?? 'none'`).
  - Per-row actions via `CnRowActions`: **Open** (route to detail), **Rename** (inline
    edit via `SchemaHeaderForm.vue`), **Delete** (opens `DeleteSchemaDialog.vue`).
  - **Add Schema** button at the top (opens `SchemaHeaderForm.vue` in create mode).
  - Empty state: `CnEmptyState` with "Nog geen schema's" and an "Add Schema" call-to-action.
  - ALL strings via `t()` with keys under `openbuilt.schema.list.*`.
  - acceptance_criteria: Playwright: navigates to `/builder/demo-webshop/schemas`,
    asserts the `klant` row renders with slug, title, version, and property count.
    Asserts the `bestelling` row shows lifecycle-state count "4".
    Asserts that a `customer` schema from a different virtual app does NOT appear.

- [ ] 2.2 **Create `src/views/SchemaDesigner.vue` (list + detail shell)**
  - spec_ref: REQ-OBSD-001, REQ-OBSD-006; design.md Decision 1
  - files: `src/views/SchemaDesigner.vue` (NEW)
  - Two modes gated on `$route.params.schemaId`:
    - **List mode** (no `schemaId`): renders `SchemaListPanel.vue`.
    - **Detail mode** (`schemaId` present): loads the schema via `useSchemasStore.get`,
      mirrors it into a staged copy in `data.staged`, renders the detail editing surface
      (tasks 3–10), exposes a **Save** button and a **Discard changes** button.
  - `data.validationErrors` is a flat map `{ [sectionKey]: string[] }` populated by
    watchers on `data.staged`; `computed.isValid` is `Object.values(validationErrors).every(v => !v.length)`.
  - **Save** is disabled when `!isValid`. On click: PUT `data.staged` via
    `useSchemasStore.update(schemaId, staged)`; on 200 reset `staged` from store
    response and surface success toast; on error surface inline and leave `staged` intact.
  - **Discard changes** resets `data.staged` to the store's current value.
  - ALL strings via `t()` with keys under `openbuilt.schema.designer.*`.
  - acceptance_criteria: Playwright: save with a valid schema → success toast + store
    refreshed. Save with invalid staged state (no initial lifecycle state) → Save
    button disabled, inline error visible. Discard → staged state reverts.

## 3. Schema header form

- [ ] 3.1 **Create `src/components/schema-designer/SchemaHeaderForm.vue`**
  - spec_ref: REQ-OBSD-002
  - files: `src/components/schema-designer/SchemaHeaderForm.vue` (NEW)
  - Props: `mode` (`create` | `edit`), `initial` (schema object for edit pre-fill).
  - Inputs: `slug` (text, kebab-case pattern `^[a-z][a-z0-9-]*[a-z0-9]$`, required),
    `title` (text, required), `description` (textarea, optional), `version`
    (text, semver pattern `^\d+\.\d+\.\d+$`, required, default `0.1.0`).
  - In `create` mode: on submit calls `useSchemasStore.create({ slug, title, description, version })`; on `201` routes to `/builder/{appSlug}/schemas/{newSlug}`; on `409` surfaces inline error on the `slug` field.
  - In `edit` mode (Rename): emits `update:schema` with changed fields; parent calls store.
  - ALL strings via `t()` with keys under `openbuilt.schema.form.*`.
  - acceptance_criteria: Playwright: Add Schema happy path → POST + route to detail.
    Duplicate slug path → inline error on slug field, no navigation. Semver pattern
    enforcement: `1.0` rejected inline; `1.0.0` accepted.

## 4. Field editor

- [ ] 4.1 **Create `src/components/schema-designer/FieldTypePicker.vue`**
  - spec_ref: REQ-OBSD-003
  - files: `src/components/schema-designer/FieldTypePicker.vue` (NEW)
  - A single `NcSelect` (with `inputLabel` prop, not a bare `<label>` per ADR-004)
    over the fixed enum: `string`, `number`, `integer`, `boolean`, `array`,
    `object`, `relation`.
  - Emits `update:type` on selection.
  - acceptance_criteria: Vitest: renders all seven type options; emits the correct
    type string on selection; `inputLabel` prop is set (ADR-004 nc-input-labels gate).

- [ ] 4.2 **Create `src/components/schema-designer/FieldRow.vue`**
  - spec_ref: REQ-OBSD-003, REQ-OBSD-008
  - files: `src/components/schema-designer/FieldRow.vue` (NEW)
  - Props: `property` (JSON Schema property object + `name` key), `index` (position
    in ordered array), `totalCount` (for disabling `↑` at top / `↓` at bottom).
  - Renders: name input (unique within schema, kebab-case or camelCase), `FieldTypePicker`,
    `required` checkbox, `default` input, `description` input, type-specific validation
    section (visible only for the selected type), `↑`/`↓` reorder buttons, drag handle,
    delete button (opens `DeletePropertyDialog.vue`).
  - Type-specific validation sections:
    - `string`: `pattern` (text), `format` (select: `email|uri|date|date-time|uuid`),
      `minLength` (integer ≥ 0), `maxLength` (integer ≥ 0), `enum` (tag input).
    - `number` / `integer`: `minimum` (number), `maximum` (number),
      `multipleOf` (number > 0), `enum` (tag input).
    - `array`: `items` (nested `FieldTypePicker` + type-specific sub-section),
      `minItems` (integer ≥ 0), `maxItems` (integer ≥ 0).
    - `object`: `properties` (recursive `FieldRow` list, max 1 level deep in v1).
    - `relation`: `target` (`NcSelect` over `useSchemasStore.list` slugs, with
      `inputLabel` prop), `cardinality` (`NcSelect` over `one`/`many`, with
      `inputLabel` prop), `inverse_of` (text, optional).
    - `boolean`: no extra validation inputs.
  - Emits `update:property`, `move-up`, `move-down`, `delete-request`.
  - ALL strings via `t()` with keys under `openbuilt.schema.field.*`.
  - acceptance_criteria: Playwright: adds `email` string property with `format: email`
    and `required: true`; saves; reloads; asserts persistence. Drags `body` above
    `title`; saves; reloads; asserts `body` renders first. Vitest: validates uniqueness
    check within the parent schema (duplicate name → inline error).

## 5. Confirm-before-destructive dialogs

- [ ] 5.1 **Create `src/dialogs/DeletePropertyDialog.vue`**
  - spec_ref: REQ-OBSD-008
  - files: `src/dialogs/DeletePropertyDialog.vue` (NEW)
  - `NcDialog`-based. Props: `propertyName` (string), `open` (bool).
  - Body: warns "Existing objects may have data in property «{propertyName}» that
    becomes unreachable if you save after deleting it."
  - Footer: **Cancel** (emits `update:open(false)`) and **Confirm** (emits `confirm`).
  - Cancelling leaves staged state unchanged (parent does nothing on cancel).
  - ALL strings via `t()` with keys under `openbuilt.schema.dialog.deleteProperty.*`.
  - acceptance_criteria: Playwright: clicking field remove opens dialog; clicking Cancel
    leaves field in list; clicking Confirm removes field from staged state.

- [ ] 5.2 **Create `src/dialogs/DeleteSchemaDialog.vue`**
  - spec_ref: REQ-OBSD-008
  - files: `src/dialogs/DeleteSchemaDialog.vue` (NEW)
  - `NcDialog`-based. Props: `schemaSlug` (string), `open` (bool).
  - Body: warns "All objects of schema «{schemaSlug}» may be affected. This action
    calls OR's runtime schema DELETE endpoint and cannot be undone."
  - Typed-slug confirmation input: `NcTextField` with `label` set to
    `t('openbuilt', 'Type "{schemaSlug}" to confirm')`. **Delete** button
    enabled only when input value === `schemaSlug` (exact match, case-sensitive).
  - Footer: **Cancel** (emits `update:open(false)`) and **Delete** (emits `confirm`,
    disabled until slug typed).
  - ALL strings via `t()` with keys under `openbuilt.schema.dialog.deleteSchema.*`.
  - acceptance_criteria: Playwright: clicking schema delete opens dialog; Delete button
    disabled until slug typed; typing wrong slug keeps it disabled; typing exact slug
    enables it; Cancel leaves schema in list; Confirm triggers store delete.

## 6. Lifecycle editor

- [ ] 6.1 **Create `src/components/schema-designer/LifecycleEditor.vue`**
  - spec_ref: REQ-OBSD-004
  - files: `src/components/schema-designer/LifecycleEditor.vue` (NEW)
  - Props: `value` (`x-openregister-lifecycle` block or `null`), `errors` (string[]).
  - Emits `update:value` with the updated lifecycle block.
  - **States section**: ordered list of rows (name input, label input, `initial` radio
    — exactly one selected). `+ Toestand toevoegen` button appends a row. Delete per row.
  - **Transitions section**: list of rows (from `NcSelect` over state names, to `NcSelect`
    over state names, label input, optional condition text). `+ Overgang toevoegen` appends.
  - **Actions per transition**: expandable per-transition sub-section; `+ Actie toevoegen`
    button opens a row with `type` `NcSelect` (fixed enum: `audit-event-emit`,
    `notification-send`, `related-object-upsert`, `related-object-archive`,
    `webhook-dispatch`). Type-specific fields rendered per selection (e.g.
    `notification-send` shows a `template` text input). No free-text PHP / JS fields anywhere.
  - Live validation: surfaces "exactly one initial state is required" when zero or >1
    states have `initial: true`; adds error to `data.validationErrors['lifecycle']` in parent.
  - ALL strings via `t()` with keys under `openbuilt.schema.lifecycle.*`.
  - acceptance_criteria: Playwright: authors `draft → gepubliceerd → gearchiveerd` with
    `draft` as initial, adds `audit-event-emit` action on `draft → gepubliceerd`, saves,
    asserts persisted JSON contains three states, three transitions, one typed action.
    Vitest: removing `initial` from only initial state → validation error surfaced.

## 7. Aggregation editor

- [ ] 7.1 **Create `src/components/schema-designer/AggregationEditor.vue`**
  - spec_ref: REQ-OBSD-005 (aggregations)
  - files: `src/components/schema-designer/AggregationEditor.vue` (NEW)
  - Props: `value` (`x-openregister-aggregations` map or `null`), `errors` (string[]).
  - Emits `update:value`.
  - Renders an ordered list of typed-record rows:
    - `name` (text, required, unique within the section)
    - `operation` (`NcSelect` with `inputLabel` prop, fixed enum:
      `count | sum | avg | min | max`)
    - `source` (text — property path or related schema slug, required)
    - `filter` (text, optional) — validated by `@openregister/declarative-dsl`
      on every keystroke; rejects free-text PHP / JS with inline error.
  - `+ Aggregatie toevoegen` button appends a new row.
  - ALL strings via `t()` with keys under `openbuilt.schema.aggregation.*`.
  - acceptance_criteria: Playwright: adds `open_bestellingen` count aggregation on
    `klant` schema; saves; asserts persisted `x-openregister-aggregations.open_bestellingen`
    matches the typed record. Vitest: filter `status = 'open'` accepted; filter
    `<?php echo 1; ?>` rejected with inline error.

## 8. Calculation editor

- [ ] 8.1 **Create `src/components/schema-designer/CalculationEditor.vue`**
  - spec_ref: REQ-OBSD-005 (calculations)
  - files: `src/components/schema-designer/CalculationEditor.vue` (NEW)
  - Props: `value` (`x-openregister-calculations` map or `null`), `schemaProperties`
    (string[] of property names for the `depends_on` picker), `errors` (string[]).
  - Emits `update:value`.
  - Renders ordered list of typed-record rows:
    - `name` (text, required, unique within the section)
    - `expression` (text, required) — validated by `@openregister/declarative-dsl`;
      on PHP / JS detection surfaces "expression must use the declarative formula DSL"
      inline and sets `errors` entry so Save is disabled.
    - `depends_on` (multi-select `NcSelect` with `inputLabel` prop over `schemaProperties`).
  - `+ Berekening toevoegen` appends a new row.
  - ALL strings via `t()` with keys under `openbuilt.schema.calculation.*`.
  - acceptance_criteria: Playwright: adds `prijs_incl_btw` calculation with expression
    `@self.prijs * 1.21` and `depends_on: ["prijs"]`; saves; asserts persisted JSON.
    Vitest: expression `<?php return ... ?>` → inline error + Save disabled.
    Expression `@self.prijs * 1.21` → accepted, Save enabled.

## 9. Notification editor

- [ ] 9.1 **Create `src/components/schema-designer/NotificationEditor.vue`**
  - spec_ref: REQ-OBSD-005 (notifications)
  - files: `src/components/schema-designer/NotificationEditor.vue` (NEW)
  - Props: `value` (`x-openregister-notifications` array or `null`), `lifecycleStates`
    (string[] of state names for transition events), `errors` (string[]).
  - Emits `update:value`.
  - Renders ordered list of typed-record rows:
    - `event` (`NcSelect` with `inputLabel` prop; options: lifecycle transition slugs
      derived from `lifecycleStates` (e.g. `draft → gepubliceerd`) plus CRUD events
      `created`, `updated`, `deleted`)
    - `channel` (`NcSelect` with `inputLabel` prop, fixed enum: `email | webhook | in-app`)
    - `template` (text, required — named template slug; free-text-with-warning
      "enter the registered template slug" pending catalogue)
    - `recipient` (text, required — relation path or fixed role)
  - `+ Notificatie toevoegen` appends a new row.
  - ALL strings via `t()` with keys under `openbuilt.schema.notification.*`.
  - acceptance_criteria: Playwright: adds a notification on `bestelling` schema for
    event `in-behandeling → verzonden`, channel `in-app`, template `bestelling-verzonden`,
    recipient `klant.email`; saves; asserts persisted `x-openregister-notifications` entry.

## 10. Relation editor

- [ ] 10.1 **Create `src/components/schema-designer/RelationEditor.vue`**
  - spec_ref: REQ-OBSD-005 (relations)
  - files: `src/components/schema-designer/RelationEditor.vue` (NEW)
  - Props: `value` (`x-openregister-relations` map or `null`), `namespaceSchemas`
    (array of schema objects from `useSchemasStore.list` for the target picker),
    `errors` (string[]).
  - Emits `update:value`.
  - Renders ordered list of typed-record rows:
    - `name` (text, required, unique within schema)
    - `target` (`NcSelect` with `inputLabel` prop; options from `namespaceSchemas`
      slugs — NOT free-text; picker sourced from store)
    - `cardinality` (`NcSelect` with `inputLabel` prop, enum: `one | many`)
    - `inverse_of` (text, optional — name of the inverse property on the target schema)
  - `+ Relatie toevoegen` appends a new row.
  - ALL strings via `t()` with keys under `openbuilt.schema.relation.*`.
  - acceptance_criteria: Playwright: adds `categorie → producten` relation (cardinality
    `many`, inverse_of `categorie`) to `categorie` schema; saves; asserts persisted
    `x-openregister-relations.producten` typed record. Vitest: target picker only shows
    schema slugs from the namespace, not free-text input.

## 11. Widget editor

- [ ] 11.1 **Create `src/components/schema-designer/WidgetEditor.vue`**
  - spec_ref: REQ-OBSD-005 (widgets); design.md Decision 9
  - files: `src/components/schema-designer/WidgetEditor.vue` (NEW)
  - Props: `value` (`x-openregister-widgets` array or `null`), `errors` (string[]).
  - Emits `update:value`.
  - Renders ordered list of typed-record rows:
    - `slot` (text, required — named page slot)
    - `widget` (text, required — canonical widget ID; free-text with visible warning
      banner "No widget catalogue registered — enter the canonical widget ID manually.
      Unknown IDs will be ignored by the page renderer." This is intentional per
      design Decision 9 until `openbuilt-page-designer` publishes the catalogue.)
    - `config` (JSON sub-form — `CnJsonViewer` in edit mode for v1)
  - `+ Widget toevoegen` appends a new row.
  - ALL strings via `t()` with keys under `openbuilt.schema.widget.*`.
  - acceptance_criteria: Playwright: adds a widget entry with slot `sidebar`,
    widget id `cn-stats-block`, config `{ "title": "Openstaande bestellingen" }`;
    saves; asserts persisted `x-openregister-widgets` entry. Warning banner visible.

## 12. Router registration and BuilderHost menu entry

- [ ] 12.1 **Register schema-designer routes in `src/router/index.js`**
  - spec_ref: REQ-OBSD-001; design.md Decision 6
  - files: `src/router/index.js`
  - Add two named routes under the OpenBuilt **outer** router (not inside any
    `CnAppRoot` nested router):
    ```js
    { path: '/builder/:slug/schemas',           name: 'SchemaList',   component: SchemaDesigner },
    { path: '/builder/:slug/schemas/:schemaId', name: 'SchemaDetail', component: SchemaDesigner },
    ```
  - Both routes resolve to `SchemaDesigner.vue`. The view uses
    `$route.params.schemaId` to switch between list and detail mode.
  - acceptance_criteria: Playwright: navigating to `/builder/demo-webshop/schemas`
    resolves to `SchemaDesigner.vue` in list mode. Navigating to
    `/builder/demo-webshop/schemas/klant` resolves to detail mode showing `klant`.

- [ ] 12.2 **Add version-aware Schemas menu entry to `BuilderHost.vue`**
  - spec_ref: REQ-OBSD-001
  - files: `src/views/BuilderHost.vue`
  - Add a `NcAppNavigationItem` with icon `icon-category-list`, label
    `t('openbuilt', 'Schemas')` (key `openbuilt.builder.menu.schemas`), and `:to`
    pointing at the `SchemaList` named route with the active `slug` param.
  - The entry is visible to any authenticated user with read access to the
    Application (chain #7 RBAC will narrow this; document in the in-app help string).
  - acceptance_criteria: Playwright: opens `/builder/demo-webshop`, asserts
    **Schemas** appears in the outer navigation, clicks it, asserts route resolves to
    the schema list panel.

## 13. Tests (ADR-008)

- [ ] 13.1 **Vitest: `src/store/__tests__/schemas.spec.js`**
  - spec_ref: REQ-OBSD-001, REQ-OBSD-006
  - Covers: `useSchemasStore("demo", "development")` calls `createObjectStore` with
    `register: "openbuilt-demo-development"`; `useSchemasStore("demo", "staging")`
    uses `openbuilt-demo-staging`; all store methods (list, get, create, update, delete)
    round-trip through a mocked OR runtime schema API.

- [ ] 13.2 **Vitest: `src/components/schema-designer/__tests__/FieldRow.spec.js`**
  - spec_ref: REQ-OBSD-003
  - Covers: add property for each of the 7 types; type-specific validation section
    visibility; `required` flag toggle; name uniqueness error (duplicate name →
    inline error); `↑`/`↓` buttons emit correct events; delete button opens
    `DeletePropertyDialog.vue`.

- [ ] 13.3 **Vitest: `src/components/schema-designer/__tests__/LifecycleEditor.spec.js`**
  - spec_ref: REQ-OBSD-004
  - Covers: add / remove states; initial-state singleton invariant (zero initial →
    error; two initial → error; exactly one → valid); add transitions; typed-enum
    `on_transition.type` constraint (only valid enum values accepted); output shape
    matches `x-openregister-lifecycle` contract.

- [ ] 13.4 **Vitest: `src/components/schema-designer/__tests__/AggregationEditor.spec.js`**
  - spec_ref: REQ-OBSD-005
  - Covers: add typed record; operation enum enforced; filter `status = 'open'`
    accepted; filter `<?php echo 1; ?>` → inline error + Save disabled.

- [ ] 13.5 **Vitest: `src/components/schema-designer/__tests__/CalculationEditor.spec.js`**
  - spec_ref: REQ-OBSD-005
  - Covers: valid expression `@self.prijs * 1.21` accepted; `<?php return ... ?>`
    rejected with "expression must use the declarative formula DSL"; `depends_on`
    picker limited to schema's own property names.

- [ ] 13.6 **Vitest: `src/components/schema-designer/__tests__/DeleteDialogs.spec.js`**
  - spec_ref: REQ-OBSD-008
  - Covers: `DeletePropertyDialog` — confirm click emits `confirm`; cancel emits
    nothing; `DeleteSchemaDialog` — Delete disabled with empty input; disabled with
    wrong slug; enabled with exact slug; cancel emits nothing.

- [ ] 13.7 **Playwright: `tests/e2e/schema-designer.spec.ts`**
  - spec_ref: All REQ-OBSD-001 through REQ-OBSD-008
  - Setup: calls `tests/fixtures/setup-demo-webshop.ts` to install the four fixture
    schemas (`klant`, `bestelling`, `product`, `categorie`) in
    `openbuilt-demo-webshop-development`.
  - Teardown: calls `tests/fixtures/teardown-demo-webshop.ts`.
  - Test cases (minimum):
    1. **Schema list scoping** — navigates to `hello-world/schemas`; asserts
       `bestelling` (from different app) is absent; asserts `klant` present.
    2. **Add Schema** — adds `leverancier` with title "Leverancier", slug
       `leverancier`, version `0.1.0`; asserts `201` + route to detail.
    3. **Duplicate slug rejected** — attempts to add `klant` again; asserts inline
       error on slug field; asserts no navigation.
    4. **Field editor — add string property** — adds `btw-nummer` of type `string`
       with `pattern: ^NL[0-9]{9}B[0-9]{2}$` and `required: true`; saves; reloads;
       asserts persistence.
    5. **Field editor — reorder** — drags `omschrijving` above `naam`; saves;
       reloads; asserts new order.
    6. **Lifecycle editor** — authors three-state lifecycle on `leverancier`;
       marks `actief` as initial; adds `audit-event-emit` action; saves; asserts
       persisted `x-openregister-lifecycle` block.
    7. **Lifecycle validation** — removes `initial` designation; asserts Save
       disabled; re-designates; asserts Save enabled.
    8. **Aggregation editor** — adds count aggregation on `klant`; saves; asserts.
    9. **Calculation editor — PHP rejected** — types `<?php return 1; ?>`;
       asserts inline error + Save disabled.
    10. **Relation editor** — adds `bestelling` (many) to `klant`; saves; asserts.
    11. **Delete field — confirm flow** — clicks delete on a field; cancels; asserts
        field remains; confirms; asserts field removed from staged state.
    12. **Delete schema — slug typing** — initiates delete on `leverancier`; asserts
        Delete disabled; types wrong slug; asserts still disabled; types `leverancier`;
        asserts enabled; cancels; asserts schema still in list.

- [ ] 13.8 **Contract integration test: `tests/e2e/contract/runtime-schema-api.spec.ts`**
  - spec_ref: REQ-OBSD-006, REQ-OBSD-007
  - Pins every store request / response shape against the OR runtime schema API
    OpenAPI document. Fails if chain #3's published OpenAPI contract drifts from
    what the designer's store calls assume.
  - acceptance_criteria: runs in CI as part of the integration suite; must pass before
    any apply PR merges.

## 14. i18n (ADR-007, ADR-025)

- [ ] 14.1 **Add English translation keys to `l10n/en.json`**
  - All new strings under `openbuilt.schema.*` namespaces:
    `openbuilt.schema.list.*`, `openbuilt.schema.form.*`, `openbuilt.schema.field.*`,
    `openbuilt.schema.designer.*`, `openbuilt.schema.lifecycle.*`,
    `openbuilt.schema.aggregation.*`, `openbuilt.schema.calculation.*`,
    `openbuilt.schema.notification.*`, `openbuilt.schema.relation.*`,
    `openbuilt.schema.widget.*`, `openbuilt.schema.dialog.deleteProperty.*`,
    `openbuilt.schema.dialog.deleteSchema.*`, `openbuilt.builder.menu.schemas`.

- [ ] 14.2 **Add Dutch translations to `l10n/nl.json`**
  - Same key set as 14.1 with Dutch values.
  - Dutch labels reference: "Schema's" (schemas list heading), "Schema toevoegen",
    "Veld toevoegen", "Sla op", "Wijzigingen verwerpen", "Toestand toevoegen",
    "Overgang toevoegen", "Aggregatie toevoegen", "Berekening toevoegen",
    "Notificatie toevoegen", "Relatie toevoegen", "Widget toevoegen", "Bevestigen",
    "Annuleren", "Verwijderen".

- [ ] 14.3 **Verify no hardcoded strings**
  - `git grep -n "\"[A-Z]" src/components/schema-designer/ src/views/SchemaDesigner.vue
    src/dialogs/DeletePropertyDialog.vue src/dialogs/DeleteSchemaDialog.vue`
    returns zero matches (all literals route through `t()`).

## 15. ADR-031 declarative-output CI gate

- [ ] 15.1 **Add imperative-output grep gate**
  - spec_ref: REQ-OBSD-007; design.md Decision 5
  - files: `.github/workflows/quality.yml` (or equivalent CI config) — add a step
    that runs:
    ```bash
    ! git diff --name-only HEAD~1 HEAD | grep -E "src/components/schema-designer|src/views/SchemaDesigner" | xargs grep -lE 'eval\(|<\?php|Function\(|script:|cb:|handler:|phpClass:'
    ```
  - Gate fails the build if any of the forbidden patterns appear in schema-designer
    source files changed in the PR diff.
  - acceptance_criteria: a test fixture file containing `eval(` placed under
    `src/components/schema-designer/` causes the gate to fail; the fixture is removed
    before the final apply commit.

## 16. Quality gates

- [ ] 16.1 **JS** — `npm run lint` (ESLint flat-config) passes on all new and
  modified SFCs.
- [ ] 16.2 **Vitest** — `npm run test:unit` passes; all new spec files from
  tasks 13.1–13.6 included.
- [ ] 16.3 **No PHP files** — `git diff --name-only HEAD~1 HEAD | grep "lib/"` returns
  empty (this spec is frontend-only; no PHP was created or modified).
- [ ] 16.4 **No bespoke store** — `git grep "defineStore" src/store/schemas.js`
  returns empty; only `createObjectStore` is used.
- [ ] 16.5 **No `NcSelect` without `inputLabel`** — `git grep "<NcSelect" src/components/schema-designer/`
  returns only occurrences that include an `inputLabel` prop (ADR-004 nc-input-labels
  gate).
- [ ] 16.6 **No inline dialogs** — `git grep -l "NcModal\|NcDialog" src/components/schema-designer/`
  returns empty; all modal / dialog markup lives in `src/dialogs/` (ADR-004 modal
  isolation gate).
- [ ] 16.7 **No `window.confirm` / `window.alert`** — `git grep "window\." src/components/schema-designer/
  src/views/SchemaDesigner.vue` returns empty.
- [ ] 16.8 **Playwright** — `tests/e2e/schema-designer.spec.ts` from task 13.7 passes
  against `localhost:3000` with a freshly-seeded demo environment.

## 17. Documentation (ADR-009)

- [ ] 17.1 **Add `docs/openbuilt-schema-designer.md`**
  - Documents: designer architecture (component tree from design Decision 1), staged-
    state pattern, version-aware register scoping (ADR-002), declarative-output
    guarantee (ADR-031), all eight sub-editors and their output JSON shapes, the
    confirm-before-destructive flows.

- [ ] 17.2 **Update integrator-guide**
  - Extend `docs/integrator-guide.md` with a "Designing a schema" walkthrough using
    the `demo-webshop` fixture schemas (`klant`, `bestelling`, `product`, `categorie`).

- [ ] 17.3 **NL Design (ADR-010) accessibility check**
  - Confirm: every new SFC uses only Nextcloud CSS variables (no hardcoded colours).
  - Confirm: `DeletePropertyDialog.vue` and `DeleteSchemaDialog.vue` have focus traps.
  - Confirm: icon-only buttons have `aria-label` attributes.
  - Confirm: WCAG AA colour contrast on lifecycle-state `initial` radio indicator.

## 18. Apply ordering

- [ ] 18.1 Confirm `openregister-runtime-schema-api` is merged to `development`
  BEFORE opening the apply PR for this spec. The store calls will 404 otherwise.
- [ ] 18.2 During apply, re-read chain #3's published request / response shapes.
  If they differ from REQ-OBSD-002 and REQ-OBSD-006 assumptions, raise a
  clarifying follow-up change rather than silently reshape the designer (spec is
  the source of truth per the workflow rule).
- [ ] 18.3 If the archived `openbuilt-schema-editor` apply PR was partially landed,
  complete task 0.2 (remove old `src/components/schema-editor/`) before creating
  the new `src/components/schema-designer/` family.
