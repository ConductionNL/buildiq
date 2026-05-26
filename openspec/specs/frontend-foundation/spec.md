---
retrofit: true
---

# frontend-foundation Specification

## Purpose

OpenBuilt's shared frontend foundation: cross-view composables (insights, live
preview, manifest validation, register picking, RBAC role resolution), the
object/settings Vuex stores, the slug utilities, the `PermissionsModal`, the
settings views, and the backend per-user `PreferencesController`. These pieces
underpin the higher-level designer, detail, wizard, and version-routing UIs.

This capability is observed behaviour of those composables, stores, utilities,
modal, settings views, and the preferences endpoint.

## Requirements

### Requirement: Composables encapsulate insights, preview, validation and register-pick

@e2e exclude retrofit composable-contract spec — `useApplicationInsights`, `useLivePreview`, `useManifestValidator`, `useRegisterPicker`, `useApplicationVersion`, `useManifestHistory` return values and reactive-state contracts are verified by Vitest unit tests; no independent Playwright-testable UI surface for composable internals

`useApplicationInsights` SHALL return reactive insights state for a version.
`useLivePreview` SHALL return the live-preview mount/state contract.
`useManifestValidator` SHALL return a manifest validation function and its
reactive error state. `useRegisterPicker(opts)` SHALL return the register
option list and selection contract. `useApplicationVersion` SHALL expose a
default editable version helper (`defaultEditableVersion`) alongside the active
version resolution. `useManifestHistory` SHALL load the version's manifest
history.

#### Scenario: Validate a manifest

- **WHEN** a caller invokes the validator composable's validate function
- **THEN** it returns the validation result and updates the reactive error state

### Requirement: Role composable resolves per-app RBAC

@e2e exclude retrofit composable-contract spec — `useRole`, `getCurrentUserGroups`, `hasAnyRole` return-value contracts are verified by Vitest unit tests; role-gated UI surface is covered by the openbuilt-rbac Playwright tests

`useRole(application, userGroups)` SHALL resolve the caller's effective role
for an application; `getCurrentUserGroups()` SHALL read the current user's
groups; `hasAnyRole(application, userGroups)` SHALL return whether the caller
holds any of the application's roles.

#### Scenario: Resolve no role

- **WHEN** the caller belongs to no group mapped on the application
- **THEN** `hasAnyRole` returns false and `useRole` resolves the lowest role

### Requirement: Object and settings stores hold OR base config

@e2e exclude retrofit store-contract spec — `configure`, `registerObjectType`, `fetchObjects`, `fetchSettings`, `saveSettings`, `initializeStores` are Vuex/Pinia store contracts verified by Vitest unit tests; store behaviour in context is exercised by the settings-and-observability Playwright tests

The object store SHALL configure the OR base URLs (`configure`), register an
object type to its schema/register (`registerObjectType`), and fetch objects of
a type (`fetchObjects`). The settings store SHALL fetch and save app settings
(`fetchSettings`, `saveSettings`). The store root SHALL initialise the modules
(`initializeStores`).

#### Scenario: Fetch objects of a registered type

- **WHEN** a view fetches objects for a registered type
- **THEN** the store resolves the type's register/schema and returns the objects

### Requirement: Slug utilities, permissions modal and settings views

@e2e exclude retrofit component/utility-contract spec — `toKebabCase`, `validateSlug`, `syncFromApplication`, `groupOptions`, `save`, `onClose` on `PermissionsModal`, and settings-view `created`/`save` lifecycle hooks are component/utility contracts verified by Vitest unit tests; slug validation is covered by the application-creation-wizard Playwright tests

`utils/slugPattern` SHALL kebab-case an input (`toKebabCase`) and validate a
slug against the shared pattern (`validateSlug`). `PermissionsModal` SHALL sync
the editable permissions from the application (`syncFromApplication`), expose
the group options (`groupOptions`, `handler`), save them (`save`), and close
(`onClose`). The settings views (`settings/AdminRoot`, `settings/Settings`)
SHALL load on `created` and `Settings` SHALL persist on `save`.

#### Scenario: Validate a slug

- **WHEN** `validateSlug` receives a non-kebab string
- **THEN** it returns an invalid result

#### Scenario: Save permissions

- **WHEN** the user edits group roles and saves in the permissions modal
- **THEN** the modal emits the updated permissions and closes

### Requirement: Per-user preferences endpoint reads and writes sanitised keys

@e2e exclude pure-backend preferences endpoint — key-sanitisation, namespace scoping, empty-value clear, and 401/400 error responses are verified by Newman REST tests; no Playwright-testable UI surface for the preferences controller directly

`PreferencesController` SHALL read a per-user preference (`getPreference`) and
write/clear one (`setPreference`), requiring an authenticated user (401
otherwise), sanitising the key to a safe charset under the `pref_` namespace
(rejecting an empty sanitised key with 400), and clearing the value when an
empty string is written. Both endpoints SHALL return `{value: string|null}`.

#### Scenario: Reject an unsafe key

- **WHEN** a request supplies a key that sanitises to empty
- **THEN** the controller returns 400 without touching IConfig

#### Scenario: Clear a preference

- **WHEN** `setPreference` is called with an empty value
- **THEN** the controller deletes the stored user value and returns `{value: null}`
