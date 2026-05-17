# Apply Notes — openbuilt-app-detail-overview

Applied: 2026-05-16

Branch: `feature/openbuilt-app-detail-overview` (off
`feature/openbuilt-app-creation-wizard`, which chains C → A → D → E →
harness → F).

## OpenRegister floor dependency — `getDistinctActorCount`

REQ-OBAI-004 sources the Active-users KPI from
`AuditTrailMapper::getDistinctActorCount(schemaIds, hours)`. That method
is delivered by `openregister-distinct-actor-aggregation` on the OR-side
branch `feature/openregister-distinct-actor-aggregation`; it is NOT yet
merged into OR's `development` floor.

To keep this app building + running against an older OR floor, the
service uses a `method_exists` guard:

```php
if (method_exists($this->auditTrailMapper, 'getDistinctActorCount') === false) {
    $this->logger->debug('… degrade to 0 (depends on openregister-distinct-actor-aggregation)');
    return 0;
}
```

When that floor change lands, the KPI returns the real number with no
code change here. The unit-test stub at
`tests/stubs/openregister-stubs.php` was extended to declare the method
signature so PHPUnit can mock it independently of the OR install.

## Endpoint shape — UUID path params + `/insights` suffix

The route `appinfo/routes.php` carries the entry

```php
['name' => 'applicationInsights#getInsights',
 'url'  => '/api/applications/{appUuid}/versions/{versionUuid}/insights',
 'verb' => 'GET',
 'requirements' => ['appUuid' => '[a-f0-9-]{8,}', 'versionUuid' => '[a-f0-9-]{8,}']],
```

This is structurally similar to the promotion route
(`/promote` suffix) and uses the same UUID-shape requirement to prevent
collision with the slug-based CRUD routes (`{versionSlug}` matches
kebab-case slugs only).

## RBAC gate: dual-layer guard (service + controller)

`#[NoAdminRequired]` on the controller method removes the default
admin-only posture. The hydra `gate-7 (no-admin-idor)` rule requires
the controller method body itself to call a `require*` /
`authorize*` / `ensure*` / `check*` helper that can deny.

The service exposes `requireAuthorisedCaller(appUuid, versionUuid,
caller)` as its public RBAC guard surface. The controller calls it
explicitly BEFORE `computeInsights()`. This is defence-in-depth + gate-7
compliance — the same RBAC logic also runs inside `computeInsights()`
internally so the service is safe to call in isolation from tests.

## Files-count KPI is a v1 proxy

OR does not yet expose a `FileService::countAttachedFilesForRegister`
aggregation. The service uses
`AuditTrailMapper::getStatisticsGroupedBySchema()`'s `size` column as a
defensive fallback — when that method is absent the KPI returns 0. The
spec explicitly flags this as a v1 proxy (storage-bytes aggregation
deferred); the label is "Files" not "Storage" and the tooltip explains
the distinction.

When OR ships the dedicated file-count aggregation, swap the
implementation of `countAttachedFiles()` in place without changing the
spec contract.

## Audit-events fallback chain

The spec's REQ-OBAI-004 references
`AuditTrailMapper::countByRegisterAndWindow(schemaIds, hours)`. OR does
NOT currently ship that exact method. The service:

1. Tries `countByRegisterAndWindow` via `method_exists` (forward-
   compatible if a future OR change adds it).
2. Falls back to summing `getActionChartData`'s `series.data[]` rows
   across the schema-set + window. Per-schema fan-out is bounded by the
   manifest's schema-set size; today's seed apps have < 10 schemas.

## Activity-chart component choice

`@conduction/nextcloud-vue` does ship `CnChartWidget` and `CnStatsBlockWidget`,
but neither matches the spec's "activity timeline" semantics cleanly.
For v1 the header renders the activity as a lightweight inline SVG
sparkline with a buckets + total-events summary line beneath. The empty
state ("No activity in the selected window") is a plain `<p>` element.

