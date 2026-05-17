## Context

OpenBuilt currently registers a single static top-bar entry for the builder shell via
`appinfo/info.xml`. Published virtual apps are invisible in the Nextcloud navigation until a
user manually navigates into the shell. This spec adds per-app dynamic nav entries, the icon
pipeline that backs them, and the small UX improvements (icon in `ApplicationCard`, removal of
the redundant Live chip) that complete the navigation experience.

The implementation spans five areas (see proposal) across three PHP artefacts, two
Vue artefacts, one JSON schema patch, and one repair-step extension. The design is deliberately
thin on each axis: nav wiring is a closure in `boot()`, icon serving is a one-method controller
+ one-method service, and the detail-page icon section reuses OR's files-attached-to-object
endpoint that the app already depends on (per ADR-001).

## Goals / Non-Goals

**Goals:**

- Register a dynamic per-app nav entry in `INavigationManager` per published Application,
  gated by the existing `permissions` RBAC block (plus the new `group:*` wildcard).
- Serve per-app icons via two typed endpoints backed by OR-attached files with a fallback chain.
- Expose an icon upload/preview section on the Application detail page (reusing existing
  detail-page tab hooks).
- Patch the `Application` schema with optional top-level `icon` and `iconDark` fields
  (`{ ref: "<filename>" }`), sibling to `slug`, `name`, `manifest`, and `permissions` in
  `lib/Settings/openbuilt_register.json`. Icons are admin-side metadata about the virtual
  app, not part of the manifest blob the citizen developer designs.
- Render the icon in `ApplicationCard.vue` and remove the duplicate Live chip.
- Seed demo icon files on the Hello World Application.

**Non-Goals:**

- Per-version icons (ADR-002: icons live on `Application`, not on `ApplicationVersion`).
- Animated or raster icon formats (SVG only in v1; future change extends the fallback chain).
- Public (unauthenticated) icon fetching (icons require a valid NC session; the nav entry
  itself is the visibility gate).
- Icon-search or icon-picker UI beyond file upload/remove (a picker over OR's icon library is
  a separate UX change).
- Cache invalidation API for the 60-second HTTP cache (the TTL is short enough for the
  existing use case; a purge endpoint can be added later if needed).
- Group management UI or any changes to how `permissions` principals are written.

## Mixed-spec rationale

The proposal declares `kind: code`. This is a code-dominant change. The only configuration
touch is a small schema patch in `lib/Settings/openbuilt_register.json` adding two top-level
properties to the `application` schema (≤15 LOC in the JSON file). The patch is tightly
coupled to the PHP icon-service code introduced by this spec and has no standalone value.
The bounded config touch satisfies ADR-032's thin-glue exception for mixed specs written as
`kind: code`.

Because the icon fields live on the Application record (not inside the manifest blob), this
spec has **no upstream coupling** to `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`.
No nextcloud-vue PR is required.

## Declarative-vs-imperative decision

Per ADR-031, the default is to use OR's declarative metadata engines for business logic.
Three behaviours in this spec require explicit decisions:

**1. Resolve the icon URL for a given application slug**

*Declarative option*: an `x-openregister-calculations` field on the `Application` schema
deriving the URL from `icon.ref` + a base-URL constant.

*Decision*: **imperative** (`IconService`). The URL involves `IURLGenerator::linkToRouteAbsolute`
(cross-system call, not a string concatenation), an explicit fallback chain that reads from
the filesystem (`/img/app.svg`), and per-request HTTP-cache header generation. These are
outside OR's calculation vocabulary.

*ADR-031 classification*: §Exceptions — "lifecycle guard / cross-system glue". Documented
here as the exception; no `x-openregister-calculations` block is added to the schema.

**2. Determine nav visibility for a given user against an Application's permissions**

*Declarative option*: an `x-openregister-relations` field + a role-derived view that OR
surfaces as a filtered list.

*Decision*: **imperative** (`AppNavigationService` + per-entry closure). Nav-visibility must
run at boot time, must call `IGroupManager::getUserGroupIds()` inside the closure (per-request
NC API call), and must produce a callable closure for `INavigationManager::add()`. Producing a
closure for a Nextcloud-owned manager is not a stored-relation shape.

*ADR-031 classification*: §Exceptions — "lifecycle guard / cross-system glue". Documented
here as the exception.

**3. Update the published-app nav list when an Application transitions draft ↔ published**

