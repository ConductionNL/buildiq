# version-routing Specification

## Purpose
TBD - created by archiving change openbuilt-version-routing. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBVR-001 Manifest endpoint accepts optional `?_version=<versionSlug>` query param

The system SHALL accept an optional query parameter `_version` (underscore-prefix form)
on `GET /index.php/apps/openbuilt/api/applications/{slug}/manifest`. The underscore
prefix is OpenBuilt's system-reserved namespace marker — it prevents collision with
user-defined `?version=` query parameters that citizen developers may add to their
own virtual apps' routes.

When `?_version=` is absent the endpoint SHALL return `Application.productionVersion`'s
manifest (existing behaviour — no change). When `?_version=<versionSlug>` is present
the endpoint SHALL resolve and return the named version's manifest subject to the RBAC
gate defined in REQ-OBVR-003.

The endpoint SHALL NOT add new routes in `appinfo/routes.php` — the existing route
entry gains a query-parameter contract only.

#### Scenario: No `?_version=` param returns the production manifest

- **GIVEN** an Application `hello-world` with `productionVersion` pointing at an
  ApplicationVersion with slug `production`
- **WHEN** any caller GETs `/api/applications/hello-world/manifest` (no query param)
- **THEN** the response is `200 application/json`
- **AND** the response body is the manifest of the `production` ApplicationVersion

#### Scenario: `?_version=staging` returns the staging manifest for an authorised caller

- **GIVEN** an Application `hello-world` with an ApplicationVersion slug `staging`
- **AND** the caller is listed in `permissions.editors` or `permissions.owners` on the
  Application
- **WHEN** the caller GETs `/api/applications/hello-world/manifest?_version=staging`
- **THEN** the response is `200 application/json`
- **AND** the response body is the manifest of the `staging` ApplicationVersion

#### Scenario: `?_version=production` with explicit slug returns the production manifest

- **GIVEN** an Application `hello-world` with `productionVersion` pointing at
  ApplicationVersion slug `production`
- **AND** any caller (including viewer or non-member) requests the production slug
  explicitly
- **WHEN** the caller GETs `/api/applications/hello-world/manifest?_version=production`
- **THEN** the response is `200 application/json`
- **AND** the response body is the production manifest
  _(the production version is accessible to all callers regardless of their role)_

#### Scenario: Unknown version slug returns 404

- **GIVEN** an Application `hello-world` with no ApplicationVersion whose slug is
  `nonexistent`
- **WHEN** any caller GETs `/api/applications/hello-world/manifest?_version=nonexistent`
- **THEN** the response is `404 Not Found`
- **AND** the response body is `{"status": 404, "message": "Version not found"}`

### Requirement: REQ-OBVR-002 `ManifestResolverService` owns the two-step slug resolution

The system SHALL implement a `ManifestResolverService` (new file or modify existing
manifest service) that encapsulates the resolution of an application slug + optional
version slug to an `ApplicationVersion` record and its manifest. The service SHALL:

1. Look up the `Application` record by `slug` via OR's `ObjectService`. Return `null`
   (caller maps to `404`) if no Application is found.
