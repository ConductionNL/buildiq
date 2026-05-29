## 1. Implementation Tasks — openbuilt-schema-designer (v1)

- [x] 1.1 **Wire `useSchemasStore` via `createObjectStore`**
  - spec_ref: REQ-OBSD-001, REQ-OBSD-002, REQ-OBSD-006
  - files: `src/store/schemas.js`
  - acceptance_criteria: `useSchemasStore` is created via
    `createObjectStore({ register: 'openbuilt-{slug}', schema:
    'schema' })` (memory rule: no bespoke Pinia store). Exposes
    `list / get / create / update / delete` over OR's runtime schema
    CRUD endpoints (chain `openregister-runtime-schema-api`). Has
    zero hand-rolled fetch calls; every network operation flows
    through the store.
  - Test: unit test mocks the OR runtime schema endpoint OpenAPI
    contract and asserts list / create / update / delete round-trip
    via the store.

- [x] 1.2 **Build `SchemaListPanel.vue` and the schema-list route**
  - spec_ref: REQ-OBSD-001
  - files: `src/components/schema-editor/SchemaListPanel.vue`,
    `src/views/SchemaDesigner.vue` (list-mode render branch)
  - acceptance_criteria: Renders one row per schema in the virtual
    app's register namespace with slug, title, version, property
    count, lifecycle-state count. **Add Schema** action surfaces the
    header form (task 1.3). Per-row **Open / Rename / Delete**
    actions wire to the schema-detail route + REQ-OBSD-008 confirm
    dialog. Empty state surfaces a "no schemas yet" message.
  - Test: Playwright opens
    `/builder/hello-world/schemas`, asserts the seeded
    `hello-message` schema row renders.

- [x] 1.3 **Build `SchemaHeaderForm.vue` for Add Schema and detail header**
  - spec_ref: REQ-OBSD-002
  - files: `src/components/schema-editor/SchemaHeaderForm.vue`
  - acceptance_criteria: Captures `slug` (kebab-case pattern,
    namespace-unique), `title` (required), `description` (optional),
    `version` (semver). On Add submit, POSTs via
    `useSchemasStore.create` and routes to
    `/builder/{slug}/schemas/{newSlug}` on success. Surfaces inline
    error messages from the runtime endpoint (e.g. `409` duplicate
    slug) on the failing field.
  - Test: Playwright walks the Add flow happy-path; second test
    walks the duplicate-slug path and asserts the inline error.

- [x] 1.4 **Build `FieldRow.vue` + `FieldTypePicker.vue`** (combined into `FieldEditor.vue` per task brief)
  - spec_ref: REQ-OBSD-003
  - files: `src/components/schema-editor/FieldRow.vue`,
    `src/components/schema-editor/FieldTypePicker.vue`
  - acceptance_criteria: One row per property; supports add, remove
    (via REQ-OBSD-008 confirm), reorder (drag-handle), edit name,
    required, default, description, and the type-specific validation
    set per spec REQ-OBSD-003. Type picker is a fixed enum (`string
    / number / integer / boolean / array / object / relation`) — no
    free-text type entry. Reorder is reflected in the saved
    `properties` JSON Schema order (Vue 2 reactivity quirk: use an
    explicit ordered array beside the keyed map).
  - Test: Playwright adds an `email` string property with `format:
    email` + `required: true`, saves, reloads, asserts persistence.

- [ ] 1.5 **Build `LifecycleEditor.vue`**
  - spec_ref: REQ-OBSD-004
  - files: `src/components/schema-editor/LifecycleEditor.vue`
  - acceptance_criteria: Authors `x-openregister-lifecycle`
    declaratively per ADR-031 — states (with `initial` radio),
    transitions, typed `on_transition` actions drawn from a fixed
    enum (`audit-event-emit / notification-send /
    related-object-upsert / related-object-archive /
    webhook-dispatch`). No free-text PHP / JS fields anywhere. Live
    validation enforces "exactly one initial state required".
  - Test: Playwright authors `draft → published → archived`, adds an
    `audit-event-emit` action, saves, asserts the persisted JSON
    contains the typed records exactly.

- [ ] 1.6 **Build `RelationEditor.vue`**
  - spec_ref: REQ-OBSD-005 (relations slice only — v1)
  - files: `src/components/schema-editor/RelationEditor.vue`
  - acceptance_criteria: Authors `x-openregister-relations` —
    `{ name, target (picker over namespace schemas), cardinality
    (one / many), inverse_of (optional) }`. Target picker is sourced
    from `useSchemasStore.list`; no free-text target slug.
  - Test: Playwright adds a `customer → orders` relation, saves,
    asserts the persisted JSON shape.

