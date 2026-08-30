# Design — buildiq-connector-widget-runtime

## Context

A virtual app can be mounted in two places, by two independent call sites:

| Path | Entry | Route | Registry wiring |
|---|---|---|---|
| **Published** | `src/builder.js` | `/apps/buildiq/builder/{slug}` → `DashboardController::builder()` → `templates/builder.php` | `registry: { ...runtimeRegistry }` on the top-level `CnAppRoot` |
| **Preview** | `src/views/BuilderHost.vue` | inside the Buildiq SPA (`src/main.js`) | `:registry="runtimeRegistry"` on a nested `CnAppRoot` |

Both were read before this design was written. The resolution chain on the
published path is:

1. `src/builder.js` passes `registry` to `CnAppRoot`.
2. `CnAppRoot` re-exposes it to all descendants via `provide()` as `cnRegistry`
   (`CnAppRoot.vue2.js`, `cnRegistry: this.registry`).
3. `CnWidgetGrid` computes `effectiveRegistry = this.registry ?? this.cnRegistry ?? {}`
   and resolves `widget.widgetKey` against it **first** — consumer registry, then
   `BUILT_IN_WIDGETS`, then the dashboard widget catalog (`getWidgetTypeEntry`).
4. Only if all three miss does it push `CnUnknownWidget` and emit
   `[CnWidgetGrid] Unknown widgetKey "…"` as a `console.warn`.

`connector-data` is registered in `src/runtimeRegistry.js` as
`widget(ConnectorDataView, ['body', 'sidebar'])`, and `ConnectorDataView`'s own
template strings survive into the shipped `js/buildiq-builder.js` bundle
(checked directly; the deployed snapshot under
`openregister/custom_apps/buildiq/js/` is byte-identical to the repo bundle).

**Conclusion: the published path resolves `connector-data` today.** The change is
therefore not a repair. It is the defence of an undefended capability, plus the
codification of the endpoint-binding matrix that `hydra-console` had to derive by
reading `app-manifest-v2.schema.json` by hand.

Constraint worth stating: step 4's failure mode is *quiet*. A missing registry
produces a designed placeholder tile and one console line — no exception, no
network error, no failing assertion in any existing test. That is precisely the
green-but-dead shape the fleet keeps re-paying for, and it is why the proof
obligation in this change is a published-path e2e rather than more unit coverage.

## Goals / Non-Goals

**Goals:**

- A single seam that every virtual-app host resolves its runtime registry from,
  so "which widgets a published app can render" has one answer, not one per host.
- A machine-checkable parity invariant between the published and preview paths.
- A normative, written-down matrix of which `widgetKey`s may bind to an
  OpenConnector endpoint on the published path, and by which mechanism.
- A published-path test that fails if `connector-data` degrades to
  `CnUnknownWidget`, treating the `Unknown widgetKey` console warning as fatal.

**Non-Goals:**

- Retiring `connector-data`. It is reachable and it expresses things
  `endpointSource` cannot (see Decision 2).
- Adding `endpointSource` support to `stats-block` from inside Buildiq (see
  Decision 3) — that is an upstream `@conduction/nextcloud-vue` schema change.
- Changing `useConnectorDataSource`'s fetch, projection, caching or
  stale-on-error behaviour. REQ-OCAS-006 already specifies those and they are
  already unit-covered.
- Write bindings. `dataSource.connector` is GET-only per REQ-OCAS-001 and stays so.
- A visual editor for connector bindings — that is REQ-OCAS-002's territory.

## Decisions

### Decision 1 — One accessor, not two imports

Both hosts import `runtimeRegistry` and hand it to `CnAppRoot` themselves. The
published entry was added *after* the preview host, and only got the prop because
someone remembered. Replacing the two hand-wirings with one exported accessor
(`getRuntimeRegistry()` in `src/runtimeRegistry.js`) makes forgetting it a
structural mistake rather than an omission, and gives the parity test one symbol
to assert against.

