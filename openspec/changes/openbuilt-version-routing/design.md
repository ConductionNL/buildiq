## Context

`openbuilt-versioning-model` (spec C / ADR-002) splits the application model into
`Application` (logical) + `ApplicationVersion` (deployable runtime), with each version
owning its own OR register named `openbuilt-{appSlug}-{versionSlug}`. However, spec C
ships no URL contract for reaching anything other than the production version — the
existing `GET /api/applications/{slug}/manifest` endpoint resolves to
`Application.productionVersion`'s manifest unconditionally, and the builder paths
(`/builder/:slug/…`) carry no version context.

This spec adds the query-parameter routing layer that makes the versioned model
reachable: `?_version=<versionSlug>` on both the manifest endpoint and the builder
paths. The slug-to-record resolution and RBAC gate live server-side in a new
`ManifestResolverService`; the frontend views read `$route.query._version` and
thread it through the composable and store layers.

## Goals / Non-Goals

**Goals:**

- Define and enforce the `?_version=<versionSlug>` query parameter contract on
  `GET /api/applications/{slug}/manifest`.
- Implement server-side RBAC on versioned manifest access: viewers and non-members
  receive `404` (not `403`) for non-production versions; unknown version slugs also
  `404`.
- Thread `?_version=` through the four builder views (`SchemaDesignerView`,
  `PageDesignerView`, `BuilderHostView`, `PageDesignerHostView`) to the schemas store
  and `useApplicationVersion` composable.
- Ship `buildVersionedRoute` helper to prevent accidental version-param strip on
  internal navigation.
- Ship PHPUnit tests for manifest RBAC gate + Playwright e2e tests for bookmarkability,
  404 on unauthorised, and default-version resolution.

**Non-Goals:**

- The version-switcher UI on the detail page — `openbuilt-app-detail-overview` (spec B)
  owns it; this spec only defines the URL contract the switcher will emit.
- The promotion endpoint — `openbuilt-version-promotion` (spec D).
- The creation wizard — `openbuilt-app-creation-wizard` (spec F).
- Backwards-compatibility redirects for old `/builder/{slug}/…` paths without
  `?_version=` — the default rule (most-upstream-non-production fallback) covers this
  transparently; no redirect, no deprecation warning.
- Separate admin gate for Nextcloud admins — they are explicitly NOT auto-granted (same
  as spec D; documented in §Decision 7 below).
- The `getDistinctActorCount` aggregation — a separate OR-side change.

## Decisions

### Decision 1 — Query parameter name: `?_version=<versionSlug>` (underscore-prefix mandatory)

The parameter name is `_version`, not `version`. The leading underscore is OpenBuilt's
"namespace marker" for system-reserved query params. Citizen developers building
virtual apps with OpenBuilt can freely add `?version=...` query params to their own
virtual apps' routes without risk of collision with the platform's version-switching
mechanism. Without the prefix, a virtual app's user-defined `?version=` would be
silently interpreted by the manifest endpoint as a version-switch request, producing a
confusing or unauthorised-looking 404.

**Why `_` specifically:** the `_` prefix has precedent in OR's own system-param
namespace and is visually distinct without requiring URL-encoding. Alternatives
considered: `v=` (too terse, still collides), `ob_version=` (verbose, still
collides), a path segment (would require new routes and breaks the existing manifest
endpoint contract — rejected on scope).

### Decision 2 — Default routing (no `?_version=` param)

When `?_version=` is absent, `ManifestResolverService` resolves to
`Application.productionVersion`. End users at the canonical URL never see anything but
production. This is the correct default: external deep-links, bookmarks, and OEmbed
integrations that do not carry `?_version=` continue to work correctly without any
schema change or migration.

For **builder paths** the default is different: when `?_version=` is absent from a
`/builder/:slug/…` URL, the frontend resolves to the **most-upstream non-production
version** — i.e. the version in the `promotesTo` chain that has no upstream pointing
at it — falling back to production if no non-production version exists. Rationale:
an admin who clicks `/builder/hello-world/schemas` is almost always working on the
development version, not production. Defaulting to production would be confusing and
dangerous (an accidental manifest save would overwrite the live app). The composable
`useApplicationVersion(appSlug, undefined)` encapsulates this fallback.

### Decision 3 — Server-side resolution + gate in `ManifestResolverService`

Resolution is a two-step lookup: (1) find the Application record by `slug`, then
(2) find the ApplicationVersion record by `application` relation + `slug`. Both steps
go through OR's `ObjectService` (ADR-022 — no app-local DB).

