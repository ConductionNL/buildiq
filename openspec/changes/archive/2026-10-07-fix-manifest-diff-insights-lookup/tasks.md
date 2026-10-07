## 1. Slug lookup

- [x] 1.1 `ApplicationsController::findRouteForSlug()` falls back to `findApplicationUuidBySlug()` (exact slug match only).
- [x] 1.2 Tests: `testGetManifestFindsAnAppWithoutARouteEntry`, `testGetManifestFallbackIgnoresAHitWithAnotherSlug`.

## 2. Diff

- [x] 2.1 `diffVersions()` uses `resolveApplicationBySlug()`; refs may be version slugs or UUIDs; scope check reads the `application` relation.
- [x] 2.2 Tests: `testDiffVersionsComparesTwoVersionsOfAnAppWithoutARoute`, `testDiffVersionsAcceptsVersionSlugs`, `testDiffVersionsRejectsAVersionOfAnotherApp`.

## 3. Insights

- [x] 3.1 `VersionDataSourceResolver::resolve()` returns the (register, schema) pairs; `computeInsights()` counts per pair and returns `schemaCounts`.
- [x] 3.2 Tests: `testVersionWithoutOwnRegisterCountsWherePagesPoint`, `testVersionRegisterSchemasCountEvenWithoutAPage`.
