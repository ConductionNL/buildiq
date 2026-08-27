## Context

Both defects sit on the same route surface — the per-published-app
`/builder/{slug}` runtime — but are independent bugs in adjacent code:

1. `AppNavigationService::registerNavEntries` (~line 185) hand-builds the nav
   entry's `href` as `'/apps/buildiq/builder/'.$slug`, a root-relative path
   with no `/index.php` prefix. On instances that cannot hide the front
   controller (no URL rewriting — the default posture of
   nextcloud-docker-dev), the only working URL is
   `/index.php/apps/buildiq/builder/{slug}`, so the nav entry 404s. The
   sibling icon URL two lines above (line ~176) already builds correctly via
   `IURLGenerator::linkToRouteAbsolute('buildiq.icon.iconLight', ...)` — the
   href just never adopted the same pattern.
2. `ApplicationsController::getManifest` (~line 250) never injects the
   Application's authoritative `name` into the manifest it returns. The
   runtime's top-bar rebrand (`src/builder.js::brandTopBar`, ~line 162, fed by
   the boot code at ~line 271: `const appName = (manifest.name ||
   manifest.title || slug)`) falls back to the raw slug whenever the stored
   manifest blob's own `name` field is stale or absent, so the top bar shows
   e.g. `pet-store` instead of `Pet Store`.

## Goals / Non-Goals

**Goals:**
- Make the nav-entry `href` resolve correctly regardless of whether the
  target instance requires `/index.php` in the URL.
- Guarantee `getManifest`'s response always carries the Application's
  current, cased display name as its top-level `name`, independent of
  whatever the stored manifest blob happens to contain.

**Non-Goals:**
- No change to `src/builder.js` or any other frontend file — it already
  reads `manifest.name` correctly; the bug is that the value it reads was
  never guaranteed to be correct.
- No change to route registration, schema shape, or the manifest's other
  fields.
- No RBAC, versioning, or lifecycle behaviour changes.

## Declarative-vs-imperative decision

N/A — bug fixes to existing imperative controller/service code, no new
declarative behaviour.

## Decisions

### Decision 1: Generate the nav href via `IURLGenerator::linkToRoute`, not a hand-built string

Replace `$appUrl = '/apps/buildiq/builder/'.$slug;` with
`$appUrl = $this->urlGenerator->linkToRoute('buildiq.dashboard.builder', ['slug' => $slug]);`
The route `buildiq.dashboard.builder` (declared in `appinfo/routes.php` as
`['name' => 'dashboard#builder', 'url' => '/builder/{slug}', 'verb' =>
'GET', ...]`) already exists and is exercised by direct browser navigation;
this decision only changes how the nav entry's link to it is generated.
`$this->urlGenerator` is already an injected constructor dependency of
`AppNavigationService`, used one method up for the icon URL, so no new
dependency is introduced.

**Alternative considered**: detect the front-controller setting
(`config.php`'s `htaccess.RewriteBase` / `overwrite.condaddress` or similar)
and conditionally prefix `/index.php`. Rejected — this duplicates logic the
Nextcloud core `IURLGenerator` already owns and centralises correctly;
reimplementing it app-side is exactly the kind of drift this bug already
demonstrates.

### Decision 2: Inject the Application's `name` into the manifest at the same site as `injectOwnerSignal`

`getManifest` already has a proven "inject a derived field into the manifest
before returning it" pattern: `injectOwnerSignal($manifest, $applicationArray,
$caller)`, called at ~line 251, sets `manifest.runtime.user.isOwner` from the
Application's `permissions`. Follow the same shape: after that call (or
folded into an adjacent statement), set
`$manifest['name'] = $applicationArray['name'] ?? $manifest['name'] ?? $slug;`
so the authoritative, cased name from the Application entity always wins over
whatever (possibly stale or absent) `name` the manifest blob carries. This
requires no new service and no frontend change — `builder.js` already prefers
`manifest.name` first.

**Alternative considered**: fix the drift at its source by re-syncing the
manifest blob's `name` field whenever the Application is renamed (a
write-side fix). Rejected for this change — it would require locating every
write path that can rename an Application and is a larger, riskier surface
than a single read-side normalisation in `getManifest`; it can be considered
separately if further drift sources are found.

## Cross-instance URL generation

Nextcloud's `IURLGenerator::linkToRoute()` resolves a route name to a path
and automatically includes the `/index.php` front-controller segment when
the instance's configuration requires it (no URL rewriting available or
enabled), and omits it when rewriting is active. A hand-built string like
`'/apps/buildiq/builder/'.$slug` can never reflect this per-instance
setting — it is baked in at write time with no way to know, from inside
`AppNavigationService`, whether the current instance needs the prefix. Every
other link-producing call site in this app (e.g. the icon URL via
`linkToRouteAbsolute`) already delegates to `IURLGenerator` for exactly this
reason; this change brings the nav-entry `href` in line with that existing
convention rather than introducing a new one.

## Risks / Trade-offs

- [Risk] `linkToRoute` returns a relative path (no scheme/host), matching the
  existing `href` shape used by other nav entries in the app menu — this is
  intentional and consistent with how Nextcloud's own nav entries behave, not
  a regression.
  → Mitigation: none needed; this matches the existing contract of
  `INavigationManager` entries elsewhere in the codebase (they are relative
  paths, not absolute URLs), unlike the icon URL which deliberately uses the
  absolute variant for `<img>`-src portability.
- [Risk] Some existing installs may have manifests whose `name` field was
  hand-edited to intentionally diverge from the Application's `name` (e.g. a
  cosmetic override in the raw JSON editor).
  → Mitigation: none provided by this change — the Application's `name` is
  the single authoritative display identity per the manifest-drift bug this
  change fixes; any legitimate need for a manifest-level display-name
  override is out of scope and would need its own capability if requested.

## Migration Plan

No data migration. Both fixes take effect on the next request after
deployment; no cache-busting or background job is required.
