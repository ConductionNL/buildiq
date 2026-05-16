## ADDED Requirements

### Requirement: REQ-OBAI-001 Insights endpoint returns KPIs and activity timeline for a version

The system SHALL expose
`GET /index.php/apps/openbuilt/api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d`,
returning a single JSON payload containing four KPI scalars and an activity
timeline scoped to the named ApplicationVersion's per-version register.

Path parameters:

- `appUuid` — Application UUID. Unknown UUID → `404`.
- `versionUuid` — ApplicationVersion UUID. Unknown UUID, or a version whose
  `application` relation does not match `appUuid` → `404`.

Query parameter:

- `window` — REQUIRED. One of `7d`, `30d`, `90d`. Missing or invalid → `400` with
  body `{"status": 400, "message": "Invalid window parameter; expected one of: 7d, 30d, 90d"}`.

Response on success (`200 application/json`):

```json
{
  "kpis": {
    "activeUsers": <int>,
    "objectCount": <int>,
    "filesCount": <int>,
    "auditEventCount": <int>
  },
  "activity": [
    { "timestamp": "<iso8601>", "eventCount": <int> }
  ]
}
```

The response SHALL carry the header `Cache-Control: public, max-age=60`.

#### Scenario: Valid call returns kpis + activity payload

- **GIVEN** an Application `<nil>` with an ApplicationVersion `<nil>` whose register
  is `openbuilt-hello-world-production`
- **AND** the caller is in `permissions.viewers` on the Application
- **WHEN** the caller GETs
  `/api/applications/<nil>/versions/<nil>/insights?window=7d`
- **THEN** the response is `200 application/json`
- **AND** the response body has the shape `{"kpis":{"activeUsers":<int>,
  "objectCount":<int>,"filesCount":<int>,"auditEventCount":<int>},"activity":[...]}`
- **AND** the response carries `Cache-Control: public, max-age=60`

#### Scenario: Missing window parameter returns 400

- **WHEN** any caller GETs `/api/applications/<nil>/versions/<nil>/insights`
  (no `window`)
- **THEN** the response is `400 Bad Request`
- **AND** the body is
  `{"status":400,"message":"Invalid window parameter; expected one of: 7d, 30d, 90d"}`

#### Scenario: Invalid window value returns 400

- **WHEN** any caller GETs
  `/api/applications/<nil>/versions/<nil>/insights?window=24h`
- **THEN** the response is `400 Bad Request`

#### Scenario: Unknown appUuid returns 404

- **WHEN** any caller GETs `/api/applications/<nil>/versions/<nil>/insights?window=7d`
  where the appUuid does not resolve to an Application
- **THEN** the response is `404 Not Found`

#### Scenario: versionUuid that belongs to a different appUuid returns 404

- **GIVEN** an ApplicationVersion `<nil>` whose `application` relation points at a
  different Application than the path's `appUuid`
- **WHEN** the caller GETs
  `/api/applications/<nil>/versions/<nil>/insights?window=7d`
- **THEN** the response is `404 Not Found`

### Requirement: REQ-OBAI-002 Auth gate mirrors openbuilt-version-routing

The endpoint SHALL apply the same RBAC gate as
`openbuilt-version-routing` REQ-OBVR-003:

- If the resolved version's UUID equals `Application.productionVersion.uuid`, the
  caller MUST be in `permissions.viewers` ∪ `permissions.editors` ∪
  `permissions.owners` on the Application. Failure → `404` (not `403`; no
  existence leak).
- Otherwise (non-production version), the caller MUST be in `permissions.editors` ∪
  `permissions.owners`. Failure → `404`.
- Nextcloud admins are NOT auto-granted (same policy as
  `openbuilt-version-routing`).

The controller SHALL carry `#[NoAdminRequired]`. The RBAC check SHALL live inside
the service layer (`ApplicationInsightsService`), not the controller, so the gate is
testable in isolation and mirrors the shape of `ManifestResolverService`.

