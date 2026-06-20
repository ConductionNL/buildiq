---
status: in-progress
---

# openbuild-remote-template-store Specification

## Purpose

Opens OpenBuild's template catalogue to a **remote OpenRegister-backed store**.
A configurable remote OpenRegister instance exposes `ApplicationTemplate` objects
over its public objects API; OpenBuild reads them via an admin-configured
**registry base URL** (with an optional read token), browses/searches them on the
Templates page, and installs a chosen template into the local instance by reusing
the existing template-clone path so the result is a normal local `Application`
(virtual app). All remote I/O runs through a **server-side proxy** (avoids
browser CORS, keeps the URL/token server-side) guarded against SSRF. This is the
*consume* half of federation — publishing back to a remote catalogue is out of
scope for this cut. When no registry is configured, local templates keep working
unchanged (additive, no regression). No new OpenRegister schema is introduced;
the registry connection is an admin app-config value.

**OpenSpec changes**: [openbuild-remote-template-store](../../changes/openbuild-remote-template-store/)

**Status**: in-progress

@e2e exclude consume-only remote store — the proxy/search/install backend is covered by the RemoteTemplateStoreService + StoreController PHPUnit suites and the UI by the TemplateGallery/CloneTemplateDialog Vitest specs; there is no live remote catalogue to drive a Playwright flow in CI.

## Requirements

### Requirement: Registry connection is an admin app-config value

The system SHALL store the remote-registry connection as OpenBuild admin
app-config values via `OCP\IAppConfig` under the `openbuild` app: a base URL
(`registry_url`), an optional read token (`registry_token`), and an optional
register name (`registry_register`, default `openbuild`). These values SHALL be
read and written through the existing `SettingsService` and surfaced on the
OpenBuild admin settings page (admin-only via the Nextcloud settings framework).
The default `registry_url` SHALL be a placeholder
(`https://store.openbuild.example/`) and an empty `registry_url` SHALL mean "no
store configured". The token SHALL be write-only from the UI: `getSettings()`
SHALL expose only a boolean presence flag (`registry_token_set`) and SHALL NOT
return the token value; saving an empty token SHALL leave the stored token
unchanged. No new OpenRegister schema is introduced by this requirement.

#### Scenario: Saving a registry URL persists it as an app-config value

- **WHEN** an admin saves a non-empty `registry_url` on the OpenBuild admin
  settings page
- **THEN** the value is stored under the `openbuild` app config
- **AND** a subsequent `getSettings()` returns that `registry_url`

#### Scenario: The registry token is never returned to the client

- **WHEN** an admin saves a `registry_token` and the settings are re-loaded
- **THEN** `getSettings()` returns `registry_token_set: true`
- **AND** the response body does NOT contain the token value

#### Scenario: Saving an empty token leaves the existing token unchanged

- **GIVEN** a registry token is already stored
- **WHEN** an admin saves the form with an empty token field
- **THEN** the stored token is unchanged
- **AND** `registry_token_set` remains `true`

### Requirement: Server-side proxy searches remote templates

The system SHALL provide a `RemoteTemplateStoreService` that fetches
`application-template` objects from the configured remote OpenRegister instance
server-side via `OCP\Http\Client\IClientService`, requesting
`{registry_url}/index.php/apps/openregister/api/objects/{registry_register}/application-template`
with an optional `?_search={query}` filter and an `Authorization: Bearer
{token}` header only when a token is configured. The service SHALL apply a
request timeout, SHALL normalise the OpenRegister list envelope to a flat array
of card-shaped entries (`slug`, `title`, `description`, `useCase`, `category`,
`version`, `screenshotUrl`), and SHALL NOT include the heavy `manifest` /
`companionSchemas` blobs in the search result. The browser SHALL never receive
the registry URL or token; it SHALL only call the local store endpoints.

#### Scenario: Search returns normalised remote template cards

- **WHEN** the store search runs against a reachable configured registry that
  returns three `application-template` objects
- **THEN** the service returns three card-shaped entries with `slug`, `title`,
  `description`, `useCase`, `category`, and `version`
- **AND** no entry contains a `manifest` or `companionSchemas` field

#### Scenario: A search term is forwarded as the remote search filter

- **WHEN** the store search runs with the query `permit`
- **THEN** the outbound request carries the URL-encoded `permit` term as the
  remote `_search` filter

### Requirement: Proxy handles unreachable and invalid registry responses

The `RemoteTemplateStoreService` SHALL fail closed on registry errors. When the
remote registry is unreachable, times out, or returns a non-2xx status, the
service SHALL surface a generic `store_unreachable` outcome and SHALL log the
reason server-side (with no token and no PII). When the registry returns a body
that cannot be parsed as the expected OpenRegister list envelope, the service
SHALL surface a generic `store_invalid_response` outcome. The service SHALL NOT
return the underlying exception message to the caller. When no registry is
configured (`registry_url` empty), the service SHALL report "not configured"
without attempting any network call.

#### Scenario: Unreachable registry yields a generic error

