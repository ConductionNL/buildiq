## Context

OpenBuild's template catalogue is closed: the four Conduction-curated
`ApplicationTemplate` records are seeded at install by
`lib/Repair/SeedApplicationTemplates.php`, listed by `TemplateGallery.vue`, and
cloned into local virtual apps by
`ApplicationsController::createFromTemplate` (`POST
/api/applications/from-template/{templateSlug}`). There is no way to discover a
template that lives on another instance.

A remote OpenRegister instance already exposes its objects over a public
objects/federation API
(`/index.php/apps/openregister/api/objects/{register}/{schema}`), so a "template
store" needs no new wire format — the **remote registry IS an OpenRegister
instance** serving `ApplicationTemplate` objects. This change adds the network
leg: read remote templates server-side, render them in the Templates page, and
install a chosen one by feeding it through the existing local clone path.

Constraints: server-side fetch only (browser CORS + secret-hiding), additive (no
regression when no registry is configured), consume-only (no publishing back),
and no new OR schema (the registry URL/token are admin app-config values).

## Goals / Non-Goals

**Goals:**

- Configure a remote OR catalogue via an OpenBuild **admin setting** (base URL +
  optional read token), defaulting to a placeholder Conduction-hosted catalogue.
- Browse/search remote `application-template` objects from the Templates page.
- Install a remote template into the local instance, reusing the existing
  template-clone path so the result is a normal local `Application` /
  `ApplicationVersion` (virtual app, unified-app-model).
- Keep the page working with zero behaviour change when no registry is set.

**Non-Goals:**

- Publishing local templates/apps to a remote catalogue (deferred — consume-only
  this cut).
- Cross-instance object-data transport (a template is a *definition* —
  manifest + companion-schema blobs — never row data).
- A new `ApplicationTemplate` schema or any OR schema change.
- Per-user registry config, multiple registries, or registry auth flows beyond a
  single optional static read token.

## Decisions

### Decision 1 — Registry config is an admin app-config value, not a schema

The registry base URL and optional read token are stored via `IAppConfig`
(`OCP\IAppConfig`) under the `openbuild` app, keys `registry_url` and
`registry_token`, read/written through the existing `SettingsService` (which
already wraps `IAppConfig` for the `register` key). They surface on the existing
OpenBuild **admin settings** page (`AdminSettings.php` + `templates/settings/admin.php`),
admin-only by the Nextcloud settings framework. The default `registry_url` is a
placeholder — `https://store.openbuild.example/` — overridable per instance; an
empty value means "no store configured". This keeps `kind: code` (config +
backend service/controller + UI; no schema change). **Alternative considered:**
an `ApplicationTemplateSource` OR schema record per registry — rejected as
over-built for a single optional registry and it would have made this a schema
change.

Token handling: the token is stored server-side and **never returned to the
browser**. `SettingsService::getSettings()` exposes a boolean
`registry_token_set` (presence flag) instead of the value; a write of an empty
token string is treated as "leave unchanged" so re-saving the form does not wipe
it (a sentinel write to clear it is a follow-up nicety, not required this cut).

### Decision 2 — Server-side proxy service (`RemoteTemplateStoreService`)

A new `lib/Service/RemoteTemplateStoreService.php` performs all remote I/O via
NC's `OCP\Http\Client\IClientService`. Two operations:

- `searchTemplates(?string $query): array` — GET
  `{registryUrl}/index.php/apps/openregister/api/objects/{register}/application-template`
  with `?_search={query}` when a query is supplied, sending
  `Authorization: Bearer {token}` only when a token is configured. Normalises the
  OR list envelope to a flat array of `{slug,title,description,useCase,category,version,screenshotUrl}`
  card-shaped entries (the heavy `manifest`/`companionSchemas` blobs are NOT
  shipped to the browser — they are fetched only at install time). A request
  timeout (10 s) and connect timeout are set; any transport/parse error is logged
  server-side and surfaced to the caller as a generic `store_unreachable` /
  `store_invalid_response` envelope (never `$e->getMessage()` to the client, per
  ADR-005).
