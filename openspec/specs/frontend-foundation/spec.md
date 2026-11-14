---
retrofit: true
---

# frontend-foundation Specification

## Purpose

Buildiq's shared frontend foundation: cross-view composables (insights, live
preview, manifest validation, register picking, RBAC role resolution), the
object/settings Vuex stores, the slug utilities, the `PermissionsModal`, the
settings views, and the backend per-user `PreferencesController`. These pieces
underpin the higher-level designer, detail, wizard, and version-routing UIs.

This capability is observed behaviour of those composables, stores, utilities,
modal, settings views, and the preferences endpoint.

## Requirements

### Requirement: Composables encapsulate insights, preview, validation and register-pick

`useApplicationInsights` SHALL return reactive insights state for a version.
`useLivePreview` SHALL return the live-preview mount/state contract.
`useManifestValidator` SHALL return a manifest validation function and its
reactive error state. `useRegisterPicker(opts)` SHALL return the register
option list and selection contract. `useApplicationVersion` SHALL expose a
default editable version helper (`defaultEditableVersion`) alongside the active
version resolution. `useManifestHistory` SHALL load the version's manifest
history.

@e2e exclude retrofit composable-contract spec — `useApplicationInsights`, `useLivePreview`, `useManifestValidator`, `useRegisterPicker`, `useApplicationVersion`, `useManifestHistory` return values and reactive-state contracts are verified by Vitest unit tests; no independent Playwright-testable UI surface for composable internals

#### Scenario: Validate a manifest

- **WHEN** a caller invokes the validator composable's validate function
- **THEN** it returns the validation result and updates the reactive error state

### Requirement: Role composable resolves per-app RBAC

`useRole(application, userGroups)` SHALL resolve the caller's effective role
for an application; `getCurrentUserGroups()` SHALL read the current user's
groups; `hasAnyRole(application, userGroups)` SHALL return whether the caller
holds any of the application's roles.

@e2e exclude retrofit composable-contract spec — `useRole`, `getCurrentUserGroups`, `hasAnyRole` return-value contracts are verified by Vitest unit tests; role-gated UI surface is covered by the buildiq-rbac Playwright tests

#### Scenario: Resolve no role

- **WHEN** the caller belongs to no group mapped on the application
- **THEN** `hasAnyRole` returns false and `useRole` resolves the lowest role

### Requirement: Object and settings stores hold OR base config

The object store SHALL configure the OR base URLs (`configure`), register an
object type to its schema/register (`registerObjectType`), and fetch objects of
a type (`fetchObjects`). The settings store SHALL fetch and save app settings
(`fetchSettings`, `saveSettings`). The store root SHALL initialise the modules
(`initializeStores`).

@e2e exclude retrofit store-contract spec — `configure`, `registerObjectType`, `fetchObjects`, `fetchSettings`, `saveSettings`, `initializeStores` are Vuex/Pinia store contracts verified by Vitest unit tests; store behaviour in context is exercised by the settings-and-observability Playwright tests

#### Scenario: Fetch objects of a registered type

- **WHEN** a view fetches objects for a registered type
- **THEN** the store resolves the type's register/schema and returns the objects

### Requirement: Slug utilities, permissions modal and settings views

`utils/slugPattern` SHALL kebab-case an input (`toKebabCase`) and validate a
slug against the shared pattern (`validateSlug`). `PermissionsModal` SHALL sync
the editable permissions from the application (`syncFromApplication`), expose
the group options (`groupOptions`, `handler`), save them (`save`), and close
(`onClose`). The settings views (`settings/AdminRoot`, `settings/Settings`)
SHALL load on `created` and `Settings` SHALL persist on `save`.

@e2e exclude retrofit component/utility-contract spec — `toKebabCase`, `validateSlug`, `syncFromApplication`, `groupOptions`, `save`, `onClose` on `PermissionsModal`, and settings-view `created`/`save` lifecycle hooks are component/utility contracts verified by Vitest unit tests; slug validation is covered by the application-creation-wizard Playwright tests

