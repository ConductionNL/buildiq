---
kind: code
depends_on:
  - github-app-repo-format
chain:
  - github-app-repo-format
  - github-shop-catalogue
  - github-app-sync
---

## Why

OpenBuild's Templates page ("the shop") already reads apps from two sources: the
locally-seeded `application-template` records (`openbuild-template-catalogue`) and
a remote OpenRegister catalogue proxied server-side (`openbuild-remote-template-store`).
Neither reaches GitHub — yet GitHub is where the world's open-source app
definitions actually live, and change `github-app-repo-format` just defined a
canonical, parseable repo layout (discovery topic `openbuild-app`) plus the
`AppRepoParser` that turns such a repo into the exact clone-seam payload the shop
already installs. What's missing is the **network leg + a third shop source**:
search GitHub for `topic:openbuild-app`, render the hits as installable cards
alongside the existing local + registry sources, and install a chosen one by
parsing its repo through change 1's parser into the audited clone path.

This is the middle change of the three-change chain — the *consume from GitHub*
half. It adds GitHub as a **new source alongside** the existing two (union-merge,
no regression): the Local and Registry tabs behave exactly as before; a new GitHub
tab appears. Browsing is anonymous (server-side, cached, rate-limit-aware) so an
instance with no GitHub credential can still discover and install public apps;
when the acting user has an allowed broker `github` credential the search is
transparently upgraded to an authenticated request for a higher rate limit and
access to private repos. Publishing back to GitHub (the owner round-trip) is the
next change (`github-app-sync`) and is out of scope here.

## What Changes

- **NEW** `lib/Service/GitHubCatalogService.php` — the server-side GitHub source:
  - **Search:** anonymous `GET https://api.github.com/search/repositories?q=topic:openbuild-app`
    via `OCP\Http\Client\IClientService`. The host is **fixed** (`api.github.com`),
    never admin-configurable, so the SSRF surface is closed by construction.
    Results are **cached server-side** with a short TTL (`ICacheFactory`), because
    the anonymous GitHub search API is rate-limited to ~10 req/min.
  - **Card metadata:** for each hit the service fetches the repo's root
    `openbuild-app.json` (anonymous contents API, cached) to build a card
    (`slug`, `name`, `description`, `category`, `appType`, `version`, declared
    `credentials[]`, repo owner/name, stars) — a repo whose descriptor is missing
    or unparseable is surfaced as a non-installable/unparseable candidate, never
    silently dropped.
  - **Credential upgrade:** when the acting user has an allowed broker `github`
    credential, search + fetch are routed through
    `CredentialBrokerService::request(...)` in-process (lazy FQCN resolution,
    `class_exists` + `Server::get`, mirroring `RemoteTemplateStoreService`'s OR
    resolution) for a higher rate limit and private-repo access. The secret never
    reaches OpenBuild — the broker performs the HTTP call. Anonymous is the
    default fallback.
- **NEW** two endpoints on a new `ShopController`:
  - `GET /api/shop/github/search?q=…` — proxied GitHub search → normalised cards
    (+ a `brokerCredentialAvailable` / rate-limit hint so the UI can explain a
    throttled anonymous result).
  - `POST /api/shop/github/install` — resolve the chosen repo's files
    (descriptor + manifest + `schemas/*`) via `GitHubCatalogService`, parse them
    with change 1's `AppRepoParser`, then hand the resulting template-array
    payload to the existing `ApplicationsController::installFromTemplateArray`
    seam. No new namespacing/rewrite/clone logic.
  - Both carry `#[NoAdminRequired]` with an in-body unauthenticated-session
    rejection (401) and validated inputs (hydra route-auth / no-admin-idor gates).
- **MODIFIED** the Templates page (`TemplateGallery.vue`) — source **tabs**
  Local / Registry / GitHub. Local + Registry are the existing surfaces unchanged;
  the new **GitHub** tab is a server-backed search box + a grid of GitHub result
  cards, each with an **Install** action that reuses the existing
  `CloneTemplateDialog` flow pointed at `POST /api/shop/github/install`. Any new
  dialog/modal lives in its own file under `src/modals/` (modal-isolation gate).
  When GitHub browsing is throttled/unavailable the tab shows a clear hint (and,
  when a broker credential would help, a pointer to add one) without breaking the
  Local/Registry tabs.
- **NO** publish-to-GitHub (owner round-trip is `github-app-sync`). **NO** change
  to the exporter, the remote-OR store, or the local catalogue behaviour (GitHub
  is additive). **NO** new OpenRegister schema (the GitHub source is a live search,
  not stored objects; installed apps are normal local `Application` records).

## Capabilities

### New Capabilities

- `github-shop-catalogue`: the `GitHubCatalogService` (SSRF-safe fixed-host
  anonymous `topic:openbuild-app` search + per-hit descriptor fetch, short-TTL
  server-side caching, automatic broker-credential upgrade for rate limit +
  private repos), and the `ShopController` `GET /api/shop/github/search` +
  `POST /api/shop/github/install` endpoints (install parses the repo via change
  1's `AppRepoParser` and clones through the existing `installFromTemplateArray`
  seam).

### Modified Capabilities

- `template-catalogue-ui`: the Templates page gains **source tabs** (Local /
  Registry / GitHub). The new GitHub tab renders server-backed search results and
  installs via the existing `CloneTemplateDialog` flow pointed at the shop
  endpoint; Local + Registry surfaces are unchanged (additive, no regression).

## Impact

- **New PHP**: `lib/Service/GitHubCatalogService.php` (fixed-host GitHub search +
  contents fetch, cache, lazy broker resolution), `lib/Controller/ShopController.php`
  (two endpoints), two route entries in `appinfo/routes.php` (specific-first,
  before the SPA catch-all). Install composes `AppRepoParser` (change 1) +
  `installFromTemplateArray` (existing seam) — no duplicated clone logic.
- **New frontend**: source tabs on `TemplateGallery.vue`; a GitHub search
  tab (debounced search → `GET /api/shop/github/search`, result cards, Install →
  `CloneTemplateDialog` → `POST /api/shop/github/install`); any new modal in its
  own file; Vitest coverage. Local/Registry tabs untouched.
- **Config**: none required for anonymous browsing. Optional: the acting user's
  broker `github` credential (listed via OpenRegister's
  `GET /apps/openregister/api/credentials`) transparently upgrades search/fetch.
- **Network/Security**: outbound reads only to the **fixed** hosts
  `api.github.com` (+ the contents/raw host) — no admin-configurable URL, so no
  SSRF surface. Anonymous by default; the broker path keeps the credential
  server-/OR-side (never in OpenBuild). Endpoints require an authenticated
  OpenBuild user. No app data leaves the instance (consume-only).
- **Dependencies**: `github-app-repo-format` (parser + format) — hard dep;
  OpenRegister (already a hard dep) for the clone seam and, optionally, the
  credential broker. The parallel OpenRegister change `github-provider-shop-rules`
  widens the broker's `github` allowRules to include `GET /search/repositories`
  and `GET /user`; this change **feature-detects** those rules and falls back to
  anonymous when they are absent.
