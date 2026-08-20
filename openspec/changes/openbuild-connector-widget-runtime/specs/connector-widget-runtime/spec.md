# connector-widget-runtime Specification

## ADDED Requirements

### Requirement: REQ-CWR-001 Single runtime-registry seam for every virtual-app host

Every OpenBuild host that mounts a virtual app's `CnAppRoot` MUST obtain its runtime component registry from one canonical accessor exported by `src/runtimeRegistry.js`, and MUST pass that value as the `registry` prop; no host may construct, filter or inline its own registry object. The accessor SHALL return the full runtime registry — at minimum the `connector-data` widget entry — so that the set of widget keys a virtual app can render is identical no matter which host mounted it. `CnAppRoot` re-exposes the prop to descendants as the injected `cnRegistry`, which `CnWidgetGrid` resolves `widgetKey` against ahead of the library built-ins and the dashboard widget catalog; a host that omits the prop therefore degrades every registry-only widget to `CnUnknownWidget` with nothing but a `console.warn`, which SHALL be treated as a defect rather than a tolerated fallback.

#### Scenario: Published standalone entry supplies the registry

- **GIVEN** the standalone runtime entry `src/builder.js`, which serves `/apps/openbuild/builder/{slug}`
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

OpenBuild SHALL treat the following as the closed, normative set of ways a widget may bind to an OpenConnector endpoint on the published virtual-app path, and MUST NOT introduce a further mechanism without amending this requirement. The `connector-data` widget key binds through a widget-level `dataSource.connector` block (REQ-OCAS-001) and is resolved from the runtime registry. The library-native `props.endpointSource` binding is available to the widget keys `object-table`, `stat`, `delta` and `workspace-filter` — for `stat` and `delta` it is nested at `props.content.endpointSource` — and to `chart` through the extended `props.endpointSource` shape (`$defs/chartEndpointSource`). No other widget key may declare an endpoint binding. Authoring guidance SHALL state that `props.endpointSource` is the choice when the endpoint already returns render-shaped rows and the built-in widget's chrome is wanted, and that `connector-data` is the choice when the payload needs `itemsPath` + `fields` projection or when the per-binding `cacheTtl` and stale-on-error contract of REQ-OCAS-006 is required.

#### Scenario: Documented matrix matches the canonical manifest schema

- **GIVEN** `@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`
- **WHEN** the endpoint-capable widget keys named in this requirement are checked against it
- **THEN** each of `object-table`, `chart`, `stat`, `delta` and `workspace-filter` accepts an `endpointSource` at the stated location
- **AND** no widget key outside this requirement's list accepts one

#### Scenario: An endpoint-bound object-table renders on the published path

- **GIVEN** a published app whose page places an `object-table` widget declaring `props.endpointSource`
- **WHEN** an end user opens the page at `/apps/openbuild/builder/{slug}`
- **THEN** the table renders rows plucked from the endpoint payload at `responsePath`
- **AND** no `Unknown widgetKey` warning is emitted

#### Scenario: Both bindings on one widget are rejected

- **GIVEN** a widget declaring an OpenRegister source and an `endpointSource` at the same time
- **WHEN** manifest validation runs
- **THEN** the manifest is rejected with an error naming the widget and stating that exactly one data binding is allowed

### Requirement: REQ-CWR-003 `stats-block` remains register-bound, with a stated rationale

The `stats-block` widget key MUST NOT accept an endpoint binding, and OpenBuild MUST NOT work around this by patching the vendored manifest schema or by injecting endpoint data into `props.entries[]`. Its entries validate against `$defs.statsBlockEntry`, which declares `additionalProperties: false` and requires `register` and `schema`, so an `endpointSource` key inside an entry is a schema violation rather than an omission. Endpoint parity for `stats-block` SHALL be recorded as an upstream `@conduction/nextcloud-vue` concern together with the reason it is a design and not a key addition — each entry is a per-schema object count (`metric`, `field`, `filter`, `hideWhenZero`) and would need its own per-entry response mapping. Authors needing endpoint-backed KPIs SHALL be directed to individual `stat` or `delta` widgets, which do accept `props.content.endpointSource`.

#### Scenario: An endpoint-bound stats-block entry is rejected

- **GIVEN** a manifest whose `stats-block` widget declares `props.entries[0].endpointSource`
- **WHEN** the manifest is validated against the canonical v2 schema
- **THEN** validation fails because `$defs.statsBlockEntry` forbids additional properties
- **AND** the app cannot be published with that widget

#### Scenario: Author is directed to the supported alternative

- **GIVEN** an author who needs an endpoint-backed KPI tile
- **WHEN** they consult the endpoint-binding guidance
- **THEN** they are directed to a `stat` or `delta` widget with `props.content.endpointSource`
- **AND** the reason `stats-block` is register-only is stated rather than left implicit

### Requirement: REQ-CWR-004 Published-path proof obligation

A registry-resolved widget key SHALL be proven to render on the published virtual-app route `/apps/openbuild/builder/{slug}`, and preview-only coverage MUST NOT be accepted as proof. The proof SHALL assert the widget's own rendered surface — for `connector-data`, either its projected data table or its error-with-retry state when OpenConnector cannot serve the binding (REQ-OCAS-005) — and SHALL additionally fail on any browser console warning identifying an unresolved `widgetKey`, because `CnUnknownWidget` is itself a designed tile and a presence-only assertion would pass against it. The test fixture SHALL be created and torn down by the test itself, SHALL be identifiable as test data, and SHALL carry no credential-bearing key in its connector binding.

#### Scenario: connector-data resolves on the published route

- **GIVEN** a published fixture app with a page placing a `connector-data` widget bound to a placeholder endpoint path
- **WHEN** the test opens `/apps/openbuild/builder/{slug}` for that app
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