- `resolveTemplate(string $slug): ?array` — GET the single
  `application-template` object by slug (full payload incl. `manifest` +
  `companionSchemas`) for the install path. Returns null on miss.

The register segment of the URL defaults to `openbuild` (the remote catalogue is
expected to host templates in its own `openbuild` register, mirroring the local
convention); it is read from an optional `registry_register` app-config value so
a catalogue that uses a different register name can be pointed at without code
changes. **Alternative considered:** fetching from the browser directly —
rejected: leaks the URL/token to the client and hits CORS on the remote OR.

### Decision 3 — Install reuses the existing clone path (no duplicated logic)

`POST /api/store/templates/{slug}/install` does **not** re-implement
companion-schema namespacing or manifest rewriting. It (1) resolves the remote
template payload via `RemoteTemplateStoreService::resolveTemplate`, (2) persists
it as a transient/local `ApplicationTemplate`-shaped payload, then (3) drives the
same clone routine the local gallery uses
(`ApplicationsController::createFromTemplate` REQ-OBTC-004/005), so the installed
app becomes a normal local `Application` + per-app register + namespaced
companion schemas, with `templateOrigin` recording the source slug + version.
The cleanest seam is to extract the clone body of `createFromTemplate` into a
small reusable method that takes an in-memory template array (the controller
endpoint already destructures into `buildClonedManifest` /
`provisionPerAppArtifacts` / `persistApplication` helpers, so the install path
calls those helpers with the remote payload instead of an OR-looked-up one).
**Alternative considered:** writing the remote template into the local OR
register first, then calling the existing slug-based endpoint — rejected: it
pollutes the local catalogue with a remote record the user did not ask to keep.

### Decision 4 — UI: Templates page becomes a store surface (additive)