*Decision*: **declarative by default — nothing to implement**. The gating closure in each
nav entry reads the Application's `status` and `permissions` from OR per request. No listener,
no writeback, no service method needed. Draft apps' entries never satisfy the `status ==
published` guard in the closure, so they are automatically invisible. This happens for free via
the existing `x-openregister-lifecycle` state machine.

## Decisions

### Decision 1 — Icon storage: OR files-attached-to-object (ADR-001)

Icon bytes live on the Application record as OR-attached files. The manifest references them
by filename in `{ ref: "<filename>" }` shape. `IconService` resolves the filename to a file
stream via OR's attachment endpoint. This is the canonical ADR-001 pattern; no alternative
was considered for this spec.

### Decision 2 — Fallback chain direction

For the light icon: `icon.ref` → `/img/app.svg` (OpenBuilt's own branding icon).
For the dark icon: `iconDark.ref` → `icon.ref` → `/img/app-dark.svg` →
`/img/app.svg`.

Rationale: an app that uploads only a light icon still gets a reasonable dark-mode fallback
via openbuilt's own dark icon. An app that uploads neither gets the OpenBuilt branding icon —
visually consistent for "not yet customised" apps.

### Decision 3 — Nav entry registration location: `Application::boot()`

Entries are registered in `boot()`, not in a listener, because `INavigationManager` binds at
boot time in NC. `AppNavigationService` is injected via the DI container (lazy, one
instantiation per request). The service queries OR for `status == published` applications; the
gating closure is per-entry and per-request.

### Decision 4 — `group:*` sentinel in permissions

`group:*` in any role array (`owners`, `editors`, or `viewers`) makes the nav entry visible to
all signed-in users. This is the canonical way to publish an app publicly without enumerating
all NC groups. Implementation: `AppNavigationService` checks for the literal string `group:*`
in the union of all three role arrays before running the group-intersection check.

Rationale for a sentinel rather than a separate boolean field: keeps the data model backwards-
compatible; the `permissions` block can express the full principal-set in one field family.

### Decision 5 — Detail-page icon section: reuse existing tab/section hooks

The icon upload UI is added as a new tab on the Application detail page via the existing tab
mechanism in `SchemaDesigner.vue` (or the equivalent detail-page extension point). No new top-
level view is created. The uploader calls OR's standard files-attached-to-object endpoint
directly from the frontend; no new openbuilt-side upload endpoint is needed.

### Decision 6 — Icon endpoint access: any signed-in user

`GET /apps/openbuilt/icons/{slug}.svg` is accessible to any authenticated NC user. The
nav-entry visibility check is the gate for "should this user see the nav entry"; once they can
see the entry and click it, their browser fetches the icon. Restricting the icon endpoint to
RBAC-eligible users would break icon rendering for users who are eligible but whose group
memberships aren't resolved at icon-fetch time (e.g. in a navigation pre-load). The icon
itself carries no sensitive information.

### Decision 7 — HTTP cache: `Cache-Control: public, max-age=60`

60-second TTL is short enough that icon changes are visible within a minute but long enough to
avoid per-request OR calls for every active user. The "public" directive is intentional: the
icon URL path contains the slug (not a per-user path), so intermediate caches can share the
response safely for the 60-second window.

## Seed Data

Per ADR-001 §Consequences ("Export/clone/transfer of an app drags its assets along"), the
seed Hello World Application MUST have demo icon files attached at install time. The
`SeedHelloWorld` repair step is extended to attach two small SVG files to the seeded record
using OR's files-attached-to-object endpoint.

**`app-icon.svg`** (light icon — Conduction cobalt `#4376FC` fill, 24×24 viewBox):

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
  <path fill="#4376FC" d="M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z"/>
</svg>
```

**`app-icon-dark.svg`** (dark / light-on-dark icon — `fill="#fff"` for dark Nextcloud themes):

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
  <path fill="#fff" d="M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z"/>
</svg>
```

The repair step attaches these files to the seeded Application via
`ObjectService::addFile(objectUuid, filename, content, mimeType)` (or the equivalent OR API
call). The attachment step is idempotent — guarded by checking whether a file with that name
already exists on the record.

The repair step patches the seeded Application's **top-level** fields to:
```json
"icon":     { "ref": "app-icon.svg" },
"iconDark": { "ref": "app-icon-dark.svg" }
```
(sibling to `slug`, `name`, `manifest`, `permissions` — not inside `manifest`).

## Risks / Trade-offs

- **OR files-attached-to-object API stability** → `IconService` wraps the OR call in a
  `try/catch`; on any error it falls back to the filesystem icon rather than returning 5xx.
- **`INavigationManager` closure overhead on large installs** (many published apps × many
  users) → `AppNavigationService` fetches the published-app list once per request and caches
  it in a private property; closures are cheap PHP callables, not objects.
- **Nav entry ordering** — multiple virtual apps could collide on `order` → entries are sorted
  alpha by `name` and placed at `order = 1000 + hash(slug) % 1000` to spread them without
  a fixed sequential counter (which would require a writeback on every new publish).
- **CSP / MIME** — the icon endpoint sets `Content-Type: image/svg+xml` explicitly; without
  this, Nextcloud's default `application/octet-stream` would block inline-SVG rendering in
  nav icon `<img>` tags under a strict CSP.

## Migration Plan

No schema migration is needed. `icon` and `iconDark` as top-level Application properties
are optional; all existing Application records are valid after the JSON patch. The schema is
patched via the existing `InitializeSettings` repair step which calls
`ConfigurationService::importFromApp('openbuilt')` — this is a re-import that is safe to
re-run against an installed instance.

No database column changes. No route renaming. The `ApplicationCard.vue` Live chip removal is
a pure UI change with no API or data impact.

Rollback: remove the three new PHP files, revert the four file edits, and re-run
`InitializeSettings`. Nav entries vanish on next reboot because they are registered at boot
time, not stored.

## Open Questions

None at spec-write time. Earlier OQ-1 (upstream `nextcloud-vue/src/schemas/app-manifest.schema.json`
coupling) was resolved by moving `icon` and `iconDark` to top-level Application fields
instead of putting them inside the manifest object — see Mixed-spec rationale and the
spec for `openbuilt-application-register` for the locked decision.