- [ ] 1.7 **Build `WidgetEditor.vue` (with OQ-3 free-text-with-warning fallback)**
  - spec_ref: REQ-OBSD-005 (widgets slice only — v1)
  - files: `src/components/schema-editor/WidgetEditor.vue`
  - acceptance_criteria: Authors `x-openregister-widgets`
    `{ slot, widget, config }`. Until chain #5 publishes the widget
    catalogue, the `widget` field is a free-text input with a
    visible "no catalogue registered yet" warning (per design OQ-3).
    `slot` is free-text; `config` is a JSON sub-form (read-only
    raw-JSON in v1, structured form in v1.1 once the catalogue
    declares each widget's config shape).
  - Test: Playwright adds a widget entry, saves, asserts persisted
    JSON.

- [ ] 1.8 **Wire live validation + explicit Save flow in `SchemaDesigner.vue`**
  - spec_ref: REQ-OBSD-006
  - files: `src/views/SchemaDesigner.vue`,
    `src/components/schema-editor/SchemaHeaderForm.vue` (validator
    plumbing)
  - acceptance_criteria: Staged copy is held in the view's `data()`;
    every sub-editor surfaces validation errors inline; **Save** is
    disabled whenever the staged state has any error. Save composes
    the JSON Schema body (including every `x-openregister-*` block
    authored in tasks 1.4 – 1.7) and PUTs via
    `useSchemasStore.update`. Success refreshes the store; failure
    surfaces inline and leaves the staged state intact. Includes a
    **Discard staged edits** button that reverts to the store value.
  - Test: Playwright authors a partial schema, removes the only
    `initial` state, asserts Save is disabled; restores the state,
    asserts Save activates; clicks Save, asserts the PUT and the
    success toast.

- [ ] 1.9 **Implement REQ-OBSD-007 ADR-031 grep gate as a CI check**
  - spec_ref: REQ-OBSD-007
  - files: `.github/workflows/declarative-output-gate.yml` OR a
    project-local `scripts/check-no-imperative-output.sh` invoked by
    the existing `quality.yml` workflow
  - acceptance_criteria: Grep gate fails the build if the diff
    introduces any of `eval(`, `<?php`, `Function(`, `script:`,
    `cb:`, `handler:`, or `phpClass:` in
    `src/components/schema-editor/**` or `src/views/SchemaDesigner.vue`.
    Documented in this spec's design.md Decision 5.
  - Test: meta-test (a fixture file containing `eval(` placed under
    `src/components/schema-editor/` causes the gate to fail; the
    fixture is then removed).

- [ ] 1.10 **Implement REQ-OBSD-008 confirm-before-destructive dialogs**
  - spec_ref: REQ-OBSD-008
  - files: `src/components/schema-editor/FieldRow.vue` (delete-field
    dialog), `src/components/schema-editor/SchemaListPanel.vue`
    (delete-schema dialog with typed-slug confirmation)
  - acceptance_criteria: Delete-field dialog requires an explicit
    confirm click; delete-schema dialog requires the user to type
    the schema slug exactly before the Delete button activates.
    Cancelling either dialog leaves the staged state / store
    unchanged.
  - Test: Playwright happy-path + cancel-path on both dialogs.

## 2. Implementation Tasks — openbuilt-runtime (modified)

- [ ] 2.1 **Register schema-designer routes on the outer router**
  - spec_ref: REQ-OBR-006
  - files: `src/router/index.js`
  - acceptance_criteria: Two routes registered under the OpenBuilt
    outer router: `/builder/:slug/schemas` (list mode) and
    `/builder/:slug/schemas/:schemaId` (detail mode). Both resolve
    to `SchemaDesigner.vue`. The existing `/builder/:slug/*`
    virtual-app preview route is unchanged.
  - Test: Playwright navigates each route and asserts the right
    component renders.

- [ ] 2.2 **Add Schemas menu entry to `BuilderHost.vue`**
  - spec_ref: REQ-OBR-007
  - files: `src/views/BuilderHost.vue`
  - acceptance_criteria: The outer-shell secondary navigation in
    the builder context surfaces a **Schemas** entry that links to
    `/builder/{slug}/schemas`. The label uses i18n key
    `openbuilt.builder.menu.schemas`. The entry is visible to any
    authenticated user with read access to the Application object
    (chain #7 RBAC will narrow this later).
  - Test: Playwright opens `/builder/hello-world`, asserts the
    Schemas entry renders, clicks it, asserts the route resolves to
    the list panel.

## 3. Verification

- [ ] 3.1 Run `npm run lint` — ESLint flat-config passes on every new
  / modified SFC.
- [ ] 3.2 Run `npm run check:manifest` (ADR-024) — manifest schema
  unchanged; build still validates against the canonical pinned
  schema.
- [ ] 3.3 Confirm no PHP files are created or modified under `lib/`
  for this spec (this is `kind: code`, frontend-only).
- [ ] 3.4 Confirm no `src/store/schemas.js` declares a bespoke
  `defineStore` — the file MUST wrap `createObjectStore` only
  (memory rule).
- [ ] 3.5 Confirm zero matches of `eval(`, `<?php`, `Function(`,
  `script:`, `cb:`, `handler:`, `phpClass:` in
  `src/components/schema-editor/**` and `src/views/SchemaDesigner.vue`
  (ADR-031 review gate, automated by task 1.9).
- [ ] 3.6 Visually verify on a fresh `docker compose up` that the
  Schemas menu entry renders inside `/builder/hello-world` and the
  designer can add a property to `hello-message`, save it via the
  runtime endpoint (chain #3), and the change appears in OR's
  schema list.

## 4. Tests (ADR-008)

- [ ] 4.1 **Vitest** —
  `src/components/schema-editor/__tests__/FieldRow.spec.js` covers
  add / remove / reorder, type-picker behaviour for each of the 7
  supported types, and validation surfacing.
- [ ] 4.2 **Vitest** —
  `src/components/schema-editor/__tests__/LifecycleEditor.spec.js`
  covers state add / remove, initial-state singleton invariant,
  transition add, typed-enum `on_transition.action` constraint.
- [ ] 4.3 **Vitest** —
  `src/store/__tests__/schemas.spec.js` covers list / get / create /
  update / delete via a mocked chain-#3 endpoint.
- [ ] 4.4 **Playwright** —
  `tests/e2e/schema-designer.spec.ts` covers the happy paths called
  out in tasks 1.2 – 1.8 + 1.10 + 2.1 + 2.2.
- [ ] 4.5 **Contract integration test** — `tests/e2e/contract/
  runtime-schema-api.spec.ts` mocks chain #3's OpenAPI document and
  asserts every store request / response matches the published
  contract. Fails fast when chain #3's contract drifts.

## 5. Documentation (ADR-009, ADR-010)

- [ ] 5.1 Add `docs/openbuilt-schema-designer.md` describing the
  designer architecture, the staged-state pattern, the
  declarative-output guarantee (ADR-031), and the phased delivery
  (v1 vs v1.1).
- [ ] 5.2 Extend the existing integrator-guide page from
  `bootstrap-openbuilt` (`docs/integrator-guide.md`) with a "How to
  design a schema" walkthrough over the seeded `hello-message`
  example.
- [ ] 5.3 NL Design (ADR-010) — confirm every new SFC uses Nextcloud
  CSS variables only (no hardcoded colours); confirm WCAG AA on the
  new dialogs (`aria-label` on icon-only buttons; focus trap on the
  confirm dialogs).
- [ ] 5.4 Update `openspec/app-config.json` capabilities list to
  declare `openbuilt-schema-designer`.

## 6. i18n (ADR-005, ADR-007)

- [ ] 6.1 Add English keys for every new string under
  `openbuilt.schema.*` in `l10n/en.json` (list / form / field
  editor / lifecycle editor / relation editor / widget editor / save
  toast / confirm dialogs / Schemas menu entry).
- [ ] 6.2 Add Dutch translations for the same keys in `l10n/nl.json`
  (workspace minimum is `nl + en`; memory rule).
- [ ] 6.3 Confirm no hard-coded English strings in any new SFC under
  `src/components/schema-editor/**` or `src/views/SchemaDesigner.vue`
  — every label / placeholder / error / toast routes through `t()`.

## 7. Apply ordering and chain coordination

- [ ] 7.1 Confirm chain spec `openregister-runtime-schema-api` is
  merged into `development` BEFORE this spec's apply PR opens. The
  apply PR MUST NOT open while chain #3 is still in review — the
  designer's network calls would 404.
- [ ] 7.2 During apply, re-read chain #3's published request /
  response shapes; if they differ from what spec REQ-OBSD-002 and
  REQ-OBSD-006 assumed, raise a follow-up clarifying change rather
  than silently reshape the designer (this keeps the spec the source
  of truth per the opsx-strict-workflow memory rule).

## 8. Phase v1.1 — full DSL editors (DEFERRED, tracked here for visibility)

- [ ] 8.1 **Build `AggregationEditor.vue`** with the full typed-record
  editor described in spec REQ-OBSD-005 (operation enum, source
  picker over property paths + related schemas, filter DSL parser).
  Depends on `@openregister/declarative-dsl` package being published
  by chain #3 — see design OQ-1.
- [ ] 8.2 **Build `CalculationEditor.vue`** with the formula DSL
  parser + dependency picker. Depends on
  `@openregister/declarative-dsl`.
- [ ] 8.3 **Build `NotificationEditor.vue`** with channel picker
  (email / webhook / in-app), template picker over registered
  templates, recipient relation-path picker. Depends on the
  notification template catalogue being declared in OR.
- [ ] 8.4 **Cross-schema validation on Save** — block Save when
  removing an `inverse_of`-referenced relation breaks a sibling
  schema (design OQ-4).
- [ ] 8.5 **WidgetEditor catalogue picker** — replace the v1
  free-text-with-warning input with a picker over the registered
  widget catalogue once chain #5 publishes it (design OQ-3).
