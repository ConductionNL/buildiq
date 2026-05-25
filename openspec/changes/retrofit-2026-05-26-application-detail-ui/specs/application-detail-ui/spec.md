---
retrofit: true
---

# application-detail-ui Specification

## Purpose

The Application detail UI is OpenBuilt's maintainer cockpit. The
`ApplicationDetailHeader` (registered as the detail-route `headerComponent`)
renders version pills, a four-card KPI grid, an activity sparkline, and the
stacked overview widgets (register / schemas / groups / pages / menu). The
`ApplicationCard` is the grid tile on the index. The action bar
(`ApplicationDetailActions`), tabs (`tabs/*`), and `VirtualAppsActions` drive
publish, permissions, manifest, versions, and icon actions. `ManifestDiff`
renders a version-to-version manifest diff, and `App.vue` is the app shell.

This capability is observed behaviour of those components. It is the frontend
half of the `application-detail-overview` and `application-insights` backend
capabilities.

## ADDED Requirements

### Requirement: Detail header cockpit renders versions, KPIs, activity and refresh

`ApplicationDetailHeader` SHALL bind the application object
(`object`, `objectId`, `appSlug`, `applicationName`, `applicationDescription`,
`applicationStatus`, `iconUrl`, `banner`), resolve and order the version chain
(`loadVersions`, `orderedVersions`, `visibleVersions`, `activeVersion`,
`activeVersionSlug`, `activeVersionUuid`, `selectVersion`, `selectedWindow`,
`windowOptions`), surface the active version's manifest-derived counts
(`activeManifest`, `activeMenu`, `activePages`, `activeSchemas`, `schemaCount`),
fetch and debounce insights (`fetchInsights`, `scheduleInsightsFetch`,
`sparklinePoints`, `totalActivityEvents`, `filesTooltip`), expose the production
pointer (`productionVersion`, `productionSemver`, `productionVersionUuid`,
`switchToProduction`), open permissions/promote flows (`onOpenPermissions`,
`onPromoteClick`, `callerRole`), refresh on demand (`refreshApplication`), and
clean up timers on `beforeDestroy`/`mounted`.

#### Scenario: Select a version pill

- **WHEN** the user activates a non-production version pill
- **THEN** the header switches the active version and re-derives the manifest
  counts and insights window

#### Scenario: Refresh insights window

- **WHEN** the user changes the time window
- **THEN** the header debounces and re-fetches the version-scoped insights

### Requirement: Overview widgets render rows with deep-links and inline actions

The overview widgets SHALL each render their domain rows: `RegisterWidget`
read-only with an "Open in OpenRegister" deep-link (`registerSlug`,
`openInOpenRegister`); `SchemasWidget` with deep-link, count formatting, and an
inline add (`openSchema`, `addSchema`, `formatCount`); `GroupsWidget` with role
badges (`rows`, `roleLabel`, `memberLabel`, `openEditor`); `PagesWidget`
(`openPage`); and `MenuWidget` (`openEntry`). Each open/add action SHALL emit or
navigate to the corresponding editor.

#### Scenario: Open the register

- **WHEN** the user clicks the register deep-link
- **THEN** the widget navigates to the register in OpenRegister

#### Scenario: Add a schema inline

- **WHEN** the user clicks "+ Add schema"
- **THEN** the schemas widget opens the schema creation flow

### Requirement: Application card tile surfaces status, version and role

`ApplicationCard` SHALL bind the application (`app`, `appUuid`), expose the
production version and semver (`productionVersion`, `productionSemver`), expose
status and role labels (`statusKey`, `statusLabel`, `role`, `roleLabel`),
fall back gracefully on a broken icon (`onIconError`), and navigate to the
detail route on activation (`onCardActivate`).

#### Scenario: Activate a card

- **WHEN** the user activates an application card
- **THEN** the index navigates to that application's detail route

### Requirement: Action bar and tabs drive publish, permissions, manifest, versions, icon

`ApplicationDetailActions` SHALL gate and trigger publish
(`canPublish`, `publish`, `builderUrl`), resolve the available groups
(`availableGroups`), and handle a permissions save (`onPermissionsSave`).
`ApplicationManifestTab` SHALL parse, validate, and save the raw manifest
(`parseAndValidate`, `save`, `handler`). `ApplicationVersionsTab` SHALL handle
rollback and short-hex display (`onRollback`, `shortHex`). `ApplicationIconTab`
SHALL react to icon updates (`onIconUpdated`). `VirtualAppsActions` SHALL react
to wizard completion (`onWizardCreated`).

#### Scenario: Publish a draft

- **WHEN** the maintainer triggers publish and the gate is met
- **THEN** the action bar publishes the active version

#### Scenario: Save an edited manifest

- **WHEN** the maintainer edits the raw manifest and saves
- **THEN** the tab validates the JSON before persisting

### Requirement: Manifest diff viewer and app shell

`ManifestDiff` SHALL fetch both manifests (`fetch`, `from`, `to`, `slug`),
compute a deterministic diff (`diffParts`, `partClass`, `sortReplacer`,
`prettyManifest`), label each side (`fromLabel`, `toLabel`), and load on
`mounted`. `App.vue` SHALL expose the app shell context: icon, store URL, admin
flag, permissions, and the per-app translation helper (`appIcon`,
`appStoreUrl`, `isAdmin`, `permissions`, `translateForApp`, `created`).

#### Scenario: Diff two versions

- **WHEN** the user selects a from/to version pair
- **THEN** the viewer fetches both manifests and renders a stable diff

#### Scenario: Resolve app context

- **WHEN** the app shell is created
- **THEN** it resolves the admin flag, permissions, and translation helper
