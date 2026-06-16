## ADDED Requirements

### Requirement: REQ-OCAS-001 Connector data-source form in the v2 manifest

The system SHALL support a third `dataSource` form, `dataSource.connector`, carried inside the manifest v2 `dataSource` $def (which declares `additionalProperties: true`), with the shape:

- `endpointPath` (string, required) — the OpenConnector endpoint path consumed at `/apps/openconnector/api/endpoint/{endpointPath}`; MUST NOT contain a scheme, host, or credentials.
- `method` (closed enum `GET`, optional, default `GET`) — v1 read-bindings are GET-only.
- `query` (object of scalar values, optional) — query parameters appended to the call.
- `itemsPath` (string dot-path, optional) — selector for the list root inside the response; absent means the response root is the single item.
- `fields` (object, required, min 1 entry) — map of display-field name → dot-path selector into an item, using the same selector grammar as the existing `dataSource.graphql.selectors`.
- `cacheTtl` (integer seconds, optional, default 60, bounded 0–3600).

OpenBuild's manifest validation layer (`useManifestValidator` pipeline) SHALL strictly validate this shape and reject unknown keys inside `connector`, exclusivity violations (a `dataSource` declaring `connector` together with `register`/`schema` or `graphql`), and `endpointPath` values containing `://`. A binding MUST NOT carry any credential-bearing key (`headers`, `token`, `apiKey`, `authorization` are explicitly rejected). Codification of the form into the canonical `app-manifest-v2.schema.json` $def is an external `nextcloud-vue` follow-up and NOT part of this requirement.

#### Scenario: Valid connector binding passes validation

- **GIVEN** an in-flight manifest where a dashboard widget declares `dataSource: { connector: { endpointPath: "kvk/companies", query: { city: "Utrecht" }, itemsPath: "resultaten", fields: { name: "naam", kvk: "kvkNummer" } } }`
- **WHEN** the validator pass runs
- **THEN** no error is reported for the binding
- **AND** the serialized manifest round-trips the block byte-identically

#### Scenario: Credential-bearing key is rejected

- **WHEN** a connector binding (authored via the Raw JSON tab) carries `headers: { Authorization: "Bearer x" }`
- **THEN** the validator marks the binding with the error `openbuild.connector.error.credentials-forbidden`
- **AND** the Save button is disabled until the key is removed

#### Scenario: Mixed-form dataSource is rejected

- **WHEN** a `dataSource` declares both `connector.endpointPath` and the `register`/`schema` shorthand
- **THEN** the validator reports an exclusivity error naming both forms
- **AND** the side-panel error links to the offending page/widget

### Requirement: REQ-OCAS-002 Builder step: bind an OpenConnector endpoint as a data source

