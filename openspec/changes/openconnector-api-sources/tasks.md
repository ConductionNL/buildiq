## 1. Manifest form + validation

- [ ] 1.1 **Define the connector dataSource form and validate it app-side**
  - spec_ref: REQ-OCAS-001
  - files: `src/services/manifestValidation/connectorDataSource.js` (new), wiring into the existing `useManifestValidator.js` pipeline
  - acceptance_criteria: A `dataSource.connector` block validates: required `endpointPath` (no `://`, no leading `/apps/`), optional `method` (enum `GET`), `query` (scalar map), `itemsPath` (dot-path), required `fields` (min 1, dot-path values), `cacheTtl` (int 0–3600). Unknown keys, credential-bearing keys (`headers`, `token`, `apiKey`, `authorization`), and mixed forms (`connector` + `register`/`schema` or `graphql`) are rejected with i18n error codes. Errors surface in the side panel AND as inline marks per REQ-OBPD-011's existing path-mapping mechanism.
  - test: Vitest: valid block passes; each rejection case produces its specific error code; round-trip serialization is byte-identical.

- [ ] 1.2 **File the nextcloud-vue follow-up for canonical $def codification**
  - spec_ref: REQ-OCAS-001
  - files: none in this repo (Codeberg issue on `Conduction/nextcloud-vue`)
  - acceptance_criteria: Issue filed describing the additive `connector` branch for the `app-manifest-v2.schema.json` `dataSource` $def (and optional native `useDataSource` resolution), linking back to this change. Issue URL recorded in this tasks file when created.
  - implement: Issue only — no code. Do NOT block this change on the lib merge (`additionalProperties: true` keeps validation green meanwhile).

## 2. Builder UI: picker

- [ ] 2.1 **Implement src/components/pageDesigner/ConnectorSourcePicker.vue**
  - spec_ref: REQ-OCAS-002, REQ-OCAS-004, REQ-OCAS-005
  - files: `src/components/pageDesigner/ConnectorSourcePicker.vue`
  - acceptance_criteria: Lists OpenConnector endpoints fetched from OpenConnector's REST API (verify the exact list route against the deployed app during apply; if absent/admin-only, the free-text fallback of task 2.2 is the primary path and this is noted in the PR). Each row shows endpoint path + Source display name only — assert no credential material is rendered or persisted. Selection writes `endpointPath` to the in-flight manifest and emits the sample-fetch event. `NcSelect` usages carry `inputLabel` (hydra-gate-nc-input-labels). Loading and error states present.
  - test: Vitest with mocked endpoint list: selection emits the binding; credential-shaped fields in the mock payload never reach the DOM.

- [ ] 2.2 **Capability soft-check + manual-path escape hatch**
  - spec_ref: REQ-OCAS-005
  - files: `src/components/pageDesigner/ConnectorSourcePicker.vue` (extend 2.1)
  - acceptance_criteria: When `useAppStatus('openconnector')` reports missing/disabled: the origin option renders disabled with hint `openbuild.connector.hint.openconnector-missing`; a manual text input still accepts an `endpointPath` and marks the binding with a non-blocking "unverified" warning. English i18n keys + nl translations (i18n keys are English source strings).
  - test: Vitest: mocked absent app → disabled option + hint + working manual input.

## 3. Builder UI: mapping editor

- [ ] 3.1 **Implement src/components/pageDesigner/ConnectorFieldMapper.vue**
  - spec_ref: REQ-OCAS-003
  - files: `src/components/pageDesigner/ConnectorFieldMapper.vue`
  - acceptance_criteria: Renders the sample response as a collapsible tree; clicking an array node sets `itemsPath`; clicking item leaves appends `fields` entries with a name prompt (pre-filled from the key); mapped list shows live sample values; re-opening an existing binding restores the full mapping losslessly; "Re-fetch sample" re-runs the call and flags selectors that no longer resolve (warning only — mapping unchanged).
  - test: Vitest: click-to-map produces the expected `itemsPath` + `fields`; lossless round-trip; dead-selector flagging on a mutated sample.

- [ ] 3.2 **Wire the origin toggle into IndexPageEditor.vue and DashboardPageEditor.vue**
  - spec_ref: REQ-OCAS-002
  - files: `src/components/pageDesigner/IndexPageEditor.vue`, `src/components/pageDesigner/DashboardPageEditor.vue` (locate actual paths in the page-designer tree; do not duplicate — extract a shared `DataSourceOriginToggle.vue` if both need identical markup)
  - acceptance_criteria: Toggle `OpenRegister | OpenConnector` (default OpenRegister; auto-set to OpenConnector when an existing binding has `connector`). OpenConnector mounts picker + mapper and hides register/schema pickers; toggling back to OpenRegister with an existing mapping prompts a confirm and clears the `connector` block on accept. Register-bound flows are byte-identical when the toggle is untouched (regression assertion).
  - test: Vitest: toggle behaviour, confirm-clear, regression snapshot of a register-bound page config.

