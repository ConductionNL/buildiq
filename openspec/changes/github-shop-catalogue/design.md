## Context

The Templates page ("the shop") is `src/views/TemplateGallery.vue`. It already
renders two sources: locally-seeded `application-template` records
(`buildiq-template-catalogue`) and, when `storeConfigured`, a remote
OpenRegister catalogue proxied by `RemoteTemplateStoreService` + `StoreController`
(`buildiq-remote-template-store`). Installing any card runs through
`ApplicationsController::installFromTemplateArray` — the reusable clone seam that
takes an `ApplicationTemplate`-shaped array and produces a namespaced local
`Application` + per-app register.

Change `github-app-repo-format` defined the canonical GitHub repo layout
(discovery topic `buildiq-app`, root `openbuild-app.json` descriptor,
`manifest.json`, `schemas/*.json`) and `AppRepoParser::parse(array $files)`, which
turns a fetched repo file map into exactly that clone-seam array. So the GitHub
source needs only the network leg: search GitHub, fetch each hit's descriptor for
a card, and — on install — fetch the whole repo, parse it, and call the existing
seam.

OpenRegister ships an in-process **credential broker**
(`CredentialBrokerService::request(credentialId, appId, method, path, headers,
body, actingUserId)`) that performs an outbound HTTP call on the app's behalf
using a stored credential the app never sees, guarded by owner / allowedApps /
allowRules / host-lock. The parallel OR change `github-provider-shop-rules` widens
the `github` provider's allowRules to include `GET /search/repositories`,
`GET /user`, `POST /user/repos`, and `POST /orgs/*/repos` (on top of the existing
`GET /repos/*`, `PUT /repos/*/contents/*`, `POST /repos/*/git/*`). This change
uses the read rules for an authenticated search/fetch **upgrade**; the write rules
are for `github-app-sync` (change 3).

## Goals / Non-Goals

**Goals:**

- Add GitHub as a **third shop source alongside** Local + Registry (union-merge,
  zero regression to the existing two).
- Search `topic:buildiq-app` server-side, SSRF-safe (fixed host), cached
  (anonymous search is ~10 req/min), and render the hits as installable cards.
- Install a chosen GitHub app by parsing its repo (change 1) into the existing
  clone seam — no new clone/namespacing logic.
- Browse anonymously by default; transparently upgrade to an authenticated
  request when the acting user has an allowed broker `github` credential
  (higher rate limit + private repos), keeping the secret out of Buildiq.
- Feature-detect the widened broker rules and fall back to anonymous cleanly.

**Non-Goals:**

- Publishing / pushing to GitHub (owner round-trip = `github-app-sync`).
- Any change to the exporter, the remote-OR store, or the local catalogue
  behaviour.
- Storing GitHub apps as OpenRegister objects (the source is a live search;
  installed apps are normal local `Application` records).
- A general GitHub client — only the fixed endpoints this shop needs.

## Decisions

### Decision 1 — GitHub as a new source ALONGSIDE local + registry (union-merge)

