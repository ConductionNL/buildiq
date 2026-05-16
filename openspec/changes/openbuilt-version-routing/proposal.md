---
kind: code
depends_on: ["openbuilt-versioning-model"]
---

## Why

The foundation spec `openbuilt-versioning-model` (spec C) ships the two-object
versioning model (`Application` + `ApplicationVersion`) and per-version registers,
but defines no URL contract for reaching a specific version. Without version-aware
routing, CnAppRoot always serves the same (production) manifest regardless of which
version an admin or builder wants to inspect, making the versioned model effectively
unreachable from the frontend. This spec defines that URL contract: a
`?_version=<versionSlug>` query parameter on both the manifest endpoint and the
builder paths, with server-side RBAC so end users always see only production.

## What Changes

- **`GET /api/applications/{slug}/manifest`** gains an optional `?_version=<versionSlug>`
  query parameter. Without the param the endpoint continues to return the production
  version's manifest (`Application.productionVersion`). With the param it returns the
  specified version's manifest — subject to an editor/owner RBAC gate (viewers and
  non-members receive `404`, not `403`; unknown slugs also `404`).
- **`ManifestResolverService`** (NEW or modify) encapsulates the two-step slug resolution
  (Application by `slug` → ApplicationVersion by `application` + `slug`) and the RBAC
  gate, leaving `ManifestController` thin.
- **Builder paths** (`/builder/:slug/schemas`, `/builder/:slug/schemas/:schemaId`,
  `/builder/:slug/pages`, `/builder/:slug/:pathMatch(.*)?`) each gain a `?_version=`
  reader on the view side; no new Vue Router route entries are required.
- **`useApplicationVersion(appSlug, versionSlug)`** (NEW composable) resolves a version
  slug to an `ApplicationVersion` record for use by builder views.
- **`buildVersionedRoute(routeName, params, currentVersion)`** (NEW helper in the router
  file) ensures internal navigation always forwards the active `?_version=` param —
  preventing accidental version-strip on link clicks.
- **View-level reads** in `SchemaDesignerView`, `PageDesignerView`, `BuilderHostView`,
  and `PageDesignerHostView` each read `$route.query._version` and pass it down.
- **`schemas.js` store** accepts an optional `versionSlug` and routes the register name
  accordingly: `openbuilt-{appSlug}-{versionSlug}` (from spec C's register naming
  convention).
- **Tests**: new PHPUnit tests for the manifest RBAC gate; new Playwright e2e test for
  bookmarkability (reload preserves `?_version`), 404 for unauthorised callers on
  non-production versions, and default-version resolution.

## Capabilities

### New Capabilities

- `version-routing`: Version-aware `?_version=<versionSlug>` URL contract for both the
  manifest endpoint and builder paths. Owns `ManifestResolverService` (or modify), the
  `useApplicationVersion` composable, the `buildVersionedRoute` router helper, and the
  view-level `_version` readers in the four builder views and the schemas store.

### Modified Capabilities

_(none — the manifest endpoint is owned by the existing `openbuilt-application-register`
capability; this spec adds a query-param contract that is purely additive and does not
change any existing requirement in that spec)_

## Impact

- **New PHP**: `lib/Service/ManifestResolverService.php` (resolve slug + optional
  version-slug → ApplicationVersion → manifest; RBAC gate; 404 on miss/unauthorised)
- **Modified PHP**: `lib/Controller/ManifestController.php` (or class name as found at
  apply time) — add `?_version=` delegation to `ManifestResolverService`
- **No new routes** in `appinfo/routes.php` — existing manifest route gains a
  query-param contract
- **New JS**: `src/composables/useApplicationVersion.js`
- **Modified JS**:
  - `src/router/index.js` — add `buildVersionedRoute` helper
  - `src/views/SchemaDesigner.vue` — read `_version` from `$route.query`
  - `src/views/PageDesigner.vue` — same
  - `src/views/BuilderHost.vue` — same
  - `src/views/PageDesignerHost.vue` — same
  - `src/stores/schemas.js` — accept `versionSlug`, compute register name
- **New tests**: `tests/Unit/Controller/ManifestControllerTest.php` (modify or new),
  `tests/e2e/version-routing.spec.ts` (new)
- **OpenRegister dependency**: relies on the `Application` + `ApplicationVersion` schemas
  delivered by `openbuilt-versioning-model`
- **ADR dependencies**: ADR-002 (versioned deployment model), ADR-022 (consume OR
  abstractions), ADR-024 (manifest), ADR-031 (declarative vs imperative)
