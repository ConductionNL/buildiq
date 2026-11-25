# connector-widget-runtime Specification

## Purpose

TBD - created by archiving change openbuild-connector-widget-runtime. Update Purpose after archive.

## Requirements

### Requirement: REQ-CWR-001 Single runtime-registry seam for every virtual-app host

Every Buildiq host that mounts a virtual app's `CnAppRoot` MUST obtain its runtime component registry from one canonical accessor exported by `src/runtimeRegistry.js`, and MUST pass that value as the `registry` prop; no host may construct, filter or inline its own registry object. The accessor SHALL return the full runtime registry — at minimum the `connector-data` widget entry — so that the set of widget keys a virtual app can render is identical no matter which host mounted it. `CnAppRoot` re-exposes the prop to descendants as the injected `cnRegistry`, which `CnWidgetGrid` resolves `widgetKey` against ahead of the library built-ins and the dashboard widget catalog; a host that omits the prop therefore degrades every registry-only widget to `CnUnknownWidget` with nothing but a `console.warn`, which SHALL be treated as a defect rather than a tolerated fallback.

@e2e exclude the registry seam is a build-time wiring of the two hosts, asserted in Vitest `tests/vitest/runtimeRegistryParity.spec.js`; its published-route effect is proven by `tests/e2e/connector-widget-runtime.spec.ts` under REQ-CWR-004

#### Scenario: Published standalone entry supplies the registry

- **GIVEN** the standalone runtime entry `src/builder.js`, which serves `/apps/buildiq/builder/{slug}`
- **WHEN** it mounts the virtual app's top-level `CnAppRoot`
- **THEN** the `registry` prop is the value returned by the canonical accessor
- **AND** the prop contains a `connector-data` entry whose `component` is `ConnectorDataView`

#### Scenario: Preview host supplies the same registry

- **GIVEN** the in-SPA preview host `src/views/BuilderHost.vue`
- **WHEN** it mounts its nested `CnAppRoot`
- **THEN** the `registry` prop is the value returned by the same canonical accessor
- **AND** its key set is identical to the published entry's key set

#### Scenario: A host that omits the registry fails the parity guard

- **GIVEN** an automated parity check over the hosts that mount a virtual-app `CnAppRoot`
- **WHEN** any such host is found that does not pass the accessor's output as `registry`
- **THEN** the check fails and names the offending host
- **AND** the failure message states that registry-only widget keys would render `CnUnknownWidget` on that path

### Requirement: REQ-CWR-002 Normative endpoint-binding matrix for the published path

Buildiq SHALL treat the following as the closed, normative set of ways a widget may bind to an OpenConnector endpoint on the published virtual-app path, and MUST NOT introduce a further mechanism without amending this requirement. The `connector-data` widget key binds through a widget-level `dataSource.connector` block (REQ-OCAS-001) and is resolved from the runtime registry. The library-native `props.endpointSource` binding is available to the widget keys `object-table`, `stat`, `delta` and `workspace-filter` — for `stat` and `delta` it is nested at `props.content.endpointSource` — and to `chart` through the extended `props.endpointSource` shape (`$defs/chartEndpointSource`). No other widget key may declare an endpoint binding. Authoring guidance SHALL state that `props.endpointSource` is the choice when the endpoint already returns render-shaped rows and the built-in widget's chrome is wanted, and that `connector-data` is the choice when the payload needs `itemsPath` + `fields` projection or when the per-binding `cacheTtl` and stale-on-error contract of REQ-OCAS-006 is required.

#### Scenario: Documented matrix matches the canonical manifest schema

@e2e exclude a schema rule with no runtime surface: the matrix is checked against the vendored v2 schema in Vitest `tests/services/endpointBindingMatrix.spec.js`

- **GIVEN** `@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`
- **WHEN** the endpoint-capable widget keys named in this requirement are checked against it
- **THEN** each of `object-table`, `chart`, `stat`, `delta` and `workspace-filter` accepts an `endpointSource` at the stated location
- **AND** no widget key outside this requirement's list accepts one