`TemplateGallery.vue` gains a **source tab strip**: **Local** (today's built-in
templates), **Registry** (today's remote-OR store, shown when `storeConfigured`),
and **GitHub** (new). The Local and Registry code paths are untouched — the GitHub
tab is purely additive, so an instance with no GitHub reachability behaves exactly
as before on the other two tabs. Default tab selection preserves current
behaviour (Registry-primary when configured, else Local), with GitHub as an
opt-in tab. **Alternative considered:** merge all three into one unified result
grid — rejected for this cut: the three sources have different card shapes,
install endpoints, and error modes; tabs keep each source's contract clean and
avoid regressing the existing two.

### Decision 2 — `GitHubCatalogService`, fixed-host, SSRF-safe by construction

A new `lib/Service/GitHubCatalogService.php` performs all GitHub I/O via
`OCP\Http\Client\IClientService`. Unlike the remote-OR store (whose URL is
admin-configurable and therefore needs an SSRF guard), **every host here is a
compile-time constant** — `https://api.github.com` for search + contents. There is
no admin-supplied URL, so there is no SSRF surface; the service still validates
`owner`/`repo`/`ref` against safe patterns before interpolating them into a path.
Operations:

- `search(?string $query, ?string $actingUserId): array` — GET
  `https://api.github.com/search/repositories?q=topic:buildiq-app{+ ' ' + query}`
  with `Accept: application/vnd.github+json`. Returns normalised cards.
- `fetchDescriptor(string $owner, string $repo, ?string $ref, ?string $actingUserId): ?array`
  — GET the repo's root `openbuild-app.json` via the contents API; decode for the
  card. A missing/unparseable descriptor yields a null/unparseable marker (the
  card is shown as non-installable, not dropped).
- `fetchRepoFiles(string $owner, string $repo, ?string $ref, ?string $actingUserId): array`
  — fetch the file map needed to install (`openbuild-app.json`, `manifest.json`,
  every `schemas/*.json`) via the contents API (directory listing + per-file
  fetch, or the git-tree API for efficiency), returning the `path => contents`
  map `AppRepoParser::parse` expects.

**Alternative considered:** fetch from the browser directly — rejected: leaks
nothing secret (anonymous) but hits GitHub's browser rate limit per-user-IP, has
no server-side cache, and cannot use the broker upgrade.

### Decision 3 — Short-TTL server-side caching (anonymous rate limit)

Anonymous GitHub search is ~10 req/min per IP; the contents API ~60 req/hr. To
keep the shop usable without a credential, `GitHubCatalogService` caches via
`OCP\ICacheFactory` (distributed cache): search results keyed by the normalised
query (TTL ~60 s) and per-repo descriptors keyed by `owner/repo@ref` (TTL ~300 s).
A cache hit serves instantly and issues no outbound request. On a `403`
rate-limit response the service returns the last cached result when present plus a
`rate_limited` hint, else a generic `github_rate_limited` outcome (never the raw
GitHub body). **Trade-off:** a short TTL means a freshly published app can take up
to a minute to appear — acceptable for a discovery surface, and an authenticated
(broker) request bypasses the tight anonymous limit anyway.

### Decision 4 — Credential upgrade via the OR broker (lazy, optional)

When the acting user has an **allowed** broker `github` credential, search + fetch
are routed through `CredentialBrokerService::request(credentialId, 'buildiq',
'GET', path, headers, null, actingUserId)` instead of the anonymous
`IClientService` call. The broker performs the authenticated GitHub call with its
stored token and returns the response — **the token never reaches Buildiq**.
Resolution is **lazy**, mirroring how `RemoteTemplateStoreService` resolves OR
services: `class_exists(CredentialBrokerService::class)` + `Server::get(...)`, so
Buildiq builds and runs even if the broker class is absent (older OR), falling
back to anonymous. "Allowed" is decided by the broker's own guards (owner /
allowedApps=`buildiq` / allowRules including `GET /search/repositories` +
`GET /repos/*` / host-lock=`api.github.com`); Buildiq does not re-implement the
check — it feature-detects by attempting the broker call and falling back to
anonymous on a `not-allowed` / rules-missing outcome. The frontend independently
lists the user's github credentials via OR's `GET /apps/openregister/api/credentials`
to decide whether to *offer* the upgrade, but the authoritative gate is the
broker. **Alternative considered:** require a credential for any GitHub browsing —
rejected: it would make the shop unusable out of the box; anonymous-first with an
optional upgrade matches the user-approved auth model.

### Decision 5 — Install = fetch → parse (change 1) → existing clone seam

`POST /api/shop/github/install` receives `{ owner, repo, ref?, name, slug }`. It:

1. rejects an unauthenticated session (401) and validates `owner`/`repo`/`ref`
   against safe patterns and `slug` against the kebab-case Application pattern;
2. calls `GitHubCatalogService::fetchRepoFiles(owner, repo, ref, actingUserId)`
   (broker upgrade when available, else anonymous) to get the `path => contents`
   map;
3. calls change 1's `AppRepoParser::parse($files)` — which strictly validates the
   repo and returns the clone-seam array, or fails loudly with an actionable
   per-file error code (surfaced as a generic-but-actionable 4xx, nothing created);