The RBAC gate is applied **only** to non-production versions:

| Caller | No `?_version=` (production) | `?_version=<prod-slug>` explicit | `?_version=<non-prod-slug>` |
| --- | --- | --- | --- |
| Viewer | `200` manifest | `200` manifest | `404` |
| Non-member | `200` manifest | `200` manifest | `404` |
| Editor / Owner | `200` manifest | `200` manifest | `200` manifest |
| NC Admin (not in permissions) | `200` manifest | `200` manifest | `404` |

Importantly, `404` is returned for both "version doesn't exist" and "caller can't see
this version". This is intentional: leaking the existence of a non-production version
to viewers/non-members would allow enumeration of version slugs.

`CnAppRoot` does not need to know about RBAC: it just sends `?_version=<slug>` and
renders whatever manifest the server returns. On `404` it shows a "version not found"
message that is identical for both the "doesn't exist" and the "you can't see it"
cases.

### Decision 4 — Builder-path version reading: `$route.query._version` in each view

No new Vue Router route entries are added. The four builder views (`SchemaDesignerView`,
`PageDesignerView`, `BuilderHostView`, `PageDesignerHostView`) each read
`this.$route.query._version` in `created()`/`mounted()` (or the equivalent Options API
lifecycle hook) and pass it to `useApplicationVersion(appSlug, versionSlug)`.

**Why read per-view rather than in a route guard or a router plugin:**
(a) the guard approach would need to know about every builder sub-route, coupling the
router to the version system; (b) Vue Router's default behaviour already preserves
query params across in-app navigation and on reload — there is nothing to "enforce"
at the router level. The views just read what the URL already contains.

### Decision 5 — `useApplicationVersion(appSlug, versionSlug)` composable

The composable accepts `appSlug: string` and `versionSlug: string | undefined`. It
returns a reactive `{ applicationVersion, loading, error }` object. Internally it calls
the existing ApplicationVersions CRUD endpoint from spec C
(`GET /api/applications/{slug}/versions/{versionSlug}`) to resolve the record. When
`versionSlug` is `undefined`, it calls the index endpoint and applies the "most-upstream
non-production fallback" rule (walk `promotesTo` chains to find the upstream with no
predecessor — implementation detail: `versions.filter(v => !versions.some(u => u.promotesTo === v.uuid))`).

The composable is the single source of truth for version resolution on the frontend.
All four builder views delegate to it rather than duplicating the lookup.

### Decision 6 — `buildVersionedRoute(routeName, params, currentVersion)` helper

A small helper added to `src/router/index.js` (or a sibling file imported by it).
Signature:

```js
buildVersionedRoute(routeName, params = {}, currentVersion = undefined)
// → { name: routeName, params, query: currentVersion ? { _version: currentVersion } : {} }
```

Callers use this instead of constructing route objects directly whenever they link to a
builder path. Without it, an `<NcButton @click="$router.push({name:'schemas'})">` would
silently strip `?_version=staging` from the URL, sending the admin back to the default
(non-production) version unexpectedly. The helper is small, focused, and testable as a
pure function.

### Decision 7 — Nextcloud admin gate exception

Nextcloud admins are NOT auto-granted access to non-production versions. They must appear
in the Application's `permissions.{owners, editors}` arrays. This is consistent with
spec D's RBAC model and ADR-002's intent: admin power applies to Nextcloud-level app
management, not to individual virtual apps' version access. The `ManifestResolverService`
RBAC check does NOT special-case `IGroupManager::isAdmin()`.

### Decision 8 — 404 (not 403) for non-production version access by unauthorised callers

The 404 response shape for unauthorised non-production access is:

```json
{"status": 404, "message": "Version not found"}
```

The same shape is used when the version slug does not exist at all. Rationale: returning
403 tells an attacker that the version exists; 404 prevents version-slug enumeration.
This is the same "treat unauthorised as not-found" pattern used by GitHub for private
repositories and by many API designs where the existence of a resource is itself
sensitive.

## Seed Data Section

Per ADR-001, every register-shipping change documents its seed data. **This spec ships
no register changes and writes no seed data.** Version routing operates entirely on
existing `Application` and `ApplicationVersion` records provisioned by spec C and (for
new installs) by the creation wizard (spec F). No `lib/Repair/*` files are added by
this spec. No entries are added to `lib/Settings/openbuilt_register.json`. No seed
objects are written at install time.

