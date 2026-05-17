---
kind: code
depends_on: ["openbuilt-nextcloud-nav", "openbuilt-versioning-model", "openbuilt-version-promotion", "openbuilt-version-routing", "openregister-distinct-actor-aggregation"]
---

## Why

The current Application detail page at `/applications/:objectId` is a generic
`CnDetailPage` rendering a stock data widget over the Application record. For a
maintainer it is effectively useless: it shows no version chain, no usage signal,
no quick path to the schemas / pages / menu / groups that compose the app. Now that
`openbuilt-versioning-model` (spec C) ships the version chain, `openbuilt-version-routing`
(spec C-routing) ships the `?_version=` contract, and `openbuilt-version-promotion`
(spec D) ships promotion, the detail page is the natural cockpit — but only if it
renders that information. This spec replaces the generic detail with a
purpose-built maintainer dashboard: hero strip, version pill tabs, KPI grid,
activity graph, and a 5-card structural-widget grid that deep-links into the
existing builder views.

## What Changes

- **Application detail page main area** is replaced. Top-to-bottom: (a) hero strip
  (icon + name + description + status + role + production semver), (b) version pill
  tabs (chain order, production starred, non-authorised versions hidden), (c) window
  toggle (7d / 30d / 90d) right of the pill tabs, (d) KPI grid (Active users, Object
  count, Files count, Audit events — each scoped to the selected version's
  per-version register), (e) activity-graph card (event timeline for the selected
  window, fed by `AuditTrailMapper::getActionChartData`), (f) structural-widget grid
  (Register / Schemas / Groups / Pages / Menu cards, each deep-linking into the
  matching builder path with `?_version=` preserved).
- **Sidebar** keeps the Manifest / Version history / Diff / Audit tabs unchanged.
  The old Overview sidebar tab is removed from the manifest (its content is now in
  the main area).
- **New backend endpoint** `GET /api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d`
  returns a single `{kpis, activity}` payload. Auth: viewer-or-better on the
  Application for production; editor-or-better on non-production (mirrors the
  `openbuilt-version-routing` 404 gate). HTTP cache: `Cache-Control: public, max-age=60`.
- **New frontend component** `ApplicationDetailHeader.vue` registered as the
  `headerComponent` of the `VirtualAppDetail` page entry in `src/manifest.json`.
  Reads `?_version=` via Vue Router; pill clicks update the URL via the existing
  `buildVersionedRoute` helper from `openbuilt-version-routing`.
- **Five small structural widget components** under `src/components/applicationDetail/widgets/`
  (Register / Schemas / Groups / Pages / Menu) — each renders one card and emits
  navigation events that resolve to the deep-link path documented in `application-detail-overview/spec.md`.
- **Manifest config** in `src/manifest.json`: `VirtualAppDetail` page entry gains
  `"headerComponent": "ApplicationDetailHeader"` and drops the `overview` entry from
  `sidebarTabs`. No other manifest changes.
- **Tests**: new PHPUnit for the insights controller + service (RBAC gate, schema-set
  walk, cache header). New Playwright e2e verifying pill switching reloads KPIs,
  the structural-widget rows deep-link with the right `?_version=`, and the activity
  graph renders for each window.

## Capabilities

### New Capabilities

- `application-detail-overview`: The maintainer-dashboard main area on the Application
  detail page — hero, version pill tabs, window toggle, KPI grid, activity-graph card,
  and the five structural-widget cards. Owns `ApplicationDetailHeader.vue`, the five
  widget components, the manifest-config touch on the `VirtualAppDetail` page entry,
  and the pill-tab visibility / production-marker / version-switching behaviour.
- `application-insights`: The backend endpoint
  `GET /api/applications/{appUuid}/versions/{versionUuid}/insights` and its supporting
  service. Owns the schema-set walk over the version's `manifest.pages[].config.{register,schema}`,
  the four KPI aggregations (object count, files count, audit events, active users
  via the OR-side `getDistinctActorCount`), the activity-graph payload via
  `getActionChartData`, the auth gate, and the `Cache-Control: public, max-age=60`
  response header.

### Modified Capabilities

_(none — the existing capabilities do not own the new main-area content or the
insights endpoint; the manifest config touch is additive)_

## Impact

- **New PHP**: `lib/Controller/ApplicationInsightsController.php`,
  `lib/Service/ApplicationInsightsService.php`
- **Modified PHP**: `appinfo/routes.php` (one new route entry for the insights endpoint)
- **New JS**: `src/components/applicationDetail/ApplicationDetailHeader.vue`,
  `src/components/applicationDetail/widgets/RegisterWidget.vue`,
  `src/components/applicationDetail/widgets/SchemasWidget.vue`,
  `src/components/applicationDetail/widgets/GroupsWidget.vue`,
  `src/components/applicationDetail/widgets/PagesWidget.vue`,
  `src/components/applicationDetail/widgets/MenuWidget.vue`,
  `src/composables/useApplicationInsights.js`
- **Modified JS**: `src/manifest.json` (`VirtualAppDetail` page entry — add
  `headerComponent`, drop `sidebarTabs.overview`)
- **New tests**: `tests/Unit/Controller/ApplicationInsightsControllerTest.php`,
  `tests/Unit/Service/ApplicationInsightsServiceTest.php`,
  `tests/e2e/application-detail-overview.spec.ts`
- **OpenRegister dependency**: relies on `AuditTrailMapper::getDistinctActorCount`
  delivered by `openregister-distinct-actor-aggregation`, plus the existing
  `getActionChartData`
- **ADR dependencies**: ADR-001 (icon via OR files), ADR-002 (versioned deployment
  model), ADR-022 (consume OR abstractions), ADR-024 (manifest), ADR-031
  (declarative vs imperative)