- **WHEN** the configured registry cannot be reached
- **THEN** the service reports `store_unreachable`
- **AND** the underlying exception message is not included in the result

#### Scenario: Invalid registry body yields a generic error

- **WHEN** the configured registry returns a body that is not a valid
  OpenRegister object list
- **THEN** the service reports `store_invalid_response`

#### Scenario: No registry configured performs no network call

- **WHEN** `registry_url` is empty and the store search is invoked
- **THEN** the service reports "not configured"
- **AND** no outbound HTTP request is made

### Requirement: Registry URL is SSRF-guarded before every fetch

Before any outbound fetch, the system SHALL validate and normalise the
configured `registry_url` against an anti-SSRF guard, reusing OpenRegister's
shared check (`OCA\OpenRegister\Service\SecurityService::assertSafeFetchUrl`)
when OpenRegister is loaded, and SHALL additionally enforce an `http`/`https`
scheme and a present host locally so a malformed value fails closed. A URL that
resolves to a private, loopback, link-local, or otherwise reserved address range
SHALL be rejected and no request SHALL be made. The guard SHALL run on every
fetch (search and install-resolve), not only at settings-save time.

#### Scenario: A private-address registry URL is rejected

- **WHEN** `registry_url` resolves to a loopback or private/reserved address
  (e.g. `http://169.254.169.254/` or `http://localhost/`)
- **THEN** the fetch is rejected by the SSRF guard
- **AND** no outbound HTTP request is made

#### Scenario: A non-http(s) scheme is rejected

- **WHEN** `registry_url` uses a scheme other than `http` or `https`
- **THEN** the fetch is rejected and no request is made

### Requirement: Store endpoints require an authenticated user

The system SHALL register two routes in `appinfo/routes.php` backed by a
`StoreController`: `GET /api/store/templates` (search) and `POST
/api/store/templates/{slug}/install` (install). Both controller methods SHALL
carry `#[NoAdminRequired]` and SHALL reject an unauthenticated session
(`IUserSession::getUser() === null`) with a 401 before performing any work. The
search endpoint is an instance-shared read available to any authenticated
OpenBuild user; the install endpoint is available to any authenticated OpenBuild
user and makes the caller the owner of the resulting Application. The `{slug}`
path parameter SHALL be validated against the kebab-case slug pattern and the
`q` search term SHALL be URL-encoded before use in the outbound request.

#### Scenario: Unauthenticated search is rejected

- **WHEN** an unauthenticated request hits `GET /api/store/templates`
- **THEN** the response is 401
- **AND** no outbound registry request is made

#### Scenario: Authenticated search proxies to the registry

- **WHEN** an authenticated user calls `GET /api/store/templates?q=permit`
  against a reachable configured registry
- **THEN** the response is 200 with the normalised remote template cards

#### Scenario: Unauthenticated install is rejected

- **WHEN** an unauthenticated request hits `POST
  /api/store/templates/{slug}/install`
- **THEN** the response is 401
- **AND** nothing is created locally

### Requirement: Install resolves the remote template and clones it locally

On `POST /api/store/templates/{slug}/install`, the system SHALL resolve the full
remote template payload (including `manifest` and `companionSchemas`) via
`RemoteTemplateStoreService`, then create a new local `Application` by reusing
the existing template-clone path (`createFromTemplate` REQ-OBTC-004 /
REQ-OBTC-005): companion schemas are cloned into a per-app namespace, manifest
schema references are rewritten, and `templateOrigin` records the source slug +
version. The installed app SHALL be a normal local `Application` (virtual app)
owned by the calling user. The install SHALL reuse the existing clone logic
rather than re-implementing namespacing/rewriting, and SHALL apply the same
manifest validation the local clone applies. A remote template that cannot be
resolved SHALL yield a generic not-found error and SHALL create nothing locally.

#### Scenario: Install creates a local Application from a remote template

- **WHEN** an authenticated user installs a resolvable remote template with a
  valid new name + slug
- **THEN** a new local `Application` exists with `status: draft`, owned by the
  caller
- **AND** its `templateOrigin.slug` and `templateOrigin.version` match the
  remote template
- **AND** its companion schemas are namespaced under the new Application slug

#### Scenario: Installing an unresolvable remote template creates nothing

- **WHEN** an authenticated user installs a slug the registry does not serve
- **THEN** the response is a generic not-found error
- **AND** no Application or schema is created locally

### Requirement: No-registry-configured fallback keeps local templates working

When no registry is configured (`registry_url` empty), the system SHALL keep the
local template catalogue fully functional and SHALL NOT attempt any remote
fetch. The settings load surfaced to the Templates page SHALL expose a
`storeConfigured` flag so the frontend can hide the store section (or show a
"configure a registry" hint to admins) without breaking the local-template
listing. This change SHALL be additive: with no registry configured, the
Templates page behaves exactly as before this change.

#### Scenario: Local templates render with no registry configured

- **WHEN** a user opens the Templates page on an instance with no `registry_url`
  set
- **THEN** the locally seeded templates are listed as before
- **AND** the store section is not rendered
- **AND** no outbound registry request is made
