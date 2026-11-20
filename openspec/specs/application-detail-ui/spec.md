---
retrofit: true
---

# application-detail-ui Specification

## Purpose

The Application detail UI is Buildiq's maintainer cockpit. The
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

**OpenSpec changes**: [github-app-sync](../../changes/github-app-sync/)

## Requirements

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

@e2e exclude retrofit component-contract spec — scenarios describe Vue composable/computed-property contracts (`loadVersions`, `fetchInsights`, `sparklinePoints`, etc.) verified by Vitest unit tests; end-to-end UI behaviour of the cockpit is covered by the application-detail-overview Playwright tests

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

@e2e exclude retrofit component-contract spec — scenarios describe widget action-emit contracts (`openInOpenRegister`, `addSchema`, `openPage`, `openEntry`) verified by Vitest unit tests; deep-link navigation covered by application-detail-overview Playwright tests

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

@e2e exclude retrofit component-contract spec — card navigation is covered by the buildiq-runtime Playwright tests (`application-list-renders-for-admin`, `hello-world-card-navigates-to-detail`); the card's role/status label contracts are Vitest-tested

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

@e2e exclude retrofit component-contract spec — `canPublish`, `publish`, `parseAndValidate`, `onRollback`, `onIconUpdated`, `onWizardCreated` are composable-level contracts verified by Vitest; publish + manifest-save integration is covered by the buildiq-runtime Playwright tests

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
`mounted`. `App.vue` SHALL expose the app shell context: icon, marketplace URL, admin
flag, permissions, and the per-app translation helper (`appIcon`,
`appStoreUrl`, `isAdmin`, `permissions`, `translateForApp`, `created`).

@e2e exclude retrofit component-contract spec — `diffParts`, `sortReplacer`, `prettyManifest`, `appIcon`, `isAdmin`, `permissions`, `translateForApp` are composable/computed contracts verified by Vitest; diff rendering and app-shell init are covered by the buildiq-runtime Playwright tests

#### Scenario: Diff two versions

- **WHEN** the user selects a from/to version pair
- **THEN** the viewer fetches both manifests and renders a stable diff

#### Scenario: Resolve app context

- **WHEN** the app shell is created
- **THEN** it resolves the admin flag, permissions, and translation helper

### Requirement: Detail cockpit renders a GitHub sync section for owners

The application detail cockpit SHALL render a **GitHub** section in the maintainer
detail/admin area exposing the round-trip actions. The section SHALL include a
**credential picker** listing the user's `github` credentials (via OpenRegister's
`GET /apps/openregister/api/credentials`) with a link to the CnCredentials pane to
add one when none exists, a **link-repo** affordance (owner + name, optional org),
a **Publish** action, a **Pull** action, and a **status readout** (linked repo,
last pushed sha, last pulled sha) sourced from
`GET /api/applications/{slug}/github/status`. The write actions
(link/publish/pull) SHALL be presented to owners; a non-owner MAY see the status
readout but not the write controls. Every new dialog/modal in this section SHALL
live in its own file under `src/modals/` (modal-isolation) — no inline
`NcModal`/`NcDialog`.

@e2e exclude retrofit component-contract spec — the GitHub-section render, credential
listing, and action wiring are `application-detail-ui` component-state contracts
verified by Vitest; the end-to-end round-trip is covered by the
application-detail-overview Playwright tests.

#### Scenario: Owner sees the GitHub section with actions

- **WHEN** an owner opens the detail cockpit of an app
- **THEN** the GitHub section renders the credential picker, a link-repo
  affordance, Publish and Pull actions, and the status readout

#### Scenario: Non-owner sees status but not write controls

- **WHEN** a viewer opens the detail cockpit of an app
- **THEN** the GitHub status readout is visible
- **AND** the link/publish/pull write controls are not offered

### Requirement: GitHub section feature-detects broker availability and degrades cleanly

The GitHub section SHALL feature-detect publish availability from the status
endpoint's `publishAvailable` / `brokerCredentialAvailable` flags. When publish is
unavailable (the broker is absent, its widened `github` write allowRules are not
present, or the owner has no allowed github credential), the section SHALL render
the **Publish** control in a disabled state with a clear hint stating what is
missing and how to resolve it (e.g. add a github credential, or that the broker
rules are not yet enabled). Pull of a public repo SHALL remain available
anonymously even when publish is disabled. The feature detection SHALL be advisory
in the UI — the authoritative gate is the server-side broker.

#### Scenario: Publish disabled with a hint when unavailable

- **WHEN** the status reports `publishAvailable: false`
- **THEN** the Publish control is disabled
- **AND** a clear hint explains what is missing and how to resolve it

#### Scenario: Publish enabled when a credential and rules are present

- **WHEN** the status reports `publishAvailable: true` and
  `brokerCredentialAvailable: true`
- **THEN** the Publish control is enabled

### Requirement: Publish and Pull actions call the sync endpoints and reflect results

The Publish action SHALL call `POST /api/applications/{slug}/github/push` with the
selected credential (and chosen version) and, on success, SHALL update the status
readout with the resulting commit sha. The Pull action SHALL call
`POST /api/applications/{slug}/github/pull` with a ref (and, for a private repo,
the selected credential) and, on success, SHALL surface the newly-created draft
version (which the owner promotes via the existing version-promotion flow) — a
pull SHALL NOT be presented as overwriting the production version. A
strict-parse failure returned by pull SHALL be surfaced as an actionable error
naming the offending file, with nothing created.

@e2e exclude retrofit component-contract spec — the action-calls-endpoint and
result-reflection behaviours are `application-detail-ui` component-state contracts
verified by Vitest; the integration is covered by the application-detail-overview
Playwright tests.

#### Scenario: Publish reflects the resulting commit sha

- **WHEN** an owner triggers Publish with a valid credential and the push succeeds
- **THEN** the status readout shows the new commit sha

#### Scenario: Pull surfaces the new draft version

- **WHEN** an owner triggers Pull with a ref and the pull succeeds
- **THEN** the section surfaces the newly-created draft version
- **AND** the production version is not presented as changed

#### Scenario: Pull parse failure is surfaced as an actionable error

- **WHEN** the pull endpoint returns a strict-parse failure
- **THEN** the section shows an actionable error naming the offending file
- **AND** no draft version is created
