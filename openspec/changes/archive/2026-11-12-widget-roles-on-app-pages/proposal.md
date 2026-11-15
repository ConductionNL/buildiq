---
kind: code
---

## Why

A widget on a page can declare `roles`, the groups that may see it. Nothing enforced that on the app's own pages (issue #1187). `ManifestResolverService::filterManifestForCaller()` stripped menu items and pages by `permission` and left every widget in place, and `@conduction/nextcloud-vue` does not evaluate `roles` on purpose: its schema leaves the check to the host app, on the read. Only widgets published to the Nextcloud dashboard honoured `roles`. A widget meant for the finance group reached every reader of the app, in the manifest and on screen.

## What changes

- `filterManifestForCaller()` also removes page widgets whose `roles` the caller does not match, in every place a page declares widgets: `widgets[]`, the legacy `config.widgets[]`, `config.sidebarTabs[].widgets[]`, and the page shapes inside `pageTemplates[]` and `pageInstances[]`.
- A removed widget takes its references with it: its `config.layout[]` row and its entry in a tabs widget's `content.tabs[]`, so the client never gets a dangling reference.
- Matching reuses `AppVisibilityResolver`, the check the Nextcloud dashboard uses: `group:*`, `user:<uid>`, `group:<gid>` or a bare group id, and the admin bypass. A widget without `roles` stays visible to everyone.
- Owners, editors and admins keep the full manifest, as they do for menu items and pages.
- No client mirror is needed. The server sends the filtered manifest, so the renderer never sees a widget the caller may not see.

## Capabilities

### Modified capabilities

- `openbuild-runtime`: page widgets are filtered by `roles` on the server.

## Impact

- `lib/Service/ManifestWidgetRoleFilter.php`: new, the widget filter.
- `lib/Service/ManifestResolverService.php`: calls it from `filterManifestForCaller()`.
- `lib/Service/AppVisibilityResolver.php`: the check order takes a user as well as a session.
- Tests: PHPUnit for the filter and for `filterManifestForCaller()`.
