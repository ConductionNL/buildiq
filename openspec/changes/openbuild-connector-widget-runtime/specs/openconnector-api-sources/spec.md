# openconnector-api-sources Specification (delta)

## MODIFIED Requirements

### Requirement: REQ-OCAS-006 Runtime fetch path with caching

The system SHALL provide `useConnectorDataSource.js`, used by the virtual-app render path whenever a page or widget declares `dataSource.connector`. "The virtual-app render path" SHALL mean BOTH the published standalone runtime entry serving `/apps/openbuild/builder/{slug}` AND the in-SPA builder preview host; both mount `CnAppRoot` with the runtime registry that resolves the `connector-data` widget key (REQ-CWR-001), and a binding that renders in preview but not on the published route SHALL be treated as a defect. The composable SHALL: issue `GET /apps/openconnector/api/endpoint/{endpointPath}` with the binding's `query` parameters; apply `itemsPath` then the `fields` selectors to produce rows (index pages) or a value object (widgets); expose `loading | data | error | isStale` state; cache responses per binding key (`appId + endpointPath + query hash`) with the binding's `cacheTtl` (default 60 s); serve a stale cached entry (max 10× TTL old) with `isStale: true` when a refresh fails; and render an error state with a retry action when no cache entry exists. Selectors that resolve to no value SHALL yield `null` cells and log a single console warning per field per mount (no render crash).

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

#### Scenario: The binding is exercised on the published route, not only in preview

- **GIVEN** an app published with a `connector-data` widget
- **WHEN** the composable's behaviour is verified
- **THEN** the verification drives `/apps/openbuild/builder/{slug}` — the published standalone entry
- **AND** a preview-only run does not satisfy the requirement