4. hands the parsed array (with the user-supplied `name` + `slug` overriding the
   descriptor's) to `ApplicationsController::installFromTemplateArray`, which does
   the companion-schema namespacing, manifest rewrite, per-app register, and
   `templateOrigin` recording — the same audited path the local + registry
   installs use. The caller becomes the app owner.

No namespacing/rewrite/validation logic is re-implemented — install is glue over
change 1's parser and the existing seam. **Alternative considered:** a bespoke
GitHub install that writes schemas directly — rejected: duplicates the clone path
and diverges from the local/registry installs.

### Decision 6 — Endpoints, auth, and input hygiene (ADR-005 / hydra gates)

A new `lib/Controller/ShopController.php` (kept distinct from the OR-store
`StoreController` so each source's contract is isolated) exposes:

- `GET /api/shop/github/search` — `#[NoAdminRequired]`; in-body 401 when
  `IUserSession::getUser() === null` (instance-shared read, like the OR-store
  search — no per-object IDOR surface); reads `q`, calls
  `GitHubCatalogService::search`, returns cards + a `brokerCredentialAvailable`
  / `rateLimited` hint.
- `POST /api/shop/github/install` — `#[NoAdminRequired]`; in-body 401; validates
  inputs; resolves + parses + clones (Decision 5); returns 201 with the new
  Application uuid or a generic-but-actionable 4xx (parser error code / not-found
  / rate-limited).

Both routes are registered specific-first in `appinfo/routes.php` before the SPA
catch-all. `q` is URL-encoded into the outbound query; `owner`/`repo`/`ref`/`slug`
are pattern-validated before use. Errors never leak the raw GitHub body or any
token; the parser's structured error codes + file paths (ADR-005-safe, change 1
Decision 8) are surfaced so the user can fix the offending repo. `#[NoAdminRequired]`
matches the method body (semantic-auth): browsing/installing is any-authenticated-user,
identical to the local + registry installs.

### Decision 7 — Declarative vs imperative (ADR-031)

This change adds **no** declarative behaviour matching the ADR-031 triggers — no
lifecycle, aggregation, derived field, notification, declarative relation, or
dashboard widget, and **no new schema**. `GitHubCatalogService` is a genuine
**external-integration imperative** path (calling the GitHub HTTP API + caching),
explicitly permitted by ADR-031's external-integration exception — the same
posture as `RemoteTemplateStoreService`. The install action is not new business
logic: it reuses change 1's parser + the existing declarative-adjacent clone seam.
So: imperative only where the network forces it, reuse everywhere else, zero new
declarative surface.

### Decision 8 — Seed Data (ADR-001)

This change introduces **no new OpenRegister schema** and **no new objects**, so
there is **no new seed data**. GitHub apps are read live from the GitHub API and
never persisted into the local catalogue; installing one produces a normal local
`Application` (via the existing clone seam), not a stored catalogue record. The
only OR objects involved (`Application`, per-app companion schemas) already have
their seed/creation home in the existing capabilities. Seed data for this change =
**N/A**. Test fixtures use an example `topic:buildiq-app` search response + a
`permit-tracker` repo file map (test data, not OR seed data).

### Decision 9 — Security (ADR-005)

- **No SSRF surface.** Every outbound host is a compile-time constant
  (`api.github.com`); there is no admin-configurable URL. `owner`/`repo`/`ref` are
  pattern-validated before path interpolation.
- **Anonymous-first, secret-never-in-Buildiq.** Default browsing is anonymous.
  The optional credential upgrade goes through the OR broker, which holds the
  token and performs the call — Buildiq passes a `credentialId`, never a secret,
  and never sees the token (broker guards: owner / allowedApps / allowRules /
  host-lock).
- **Authenticated user required.** Both endpoints reject an unauthenticated
  session (401) before any work.
- **Fail-closed, no leakage.** Rate-limit / transport / non-2xx map to generic
  `github_rate_limited` / `github_unreachable` outcomes; the raw GitHub body and
  any token are never returned or logged. Parser errors surface as structured,
  PII-free codes + file paths (change 1).
- **Hostile repo safe.** Install runs change 1's strict all-or-nothing parser
  (manifest schema validation, schema-file validation, size/depth bounds) before
  anything is handed to the clone seam — a hostile repo yields a rejected install,
  never a partial write.

## Risks / Trade-offs

- **[Anonymous rate limit]** ~10 search req/min → short-TTL cache + optional
  broker upgrade; a throttled result shows a clear hint (and, if it would help, a
  pointer to add a github credential) rather than an error.
- **[Broker rules not yet widened]** the parallel OR change may not be deployed →
  the service feature-detects and falls back to anonymous; the UI hides the
  "authenticated browsing" affordance when the broker call returns rules-missing.
- **[Hostile / malformed repo on GitHub]** → change 1's strict parser rejects it
  loudly with an actionable code; nothing is written locally.
- **[Descriptor fetch cost per hit]** search returns many repos; fetching each
  descriptor is N extra calls → cap the page size, cache descriptors, and fetch
  descriptors lazily/batched; a hit whose descriptor is unavailable is shown as a
  non-installable card, not dropped.
- **[Trade-off: tabs vs unified grid]** tabs keep the three sources' contracts
  isolated (no regression) at the cost of a unified search; a future change can
  add a cross-source "all" view over the same services.

## Migration Plan

1. Land `github-app-repo-format` (change 1) first — this change hard-depends on
   `AppRepoParser` and the format.
2. Ship `GitHubCatalogService`, `ShopController`, the two routes, and the
   `TemplateGallery.vue` source tabs + GitHub tab. Purely additive; no schema, no
   migration, no seed change.
3. GitHub browsing works anonymously on deploy. When the OR
   `github-provider-shop-rules` change is live and a user has an allowed github
   credential, search/fetch transparently upgrade.
4. **Rollback:** removing the GitHub tab + the two routes leaves Local + Registry
   untouched; no data is affected (installed apps are normal local Applications
   that survive independently).

## Open Questions

- **OQ-1 (search query composition):** the base query is `topic:buildiq-app`;
  a user term is appended as a free-text qualifier. Whether to also match repo
  name/description or restrict strictly to the topic is provisionally
  **topic-scoped + free-text append** — revisit if results are too broad/narrow.
- **OQ-2 (descriptor fetch strategy):** provisionally fetch descriptors lazily
  (on card render / hover) and cache, to stay within the anonymous contents
  limit; an authenticated (broker) session can eager-fetch. Revisit against real
  rate-limit behaviour.
- **OQ-3 (install authorization):** provisionally any authenticated Buildiq user
  may install a GitHub app (mirrors the local + registry installs, both
  `#[NoAdminRequired]`). An admin-or-builder-group gate is a clean follow-up if
  GitHub installs need tighter control.