2. If `versionSlug` is null or absent, return `Application.productionVersion`'s manifest
   (the relation, resolved to its target object via OR's object service).
3. If `versionSlug` is provided, look up the `ApplicationVersion` whose `application`
   relation points at the found Application AND whose `slug` matches `versionSlug`.
   Return `null` (caller maps to `404`) if no such ApplicationVersion is found.
4. Apply the RBAC gate (REQ-OBVR-003). If the gate fails, return `null` (caller maps to
   `404` — same response as "not found").
5. Return the resolved ApplicationVersion's `manifest` payload.

`ManifestController` (or the class name as found at apply time) SHALL remain thin —
it reads `?_version=` from the request, delegates to `ManifestResolverService`, and
maps `null` → `404` and non-null → `200`.

The `ManifestController` method MUST carry `#[NoAdminRequired]` (the production manifest
is publicly accessible; the RBAC gate lives inside the resolver service).

#### Scenario: Service resolves production manifest without RBAC (no `versionSlug`)

- **GIVEN** an Application with `productionVersion` record available
- **WHEN** `ManifestResolverService::resolve('hello-world', null, $caller)` is called
- **THEN** the service returns the production ApplicationVersion's `manifest` without
  consulting the RBAC gate

#### Scenario: Service performs two-step lookup for a named version

- **WHEN** `ManifestResolverService::resolve('hello-world', 'staging', $caller)` is
  called
- **THEN** the service first looks up the Application by slug `hello-world`
- **AND** then looks up the ApplicationVersion whose `application` matches that
  Application AND whose `slug` is `staging`

#### Scenario: Service returns null for a missing Application

- **WHEN** `ManifestResolverService::resolve('no-such-app', 'staging', $caller)` is
  called
- **THEN** the service returns null (causing the controller to return 404)

### Requirement: REQ-OBVR-003 RBAC gate: editor/owner required for non-production versions; 404 (not 403) on failure

The system SHALL, when `?_version=<versionSlug>` resolves to a non-production version
(i.e. `resolvedVersion.uuid !== Application.productionVersion.uuid`), check the caller's
role against the parent Application's `permissions.{owners, editors}` arrays:

- If the caller is listed in `permissions.owners` OR `permissions.editors` → allow.
- Otherwise (viewer, non-member, Nextcloud admin not in either list) → return `null`
  from the resolver (controller maps to `404`).

The same `404` response body SHALL be returned for both the "version doesn't exist"
case and the "caller is unauthorised" case:

```json
{"status": 404, "message": "Version not found"}
```

**Nextcloud admins are NOT auto-granted** access to non-production versions. An admin
who is not in `permissions.owners` or `permissions.editors` on the Application SHALL
receive `404`, not `200`.

`ManifestResolverService` SHALL log a `debug`-level line with the real reason
(`version_access_denied` + caller uid) when it returns `null` for an RBAC failure.
The log line is server-side only and SHALL NOT be exposed in the HTTP response.

#### Scenario: Viewer receives 404 for non-production version

- **GIVEN** an Application `hello-world` with ApplicationVersion `staging` (not the
  production version)
- **AND** the caller is listed in `permissions.viewers` only
- **WHEN** the caller GETs `/api/applications/hello-world/manifest?_version=staging`
- **THEN** the response is `404 Not Found`
- **AND** the response body is `{"status": 404, "message": "Version not found"}`

#### Scenario: Non-member receives 404 for non-production version

- **GIVEN** the caller is not listed in any of the Application's permission arrays
- **WHEN** the caller GETs `/api/applications/hello-world/manifest?_version=staging`
- **THEN** the response is `404 Not Found`

#### Scenario: Nextcloud admin without per-app role receives 404

- **GIVEN** a Nextcloud admin who is NOT listed in `permissions.owners` or
  `permissions.editors` on Application `hello-world`
- **WHEN** the admin GETs `/api/applications/hello-world/manifest?_version=staging`
- **THEN** the response is `404 Not Found`
  _(NC admin power does NOT auto-grant non-production version access)_

#### Scenario: Editor receives 200 for non-production version

- **GIVEN** the caller is listed in `permissions.editors` on Application `hello-world`
- **WHEN** the caller GETs `/api/applications/hello-world/manifest?_version=staging`
- **THEN** the response is `200 application/json` with the staging manifest

#### Scenario: Owner receives 200 for non-production version

- **GIVEN** the caller is listed in `permissions.owners`
- **WHEN** the caller GETs `/api/applications/hello-world/manifest?_version=staging`
- **THEN** the response is `200 application/json` with the staging manifest

#### Scenario: RBAC failure is logged server-side without leaking to the response

- **GIVEN** a viewer requests a non-production version and is rejected
- **WHEN** `ManifestResolverService` returns null
- **THEN** the server log contains a `debug`-level entry with `version_access_denied`
  and the caller's uid
- **AND** the HTTP response body is `{"status": 404, "message": "Version not found"}`
  (no mention of authorisation)

### Requirement: REQ-OBVR-004 Builder paths read `?_version=` from `$route.query`

The system SHALL read `$route.query._version` in the `created()` or `mounted()` hook
(Options API) of each of the following builder views:

- `src/views/SchemaDesigner.vue`
- `src/views/PageDesigner.vue`
- `src/views/BuilderHost.vue`
- `src/views/PageDesignerHost.vue`

Each view SHALL pass the resolved `versionSlug` (or `undefined` when absent) to
`useApplicationVersion(appSlug, versionSlug)` to obtain a reactive
`applicationVersion` object. No new Vue Router route entries are required — the
existing routes with `:slug` retain their shape; `?_version=` is a query param that
Vue Router already preserves across in-app navigation and on page reload.

#### Scenario: SchemaDesignerView reads `?_version=staging` from the URL

- **GIVEN** the admin navigates to
  `/builder/hello-world/schemas?_version=staging`
- **WHEN** `SchemaDesigner.vue` is mounted
- **THEN** the component calls `useApplicationVersion('hello-world', 'staging')`
- **AND** the schema list is populated from the `staging` ApplicationVersion's register
  (`openbuilt-hello-world-staging`)

#### Scenario: BuilderHostView with no `?_version=` falls back to most-upstream non-production version

- **GIVEN** an Application `hello-world` with versions `development → staging → production`
- **WHEN** the admin navigates to `/builder/hello-world` (no `?_version=`)
- **THEN** the component calls `useApplicationVersion('hello-world', undefined)`
- **AND** `useApplicationVersion` resolves to the `development` version (most-upstream
  non-production, with no predecessor in the chain)

#### Scenario: BuilderHostView with no `?_version=` falls back to production when no non-production version exists

- **GIVEN** an Application `hello-world` with only one ApplicationVersion: `production`
- **WHEN** the admin navigates to `/builder/hello-world` (no `?_version=`)
- **THEN** `useApplicationVersion('hello-world', undefined)` resolves to the `production`
  ApplicationVersion (only version available)

### Requirement: REQ-OBVR-005 `useApplicationVersion(appSlug, versionSlug)` composable

The system SHALL provide a Vue composable at `src/composables/useApplicationVersion.js`
with the signature:

```js
useApplicationVersion(appSlug: string, versionSlug: string | undefined)
// returns: { applicationVersion: Ref<ApplicationVersion|null>, loading: Ref<boolean>, error: Ref<Error|null> }
```

When `versionSlug` is a non-empty string the composable SHALL call
`GET /api/applications/{appSlug}/versions/{versionSlug}` (spec C endpoint) and expose
the resolved `ApplicationVersion` record.

When `versionSlug` is `undefined` (or empty) the composable SHALL call
`GET /api/applications/{appSlug}/versions` (list), filter for versions where no other
version's `promotesTo` points at the current version (i.e. find the upstream-most
non-production version), and fall back to the production version if no non-production
version qualifies. The composable SHALL expose the selected `ApplicationVersion` record.

