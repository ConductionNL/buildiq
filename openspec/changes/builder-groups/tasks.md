## 1. Setting

- [x] 1.1 `SettingsService` stores `builder_groups` as a JSON list and returns it as an array. Only non-empty strings are kept.
- [x] 1.2 `SettingsService::getBuilderGroups()` gives other services the list.

## 2. Enforcement

- [x] 2.1 `PermissionResolver::matchesCaller()` grants a builder-group member any check that includes the `editors` role, on a non-empty permission block.
- [x] 2.2 Owner-only checks (`['owners']`) are unaffected.

## 3. Frontend

- [x] 3.1 `DashboardController` publishes `builderGroups` next to `currentUserGroups`.
- [x] 3.2 `useRole()` answers `editor` for a builder-group member with no higher role.
- [x] 3.3 The admin Configuration section shows a Builder groups multi-select, filled from the Nextcloud group list and saved with Save.

## 4. Verification

- [x] 4.1 PHPUnit: settings round-trip, resolver grant for editor checks, no grant for owner-only checks, and no grant when the setting is empty.
- [x] 4.2 Vitest: `useRole` with and without builder groups; the settings form loads, picks and saves the groups.