#### Scenario: Validate a slug

- **WHEN** `validateSlug` receives a non-kebab string
- **THEN** it returns an invalid result

#### Scenario: Save permissions

- **WHEN** the user edits group roles and saves in the permissions modal
- **THEN** the modal emits the updated permissions and closes

### Requirement: Per-user preferences endpoint reads and writes sanitised keys

`PreferencesController` SHALL read a per-user preference (`getPreference`) and
write/clear one (`setPreference`), requiring an authenticated user (401
otherwise), sanitising the key to a safe charset under the `pref_` namespace
(rejecting an empty sanitised key with 400), and clearing the value when an
empty string is written. Both endpoints SHALL return `{value: string|null}`.

@e2e exclude pure-backend preferences endpoint — key-sanitisation, namespace scoping, empty-value clear, and 401/400 error responses are verified by Newman REST tests; no Playwright-testable UI surface for the preferences controller directly

#### Scenario: Reject an unsafe key

- **WHEN** a request supplies a key that sanitises to empty
- **THEN** the controller returns 400 without touching IConfig

#### Scenario: Clear a preference

- **WHEN** `setPreference` is called with an empty value
- **THEN** the controller deletes the stored user value and returns `{value: null}`

### Requirement: Shell manifest build uses the shared buildManifest pipeline

Buildiq's own app-shell bootstrap (`src/main.js`) SHALL build its
effective runtime manifest via `@conduction/nextcloud-vue`'s shared
`buildManifest(base, fragments, menuLayout)` utility rather than a
locally re-implemented merge function. The app-local step SHALL be
limited to collecting `src/manifest.d/*.json` fragments (via
`require.context`, per ADR-037) and declaring `src/menu-layout.json`
(`relocations`, `removals`, `settingsSection`); no app code SHALL
re-implement `mergeMenuItems`, `applyMenuRelocations`,
`applyMenuRemovals`, or `applySettingsSection`.

#### Scenario: Manifest build delegates to the shared util

- **WHEN** the Buildiq shell boots and resolves its effective manifest
- **THEN** the merge of the bundled base manifest with every
  `src/manifest.d/*.json` fragment is performed by
  `@conduction/nextcloud-vue`'s `buildManifest()`
- **AND** no locally-defined function duplicates its relocation, removal,
  or settings-foldout-placement logic

#### Scenario: Existing pages and menu entries remain reachable

- **WHEN** `buildManifest()` runs with an empty (no-op) `menu-layout.json`
- **THEN** the resolved `pages[]` and `menu[]` arrays are equivalent to the
  arrays produced by the prior local `mergeManifestFragments()` function
- **AND** every previously-reachable route stays reachable through its
  existing menu entry or the settings foldout

### Requirement: Production build ships without full source maps and without unused dependencies

The production webpack build (`NODE_ENV=production`) SHALL set `devtool: false` for all entries (`main`, `adminSettings`, `builder`) — no `.map` file SHALL be emitted alongside the minified `js/` output. Development builds SHALL continue to use `cheap-source-map` for fast, line-level debugging. `package.json` SHALL NOT declare a runtime dependency that no file under `src/` imports.

#### Scenario: Production build emits no source maps

- **WHEN** `npm run build` runs with `NODE_ENV=production`
- **THEN** the `js/` output directory SHALL contain no `.map` files for `buildiq-main.js`, `buildiq-settings.js`, or `buildiq-builder.js`

#### Scenario: Development build keeps fast source maps

- **WHEN** the dev build runs (`NODE_ENV=development`)
- **THEN** `webpackConfig.devtool` SHALL be `cheap-source-map`

#### Scenario: No unused runtime dependency ships in package.json

- **WHEN** auditing `package.json` `dependencies` against actual imports under `src/`
- **THEN** every listed dependency SHALL have at least one importing file, OR be justified in a code comment (e.g. a peer dependency required transitively by a declared dependency)
