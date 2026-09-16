## ADDED Requirements

### Requirement: Admin-nominated builder groups grant editor access on every Application

The system SHALL let an admin nominate Nextcloud groups as **builder groups** in Buildiq's admin settings. The setting SHALL be stored as the `builder_groups` app config value, a list of group ids. `GET /api/settings` SHALL return it as an array, and only an admin SHALL be able to change it through `PUT /api/settings`.

A user who is a member of at least one builder group SHALL be treated as an **editor** of every Application whose `permissions` block is not empty. Every permission check that accepts the `editors` role SHALL accept that user. A check that accepts only `owners` SHALL NOT. Membership is OR-ed across the listed groups. With an empty list, no user SHALL gain access this way, and access SHALL be decided by the admin group and the per-Application roles alone, exactly as before.

The frontend SHALL apply the same rule: `useRole(application)` SHALL answer `editor` for a builder-group member who holds no higher role on the Application, using the `builderGroups` initial state published by the dashboard page.

The admin settings page SHALL show a **Builder groups** multi-select group picker in its Configuration section, saved with the section's Save button.

**ID:** REQ-OBRBAC-008

#### Scenario: A builder-group member can open the designer of any app

- **GIVEN** an admin saved `buildiq-builders` as a builder group
- **AND** user `carol` is a member of `buildiq-builders` and holds no role on Application `permit-tracker`
- **WHEN** `carol` opens `/apps/buildiq/builder/permit-tracker/pages`
- **THEN** the manifest request succeeds and the page designer is editable for her

#### Scenario: A user outside the builder groups is refused

- **GIVEN** an admin saved `buildiq-builders` as a builder group
- **AND** user `dave` is in no builder group and holds no role on `permit-tracker`
- **WHEN** `dave` requests the manifest of `permit-tracker`
- **THEN** the response is 403

#### Scenario: Builder groups do not grant owner-only actions

- **GIVEN** `carol` is a builder-group member with no role on `permit-tracker`
- **WHEN** she tries to change the permissions of `permit-tracker`
- **THEN** the request is refused, because that check accepts owners only

#### Scenario: Per-app roles keep working without builder groups

- **GIVEN** the builder groups setting is empty
- **AND** `erin` is an editor of `permit-tracker` through Manage permissions
- **WHEN** `erin` opens the page designer of `permit-tracker`
- **THEN** she can edit it, as before

#### Scenario: An admin picks and saves builder groups

- **WHEN** an admin opens Settings → Administration → Buildiq, picks `buildiq-builders` in Builder groups and clicks Save
- **THEN** `GET /api/settings` returns `builder_groups: ["buildiq-builders"]`
