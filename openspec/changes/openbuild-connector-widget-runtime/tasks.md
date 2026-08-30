# Tasks — buildiq-connector-widget-runtime

## 1. Canonical registry seam (REQ-CWR-001)

- [ ] 1.1 Export a canonical `getRuntimeRegistry()` accessor from `src/runtimeRegistry.js` returning the full runtime registry, with a docblock stating that every virtual-app host must pass its output as `CnAppRoot`'s `registry` prop.

Acceptance criteria:
- The accessor returns an object containing `connector-data` whose `component` is `ConnectorDataView`.
- The existing named `runtimeRegistry` export is retained so current importers keep working.

- [ ] 1.2 Source the `registry` prop in the published standalone entry `src/builder.js` from the accessor instead of spreading the imported object.

Acceptance criteria:
- `/apps/buildiq/builder/{slug}` mounts `CnAppRoot` with the accessor's output.
- No behaviour change: `connector-data` still resolves ahead of built-ins via the injected `cnRegistry`.

- [ ] 1.3 Source the `registry` prop in the preview host `src/views/BuilderHost.vue` from the same accessor.

Acceptance criteria:
- Preview and published key sets are identical.

## 2. Parity guard (REQ-CWR-001)

- [ ] 2.1 Add a unit spec asserting that both virtual-app hosts supply the accessor's output as `registry`, failing with the offending host named.

Acceptance criteria:
- Removing the `registry` prop from `src/builder.js` fails this spec.
- The failure message states that registry-only keys would render `CnUnknownWidget` on that path.

- [ ] 2.2 Extend the guard to assert the published path's effective registry is a superset of the preview path's.

Acceptance criteria:
- A key added to preview only fails the spec.

## 3. Endpoint-binding matrix (REQ-CWR-002, REQ-CWR-003)

- [ ] 3.1 Add a schema-backed unit spec asserting `object-table`, `chart`, `stat`, `delta` and `workspace-filter` accept `endpointSource` at the documented location in `app-manifest-v2.schema.json`.

Acceptance criteria:
- `stat`/`delta` are asserted at `props.content.endpointSource`; `chart` against `$defs/chartEndpointSource`.
- The spec reads the vendored schema rather than restating it.

- [ ] 3.2 Assert `stats-block` rejects an `endpointSource` inside `props.entries[]` because `$defs.statsBlockEntry` is `additionalProperties: false`.

Acceptance criteria:
- Validation fails with a message naming the offending entry.

- [ ] 3.3 Document the matrix and the when-to-use-which guidance in the app docs, including the `stat`/`delta` alternative for endpoint-backed KPIs.

Acceptance criteria:
- The `stats-block` limitation is stated with its rationale, not as a bug.

- [ ] 3.4 Raise the `stats-block` endpoint-parity item upstream against `@conduction/nextcloud-vue`, referencing the per-entry response-mapping design it needs.

Acceptance criteria:
- No change is made to the vendored schema inside Buildiq.

## 4. Published-path proof (REQ-CWR-004, REQ-OCAS-006)

- [ ] 4.1 Add a Playwright spec that creates and publishes a fixture app carrying a `connector-data` widget bound to a placeholder endpoint path with no credential-bearing keys.

Acceptance criteria:
- The fixture is self-identifying as test data and is torn down after the run.
- The widget is not placed as a single full-width body widget on a dashboard page (ADR-036 single-custom-widget rule).

- [ ] 4.2 Drive `/apps/buildiq/builder/{slug}` and assert the `ConnectorDataView` surface renders — its projected table, or its error state with a Retry action.

Acceptance criteria:
- The assertion targets the widget's own surface, never a generic "something rendered" check.
- No `CnUnknownWidget` placeholder is present for that widget.

- [ ] 4.3 Fail the spec on any console warning identifying an unresolved `widgetKey`, matched on the `CnWidgetGrid` prefix together with the key token.

Acceptance criteria:
- Temporarily removing the registry from `src/builder.js` fails on both the console assertion and the DOM assertion.

- [ ] 4.4 Add an endpoint-bound `object-table` case to the same fixture, asserting its rows render on the published route.

Acceptance criteria:
- Covers the `props.endpointSource` half of the matrix on the published path.

## 5. Traceability and gates

- [ ] 5.1 Add `@spec` anchors on the touched frontend symbols pointing at the new requirements.

Acceptance criteria:
- Gate-16 spec-coverage passes on the diff.

- [ ] 5.2 Reference the new scenarios from the e2e specs so gate-19 e2e-coverage resolves every added scenario.

Acceptance criteria:
- Scenarios not covered by a test carry a reason-bearing `@e2e exclude`.

- [ ] 5.3 Run the Buildiq quality gates and the vitest + Playwright suites, and fix any pre-existing failures encountered.

Acceptance criteria:
- Gates report a summary line; an aborted run is not accepted as green.