This is explicit and intentional: routing is a behaviour over existing data, not a
data fixture.

## Declarative-vs-Imperative Decision Section

Per ADR-031, every business-logic site in this spec is classified below.

| Concern | Declarative attempt | Final decision | Rationale |
| --- | --- | --- | --- |
| Resolve `?_version=<slug>` → ApplicationVersion record | `x-openregister-calculation` on Application or ApplicationVersion | **Imperative** (`ManifestResolverService`) | The resolution involves a 2-step cross-object lookup (Application by slug → ApplicationVersion by application+slug) + RBAC + 404 handling. OR's calculation vocabulary covers single-row derived fields, not cross-object lookup chains with access-control branches. ADR-031 §Exceptions: cross-row traversal. |
| Default version when `?_version=` absent (most-upstream-non-production fallback for builder paths) | `x-openregister-calculation` traversing `promotesTo` chain on ApplicationVersion | **Imperative** (composable `useApplicationVersion`) | Traversing a linked list of `promotesTo` relations to find the upstream-most record is currently outside OR's stable calculation vocabulary (requires multiple `findAll` + filter, or recursive relation-walking). ADR-031 §Exceptions: cross-row traversal. This is v1 — if OR gains relation-traversal calculations in a future release, this can move declarative. |
| RBAC gate (editor/owner required for non-production version access) | `x-openregister-authorization` block on ApplicationVersion or ManifestController route | **Already declarative** via `permissions.{owners, editors}` block on Application (owned by spec C / ADR-005) | The controller reads the existing RBAC block and enforces it. No new declarative or imperative auth work in this spec — `ManifestResolverService` calls the same permission-resolver helper that spec C already wired. |
| 404 vs 403 branching for unauthorised non-production access | Declarative response-code mapping on the auth block | **Imperative** (explicit branch in `ManifestResolverService`) | The "respond 404 not 403 for auth failures" rule is a deliberate security behaviour (prevent version-slug enumeration). OR's auth blocks produce 403; overriding to 404 requires explicit code in the service. ADR-031 §Exceptions: security-shaped response code override. |
| `buildVersionedRoute` helper (forward `?_version=` on navigation) | Vue Router navigation guard or meta-field convention | **Imperative** (pure helper function) | The helper is a pure function with no side effects and no state. It is not business logic in the ADR-031 sense — it is a safety wrapper around `$router.push`. No declarative analog applies. |

## Risks / Trade-offs

- **Risk: A builder view navigates to another builder route without using `buildVersionedRoute`,
  stripping `?_version=` silently.** → Mitigation: the helper is discoverable in the router
  file alongside all route definitions; a linting rule or code-review check on
  `$router.push({name: 'builder-…'})` without the helper is the follow-up (tracked in
  tasks.md). In the worst case the admin lands on the default (non-production) version —
  disconcerting but not data-destructive.
- **Risk: The "most-upstream-non-production fallback" rule is expensive on large version
  chains** (requires fetching all versions for the app). → Mitigation: version chains are
  expected to be short (2-5 nodes per ADR-002 §Decision); the full-list fetch is
  acceptable. If chains grow large, a dedicated "get head version" endpoint can be added
  as a follow-up.
- **Risk: Playwright bookmarkability test is flaky if the frontend rehydrates the
  composable after reload and the OR fetch races with the assertion.** → Mitigation: the
  test waits for `networkidle` before asserting; `useApplicationVersion` is mocked in the
  unit test and exercises the real OR endpoint only in the e2e test.
- **Risk: The 404-for-unauthorised rule obscures permission errors in the developer
  console**, making it harder to debug access issues during development. → Mitigation:
  `ManifestResolverService` logs a `debug`-level line including the real reason
  (`version_access_denied`) when it returns 404 for an auth reason. The log is server-side
  and not exposed in the HTTP response.
- **Trade-off: No backwards-compatibility shim.** Old `/builder/{slug}/…` links without
  `?_version=` fall to the default (most-upstream non-production fallback). This is
  intentional: the fallback is the sensible default, and adding shims would require
  tracking which links predate the routing spec.

## Open Questions

None — all architectural axes are covered by the locked decisions in the prompt. Concrete
OR API call shapes for multi-step lookup (how to filter ApplicationVersions by
`application` slug + version `slug` in a single call vs two calls) will be resolved at
apply time and tracked in tasks.md. Genuine ambiguities surfaced during artifact
generation are listed in the `DEFERRED_QUESTIONS` block at the end of the delivery
message.