*Alternative considered:* leave the two imports and only add the e2e. Rejected —
the e2e proves today's behaviour but does not stop a third host being added
without a registry. The fleet has already been bitten by exactly that shape
(`BuilderHost` → `builder.js` split).

*Alternative considered:* make `CnAppRoot` warn loudly when mounted without a
registry. Rejected as the primary fix — it is an upstream library change, it
cannot know whether a given app *needs* a custom registry, and Buildiq would
still be relying on a console warning nobody reads.

### Decision 2 — Keep `connector-data`; do not standardise on `endpointSource`

`endpointSource` (`$defs/endpointSource`: `url`, `method`, `params`,
`responsePath`) covers "fetch an array and hand it to the widget". It cannot
express what `dataSource.connector` specifies in REQ-OCAS-001: the
`itemsPath` + `fields` dot-path projection, the per-binding `cacheTtl`, the
stale-on-error window (10× TTL) with `isStale`, or the credential-forbidden
validation Buildiq enforces in `services/manifestValidation/connectorDataSource.js`.
Retiring the working widget would trade a specified, validated, cached binding
for a thinner one.

They are complementary, and the matrix says so:

| Mechanism | Widget keys | Binding location |
|---|---|---|
| `dataSource.connector` → `connector-data` | `connector-data` | widget-level `dataSource` |
| `props.endpointSource` | `object-table`, `stat`, `delta`, `workspace-filter` | `props.endpointSource` (`stat`/`delta`: `props.content.endpointSource`) |
| `props.endpointSource` (chart variant) | `chart` | `props.endpointSource` → `$defs/chartEndpointSource` |
| **none — register-bound only** | `stats-block` | `props.entries[]` → `$defs/statsBlockEntry` |

Guidance that follows from it: use `endpointSource` when the endpoint already
returns render-shaped rows and the built-in widget's chrome is wanted; use
`connector-data` when the payload needs projecting, or when the caching /
stale-on-error contract matters.

### Decision 3 — `stats-block` stays register-bound, deliberately

`$defs.statsBlockEntry` is `additionalProperties: false` with
`required: ["register", "schema"]`. An `endpointSource` key inside an entry is a
hard schema violation, not an oversight to route around. A KPI strip is also the
one widget whose entries are individually *counted* against an OpenRegister
schema (`metric`, `field`, `filter`, `hideWhenZero`), so an endpoint binding would
need its own per-entry response mapping — a genuine upstream design, not a key
addition. Recorded here as an upstream item with rationale so the next app does
not re-discover the wall; Buildiq does not patch the vendored schema.

*Workaround for authors who need endpoint-backed KPIs today:* place individual
`stat` / `delta` widgets, which do accept `props.content.endpointSource`.

### Decision 4 — The proof runs on the published route, and console warnings are fatal

The test drives `/apps/buildiq/builder/{slug}` — the real
`DashboardController::builder()` response — not the SPA preview. It asserts the
widget's *own* rendered surface (its table, or its error/retry state when
OpenConnector has nothing to serve), and additionally fails on any
`Unknown widgetKey` console warning. Asserting only "something rendered" would
pass against `CnUnknownWidget`, which is itself a designed tile.

*Alternative considered:* a jsdom/vitest mount of `builder.js`. Rejected — it
would re-test the registry object, not the shipped bundle, and the failure mode
this guards against (a bundler or a refactor dropping the wiring) only shows up
in the real page.

### Decision 5 — Declarative vs imperative (ADR-031)

This change adds **no** business logic in either form. The binding it protects is
already declarative: `dataSource.connector` is manifest data, resolved at render
time by a library-driven registry lookup, and validated declaratively against
`app-manifest-v2.schema.json` plus Buildiq's connector validator. No state
machine, aggregation, calculation or notification is introduced, so there is
nothing to move from a service class into `x-openregister-*` schema metadata.

