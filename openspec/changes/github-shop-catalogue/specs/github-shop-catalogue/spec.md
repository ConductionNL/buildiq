## ADDED Requirements

### Requirement: Server-side GitHub search over the openbuild-app topic

The system SHALL provide a `GitHubCatalogService` that searches GitHub for
OpenBuild apps server-side via `OCP\Http\Client\IClientService`, requesting
`https://api.github.com/search/repositories` with a query that includes
`topic:openbuild-app` (a user search term, when supplied, appended as an
additional qualifier). The host SHALL be a fixed compile-time constant
(`api.github.com`) — never admin-configurable — so there is no SSRF surface; the
service SHALL still validate any `owner` / `repo` / `ref` value against a safe
pattern before interpolating it into a request path. The service SHALL normalise
the GitHub search response to a flat array of card entries and SHALL never return
the raw GitHub response body to the caller.

**ID:** REQ-GHSC-001

#### Scenario: Search targets the openbuild-app topic on the fixed host

- **WHEN** the GitHub shop search runs
- **THEN** the outbound request is to `api.github.com` and its query includes
  `topic:openbuild-app`

#### Scenario: A user term is appended to the topic query

- **WHEN** the search runs with the term `permit`
- **THEN** the outbound query carries both `topic:openbuild-app` and the
  URL-encoded `permit` term

### Requirement: Per-hit descriptor fetch builds installable cards

The `GitHubCatalogService` SHALL, for each search hit, fetch the repo's root
`openbuild-app.json` (via the GitHub contents API on the fixed host) and build a
card carrying `slug`, `name`, `description`, `category`, `appType`, `version`, the
declared `credentials[]`, and the repo identity (owner / name, optional stars).
A hit whose descriptor is missing or unparseable SHALL be surfaced as a
non-installable / unparseable candidate — it SHALL NOT be silently dropped from
the results.

**ID:** REQ-GHSC-002

#### Scenario: A conforming repo yields an installable card

- **WHEN** a search hit's repo has a parseable root `openbuild-app.json`
- **THEN** the card carries the descriptor's `slug`, `name`, `description`,
  `category`, `appType`, and `version`

#### Scenario: A repo without a parseable descriptor is surfaced, not dropped

- **WHEN** a search hit's repo has no parseable `openbuild-app.json`
- **THEN** the card is surfaced as a non-installable / unparseable candidate
- **AND** the hit is not silently removed from the result set

### Requirement: Short-TTL server-side caching for rate-limit resilience

The system SHALL cache GitHub search results and per-repo descriptors
server-side via `OCP\ICacheFactory` with a short TTL, because anonymous GitHub
search is rate-limited (~10 requests/minute). A cache hit SHALL be served without
issuing an outbound request. When GitHub returns a rate-limit response and a
cached result exists, the service SHALL serve the cached result with a
`rate_limited` hint; when none exists, it SHALL surface a generic
`github_rate_limited` outcome without the raw GitHub body.

**ID:** REQ-GHSC-003

#### Scenario: A cached search is served without an outbound request

- **GIVEN** a search query whose result is already cached and unexpired
- **WHEN** the same query runs again
- **THEN** the cached result is returned
- **AND** no outbound GitHub request is made

#### Scenario: Rate limiting yields a generic outcome, not the raw body

- **WHEN** GitHub returns a rate-limit response and no cached result exists
- **THEN** the service surfaces a generic `github_rate_limited` outcome
- **AND** the raw GitHub response body is not returned to the caller

### Requirement: Automatic broker-credential upgrade for search and fetch

