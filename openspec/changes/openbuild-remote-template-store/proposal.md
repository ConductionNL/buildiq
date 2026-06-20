---
kind: code
depends_on: []
chain:
  - openbuild-remote-template-store
---

## Why

OpenBuild's template catalogue (`openbuild-template-catalogue`) ships four
Conduction-curated seeds and clones them into local virtual apps, but every
template a citizen developer can reach is baked into *this* instance at install
time. There is no way to discover a template that someone else published — no
"app store" surface. Every serious no-code builder (Retool, Appsmith, Budibase)
offers a remote marketplace of starter templates; OpenBuild ships a closed,
four-item catalogue. This change opens the catalogue to a **remote
OpenRegister-backed store**: a configurable Conduction-hosted (or
self-hosted) catalogue from which an integrator can browse, search, and install
`ApplicationTemplate` records into their own instance — re-using the clone path
that already exists. This is the *consume* half of federation; publishing back
to a remote catalogue is explicitly out of scope for this cut.

Everything hard already exists: the `ApplicationTemplate` schema
(slug `application-template`: `slug`, `title`, `description`, `useCase`,
`category`, `manifest`, `companionSchemas`, `isSeeded`, `sourceUrl`, `version`),
the clone-from-template endpoint (`POST
/api/applications/from-template/{templateSlug}`, REQ-OBTC-004/005), and the
gallery surface (`TemplateGallery.vue` + `CloneTemplateDialog.vue`). What's
missing is the network leg: read templates from a *remote* OR instance and feed
the chosen one through the existing local clone. A remote OR instance exposes its
objects over a public objects/federation API, so the remote registry **is** an
OpenRegister instance serving `ApplicationTemplate` objects — no new wire format,
no new schema.

## What Changes

- **NEW** admin app-config values — a registry **base URL** (default a
  placeholder Conduction-hosted catalogue, overridable per instance) and an
  optional read **token**, both stored via `IAppConfig` (`IConfig` app-value)
  and surfaced on the existing OpenBuild admin settings page. The token is
  write-only from the UI (never echoed back) and never reaches the browser.
- **NEW** server-side proxy service — `RemoteTemplateStoreService` fetches
  `application-template` objects from
  `{registryUrl}/index.php/apps/openregister/api/objects/{register}/application-template`
  (with an optional `?_search=` filter), with a timeout, hardened error
  handling, and an **SSRF guard** on the configured URL. The fetch runs
  server-side so the browser never sees the registry URL/token and there is no
  CORS problem.
- **NEW** two controller endpoints on a new `StoreController`:
  `GET /api/store/templates?q=…` (proxied search → remote results) and
  `POST /api/store/templates/{slug}/install` (resolve the remote template
  payload, then clone it locally through the existing `createFromTemplate`
  path). Both carry `#[NoAdminRequired]` and an in-body authorization guard.
- **MODIFIED** the Templates page — it becomes a searchable "store" surface: a
  search box + remote result cards rendered alongside/under the existing local
  seeded templates. An **Install** action on a remote card reuses
  `CloneTemplateDialog` to name + install the chosen template. When no registry
  is configured, the store section is hidden (or shows a "configure a registry"
  hint) and local templates keep working unchanged — fully additive, no
  regression.
- **NO** publishing to a remote catalogue (consume-only this cut). **NO** new
  OpenRegister schema — the registry URL/token are admin app-config values, not
  a schema change, and `ApplicationTemplate` already exists and is seeded by
  `SeedApplicationTemplates`. **NO** change to the clone endpoint, the seed
  repair step, or the `ApplicationTemplate` schema.

## Capabilities

### New Capabilities

- `openbuild-remote-template-store`: the registry admin setting (base URL +
  optional token), the server-side `RemoteTemplateStoreService` proxy (SSRF-safe
  fetch + search of remote `application-template` objects, timeout/error
  handling), the `StoreController` search + install endpoints (install resolves
  the remote payload and clones it locally via the existing
  `createFromTemplate` path), and the no-registry-configured fallback.

### Modified Capabilities

- `template-catalogue-ui`: the Templates page gains a store search box and
  remote result cards, an Install action that reuses `CloneTemplateDialog`, and
  a no-registry empty state. Local-template behaviour is unchanged.

## Impact

- **New PHP**: `lib/Service/RemoteTemplateStoreService.php` (proxy fetch +
  search + SSRF guard + install-resolve), `lib/Controller/StoreController.php`
  (two endpoints), two route entries in `appinfo/routes.php`, and the
  registry-URL/token keys added to `SettingsService` + the admin settings
  surface. The install endpoint composes the existing
  `ApplicationsController::createFromTemplate` clone logic (no duplication of
  the namespacing/rewrite code).
- **New frontend**: store search box + remote result cards on the Templates
  page (reusing `CloneTemplateDialog` for install), wired through the new
  `/api/store/*` endpoints; Vitest coverage.
- **Config**: two `openbuild` app-config values (`registry_url`,
  `registry_token`). No per-user config, no schema change, no migration.
- **Network/Security**: a single outbound HTTP read to the configured registry,
  gated by an SSRF guard (mirrors
  `OCA\OpenRegister\Service\SecurityService::assertSafeFetchUrl`); endpoints
  require an authenticated OpenBuild user; the token stays server-side. No
  template data leaves this instance (consume-only).
- **Dependencies**: OpenRegister (already a hard dep) for the local clone;
  the remote registry is any reachable OR instance exposing
  `application-template` objects publicly.