The one imperative element is the registry accessor itself. That is deliberate
and consistent with ADR-031's boundary: ADR-031 governs *business* logic, while a
component registry is a frontend render-time wiring concern — the same category
as `defaultPageTypes`, which the library also passes as a plain object. Making it
declarative (e.g. a manifest-declared component map) would let a virtual app
name arbitrary components, which is a capability question, not a rendering one,
and is out of scope.

### Decision 6 — Seed Data (ADR-001)

**No OpenRegister schema is introduced or modified by this change**, so ADR-001's
seed-data obligation does not attach — it falls under the stated exception for
changes that only touch frontend components and non-schema logic. Buildiq's
`Application` / `BuiltAppRoute` schemas are untouched.

The e2e does need a **published app fixture** carrying a `connector-data` widget.
That fixture is test data, not product seed data, and is handled accordingly:

- Created and torn down by the spec's own setup against the existing
  `/api/applications` endpoints — never left behind in a shared register.
- Fictional and self-identifying per ADR-001's marker convention, so a fixture
  that escapes teardown is recognisable as test data rather than a user's app.
- Its connector binding points at a placeholder endpoint path (e.g.
  `example/items`) with **no** credential-bearing keys — `dataSource.connector`
  forbids `headers` / `token` / `apiKey` / `authorization` by validation
  (REQ-OCAS-001), so the fixture cannot carry a secret even by accident, and the
  spec asserts nothing that requires one.

## Risks / Trade-offs

- **The e2e depends on OpenConnector being installed on the test instance.** →
  The assertion is written against the widget's *own* surface in both outcomes:
  rendered rows when the endpoint answers, or `ConnectorDataView`'s error+retry
  state when it does not. Both prove resolution; neither is `CnUnknownWidget`.
  REQ-OCAS-005 already specifies the degraded case.
- **Console-warning-as-failure is brittle if the library changes its wording.** →
  Assert on the stable `widgetKey` token and the `CnWidgetGrid` prefix together,
  and pair the console check with a positive DOM assertion so a reworded warning
  cannot turn the test green-but-dead in its own right.
- **The parity test can rot into a tautology** if it merely re-imports the same
  module both hosts import. → It asserts on the hosts' *mount configuration*
  (that each supplies the accessor's output), not on the module's contents.
- **`connector-data` on a dashboard page as a single 12×12 body widget is
  rejected** by `validateManifestV2`'s single-custom-widget anti-pattern rule
  (ADR-036 Decision 1). → The matrix documents this; the fixture places it
  alongside a second widget, or narrower than full width.
- **Two binding mechanisms is more surface to teach**, and an author can pick the
  wrong one. → Mitigated by making the matrix normative with explicit
  when-to-use-which guidance, rather than leaving it as folklore.
- **Upstream `stats-block` parity may never land**, leaving one widget
  permanently register-only. → Acceptable: `stat` / `delta` cover the
  endpoint-backed KPI case, and the limitation is now written down instead of
  being rediscovered per app.

## Migration Plan

No data migration and no manifest migration. `connector-data`, its registry key,
its entry shape and its `dataSource.connector` contract are unchanged, so every
existing published app renders exactly as before. Rollback is reverting the
commit: the accessor is an internal frontend refactor with no persisted state
and no API surface.

Deploy order is the ordinary Buildiq frontend flow — rebuild the bundles and
redeploy; the published route serves `js/buildiq-builder.js`, so a stale bundle
would keep the old (working) wiring rather than break.

## Open Questions

- Should the upstream `stats-block` endpoint-parity item be filed against
  `@conduction/nextcloud-vue` as part of this change, or batched with the next
  manifest-schema wave? The decision here does not depend on the answer.
- Should a future change surface the endpoint-binding matrix inside the page
  designer (offering only endpoint-capable widget keys when an author picks an
  endpoint source), or is a normative spec entry sufficient for now?