The system SHALL upgrade GitHub search and fetch to an authenticated request when
the acting user has an allowed broker `github` credential — routing the call
through OpenRegister's `CredentialBrokerService::request(credentialId,
'openbuild', method, path, headers, body, actingUserId)` so the credential's
token is used by the broker and NEVER reaches OpenBuild. The service SHALL resolve
the broker lazily (`class_exists` + `Server::get`, mirroring the OR-service
resolution in `RemoteTemplateStoreService`) and SHALL fall back to an anonymous
request when the broker class is absent, the widened `github` allowRules are not
present, or the broker denies the call. Anonymous browsing SHALL remain the
default so the shop is usable with no credential configured.

**ID:** REQ-GHSC-004

#### Scenario: An allowed github credential upgrades the request

- **GIVEN** the acting user has an allowed broker `github` credential and the
  widened allowRules are present
- **WHEN** the GitHub shop search runs
- **THEN** the request is performed through the credential broker
- **AND** no GitHub token is present in OpenBuild's process or response

#### Scenario: Missing broker or rules falls back to anonymous

- **WHEN** the broker class is absent, the widened rules are missing, or the
  broker denies the call
- **THEN** the service performs an anonymous GitHub request
- **AND** the search still returns results (subject to the anonymous rate limit)

### Requirement: GitHub shop search endpoint requires an authenticated user

The system SHALL register `GET /api/shop/github/search` in `appinfo/routes.php`
backed by a `ShopController` method carrying `#[NoAdminRequired]`. The method
SHALL reject an unauthenticated session (`IUserSession::getUser() === null`) with
a 401 before performing any work. On success it SHALL return 200 with the
normalised GitHub cards plus a hint indicating whether an authenticated
(broker-upgraded) request was used and whether the anonymous result was rate
limited. The `q` search term SHALL be URL-encoded before use in the outbound
request.

**ID:** REQ-GHSC-005

#### Scenario: Unauthenticated search is rejected

- **WHEN** an unauthenticated request hits `GET /api/shop/github/search`
- **THEN** the response is 401
- **AND** no outbound GitHub request is made

#### Scenario: Authenticated search returns normalised cards

- **WHEN** an authenticated user calls `GET /api/shop/github/search?q=permit`
- **THEN** the response is 200 with the normalised GitHub cards
- **AND** the response carries a broker-availability / rate-limit hint

### Requirement: GitHub install parses the repo and reuses the clone seam

The system SHALL register `POST /api/shop/github/install` in `appinfo/routes.php`
backed by a `ShopController` method carrying `#[NoAdminRequired]` that rejects an
unauthenticated session with 401. Given `{ owner, repo, ref?, name, slug }` (with
`owner` / `repo` / `ref` pattern-validated and `slug` validated against the
kebab-case Application pattern), the method SHALL fetch the repo's file map via
`GitHubCatalogService` (broker-upgraded when available, else anonymous), parse it
with `github-app-repo-format`'s `AppRepoParser`, and create a new local
`Application` by handing the parsed template-array payload (with the user-supplied
`name` + `slug`) to the existing `ApplicationsController::installFromTemplateArray`
seam. The method SHALL NOT re-implement companion-schema namespacing, manifest
rewriting, or clone validation. A repo that fails the strict parse SHALL yield a
generic-but-actionable 4xx carrying the parser's error code and offending file
path, and SHALL create nothing locally.

**ID:** REQ-GHSC-006

#### Scenario: Installing a conforming GitHub app creates a local Application

- **WHEN** an authenticated user installs a resolvable conforming
  `topic:openbuild-app` repo with a valid new `name` + `slug`
- **THEN** a new local `Application` exists with `status: draft`, owned by the
  caller
- **AND** its companion schemas are namespaced under the new slug (via the reused
  clone seam)
- **AND** its `templateOrigin` records `source: "github"` and the repo identity

#### Scenario: Installing a malformed repo creates nothing and reports the file

- **WHEN** an authenticated user installs a repo whose `AppRepoParser` parse fails
  (e.g. an unparseable `schemas/*.json`)
- **THEN** the response is a generic-but-actionable 4xx carrying the parser error
  code and offending file path
- **AND** no `Application` or schema is created locally

#### Scenario: Unauthenticated install is rejected

- **WHEN** an unauthenticated request hits `POST /api/shop/github/install`
- **THEN** the response is 401
- **AND** nothing is created locally