Rationale: avoiding `CnChartWidget` keeps this component dependency-free
for the chart slot, mirroring the Decision-7 KPI rationale (we render
four `CnCard` instances and skip `CnKpiGrid` to keep the coupling
narrow). If we want a heavier chart in v2, swap the `<svg>` block for an
apexcharts wrapper without changing the data contract.

## Inline "+ Add schema" deferred

The Schemas widget's "+ Add schema" button looks for a global registry
hook (`window.openbuilt?.openAddSchemaDialog`). The current build does
NOT register one — the existing create-schema dialog
(`src/modals/AddSchemaDialog.vue`) is opened from the SchemaDesigner
view, not from a globally-callable hook. The widget emits an
`add-schema` event AND logs the documented debug notice when no global
opener is registered, per REQ-OBADO-007's "no-op stub" scenario.

When the schema-designer spec adds a global opener, this widget will
call it immediately with no further change.

## Groups widget permissions-editor target

REQ-OBADO-008 says the row click "opens the existing permissions
editor". The exact installed target today is `PermissionsModal.vue`
(opened via the detail page's action bar, not via a route). To keep
the widget self-contained and ADR-004-clean, it emits an
`open-permissions` event on click. `ApplicationDetailHeader.vue`
forwards the event up; the parent surface decides how to surface it.

When a permissions-editor route lands (e.g. `/builder/{slug}/permissions`),
update the widget to push it directly via `buildVersionedRoute`.

## Manifest config change

`src/manifest.json`'s `VirtualAppDetail` page entry was modified to:

1. Add `"headerComponent": "ApplicationDetailHeader"` at the PAGE level
   (NOT inside `config`). This matches the canonical nc-vue manifest
   schema — `page.headerComponent` is a "slot sugar" field for
   `slots.header`.
2. Remove the `sidebarTabs[id=overview]` entry. The remaining tabs
   (manifest, history, diff, icons, audit) are preserved unchanged.

The `tests/vitest/manifest.spec.js` "no unused customComponents"
check was extended to look up `headerComponent` (and `actionsComponent`)
at both the page level and inside `config`, so the existing fleet's
older manifests with `config.headerComponent` continue to work.

## Pre-existing PHPUnit failures NOT touched

The full unit suite shows 22 errors + 4 failures on the parent branch
(unrelated to this spec — `CreateFromTemplateTest::__construct()
argument count`, `ApplicationCreationServiceTest` mock-builder issues,
`ManifestResolverServiceTest` returning null where the fixture expects
a manifest). The 16 new tests added by this spec all pass. The pre-
existing red is left for a dedicated quality pass.

## Component-test stubs

`tests/vitest/stubs/conduction-nextcloud-vue.js` was extended with a
`CnCard` stub component so `ApplicationDetailHeader.vue` mounts under
Vitest. The stub renders `{title, description}` only — the spec
contract is "label + value", not the full nc-vue card layout.

## Hydra mechanical gates

All 14 gates green:

- gate-1 spdx-headers PASS
- gate-2 forbidden-patterns PASS
- gate-3 stub-scan PASS
- gate-4 composer-audit PASS
- gate-5 route-auth PASS
- gate-6 orphan-auth PASS
- gate-7 no-admin-idor PASS (required the controller-level
  `requireAuthorisedCaller` guard documented above)
- gate-8 unsafe-auth-resolver PASS
- gate-9 semantic-auth PASS
- gate-10 initial-state PASS
- gate-11 admin-router PASS
- gate-12 nc-input-labels PASS
- gate-13 modal-isolation PASS
- gate-14 route-reachability PASS

## Promote E2E `describe.skip` left in place

The Playwright destructive-confirmation gate (`tests/e2e/promoteDestructive.spec.ts`)
has a `test.describe.skip(...)` wrapper that says "pending spec B /
openbuilt-app-detail-overview". This spec adds the Promote affordance
on the version pills, but the click handler delegates to a global
opener (`window.openbuilt?.openPromoteDialog`) that is NOT wired in
this PR — the affordance is a trigger surface, not the dialog wiring
itself. Leaving the skip wrapper is correct; a follow-on PR can wire
the global opener and lift the skip in one move.

## Iteration count

1 (this pass).
