## Why

The admin tutorial "Manage who can build" (`docs/tutorials/admin/01-rbac.md`, steps 2 to 4) tells an admin to open **Settings → Administration → Buildiq**, pick one or more groups in a **Builder groups** picker and click **Save**. After that, members of those groups see the builder controls on every virtual app, and everyone else gets a 403. The admin settings page has no such picker. The only way to give someone builder access today is per app, through **Actions → Manage permissions**, one app at a time.

## What Changes

- **A Builder groups setting.** `builder_groups` holds a list of Nextcloud group ids. `GET /api/settings` returns it as an array. `PUT /api/settings` (admin only, as today) stores it. Unknown or empty entries are dropped on save.
- **A group picker on the admin page.** The Configuration section gets a multi-select **Builder groups** picker, filled from Nextcloud's group list, and saved with the existing **Save** button.
- **Builder groups grant editor access on every app.** `PermissionResolver::matchesCaller()` treats a member of any builder group as an editor of every Application whose permission block is not empty. Every check that accepts editors therefore accepts builders: the manifest endpoint, the app list, manifest saves, the page and schema designers, version promotion and automations. Owner-only actions (publishing, managing permissions, transferring ownership, deleting) still need an owner role.
- **The UI follows the same rule.** `DashboardController` publishes the configured groups as the `builderGroups` initial state, and `useRole()` answers `editor` for a builder-group member who has no higher role on the app.
- **Per-app access keeps working.** Owners, editors and viewers set through Manage permissions behave exactly as before, whether or not builder groups are configured. With the setting empty, nothing changes: only admins and per-app roles have access, and a user with neither still gets the 403 the tutorial describes.
- **No BREAKING changes.**

## Capabilities

### Modified Capabilities

- `openbuild-rbac`: a new requirement for instance-wide builder groups.

## Impact

- `lib/Service/SettingsService.php`: the `builder_groups` key (JSON-encoded list).
- `lib/Service/PermissionResolver.php`: the builder-group grant on editor checks.
- `lib/Controller/DashboardController.php`: the `builderGroups` initial state.
- `src/composables/useRole.js`: builder groups count as editor.
- `src/views/settings/Settings.vue`: the group picker.
- Tests for each.
