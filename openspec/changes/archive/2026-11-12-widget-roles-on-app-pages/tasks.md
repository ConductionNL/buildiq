## 1. Filter

- [x] 1.1 Add `ManifestWidgetRoleFilter`, removing widgets by `roles` from `widgets[]`, `config.widgets[]`, `config.sidebarTabs[].widgets[]`, `pageTemplates[].page` and `pageInstances[].override`, and pruning `config.layout[]` rows and tabs-widget `content.tabs[]` entries that name a removed widget.
- [x] 1.2 Match `roles` through `AppVisibilityResolver::isVisibleToUser()`, extracted from `isVisible()` so both share one check order.
- [x] 1.3 Call the filter from `ManifestResolverService::filterManifestForCaller()` after the owner, editor and admin bypass.

## 2. Tests

- [x] 2.1 PHPUnit: matching rules (bare gid, `group:`, `group:*`, `user:`, admin, unauthenticated, malformed `roles`) and every widget shape.
- [x] 2.2 PHPUnit: `filterManifestForCaller()` removes the widget for a viewer outside its roles, keeps it for one inside, and leaves the manifest whole for owners, editors and admins.

## Acceptance criteria

- A viewer outside a widget's `roles` never receives that widget in the manifest.
- Owners, editors and admins receive the manifest unfiltered.
- No `config.layout[]` row or tabs entry names a widget the caller did not receive.