`TemplateGallery.vue` gains a **store section**: a search box bound to a
debounced `GET /api/store/templates?q=…` call and a row of remote result cards
rendered under the existing local templates. Each remote card's "Install"
action opens the existing `CloneTemplateDialog` (name + slug form), whose submit
calls `POST /api/store/templates/{slug}/install` instead of the local
from-template endpoint; on success it redirects to the editor exactly like a
local clone (REQ-OBTC-006). The store section is **only rendered when a registry
is configured** — the page reads a `storeConfigured` flag from the existing
settings load; when false, the store section is hidden (admins see a "configure
a registry" hint linking to admin settings). Local templates are untouched.

### Decision 5 — Declarative vs imperative (ADR-031)

This change does **not** add any declarative schema behaviour — no new schema, no
state machine, no calculated/aggregated field, no notification dialect. The
remote fetch/proxy is a genuine **external-integration imperative path**, which
ADR-031 explicitly permits under its *external-integration exception*: calling a
remote HTTP API is I/O that cannot be expressed as schema metadata, so it lives
in an imperative service (`RemoteTemplateStoreService`) + controller. The
**install** action is not new business logic — it reuses the existing
declarative-clone path (which itself is thin glue per ADR-032). So: imperative
where the network forces it (the proxy), reuse everywhere else, and **zero new
declarative surface**.

### Decision 6 — Seed Data (ADR-001)

This change adds **no new OpenRegister schema** and therefore **no new seed
data**. The `ApplicationTemplate` schema (slug `application-template`) already
exists in `lib/Settings/openbuild_register.json` and is seeded locally by
`lib/Repair/SeedApplicationTemplates.php` (the four Conduction templates) —
unchanged here. Remote templates are read on demand from the configured registry
and are never persisted into the local catalogue unless the user installs one
(which creates an `Application`, not an `ApplicationTemplate`). Seed data for
this change = **N/A**; the only OR objects involved already have a seed home.

### Decision 7 — Security (ADR-005)

- **Server-side proxy only.** The browser never receives the registry URL or
  token; it only ever calls the local `/api/store/*` endpoints.
- **SSRF guard.** Before any outbound fetch, the configured `registry_url` is
  validated/normalised with the same anti-SSRF check OpenRegister already ships:
  `OCA\OpenRegister\Service\SecurityService::assertSafeFetchUrl($url)` (resolves
  the host and rejects private/reserved IP ranges via
  `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`). OpenBuild calls that
  shared guard when OR is loaded (OR is a hard dep) and additionally enforces
  `https`/`http` scheme + host-present normalisation locally so a malformed admin
  value fails closed. This blocks an admin (or a settings-injection) from
  pointing the proxy at `http://169.254.169.254/` or `http://localhost:…`.
- **Authenticated user required.** Both `/api/store/*` endpoints carry
  `#[NoAdminRequired]` and the method body rejects an unauthenticated session
  (`IUserSession::getUser() === null` → 401). The search endpoint is an
  instance-shared read (the remote catalogue is the same for every user on this
  instance, like the local seeded list), so any authenticated OpenBuild user may
  browse. **Install** creates a local `Application`; it is guarded the same way
  the local clone is — the caller becomes the owner via the existing
  `createFromTemplate` permission block — and is provisionally gated to **any
  authenticated OpenBuild user** (matching local clone, which is
  `#[NoAdminRequired]`). See DEFERRED_QUESTIONS for the admin-only alternative.
- **No PII / no secret leakage in logs or errors.** Transport failures log the
  generic reason + a correlation id server-side; the client gets a static
  `store_unreachable` / `store_invalid_response` envelope. The token is never
  logged.
- **Input encoding.** The `q` search term is `rawurlencode`d into the remote
  query string; the `{slug}` path param is validated against the kebab-case slug
  pattern before use.

## Risks / Trade-offs

- **[Remote registry unreachable / slow]** → 10 s timeout + generic
  `store_unreachable` envelope; the store section shows an error state while the
  local templates remain fully usable (no page-level failure).
- **[Remote catalogue serves a malformed / hostile manifest]** → install runs
  the same `validateManifest` guard the local clone path applies before
  persisting; an invalid manifest is rejected with a generic error, nothing is
  written locally.
- **[SSRF via admin-set URL]** → `assertSafeFetchUrl` + scheme/host normalisation
  fail closed on private/reserved hosts; the guard runs on *every* fetch, not
  just at save time, so a TOCTOU host re-bind is caught at request time.
- **[Token accidentally exposed]** → token is write-only from the UI, never in
  `getSettings()` output, never logged, never sent to the browser.
- **[Register-name mismatch on the remote catalogue]** → `registry_register`
  app-config override lets an operator point at a differently-named register
  without a code change; default `openbuild` matches the Conduction catalogue
  convention.
- **[Trade-off: only one registry]** → single-registry keeps the config and UI
  trivial; multi-registry is a clean follow-up (the service already takes the
  base URL as a parameter-shaped value).

## Migration Plan

1. Deploy the code (new service, controller, two routes, settings keys, UI). No
   schema change, no repair-step change, no migration — purely additive.
2. On a fresh deploy `registry_url` defaults to the placeholder; until an admin
   sets a real URL the store section is hidden and the app behaves exactly as
   before (local templates only).
3. Admin sets the registry URL (+ optional token) on the OpenBuild admin
   settings page; the store section appears for all users.
4. **Rollback:** clearing `registry_url` (empty string) instantly disables the
   store section with no data loss — installed apps are normal local
   Applications and survive independently. Reverting the code removes the
   `/api/store/*` endpoints; nothing else is affected.

## Open Questions

- **OQ-1 (default registry URL):** ships as the placeholder
  `https://store.openbuild.example/` rather than a real Conduction host, pending
  confirmation of the canonical catalogue URL. Operators override per instance.
- **OQ-2 (install authorization):** provisionally any authenticated OpenBuild
  user may install (mirrors the local `#[NoAdminRequired]` clone). An
  alternative is admin-or-app-builder-group only; revisit if remote installs need
  tighter gating.
- **OQ-3 (publishing back):** deferred entirely — this cut is consume-only. A
  future change adds a publish path + curation/trust story for a Conduction
  community catalogue.