The composable is the single source of truth for version resolution on the frontend;
all four builder views SHALL delegate to it rather than implementing their own lookup.

#### Scenario: Composable resolves a named version

- **WHEN** `useApplicationVersion('hello-world', 'staging')` is called
- **THEN** `applicationVersion.value` is the ApplicationVersion record whose slug is
  `staging` under Application `hello-world`
- **AND** `loading.value` transitions from `true` to `false` when the fetch completes

#### Scenario: Composable resolves most-upstream non-production version when `versionSlug` is undefined

- **GIVEN** an Application with versions `development → staging → production`
  (where `development.promotesTo === staging`, `staging.promotesTo === production`,
  `production.promotesTo === null`)
- **WHEN** `useApplicationVersion('hello-world', undefined)` is called
- **THEN** `applicationVersion.value` is the `development` ApplicationVersion
  (it is the only version with no other version's `promotesTo` pointing at it)

#### Scenario: Composable surfaces loading and error states

- **WHEN** the fetch is in-flight
- **THEN** `loading.value` is `true`
- **WHEN** the fetch resolves successfully
- **THEN** `loading.value` is `false` and `error.value` is `null`
- **WHEN** the fetch fails
- **THEN** `error.value` holds the caught error and `loading.value` is `false`

### Requirement: REQ-OBVR-006 `buildVersionedRoute(routeName, params, currentVersion)` helper

The system SHALL provide a helper function `buildVersionedRoute` in
`src/router/index.js` (or a sibling file imported by it) with the following contract:

```js
buildVersionedRoute(
  routeName: string,
  params: object = {},
  currentVersion: string | undefined = undefined
): RouteLocationRaw
```

The helper SHALL return `{ name: routeName, params, query: currentVersion ? { _version: currentVersion } : {} }`.

All internal navigation that opens builder paths SHALL use `buildVersionedRoute` instead
of constructing route objects directly. This prevents accidental strip of `?_version=`
from the URL when the admin navigates between builder sub-sections.

#### Scenario: Helper forwards the version param when present

- **WHEN** `buildVersionedRoute('schemas', { slug: 'hello-world' }, 'staging')` is called
- **THEN** the returned object is
  `{ name: 'schemas', params: { slug: 'hello-world' }, query: { _version: 'staging' } }`

#### Scenario: Helper produces no query object when version is absent

- **WHEN** `buildVersionedRoute('schemas', { slug: 'hello-world' }, undefined)` is called
- **THEN** the returned object is
  `{ name: 'schemas', params: { slug: 'hello-world' }, query: {} }`

### Requirement: REQ-OBVR-007 `schemas.js` store accepts `versionSlug` and routes the register name

The system SHALL modify `src/stores/schemas.js` so that every OR call that targets the
per-app register name accepts an optional `versionSlug` parameter. When `versionSlug`
is provided, the register name SHALL be `openbuilt-{appSlug}-{versionSlug}` (matching
spec C's per-version register naming convention). When `versionSlug` is absent or
empty, the store SHALL fall back to the production version's register name (resolved
from `Application.productionVersion.register`).

The store SHALL expose `versionSlug` as a piece of reactive state so that views can
set it once (after resolving via `useApplicationVersion`) and subsequent store calls
automatically target the correct register.

#### Scenario: Store targets the staging register when versionSlug is 'staging'

- **GIVEN** the store's `versionSlug` is set to `'staging'` for Application
  `hello-world`
- **WHEN** the store fetches schemas
- **THEN** the OR call targets register `openbuilt-hello-world-staging`

#### Scenario: Store targets the production register when versionSlug is absent

- **GIVEN** the store's `versionSlug` is not set
- **AND** `Application.productionVersion.register` is `openbuilt-hello-world-production`
- **WHEN** the store fetches schemas
- **THEN** the OR call targets register `openbuilt-hello-world-production`

### Requirement: REQ-OBVR-008 Browser reload preserves `?_version=` (bookmarkability)

The system SHALL not add any logic to "clean" or redirect away from `?_version=` on
page load. Vue Router's default query-param preservation behaviour is sufficient; this
requirement exists to mandate that NO code subverts it (e.g. no `beforeEach` hook that
strips unknown query params, no `router.replace()` call that drops query params on
mount).

An authorised caller who bookmarks `/builder/hello-world/schemas?_version=staging` SHALL
be able to reload the page and land on the same version without being redirected to the
default.

#### Scenario: Reload of a versioned builder URL stays on the same version

- **GIVEN** an authorised editor has navigated to
  `/builder/hello-world/schemas?_version=staging`
- **WHEN** the editor reloads the page (full browser reload)
- **THEN** the page loads with `?_version=staging` still in the URL
- **AND** `SchemaDesigner.vue` resolves to the `staging` ApplicationVersion

#### Scenario: In-app navigation between builder sub-sections preserves `?_version=`

- **GIVEN** the editor is on `/builder/hello-world/schemas?_version=staging`
- **WHEN** the editor clicks a link that uses `buildVersionedRoute('pages',
  { slug: 'hello-world' }, 'staging')`
- **THEN** the URL becomes `/builder/hello-world/pages?_version=staging`
- **AND** `PageDesigner.vue` resolves to the `staging` ApplicationVersion

### Requirement: REQ-OBVR-009 CnAppRoot shows a version-not-found message on 404

The system SHALL ensure that when `CnAppRoot` (in `BuilderHostView` or
`PageDesignerHostView`) receives a `404` response from the manifest endpoint for a
`?_version=<versionSlug>` request, it renders a "version not found" message that is
identical for both the "doesn't exist" and "you can't see it" cases. `CnAppRoot`
SHALL NOT distinguish between the two — it just renders the 404 state.

The message text is the responsibility of `CnAppRoot` (in `@conduction/nextcloud-vue`);
this spec requires only that `BuilderHostView` / `PageDesignerHostView` propagate the
error state from `useApplicationVersion` to the slot or prop that `CnAppRoot` uses
for its error display.

#### Scenario: CnAppRoot shows version-not-found on 404

- **GIVEN** `useApplicationVersion('hello-world', 'nonexistent')` resolves to a 404
- **WHEN** `BuilderHostView` renders
- **THEN** the view shows the "version not found" UI state (no manifest, no stack trace)
- **AND** no HTTP 403 or 401 cue is visible to the caller

