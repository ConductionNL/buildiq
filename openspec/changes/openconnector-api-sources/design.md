## Context

Virtual apps render through the ADR-024 CnAppRoot manifest shell. Data binding is declarative: the v2 manifest's `dataSource` $def currently supports (a) the `{ register, schema, filter?, aggregate }` shorthand resolved against OpenRegister and (b) the raw `{ graphql }` form. Both terminate in OpenRegister. External APIs have no binding form, although OpenConnector — installed alongside openbuild in every Conduction deployment profile — already executes configured external calls behind `GET /apps/openconnector/api/endpoint/{_path}` with credentials held in its Source objects.

The design question is therefore not "how do we call external APIs" (OpenConnector answers that) but "how does a citizen developer *declare* an OpenConnector binding in a manifest, map the foreign payload to display fields, and have the runtime resolve it safely when OpenConnector may be absent".

## Goals / Non-Goals

**Goals:**

- Declarative, manifest-carried binding of an OpenConnector endpoint to index pages and dashboard/detail widgets.
- Visual mapping of an arbitrary JSON payload onto display fields without writing selectors by hand (sample-driven, click-to-map).
- Zero credential handling in openbuild — not stored, not displayed, not proxied.
- Graceful behaviour at both design time and runtime when OpenConnector is missing or the endpoint errors.
- Read-path caching that keeps repeated page renders from hammering external APIs.

**Non-Goals:**

- **Write-bindings UX** — form pages can already POST to arbitrary `submitEndpoint` URLs; connector-aware write UX is OQ-1, deferred.
- **Configuring OpenConnector itself** — Sources, Endpoints, auth, rate limits, and SSRF posture are authored in OpenConnector's own UI by an integrator. openbuild only *selects* an existing endpoint.
- **Synchronization** — pulling external data *into* OpenRegister is OpenConnector's synchronization feature; this change is live read-through, not sync.
- **Pagination contract normalization** — v1 renders whatever one endpoint call returns (`itemsPath` selects the list); cursor/offset pagination mapping is deferred.

## Decisions

### Decision 1 — Carry the binding as a third `dataSource` form, not a new manifest key

`dataSource.connector = { endpointPath, method?, query?, itemsPath?, fields, cacheTtl? }` rides under the existing $def's `additionalProperties: true`.

**Rationale**: the $def is already the single place CnWidgetGrid/CnIndexPage look for data bindings; a sibling key (`externalSource`) would fork the resolution path and double the designer surface. The canonical-schema codification is a one-line additive $def change in `nextcloud-vue`, filed as a follow-up — until it lands, validation passes (additional properties allowed) and openbuild's own validator layer enforces the shape strictly.

**Alternatives considered**:
- *New top-level `pages[].config.externalSource`* — rejected: forks the binding model; widgets and pages would resolve data from two different keys.
- *Mint a synthetic OR register that proxies the API* — rejected: that is exactly OpenConnector's synchronization feature; duplicating it as a hidden proxy register violates ADR-022.

### Decision 2 — Frontend-direct fetch through OpenConnector; no openbuild PHP proxy

`useConnectorDataSource` fetches `/apps/openconnector/api/endpoint/{endpointPath}` from the browser with the NC session + requesttoken, exactly like any first-party app frontend.

**Rationale**: an openbuild controller that forwards to OpenConnector would be a literal pass-through (redundant-controller gate, ADR-022) and would *become* a credential-adjacent surface. OpenConnector already enforces auth on its endpoint route and holds the outbound credentials.

**Alternatives considered**:
- *PHP-side resolve during manifest serve* — rejected: turns every page load into server-side external calls, blocks the manifest endpoint on third-party latency, and complicates caching.

### Decision 3 — Sample-driven mapping with dot-path selectors

The mapper fetches one sample response at design time, renders the JSON as a tree, and the builder clicks nodes to produce `itemsPath` (list root) and `fields: { displayName: "dot.path" }` entries. Selector grammar = the dot-path subset already used by the v1 `dataSource.graphql.selectors` map (`a.b.c`, numeric indices allowed) — no new expression language.

**Rationale**: reuses the selector semantics the lib already executes; analysts never hand-write JSONPath; the manifest stays declarative and auditable.

### Decision 4 — Capability check at three layers

1. **Manifest**: `dependencies[]` gains `"openconnector"` automatically when ≥1 connector binding exists (CnAppRoot's standard `useAppStatus` gate — end users of a broken install get the lib's built-in missing-dependency screen, not a blank page).
2. **Designer**: the origin toggle soft-checks `useAppStatus('openconnector')`; when absent, the OpenConnector option renders disabled with an i18n hint and a free-text endpoint-path fallback is offered (binding can be authored ahead of installation, flagged as unverifiable).
3. **Runtime resolver**: a 404/501 from the OpenConnector route resolves to the error state (with stale-cache fallback), never an uncaught rejection.

### Decision 5 — Client-side TTL cache, stale-on-error

Per-binding cache key = `appId + endpointPath + query hash`; default TTL 60 s, overridable per binding via `cacheTtl` (bounded 0–3600). On fetch failure, a stale entry (≤10× TTL old) is served with a visible "showing cached data" badge; otherwise the error state renders with a retry affordance.

**Rationale**: dashboards re-render widgets aggressively; without a floor, a 6-widget dashboard bound to one API issues 6+ calls per visit. Client-side is sufficient for v1 (per-user data correctness is guaranteed because OpenConnector applies the caller's session); shared server-side caching is OpenConnector's domain (OQ-2).

## Risks / Trade-offs

- **Schema codification lag** (`nextcloud-vue` follow-up not yet merged): the canonical validator can't *reject* a malformed connector block, only openbuild's layer can. Mitigated by strict app-side validation + the filed follow-up.
- **Endpoint list availability**: if the deployed OpenConnector lacks a consumable endpoint-list route, the picker degrades to free-text path entry (REQ-OCAS-005) — worse UX, same manifest output. Verified during apply.
- **External payload drift**: the upstream API changing shape breaks mappings silently (empty columns). Mitigated by the mapper's "re-fetch sample & diff against mapping" check and runtime empty-selector warnings; full contract monitoring is out of scope.
