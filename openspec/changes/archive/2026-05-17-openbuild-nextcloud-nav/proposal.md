---
kind: code
depends_on: []
---

## Why

Published OpenBuild virtual apps are invisible in the Nextcloud top bar — users must navigate
into the OpenBuild shell, find the app, and click through to reach it, instead of reaching
it directly from the global navigation. Adding a per-app top-bar entry (gated by the existing
`permissions` RBAC block) makes virtual apps first-class citizens of the Nextcloud navigation
surface and lets an operator brand each entry with a per-app SVG icon stored per ADR-001.

## What Changes

- **NEW** `icon` and `iconDark` top-level fields on the `Application` schema (sibling to
  `slug`, `name`, `manifest`, `permissions`) — each a `{ ref: "<filename>" }` pointer to an SVG
  attached to the Application record via OpenRegister's files-attached-to-object mechanism
  (ADR-001). Both optional; the PHP fallback chain fills in a default when absent. Icons live
  outside the `manifest` object on purpose: they are admin-side metadata about the virtual app,
  not part of the manifest blob the citizen developer designs and the runtime serves to
  `CnAppRoot`. This keeps the change orthogonal to `@conduction/nextcloud-vue`'s app-manifest
  schema (no upstream patch required).
- **NEW** Icon-serving endpoints `GET /apps/openbuild/icons/{slug}.svg` and
  `GET /apps/openbuild/icons/{slug}-dark.svg` — thin controller backed by a service that reads
  the attached SVG from OR, falls back through `iconDark → icon → /img/app-dark.svg →
  /img/app.svg` for the dark variant and `icon → /img/app.svg` for the light variant.
  60-second HTTP cache. Any signed-in user may fetch.
- **NEW** Per-published-app Nextcloud navigation entries wired in `Application::boot()` via
  `INavigationManager::add()`. Each entry carries a closure evaluated per request; the closure
  resolves the signed-in user's UID + group memberships against the union of
  `permissions.owners ∪ permissions.editors ∪ permissions.viewers`. `group:*` in any role
  makes the entry visible to all signed-in users. Apps with empty permissions are hidden from
  everyone except Nextcloud admins.
- **MODIFIED** `ApplicationCard.vue` — icon rendered in front of the app title via the new
  icon-serving endpoint; redundant `Live` chip (line 30, wired to `app.currentVersion`)
  removed because the status pill on line 23 already signals "Published".
- **NEW** Icon section on the Application detail page — SVG file pickers for `icon` and
  `iconDark` using OR's files-attached-to-object endpoint, with a split light/dark live
  preview.

## Capabilities

### New Capabilities

- `app-icon-management`: Upload, preview, and remove per-app SVG icons (light + dark) stored
  as OR-attached files on the Application record. Owns the icon-serving endpoints and the
  detail-page icon section.
- `app-nav-entries`: Dynamic per-published-app top-bar navigation entries, gated by
  `permissions` RBAC. Owns `Application::boot()` nav wiring and the `AppNavigationService`
  that enumerates published apps and builds the gating closure.

### Modified Capabilities

- `openbuild-application-register`: Schema patch — adds `icon` and `iconDark` as optional
  top-level properties on the `Application` schema (sibling to `manifest`, not inside it).
- `openbuild-runtime`: `ApplicationCard.vue` gains an icon and loses the redundant Live chip.

## Impact

- **New PHP** — `lib/Controller/IconController.php`, `lib/Service/IconService.php`,
  `lib/Service/AppNavigationService.php`.
- **Modified PHP** — `lib/AppInfo/Application.php` (`boot()` wired), `appinfo/routes.php`
  (two icon routes registered).
- **Modified JSON** — `lib/Settings/openbuild_register.json` (two top-level fields added to
  the `application` schema).
- **Modified Vue** — `src/components/ApplicationCard.vue` (icon + Live chip removal);
  detail-page icon section added via existing tab/section extension points.
- **Nextcloud OCP interfaces** — `INavigationManager`, `IURLGenerator`, `IUserSession`,
  `IGroupManager` (all from `\OCP\`).
- **OpenRegister dependency** — OR's files-attached-to-object endpoint (already required by
  the app per ADR-001 / info.xml `<dependencies>`). No new external packages.
- **No breaking changes** — `icon`/`iconDark` are optional; existing Applications without
  them fall back silently. The Live chip removal is UI-only and affects no API or data shape.