#### Scenario: Viewer can read production insights

- **GIVEN** the caller is in `permissions.viewers` on the Application
- **AND** the requested version is the production version
- **WHEN** the caller GETs the insights endpoint with `window=7d`
- **THEN** the response is `200`

#### Scenario: Viewer cannot read non-production insights (404)

- **GIVEN** the caller is in `permissions.viewers` on the Application
- **AND** the requested version is `staging` (non-production)
- **WHEN** the caller GETs the insights endpoint with `window=7d`
- **THEN** the response is `404 Not Found` (not `403`)

#### Scenario: Editor can read non-production insights

- **GIVEN** the caller is in `permissions.editors` on the Application
- **AND** the requested version is `staging`
- **WHEN** the caller GETs the insights endpoint with `window=7d`
- **THEN** the response is `200`

#### Scenario: Nextcloud admin without listed permission cannot read non-production (404)

- **GIVEN** the caller is a Nextcloud admin
- **AND** the caller is NOT in `permissions.editors` or `permissions.owners`
- **AND** the requested version is `staging`
- **WHEN** the caller GETs the insights endpoint with `window=7d`
- **THEN** the response is `404 Not Found`
  _(admins are not auto-granted — same policy as openbuilt-version-routing)_

### Requirement: REQ-OBAI-003 Schema-set walk over the version's manifest.pages[].config

The system SHALL derive the schema-set for a version's insights aggregation by
walking the version's `manifest.pages[].config.{register,schema}` entries
server-side and unique-ing the resulting schema IDs. The walk SHALL:

1. Resolve the ApplicationVersion record and its `manifest` payload.
2. Iterate `manifest.pages[]`. For each page entry, read `config.register` and
   `config.schema`. Collect `(registerSlug, schemaId)` tuples; skip entries with
   missing or null values.
