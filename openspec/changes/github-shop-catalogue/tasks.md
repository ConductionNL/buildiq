## 1. GitHubCatalogService (server-side GitHub source)

- [ ] 1.1 Create `lib/Service/GitHubCatalogService.php` (SPDX + copyright in the file docblock) injecting `IClientService`, `ICacheFactory`, `LoggerInterface`; define the GitHub host as a compile-time constant (`https://api.github.com`) — no admin-configurable URL. (REQ-GHSC-001)
- [ ] 1.2 Implement `search(?string $query, ?string $actingUserId)`: GET `/search/repositories` with a query containing `topic:buildiq-app` plus the URL-encoded user term when supplied; send `Accept: application/vnd.github+json`; apply a connect + request timeout; normalise the response to flat card entries; never return the raw GitHub body. (REQ-GHSC-001)
- [ ] 1.3 Implement `fetchDescriptor(owner, repo, ref?, actingUserId?)`: fetch the repo's root `openbuild-app.json` via the contents API; validate `owner`/`repo`/`ref` against safe patterns before path interpolation; return the parsed descriptor or a null/unparseable marker (never drop the hit). (REQ-GHSC-002)
- [ ] 1.4 Build cards from the descriptor: `slug`, `name`, `description`, `category`, `appType`, `version`, declared `credentials[]`, repo owner/name + optional stars; mark a card non-installable when its descriptor is missing/unparseable. (REQ-GHSC-002)
- [ ] 1.5 Implement `fetchRepoFiles(owner, repo, ref?, actingUserId?)`: return the `path => contents` map (`openbuild-app.json`, `manifest.json`, every `schemas/*.json`) via the contents API (directory listing + per-file fetch, or the git-tree API), shaped for `AppRepoParser::parse`. (REQ-GHSC-006)
- [ ] 1.6 Server-side caching via `ICacheFactory`: cache search results (short TTL ~60s, keyed by normalised query) and descriptors (TTL ~300s, keyed by `owner/repo@ref`); serve cache hits with no outbound request. (REQ-GHSC-003)
- [ ] 1.7 Rate-limit + error handling: on a GitHub 403 rate-limit serve the last cached result with a `rate_limited` hint when present, else surface a generic `github_rate_limited`; map transport/non-2xx to a generic `github_unreachable`; never log or return the raw body or any token. (REQ-GHSC-003)

## 2. Broker-credential upgrade (optional, lazy)

