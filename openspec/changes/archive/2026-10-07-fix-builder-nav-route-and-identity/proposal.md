---
kind: code
---

## Why

Two independent defects sit on the same route surface — the per-published-app
`/builder/{slug}` runtime — and both misrepresent the app to the user. First,
the top-bar nav entry for a published app links to a hand-built path that
omits the `/index.php` front-controller segment, so on any instance that
cannot hide the front controller (no URL rewriting — e.g.
nextcloud-docker-dev) clicking the app in the Nextcloud app menu 404s.
Second, the manifest served for a published app never carries the
Application's authoritative, cased display `name`, so when the manifest
blob's own `name` field is missing or has drifted from a rename, the runtime
top-bar falls back to the raw slug (e.g. `pet-store` instead of `Pet Store`).
Both are small, contained backend bugs worth fixing together because they
affect the same navigation-to-runtime path a user takes when opening a
published app.

## What Changes

- `lib/Service/AppNavigationService.php::registerNavEntries` (~line 185): stop
  hand-building `$appUrl = '/apps/buildiq/builder/'.$slug` and instead
  generate the nav entry's `href` via the already-injected `IURLGenerator`:
  `$this->urlGenerator->linkToRoute('buildiq.dashboard.builder', ['slug' =>
  $slug])`. `linkToRoute` includes `/index.php` exactly when the instance
  requires it, so the same code path is correct on both rewrite-hidden and
  front-controller-required instances.
- `lib/Controller/ApplicationsController.php::getManifest` (~line 250, next to
  the existing `injectOwnerSignal` call): inject the Application's current,
  authoritative `name` field into the manifest before it is returned, so
  `manifest.name` always reflects the cased display name regardless of
  manifest-blob drift. No frontend change is needed — `src/builder.js`
  already reads `manifest.name` first (falling back to slug only when it is
  absent).
- No BREAKING changes. Both fixes are additive/corrective within existing
  methods; no new routes, schemas, or public contracts are introduced.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `app-nav-entries`: the nav entry's `href` requirement is amended to require
  generation via `IURLGenerator::linkToRoute`, not a hand-built string, so the
  entry resolves correctly regardless of the instance's front-controller
  (`/index.php`) configuration.
- `buildiq-runtime`: the manifest endpoint requirement is amended to require
  that the response's top-level `name` field always reflects the
  Application's authoritative, cased display name, independent of whatever
  `name` value (if any) is present in the stored manifest blob.

## Impact

- `lib/Service/AppNavigationService.php` — nav entry `href` generation.
- `lib/Controller/ApplicationsController.php` — `getManifest` manifest
  shaping (name injection alongside the existing owner-signal injection).
- `tests/Unit/Service/AppNavigationServiceTest.php` — extended with a case
  asserting `href` comes from `linkToRoute`.
- `tests/Unit/Controller/ApplicationsControllerTest.php` — new file asserting
  `getManifest` always returns the Application's authoritative `name`.
- No route, schema, or frontend changes.