3. Filter to tuples where `registerSlug` equals
   `openbuilt-{appSlug}-{versionSlug}` (the version's own per-version register).
   Tuples referencing other registers SHALL be ignored — the insights endpoint
   scopes to the version's own register only.
4. Unique by schema ID.

The resulting schema-set drives all four KPI aggregations and the activity-chart
call. An empty schema-set is a valid input — all four KPIs return `0` and
`activity` is `[]`.

#### Scenario: Walk derives unique schema IDs from manifest.pages

- **GIVEN** a manifest with three page entries:
  `[{config:{register:"openbuilt-hello-world-production", schema:"<nil>"}},
   {config:{register:"openbuilt-hello-world-production", schema:"<nil>"}},
   {config:{register:"openbuilt-hello-world-production", schema:"<nil>"}}]`
  (the same schema referenced twice plus a distinct one)
- **WHEN** the service walks the manifest
- **THEN** the resulting schema-set contains two unique schema IDs

#### Scenario: Tuples referencing other registers are ignored

- **GIVEN** a manifest page entry with
  `config:{register:"some-other-register", schema:"<nil>"}`
- **WHEN** the service walks the manifest
- **THEN** the resulting schema-set does NOT include that schema

#### Scenario: Empty manifest pages yields zero KPIs and empty activity

- **GIVEN** a manifest with `pages: []`
- **WHEN** the caller GETs the insights endpoint
- **THEN** the response is
  `{"kpis":{"activeUsers":0,"objectCount":0,"filesCount":0,"auditEventCount":0},
  "activity":[]}`

### Requirement: REQ-OBAI-004 KPI aggregations source

The four KPI scalars SHALL be computed from these OR-facing sources:

| KPI                | Source                                                                                                |
| ------------------ | ----------------------------------------------------------------------------------------------------- |
| `activeUsers`      | `AuditTrailMapper::getDistinctActorCount(schemaIds, hours)` — delivered by `openregister-distinct-actor-aggregation` |
| `objectCount`      | Sum of `ObjectService::countObjects` (or the analogous OR call) over each schema in the schema-set    |
| `filesCount`       | `FileService::countAttachedFilesForRegister(registerSlug)` — counts OR-attached files across all objects in the version's register |
| `auditEventCount`  | `AuditTrailMapper::countByRegisterAndWindow(schemaIds, hours)` — total audit-trail rows in the window |

`hours` SHALL be derived from the `window` query parameter:
`7d → 168`, `30d → 720`, `90d → 2160`.

The Active-users KPI and the Audit-events KPI scope to the time window; the
Object-count KPI and the Files-count KPI are point-in-time totals and do NOT scope
to the window.

The Files-count KPI is a v1 proxy for "storage" — it counts files, not bytes. The
canonical storage-bytes aggregation is deferred (separate spec).

#### Scenario: 7d window translates to 168 hours

- **WHEN** the caller GETs the insights endpoint with `window=7d`
- **THEN** `getDistinctActorCount` is called with `hours=168`
- **AND** `countByRegisterAndWindow` is called with `hours=168`

#### Scenario: Object count and Files count ignore the window

- **WHEN** the caller GETs the insights endpoint with `window=30d`
- **THEN** `objectCount` is the current sum of `countObjects` across the schema-set
  (no window filter)
- **AND** `filesCount` is the current count of OR-attached files for the
  register (no window filter)

### Requirement: REQ-OBAI-005 Activity payload sourced from getActionChartData

The `activity` array SHALL be sourced from
`AuditTrailMapper::getActionChartData(schemaIds, hours)` called once per request
with the same schema-set and window-derived hours used by the KPI aggregations.

Each element of `activity` SHALL have the shape
`{ "timestamp": "<iso8601>", "eventCount": <int> }`. The bucket granularity SHALL
match `getActionChartData`'s native granularity (no resampling in this spec).

#### Scenario: Activity payload reflects getActionChartData buckets

- **GIVEN** `getActionChartData` returns three daily buckets for the 7d window
- **WHEN** the caller GETs the insights endpoint with `window=7d`
- **THEN** the response's `activity` array contains exactly those three buckets
  with `timestamp` (ISO 8601) and `eventCount` (int) fields

### Requirement: REQ-OBAI-006 Cache-Control: public, max-age=60 on successful responses

Successful (`200`) responses SHALL carry the header
`Cache-Control: public, max-age=60`. Error responses (`400`, `404`) SHALL NOT carry
this header. The 60-second window is a fixed compile-time value in this spec; future
tuning is out of scope.

#### Scenario: 200 carries the cache header

- **GIVEN** a valid authorised request
- **WHEN** the endpoint responds `200`
- **THEN** the response carries `Cache-Control: public, max-age=60`

#### Scenario: 404 does not carry the cache header

- **WHEN** the endpoint responds `404` (unknown appUuid)
- **THEN** the response does NOT carry the `Cache-Control` header from this spec

### Requirement: REQ-OBAI-007 Route registration in appinfo/routes.php

The system SHALL register exactly one new route entry in
`appinfo/routes.php` pointing at `ApplicationInsightsController::getInsights`,
matching the path
`/api/applications/{appUuid}/versions/{versionUuid}/insights` with verb `GET`.

The route entry SHALL carry the auth posture attribute on the controller method
(per hydra-gate-route-auth): `#[NoAdminRequired]` (the RBAC gate lives inside the
service; the controller itself is authenticated-only).

#### Scenario: routes.php declares the insights route

- **GIVEN** the change is applied
- **WHEN** `appinfo/routes.php` is loaded
- **THEN** an entry exists with verb `GET`, URL
  `/api/applications/{appUuid}/versions/{versionUuid}/insights`, mapping to the
  `getInsights` method on the insights controller

#### Scenario: Controller method carries #[NoAdminRequired]

- **WHEN** static-analysis reads `ApplicationInsightsController`
- **THEN** the `getInsights` method is annotated with `#[NoAdminRequired]`