- [ ] 2.1 Lazily resolve `OCA\OpenRegister\Service\Credential\CredentialBrokerService` (`class_exists` + `Server::get`, mirroring `RemoteTemplateStoreService`'s OR resolution); tolerate its absence (older OR) by falling back to anonymous. (REQ-GHSC-004)
- [ ] 2.2 When the acting user has an allowed broker `github` credential, route `search`/`fetchDescriptor`/`fetchRepoFiles` through `CredentialBrokerService::request(credentialId, 'buildiq', 'GET', path, headers, null, actingUserId)` so the token stays broker-side and never enters Buildiq. (REQ-GHSC-004)
- [ ] 2.3 Feature-detect the widened `github` allowRules by attempting the broker call and falling back to anonymous on a not-allowed / rules-missing outcome; keep anonymous browsing as the default so the shop works with no credential. (REQ-GHSC-004)

## 3. ShopController endpoints

- [ ] 3.1 Create `lib/Controller/ShopController.php` (SPDX + copyright in the file docblock) injecting `IRequest`, `IUserSession`, `GitHubCatalogService`, the `AppRepoParser` (change 1), the `installFromTemplateArray` collaborators, and `LoggerInterface`.
- [ ] 3.2 `githubSearch()` — `#[NoAdminRequired]`; reject an unauthenticated session with 401 (in-body guard; instance-shared read, no per-object IDOR); read + URL-encode `q`; call `GitHubCatalogService::search`; return 200 with cards + a `brokerCredentialAvailable` / `rateLimited` hint or a generic error. (REQ-GHSC-005)
- [ ] 3.3 `githubInstall()` — `#[NoAdminRequired]`; reject an unauthenticated session with 401; validate `owner`/`repo`/`ref` against safe patterns and `slug` against the kebab-case Application pattern. (REQ-GHSC-006)
- [ ] 3.4 Install flow: `fetchRepoFiles` → `AppRepoParser::parse` (change 1, strict all-or-nothing) → `ApplicationsController::installFromTemplateArray` with the user-supplied `name` + `slug`; do NOT re-implement namespacing/rewrite/validation; return 201 with the new Application uuid, or a generic-but-actionable 4xx carrying the parser error code + offending file path with nothing created. (REQ-GHSC-006)
- [ ] 3.5 Register both routes in `appinfo/routes.php` — `GET /api/shop/github/search` → `shop#githubSearch`, `POST /api/shop/github/install` → `shop#githubInstall` — specific-first, before the SPA catch-all, with `requirements` on path params.

## 4. Frontend: source tabs + GitHub tab

- [ ] 4.1 Add a source **tab strip** (Local / Registry / GitHub) to `src/views/TemplateGallery.vue`; keep the Local and Registry code paths unchanged; default selection preserves current behaviour (Registry-primary when configured, else Local). (template-catalogue-ui: source tabs)
- [ ] 4.2 GitHub tab: a debounced search box bound to `GET /api/shop/github/search?q=…` and a grid of GitHub result cards (name, description, category, appType, version, credentials, owner/name, stars); render an unparseable-descriptor hit as a non-installable card. (template-catalogue-ui: GitHub tab)
- [ ] 4.3 Wire each installable GitHub card's "Install" action to open the existing `src/modals/CloneTemplateDialog.vue` seeded with the GitHub app; route its submit to `POST /api/shop/github/install`; redirect to the editor on success (reuse the local/registry redirect path). (template-catalogue-ui: GitHub install)
- [ ] 4.4 Surface a strict-parse failure returned by the install endpoint in the dialog as an actionable error naming the offending file, creating nothing. (template-catalogue-ui: GitHub install)
- [ ] 4.5 Degraded state: on a rate-limited/unreachable GitHub search show a clear non-blocking hint (and, when no allowed github credential is present, a pointer to add one via OR's credentials pane), without breaking the Local/Registry tabs; feature-detect a github credential via OR's `GET /apps/openregister/api/credentials` (advisory only). (template-catalogue-ui: degraded browsing)
- [ ] 4.6 Any NEW dialog/modal introduced by the GitHub tab lives in its own file under `src/modals/` (modal-isolation gate); reuse `CloneTemplateDialog` for install rather than inlining a modal.
- [ ] 4.7 i18n English source strings for the GitHub tab (tab label, search placeholder, Install label, credential/rate-limit hints, unparseable-card badge) + Dutch translations in `l10n/nl.json`.

## 5. Tests

- [ ] 5.1 PHPUnit `GitHubCatalogService`: search targets `api.github.com` with `topic:buildiq-app`; a user term is appended URL-encoded; response normalised to cards; a cache hit issues no outbound request; a 403 rate-limit serves cache-or-`github_rate_limited` without the raw body; an unparseable descriptor yields a non-installable card (not dropped). (REQ-GHSC-001..003)
- [ ] 5.2 PHPUnit broker upgrade: with an allowed github credential the call routes through `CredentialBrokerService::request` (no token in Buildiq); with the broker class absent / rules missing / denied it falls back to anonymous and still returns results. (REQ-GHSC-004)
- [ ] 5.3 PHPUnit `ShopController`: unauthenticated search + install rejected (401); authenticated search returns cards + hint; install fetches → parses → clones (201 with new Application uuid + `templateOrigin.source: github`); a malformed repo yields a generic-but-actionable 4xx (parser code + file) with nothing created; invalid owner/repo/slug rejected. (REQ-GHSC-005, REQ-GHSC-006)
- [ ] 5.4 Vitest `TemplateGallery`: source tabs render; the GitHub tab search renders cards and issues the search request only when selected; Install opens `CloneTemplateDialog`; the Local/Registry tabs are unchanged; a rate-limited search shows the hint without breaking other tabs. (template-catalogue-ui)
- [ ] 5.5 Vitest `CloneTemplateDialog`: a GitHub-card submit calls `POST /api/shop/github/install` and redirects on success; a strict-parse error is surfaced in the dialog naming the file; submission gated on a valid target. (template-catalogue-ui)

## 6. Docs + gates

- [ ] 6.1 Update the Buildiq template/shop docs to describe the GitHub source (topic `buildiq-app`, anonymous-first browsing, optional broker-credential upgrade, install via the change-1 parser), noting it is consume-only (publish is `github-app-sync`).
- [ ] 6.2 Run the hydra gates green: route-auth + semantic-auth (both endpoints `#[NoAdminRequired]` with matching in-body 401 guards), route-reachability (both routes ↔ `ShopController` methods), no-admin-idor (auth guard in each method body; installs are instance-scoped, caller becomes owner), spec-coverage (`@spec` tags on changed methods), modal-isolation (reuse `CloneTemplateDialog`; any new modal in its own file), spdx-headers, forbidden-patterns, stub-scan, composer-audit.
