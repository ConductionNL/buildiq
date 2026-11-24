# Tasks — buildiq-connector-widget-runtime

## 1. Canonical registry seam (REQ-CWR-001)

- [x] 1.1 Export a canonical `getRuntimeRegistry()` accessor from `src/runtimeRegistry.js` returning the full runtime registry, with a docblock stating that every virtual-app host must pass its output as `CnAppRoot`'s `registry` prop.

Acceptance criteria:
- The accessor returns an object containing `connector-data` whose `component` is `ConnectorDataView`.
- The existing named `runtimeRegistry` export is retained so current importers keep working.

- [x] 1.2 Source the `registry` prop in the published standalone entry `src/builder.js` from the accessor instead of spreading the imported object.

Acceptance criteria:
- `/apps/buildiq/builder/{slug}` mounts `CnAppRoot` with the accessor's output.
- No behaviour change: `connector-data` still resolves ahead of built-ins via the injected `cnRegistry`.

- [x] 1.3 Source the `registry` prop in the preview host `src/views/BuilderHost.vue` from the same accessor.

Acceptance criteria:
- Preview and published key sets are identical.

## 2. Parity guard (REQ-CWR-001)

- [x] 2.1 Add a unit spec asserting that both virtual-app hosts supply the accessor's output as `registry`, failing with the offending host named.

Acceptance criteria:
- Removing the `registry` prop from `src/builder.js` fails this spec.
- The failure message states that registry-only keys would render `CnUnknownWidget` on that path.

- [x] 2.2 Extend the guard to assert the published path's effective registry is a superset of the preview path's.

Acceptance criteria:
- A key added to preview only fails the spec.

## 3. Endpoint-binding matrix (REQ-CWR-002, REQ-CWR-003)

- [x] 3.1 Add a schema-backed unit spec asserting `object-table`, `chart`, `stat`, `delta` and `workspace-filter` accept `endpointSource` at the documented location in `app-manifest-v2.schema.json`.

Acceptance criteria:
- `stat`/`delta` are asserted at `props.content.endpointSource`; `chart` against `$defs/chartEndpointSource`.
- The spec reads the vendored schema rather than restating it.

- [x] 3.2 Assert `stats-block` rejects an `endpointSource` inside `props.entries[]` because `$defs.statsBlockEntry` is `additionalProperties: false`.

Acceptance criteria:
- Validation fails with a message naming the offending entry.

- [x] 3.3 Document the matrix and the when-to-use-which guidance in the app docs, including the `stat`/`delta` alternative for endpoint-backed KPIs.

Acceptance criteria:
- The `stats-block` limitation is stated with its rationale, not as a bug.

- [ ] 3.4 Raise the `stats-block` endpoint-parity item upstream against `@conduction/nextcloud-vue`, referencing the per-entry response-mapping design it needs.

Acceptance criteria:
- No change is made to the vendored schema inside Buildiq.

## 4. Published-path proof (REQ-CWR-004, REQ-OCAS-006)

- [x] 4.1 Add a Playwright spec that creates and publishes a fixture app carrying a `connector-data` widget bound to a placeholder endpoint path with no credential-bearing keys. (written, runs in nightly: the spec sits in tests/e2e and is not verified against a live instance)
  > Written 21 Nov (4.1 to 4.4): `tests/e2e/connector-widget-runtime.spec.ts` builds the fixture app `pw-cwr-fixture` (marked as a Playwright fixture, deleted in `afterAll`) with a dashboard holding two `connector-data` widgets and an endpoint-bound `object-table`, answers the endpoints with `page.route`, asserts the stored bindings carry no credential key, the projected table, the error state with Retry, and the object-table rows at `responsePath` on `/apps/buildiq/builder/{slug}`, and fails on any `CnWidgetGrid` unresolved `widgetKey` warning. Left: running it on an instance.

Acceptance criteria:
- The fixture is self-identifying as test data and is torn down after the run.
- The widget is not placed as a single full-width body widget on a dashboard page (ADR-036 single-custom-widget rule).

- [x] 4.2 Drive `/apps/buildiq/builder/{slug}` and assert the `ConnectorDataView` surface renders — its projected table, or its error state with a Retry action. (written, runs in nightly: the spec sits in tests/e2e and is not verified against a live instance)

Acceptance criteria:
- The assertion targets the widget's own surface, never a generic "something rendered" check.
- No `CnUnknownWidget` placeholder is present for that widget.

- [x] 4.3 Fail the spec on any console warning identifying an unresolved `widgetKey`, matched on the `CnWidgetGrid` prefix together with the key token. (written, runs in nightly: the spec sits in tests/e2e and is not verified against a live instance)

Acceptance criteria:
- Temporarily removing the registry from `src/builder.js` fails on both the console assertion and the DOM assertion.

- [x] 4.4 Add an endpoint-bound `object-table` case to the same fixture, asserting its rows render on the published route. (written, runs in nightly: the spec sits in tests/e2e and is not verified against a live instance)

Acceptance criteria:
- Covers the `props.endpointSource` half of the matrix on the published path.

## 5. Traceability and gates

- [x] 5.1 Add `@spec` anchors on the touched frontend symbols pointing at the new requirements.

Acceptance criteria:
- Gate-16 spec-coverage passes on the diff.

- [x] 5.2 Reference the new scenarios from the e2e specs so gate-19 e2e-coverage resolves every added scenario.
  > Done 21 Nov: the tags in `tests/e2e/connector-widget-runtime.spec.ts` were in a path form gate-19 reads as prose; they are now `@e2e <spec>::<scenario>` anchors, in the header and in the test that proves each one. Two tests were added there: three `connector-data` widgets on one endpoint and query make one request (cache hit), and a failed refresh past the TTL shows the cached rows with the notice and a Retry (stale on error). The stale notice had no Retry, which the scenario asks for: `ConnectorDataView` now offers one beside it (Vitest `tests/components/runtime/ConnectorDataView.spec.js`). The index-page scenario is anchored on the REQ-BQCB-001 test in `tests/e2e/connector-bound-pages.spec.ts`. "Documented matrix" and "Both bindings on one widget are rejected" carry a reason-bearing `@e2e exclude` and are asserted in Vitest (`endpointBindingMatrix.spec.js`, new `endpointBindingExclusive.spec.js`). Gate-19 lists none of this change's scenarios as missing. The e2e specs are written, not run here (no live instance).

Acceptance criteria:
- Scenarios not covered by a test carry a reason-bearing `@e2e exclude`.

- [ ] 5.3 Run the Buildiq quality gates and the vitest + Playwright suites, and fix any pre-existing failures encountered.

Acceptance criteria:
- Gates report a summary line; an aborted run is not accepted as green.
