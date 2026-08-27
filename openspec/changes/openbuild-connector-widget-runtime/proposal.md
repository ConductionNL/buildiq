---
kind: code
depends_on: []
---

# buildiq-connector-widget-runtime — lock the endpoint-binding surface on the PUBLISHED virtual-app path

## Why

Building the `hydra-console` virtual app raised a question nobody could answer
from the specs: **can a widget in a published Buildiq app bind to an
OpenConnector endpoint, or only in the builder preview?** Buildiq ships a
`connector-data` widget whose only documented consumer (REQ-OCAS-006) says it is
"used by the virtual-app render path" — without saying *which* render path. There
are two, and they are wired by two independent call sites.

The code was read before this proposal was written. **The answer is yes:
`connector-data` DOES resolve on the published path.** `src/builder.js` — the
standalone runtime entry served by `DashboardController::builder()` for
`/apps/buildiq/builder/{slug}` — passes `registry: { ...runtimeRegistry }` to
the top-level `CnAppRoot`, `CnAppRoot` provides it to descendants as `cnRegistry`,
and `CnWidgetGrid` resolves `widgetKey` against the consumer registry *before*
built-ins and before the dashboard catalog. The component survives into the
shipped `js/buildiq-builder.js` bundle (its template strings are present), and
the deployed snapshot is byte-identical to the repo bundle.

So the capability is real — but it is **undefended**, and that is the actual
problem worth a change:

1. **Nothing tests it on the published path.** The only automated proof is a
   vitest spec for the composable (`tests/composables/useConnectorDataSource.spec.js`)
   plus the preview host. Delete `registry:` from `builder.js` and every test
   still passes, while every published app silently degrades to `CnUnknownWidget`
   with a single `console.warn` — the exact green-but-dead shape this fleet keeps
   paying for.
2. **The registry is wired twice, by hand.** `src/builder.js` (published) and
   `src/views/BuilderHost.vue` (preview) each pass `runtimeRegistry` in their own
   `CnAppRoot` invocation. Nothing asserts the two stay in parity, so a future
   render path can be added — as `builder.js` itself once was — and quietly ship
   without it.
3. **The endpoint-binding matrix is folklore.** Library-native `props.endpointSource`
   works for some widget keys and not others; `hydra-console` was built on it
   only after that was determined by reading the schema. No spec records which
   keys can bind to an endpoint, so the next app re-derives it or guesses wrong.

Because the answer came back "it resolves", this change is **document, defend and
test** — not "remove `connector-data`". Retiring a working widget in favour of
`endpointSource` would trade a proven binding for one that cannot express
`itemsPath` + `fields` projection, per-binding `cacheTtl`, or stale-on-error, all
of which REQ-OCAS-001/006 already specify and `endpointSource` does not offer.

`kind: code` — the deliverable is a shared registry module, a parity unit guard
and a published-path Playwright spec. No manifest or JSON-schema change is
required for `connector-data` to work; the one schema-shaped item (stats-block
endpoint parity) is resolved here as an explicit **non-change** with a rationale.

## What Changes

1. **One canonical runtime-registry seam.** Every host that mounts a virtual
   app's `CnAppRoot` resolves its registry from a single exported accessor rather
   than each importing `runtimeRegistry` and passing it by hand. The published
   entry (`src/builder.js`) and the preview host (`src/views/BuilderHost.vue`)
   both go through it.
2. **A parity guard.** A unit test asserts that every host mounting a virtual-app
   `CnAppRoot` supplies the runtime registry, and that the published path's
   effective registry is a superset of the preview path's — so preview can never
   again offer a widget the published app cannot render.
3. **A published-path e2e.** A Playwright spec drives a real published app at
   `/apps/buildiq/builder/{slug}` with a `connector-data` widget, and asserts
   the widget's own rendered surface — never `CnUnknownWidget`, and no
   `Unknown widgetKey` console warning. Preview-only coverage is explicitly not
   sufficient.
4. **The endpoint-binding matrix becomes normative.** The set of widget keys that
   may bind to an OpenConnector endpoint on the published path is written down
   and locked: `connector-data` (via `dataSource.connector`), and the
   library-native `props.endpointSource` keys `object-table`, `chart`,
   `stat`, `delta` and `workspace-filter`.
5. **`stats-block` stays register-bound — deliberately.** Its `props.entries[]`
   items validate against `$defs.statsBlockEntry`, which is
   `additionalProperties: false` and *requires* `register` + `schema`. Endpoint
   parity for it is recorded as an upstream `@conduction/nextcloud-vue` concern
   with a stated rationale, not silently worked around in Buildiq.
6. **The unresolved-key failure mode is made loud enough to catch.** An
   unresolved `widgetKey` currently yields `CnUnknownWidget` plus one
   `console.warn`; the e2e treats that warning as a hard failure so the
   green-but-dead pattern cannot pass CI.

No breaking changes: `connector-data` keeps its key, its registry entry shape and
its `dataSource.connector` contract. Existing published apps are unaffected.

## Capabilities

### New Capabilities
- `connector-widget-runtime`: the runtime-registry seam shared by every
  virtual-app render path, the normative endpoint-binding matrix (which
  `widgetKey`s may bind to an OpenConnector endpoint, and by which mechanism),
  and the published-path proof obligation for both.

### Modified Capabilities
- `openconnector-api-sources`: REQ-OCAS-006 currently says `useConnectorDataSource`
  is used by "the virtual-app render path" without distinguishing the published
  standalone entry from the builder preview, and none of its scenarios exercise
  `/apps/buildiq/builder/{slug}`. Tightened to name the published path
  explicitly and to require the proof to run there.

## Impact

- `src/runtimeRegistry.js` — gains the canonical accessor other hosts consume.
- `src/builder.js` — the published standalone runtime entry; its `registry` prop
  is sourced from the accessor.
- `src/views/BuilderHost.vue` — the preview host; same.
- `src/components/runtime/ConnectorDataView.vue`,
  `src/composables/useConnectorDataSource.js` — unchanged behaviour; now covered
  on the published path.
- `tests/e2e/` — one new published-path spec; `tests/` — one new parity unit spec.
- Depends on OpenConnector being installed and exposing an endpoint; absence is
  already covered by REQ-OCAS-005 graceful degradation.
- Upstream `@conduction/nextcloud-vue`: `$defs.statsBlockEntry` endpoint parity is
  raised there, not patched here.