## 4. Runtime resolver

- [ ] 4.1 **Implement src/composables/useConnectorDataSource.js**
  - spec_ref: REQ-OCAS-006, REQ-OCAS-004
  - files: `src/composables/useConnectorDataSource.js`
  - acceptance_criteria: Resolves a `connector` binding: GET `/apps/openconnector/api/endpoint/{endpointPath}` with `query` params via `@nextcloud/axios` (session + requesttoken only — no extra auth headers); applies `itemsPath` + `fields` dot-path selectors (reuse/extract the existing graphql-selectors helper rather than re-implementing); exposes `{ loading, data, error, isStale, retry }`; unresolved selectors yield `null` + one console warning per field per mount.
  - test: Vitest with mocked axios: happy path rows; single-item (no `itemsPath`); null-selector handling; no Authorization header on the request.

- [ ] 4.2 **Caching: TTL, dedupe, stale-on-error**
  - spec_ref: REQ-OCAS-006
  - files: `src/composables/useConnectorDataSource.js` (extend 4.1), `src/services/connectorCache.js` (module-scoped cache)
  - acceptance_criteria: Cache key = `appId + endpointPath + stable query hash`; TTL from `cacheTtl` (default 60 s, clamped 0–3600); concurrent mounts within TTL share one in-flight request (dedupe); refresh failure with an entry ≤10× TTL serves it with `isStale: true`; no entry → `error` state with `retry()`.
  - test: Vitest with fake timers: dedupe (3 consumers → 1 request); TTL expiry refetch; stale-on-error; error-no-cache.

- [ ] 4.3 **Hook the resolver into the virtual-app render path**
  - spec_ref: REQ-OCAS-006, REQ-OCAS-005
  - files: the runtime page-render integration point (where `dataSource` is currently handed to CnIndexPage/CnWidgetGrid — locate in `src/` during apply; likely the manifest page host)
  - acceptance_criteria: Pages/widgets with `dataSource.connector` render through `useConnectorDataSource`; register/graphql bindings keep their existing path untouched; OpenConnector 404 (app absent) renders the per-widget error state, never a blank page. NOTE pre-existing gotcha: CnWidgetGrid drops `widget.dataSource` on some nc-vue versions — verify against the pinned nc-vue and fix-or-flag per the known stats-block bug.
  - test: Vitest: connector page renders rows; register page untouched (snapshot); 404 → error state.

## 5. Dependency management

- [ ] 5.1 **Auto-manage `openconnector` in manifest dependencies[] on save**
  - spec_ref: REQ-OCAS-005
  - files: the page-designer save flow (`PageDesigner.vue` save serializer or shared save service)
  - acceptance_criteria: On save: ≥1 connector binding → `"openconnector"` present exactly once in `dependencies[]`; 0 bindings → it is removed ONLY if it was auto-added (track via a designer-local marker, or conservatively: never auto-remove and surface a removable hint chip — pick one during apply and document in the PR). Save still flows through OR REST PUT per REQ-OBPD-009 (no new controller).
  - test: Vitest: add-binding-save adds the dep once; idempotent on resave; removal behaviour per the chosen strategy.

## 6. Verification

- [ ] 6.1 **Playwright e2e: bind, map, render**
  - spec_ref: REQ-OCAS-002, REQ-OCAS-003, REQ-OCAS-006
  - files: `tests/e2e/connector-sources.spec.ts`
  - acceptance_criteria: UI-driven (Playwright = UI only): seed an OpenConnector Source + Endpoint pointing at a local mock API via occ/REST fixture setup; in the builder, flip the origin toggle, pick the endpoint, click-map two fields, save; open the published virtual app and assert the external rows render; disable openconnector via occ and assert the CnAppRoot dependency screen. Gate-19 annotations updated.
  - test: This task IS the test; runs against localhost:8080 dev instance.

- [ ] 6.2 **Newman: API-contract assertions**
  - spec_ref: REQ-OCAS-004, REQ-OCAS-006
  - files: `tests/integration/openbuild.postman_collection.json` (extend)
  - acceptance_criteria: Newman asserts the OpenConnector endpoint route returns the expected payload for the seeded fixture and that the saved Application object (via OR REST) contains the connector binding with NO credential keys (API contract checks belong in Newman, not Playwright).

- [ ] 6.3 **Quality gates + exporter smoke**
  - spec_ref: All
  - files: all touched files
  - acceptance_criteria: `npm run lint` + vitest green; hydra gates pass (no new PHP so PHP gates are no-ops, but nc-input-labels/modal-isolation/e2e-coverage apply); fix any pre-existing issues encountered in touched files (don't defer). Exporter smoke: export a connector-bound virtual app and assert the manifest block + resolver composable are carried into the generated app unchanged.