The page designer SHALL extend the index-page and dashboard-widget data-binding step with a **data-source origin toggle** (`OpenRegister` | `OpenConnector`, default `OpenRegister`). Selecting `OpenConnector` SHALL mount `ConnectorSourcePicker.vue`, which lists the configured OpenConnector endpoints (fetched live from OpenConnector's REST API), shows each endpoint's path and target Source name (never its credentials), and on selection writes `dataSource.connector.endpointPath` into the in-flight manifest and triggers the sample fetch for REQ-OCAS-003. Switching the toggle back to `OpenRegister` SHALL clear the `connector` block (with a confirm prompt when a mapping already exists).

#### Scenario: Picking an endpoint binds it to an index page

- **GIVEN** OpenConnector is installed and exposes an endpoint `kvk/companies`
- **WHEN** the builder edits an index page, flips the origin toggle to `OpenConnector`, and selects `kvk/companies` from the picker
- **THEN** the in-flight manifest's page config gains `dataSource.connector.endpointPath: "kvk/companies"`
- **AND** the register/schema pickers are hidden
- **AND** a sample fetch is issued to populate the mapping editor

#### Scenario: Toggling back to OpenRegister clears the binding after confirmation

- **GIVEN** an index page with a complete connector binding including three mapped fields
- **WHEN** the builder flips the origin toggle to `OpenRegister`
- **THEN** a confirm dialog warns that the connector mapping will be discarded
- **AND** on confirm, the `connector` block is removed and the register/schema pickers mount empty

### Requirement: REQ-OCAS-003 Schema mapping of the external payload to display fields

The system SHALL provide `ConnectorFieldMapper.vue`, mounted under the picker, which: (a) renders the sample response as a collapsible JSON tree; (b) lets the builder click an array node to set `itemsPath`; (c) lets the builder click leaf nodes inside an item to append `fields` entries, prompting for the display-field name (pre-filled from the leaf key); (d) shows the resulting column/field list with live sample values; and (e) round-trips an existing mapping losslessly when re-opening the editor. A "Re-fetch sample" action SHALL re-run the sample call and flag every mapped selector that no longer resolves against the fresh payload.

#### Scenario: Click-to-map builds itemsPath and fields

- **GIVEN** a sample response `{ "resultaten": [ { "naam": "Acme", "kvkNummer": "123" } ], "totaal": 1 }`
- **WHEN** the builder clicks the `resultaten` array node and then the `naam` and `kvkNummer` leaves, accepting the suggested names
- **THEN** the manifest binding contains `itemsPath: "resultaten"` and `fields: { naam: "naam", kvkNummer: "kvkNummer" }`
- **AND** the preview list shows one row with `Acme` / `123`

#### Scenario: Re-fetch flags a dead selector

- **GIVEN** a saved mapping with `fields.kvk: "kvkNummer"` and an upstream API that renamed the property to `kvk_nummer`
- **WHEN** the builder clicks "Re-fetch sample"
- **THEN** the mapper marks the `kvk` field row with a warning that its selector resolved to no value in the fresh sample
- **AND** the mapping is NOT silently modified

### Requirement: REQ-OCAS-004 Auth delegated entirely to OpenConnector

OpenBuild SHALL NOT store, render, request, or transmit any external-API credential. All outbound authentication (API keys, OAuth, basic auth, mTLS) lives in OpenConnector Source objects, configured in OpenConnector's own UI. The runtime call from a built app SHALL be a same-origin request to `/apps/openconnector/api/endpoint/{endpointPath}` authenticated only by the caller's Nextcloud session and requesttoken. The picker SHALL display endpoint path and Source *name* only; if OpenConnector's list payload includes credential material, the picker MUST NOT render or persist it. The manifest, the OR-persisted Application object, and openbuild's localStorage/sessionStorage SHALL contain no secret material for connector bindings (enforced by the REQ-OCAS-001 validator and a dedicated test).

#### Scenario: Manifest stays credential-free end to end

- **GIVEN** an endpoint whose Source authenticates with an API key configured in OpenConnector
- **WHEN** the builder binds the endpoint, maps fields, saves, and the runtime renders the page
- **THEN** the saved Application object's manifest contains only `endpointPath`, `query`, `itemsPath`, `fields`, `cacheTtl`
- **AND** the browser's outbound request carries no `Authorization` header for the external API (only NC session cookies + requesttoken to the same-origin OpenConnector route)

#### Scenario: Designer never displays credentials

- **WHEN** the ConnectorSourcePicker lists endpoints
- **THEN** each row shows the endpoint path and Source display name only
- **AND** no credential, header, or token value appears in the DOM or in the component's persisted state

### Requirement: REQ-OCAS-005 Capability check and graceful degradation when OpenConnector is absent

When a virtual app contains at least one connector binding, the system SHALL ensure `"openconnector"` is present in the manifest v2 `dependencies[]` array on save (and SHALL remove it on save when the last connector binding is deleted, unless the builder added it manually). At design time, when `useAppStatus('openconnector')` reports the app missing or disabled, the origin toggle's OpenConnector option SHALL render disabled with the i18n hint `openbuild.connector.hint.openconnector-missing`, and an "enter endpoint path manually" escape hatch SHALL allow authoring an unverified binding (marked with a non-blocking warning). At runtime, a missing OpenConnector SHALL surface through CnAppRoot's standard dependency gate; if a page nevertheless renders (e.g. dependency check bypassed), the resolver's 404 SHALL produce the per-widget error state of REQ-OCAS-006, never a blank page or uncaught exception.

#### Scenario: Dependency auto-added on save

- **WHEN** the builder saves a manifest whose only connector binding was just added
- **THEN** the persisted manifest's `dependencies` array contains `"openconnector"` exactly once

#### Scenario: Designer degrades when OpenConnector is missing

- **GIVEN** OpenConnector is not installed on the instance
- **WHEN** the builder opens the data-binding step
- **THEN** the OpenConnector origin option is disabled with the missing-app hint
- **AND** the manual-path escape hatch still allows authoring a binding, flagged "cannot be verified on this instance"

#### Scenario: Runtime gate for end users

- **GIVEN** a published virtual app with a connector binding and `dependencies: ["openconnector"]`, on an instance where OpenConnector was disabled after publication
- **WHEN** an end user opens the app
- **THEN** CnAppRoot's missing-dependency screen renders, naming OpenConnector
- **AND** no fetch to `/apps/openconnector/...` is attempted

### Requirement: REQ-OCAS-006 Runtime fetch path with caching

The system SHALL provide `useConnectorDataSource.js`, used by the virtual-app render path whenever a page or widget declares `dataSource.connector`. The composable SHALL: issue `GET /apps/openconnector/api/endpoint/{endpointPath}` with the binding's `query` parameters; apply `itemsPath` then the `fields` selectors to produce rows (index pages) or a value object (widgets); expose `loading | data | error | isStale` state; cache responses per binding key (`appId + endpointPath + query hash`) with the binding's `cacheTtl` (default 60 s); serve a stale cached entry (max 10× TTL old) with `isStale: true` when a refresh fails; and render an error state with a retry action when no cache entry exists. Selectors that resolve to no value SHALL yield `null` cells and log a single console warning per field per mount (no render crash).

#### Scenario: Index page renders external rows

- **GIVEN** a published app whose index page binds `kvk/companies` with `itemsPath: "resultaten"` and two mapped fields
- **WHEN** an end user opens the page and OpenConnector returns 200 with three items
- **THEN** the index table renders three rows with the two mapped columns
- **AND** the response is cached under the binding key

#### Scenario: Cache hit suppresses duplicate calls

- **GIVEN** a dashboard with three widgets bound to the same endpoint and query
- **WHEN** the dashboard mounts
- **THEN** at most one request reaches `/apps/openconnector/api/endpoint/kvk/companies` within the TTL window
- **AND** all three widgets render from the shared cache entry

#### Scenario: Stale-on-error fallback

- **GIVEN** a cache entry 90 seconds old (TTL 60 s) and an upstream API now returning 502 through OpenConnector
- **WHEN** the page re-renders and the refresh fails
- **THEN** the cached rows render with a visible "showing cached data" badge (`isStale: true`)
- **AND** a retry affordance is offered

#### Scenario: Error state without cache

- **GIVEN** no cache entry for the binding
- **WHEN** the fetch fails with a 5xx
- **THEN** the page/widget renders the standard error state with the endpoint path and a Retry button
- **AND** no uncaught promise rejection reaches the console
