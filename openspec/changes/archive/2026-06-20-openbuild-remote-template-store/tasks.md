## 1. Admin setting: registry URL + token

- [x] 1.1 Add `registry_url`, `registry_token`, `registry_register` to `SettingsService` config handling (read via `IAppConfig::getValueString`, write via `setValueString`); default `registry_url` to the placeholder `https://store.openbuild.example/` and `registry_register` to `openbuild`.
- [x] 1.2 In `SettingsService::getSettings()`, expose `registry_url`, `registry_register`, a boolean `registry_token_set` (presence flag) and a `storeConfigured` flag; NEVER return the token value.
- [x] 1.3 In `SettingsService::updateSettings()`, treat an empty `registry_token` as "leave unchanged" so re-saving the form does not wipe the stored token.
- [x] 1.4 Surface the registry URL + token (write-only) fields on the OpenBuild admin settings page (`templates/settings/admin.php` + admin settings Vue component), reusing the existing settings load/save wiring; provide `storeConfigured` to the frontend via the existing settings load.
- [x] 1.5 i18n English source strings for the new admin fields + Dutch translations in `l10n/nl.json`.

## 2. Proxy service: RemoteTemplateStoreService

- [x] 2.1 Create `lib/Service/RemoteTemplateStoreService.php` (SPDX + copyright header) injecting `IClientService`, `IAppConfig`, `LoggerInterface` (and OR `SecurityService` when available).
- [x] 2.2 Implement `searchTemplates(?string $query)`: build `{registry_url}/index.php/apps/openregister/api/objects/{registry_register}/application-template`, append `?_search=` with the `rawurlencode`d query when present, send `Authorization: Bearer {token}` only when a token is set, apply a connect + request timeout (10 s).
- [x] 2.3 Normalise the OR list envelope to flat card entries (`slug`, `title`, `description`, `useCase`, `category`, `version`, `screenshotUrl`); strip `manifest`/`companionSchemas` from search results.
- [x] 2.4 Implement `resolveTemplate(string $slug)`: fetch the single `application-template` object by slug (full payload incl. `manifest`/`companionSchemas`); return null on miss.
- [x] 2.5 SSRF-safe URL handling: before every fetch, normalise the URL (enforce `http`/`https` scheme + present host) and call `OCA\OpenRegister\Service\SecurityService::assertSafeFetchUrl()` when OR is loaded; reject private/reserved/loopback hosts and make no request.
- [x] 2.6 Error + timeout handling: map unreachable/timeout/non-2xx to a generic `store_unreachable` outcome and unparseable bodies to `store_invalid_response`; log the reason server-side (no token, no PII); never return the exception message to the caller.
- [x] 2.7 No-registry handling: when `registry_url` is empty, report "not configured" and make no network call.

## 3. Controller endpoints: StoreController

- [x] 3.1 Create `lib/Controller/StoreController.php` (SPDX + copyright header) injecting `IRequest`, `IUserSession`, `RemoteTemplateStoreService`, `LoggerInterface`, and the clone collaborators needed for install.
- [x] 3.2 `search()` — `#[NoAdminRequired]`; reject unauthenticated session with 401 (in-body guard, no-admin-idor: instance-shared read); read `q`, call `searchTemplates`, return 200 with cards or the generic error envelope.
- [x] 3.3 `install(string $slug)` — `#[NoAdminRequired]`; reject unauthenticated session with 401 (in-body guard); validate `{slug}` against the kebab-case pattern; resolve the remote template via `resolveTemplate`; 404 (generic) when unresolved with nothing created.
- [x] 3.4 Install → clone reuse: extract the in-memory clone body of `ApplicationsController::createFromTemplate` (companion-schema namespacing, manifest rewrite, `templateOrigin`, per-app register, persist) into a reusable seam and call it from `install()` with the remote payload — do NOT duplicate the namespacing/rewrite logic; apply the same `validateManifest` guard.
- [x] 3.5 Register both routes in `appinfo/routes.php` with correct verbs and `requirements` on `{slug}`: `GET /api/store/templates` → `store#search`, `POST /api/store/templates/{slug}/install` → `store#install`; place specific-first before the SPA catch-all.

## 4. Frontend: store UI on the Templates page

- [x] 4.1 Add a store section to `src/views/TemplateGallery.vue`: a debounced search box bound to `GET /api/store/templates?q=…` and a row of remote result cards, rendered only when `storeConfigured` is true.
- [x] 4.2 Wire each remote card's "Install" action to open the existing `src/modals/CloneTemplateDialog.vue` seeded with the remote template; route its submit to `POST /api/store/templates/{slug}/install` and redirect to the editor on success (reuse the local-clone redirect path).
- [x] 4.3 No-registry empty state: hide the store section when `storeConfigured` is false; show admins a "configure a registry" hint linking to admin settings; keep the local template list unchanged.
- [x] 4.4 Store error state: render a generic "store unavailable" message on a failed search without breaking the local-template listing.
- [x] 4.5 i18n English source strings for store UI (search placeholder, Install label, badges, empty/error states) + Dutch translations in `l10n/nl.json`.

## 5. Tests

- [x] 5.1 PHPUnit `RemoteTemplateStoreService`: happy-path search (normalised cards, manifest stripped), search term forwarded as `_search`, registry-unreachable → `store_unreachable`, invalid/unparseable response → `store_invalid_response`, no-registry-configured → no network call, SSRF guard rejects a private/loopback host and a non-http(s) scheme.
- [x] 5.2 PHPUnit `StoreController`: unauthenticated search + install rejected (401), authenticated search proxies and returns cards, install resolves remote payload and clones locally (201 with new Application UUID + `templateOrigin`), unresolvable slug → generic 404 with nothing created.
- [x] 5.3 Vitest `TemplateGallery`: store search renders remote results, Install opens `CloneTemplateDialog`, no-registry empty state hides the store section and issues no store request, local templates still render.
- [x] 5.4 Vitest `CloneTemplateDialog`: remote-card submit calls the store install endpoint and redirects on success; submission gated on a valid target.

## 6. Docs + gates

- [x] 6.1 Update the OpenBuild feature/docs (e.g. `docs/intro.md` or the template-catalogue doc) to describe the remote template store, the admin registry setting, and the consume-only scope.
- [x] 6.2 Run hydra gates green: route-auth + semantic-auth (both endpoints `#[NoAdminRequired]` with matching in-body guards), route-reachability (both routes ↔ `StoreController` methods), no-admin-idor (auth guard in each method body), spec-coverage (`@spec` tags on changed methods), modal-isolation (install reuses the standalone `CloneTemplateDialog`), spdx-headers, forbidden-patterns, stub-scan, composer-audit.