#### Scenario: An endpoint-bound object-table renders on the published path

- **GIVEN** a published app whose page places an `object-table` widget declaring `props.endpointSource`
- **WHEN** an end user opens the page at `/apps/buildiq/builder/{slug}`
- **THEN** the table renders rows plucked from the endpoint payload at `responsePath`
- **AND** no `Unknown widgetKey` warning is emitted

#### Scenario: Both bindings on one widget are rejected

@e2e exclude a manifest validation rule with no runtime surface: the canonical validator's refusal, naming the widget, is asserted in Vitest `tests/services/endpointBindingExclusive.spec.js`

- **GIVEN** a widget declaring an OpenRegister source and an `endpointSource` at the same time
- **WHEN** manifest validation runs
- **THEN** the manifest is rejected with an error naming the widget and stating that exactly one data binding is allowed

### Requirement: REQ-CWR-003 `stats-block` entries take an endpoint only through the library's entry mapping

A `stats-block` entry SHALL count over its `register` and `schema`, or read an app endpoint through the library's `endpointSource` and `valueField` on the entry (nextcloud-vue 2.212.0). Buildiq MUST NOT patch the manifest schema or inject endpoint data into `props.entries[]`; any other key on an entry is a schema violation.

@e2e exclude schema rule, asserted in Vitest `tests/services/endpointBindingMatrix.spec.js`

#### Scenario: An endpoint-bound stats-block entry validates

- **GIVEN** a manifest whose `stats-block` widget declares `props.entries[0]` with `endpointSource: { url: "/apps/example/api/kpi" }` and `valueField: "open"`
- **WHEN** the manifest is validated against the canonical v2 schema
- **THEN** the entry has no errors

#### Scenario: An unknown key on an entry is rejected

- **GIVEN** a `stats-block` whose `props.entries[1]` carries a key the schema does not declare
- **WHEN** the manifest is validated
- **THEN** validation fails naming `/props/entries/1` and the key

#### Scenario: An entry without a source is rejected

- **GIVEN** a `stats-block` entry with neither `register` and `schema` nor `endpointSource`
- **WHEN** the manifest is validated
- **THEN** validation fails for that entry

### Requirement: REQ-CWR-004 Published-path proof obligation

A registry-resolved widget key SHALL be proven to render on the published virtual-app route `/apps/buildiq/builder/{slug}`, and preview-only coverage MUST NOT be accepted as proof. The proof SHALL assert the widget's own rendered surface — for `connector-data`, either its projected data table or its error-with-retry state when OpenConnector cannot serve the binding (REQ-OCAS-005) — and SHALL additionally fail on any browser console warning identifying an unresolved `widgetKey`, because `CnUnknownWidget` is itself a designed tile and a presence-only assertion would pass against it. The test fixture SHALL be created and torn down by the test itself, SHALL be identifiable as test data, and SHALL carry no credential-bearing key in its connector binding.

#### Scenario: connector-data resolves on the published route

- **GIVEN** a published fixture app with a page placing a `connector-data` widget bound to a placeholder endpoint path
- **WHEN** the test opens `/apps/buildiq/builder/{slug}` for that app
- **THEN** the `ConnectorDataView` surface renders — its projected table, or its error state with a Retry action
- **AND** no `CnUnknownWidget` placeholder is present for that widget

#### Scenario: An unresolved widget key fails the test

- **GIVEN** the same published fixture with the runtime registry removed from the published entry
- **WHEN** the test runs
- **THEN** it fails on the `Unknown widgetKey "connector-data"` console warning
- **AND** it fails on the absent `ConnectorDataView` surface, so a reworded warning alone cannot make it pass

#### Scenario: Fixture carries no credential

- **GIVEN** the fixture app's `dataSource.connector` binding
- **WHEN** it is validated before publication
- **THEN** it contains none of `headers`, `token`, `apiKey` or `authorization`
- **AND** the fixture is removed after the test run
