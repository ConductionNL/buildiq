## ADDED Requirements

### Requirement: Owner links an application to a GitHub repository

The system SHALL expose `POST /api/applications/{slug}/github/link`, registered in
`appinfo/routes.php` and backed by a `GitHubSyncController` method carrying
`#[NoAdminRequired]`. The method SHALL load the Application by `slug` (404 when
absent) and require the caller be an **owner** of the Application (reusing the
`permissions`/`ApplicationVersionOwnerGuard` owner gate) — a caller in
`editors`/`viewers`, or a Nextcloud admin not listed in `permissions.owners`,
SHALL receive 403. Given `{ owner, name, org? }` (pattern-validated), it SHALL
store `githubRepo = { owner, name }` and `githubDefaultBranch` (resolved from the
repo's default branch, or a sensible default when the repo does not yet exist) on
the Application, and return the stored linkage.

**ID:** REQ-GHAS-001

#### Scenario: Owner links an app to a repo

- **WHEN** an owner calls `POST /api/applications/permit-tracker/github/link` with
  `{ owner: "conduction", name: "permit-tracker" }`
- **THEN** the Application's `githubRepo` is `{ owner: "conduction", name: "permit-tracker" }`
- **AND** `githubDefaultBranch` is populated
- **AND** the response is 200 with the stored linkage

#### Scenario: Non-owner link is rejected

- **GIVEN** a caller listed in `permissions.editors` (not `owners`)
- **WHEN** the caller calls the link endpoint
- **THEN** the response is 403
- **AND** the Application's `githubRepo` is unchanged

#### Scenario: Nextcloud admin without owner role is rejected

- **GIVEN** a Nextcloud admin not in the Application's `permissions.owners`
- **WHEN** the admin calls the link endpoint
- **THEN** the response is 403 (admin power does not auto-grant a GitHub write)

### Requirement: Push serializes via the repo format and routes every call through the broker

The system SHALL provide `GitHubAppSyncService::push(slug, credentialId, repo?)`,
exposed via `POST /api/applications/{slug}/github/push` (`#[NoAdminRequired]`,
owner-gated as in REQ-GHAS-001). Push SHALL serialize the chosen
`ApplicationVersion` via `github-app-repo-format`'s `AppRepoSerializer` into an
in-memory file map, then commit it to GitHub by porting the Git Data API tree-push
mechanics (blob → tree → commit → ref) from `GitHubPushService`, routing **EVERY**
outbound HTTP call through
`CredentialBrokerService::request(credentialId, 'openbuild', method, path,
headers, body, actingUserId)` so the credential's token is used by the broker and
NEVER reaches OpenBuild. When the Application is not yet linked to a repo (or a
`repo` override is supplied for a new repo), push SHALL create the repository
(`POST /user/repos` or `POST /orgs/{org}/repos` via the broker) and set the
discovery topic `openbuild-app`. A created repository SHALL default to **PUBLIC**
(`"private": false`) so the shop's anonymous catalogue search can discover it; the
owner MAY override this via an optional `visibility` push param (`'public'` |
`'private'`, default `'public'`). Push SHALL be non-destructive — the commit is
parented on the current branch head (push adds a commit, never a force overwrite)
— and SHALL record the resulting `commitSha` on the pushed `ApplicationVersion`.

**ID:** REQ-GHAS-002

#### Scenario: Owner pushes a version through the broker

- **GIVEN** a linked Application whose owner has an allowed broker `github`
  credential
- **WHEN** the owner calls the push endpoint with that `credentialId`
- **THEN** the app's chosen version is serialized via `AppRepoSerializer` and
  committed to the linked repo
- **AND** every GitHub call is performed through the credential broker (no token
  in OpenBuild's process or response)
- **AND** the pushed `ApplicationVersion.commitSha` records the new commit

#### Scenario: Push of an unlinked app creates the repo and sets the topic

- **GIVEN** an Application with no `githubRepo` and a push request naming a new repo
- **WHEN** an owner pushes
- **THEN** a GitHub repository is created via the broker
- **AND** the repository is created PUBLIC by default (`"private": false`) so the shop can discover it
- **AND** the repository carries the topic `openbuild-app`
- **AND** the Application's `githubRepo` + `githubDefaultBranch` are stored

#### Scenario: Owner overrides created-repo visibility to private

- **GIVEN** an Application with no `githubRepo` and a push request naming a new repo
- **WHEN** an owner pushes with `visibility: 'private'`
- **THEN** the repository is created PRIVATE (`"private": true`)
- **AND** absent an explicit `visibility`, the repository defaults to PUBLIC

#### Scenario: Push is non-destructive

- **WHEN** an owner pushes to a linked repo
- **THEN** the push adds a new commit parented on the current head
- **AND** no branch history is force-overwritten or rewritten

### Requirement: Pull creates a new draft ApplicationVersion, never overwriting production

The system SHALL provide `GitHubAppSyncService::pull(slug, ref, credentialId?)`,
exposed via `POST /api/applications/{slug}/github/pull` (`#[NoAdminRequired]`,
owner-gated). Pull SHALL fetch the linked repo's file map via
`github-shop-catalogue`'s `GitHubCatalogService::fetchRepoFiles` (the broker path
when a `credentialId` is supplied, for private repos; anonymous otherwise, for
public repos), parse it with `github-app-repo-format`'s `AppRepoParser` (strict,
all-or-nothing), and create a **new draft `ApplicationVersion`** on the existing
Application carrying the parsed `manifest`, `status: draft`, and stamped with
`commitSha` (the resolved commit for `ref`) and `sourceRef` (`ref`). Pull SHALL
NEVER modify `Application.productionVersion` or any published version — activation
of the pulled draft uses the existing version-promotion/release flow. A repo that
fails the strict parse SHALL yield a generic-but-actionable error carrying the
parser code + offending file path and SHALL create nothing.

**ID:** REQ-GHAS-003

#### Scenario: Pull creates a draft version stamped with provenance

- **GIVEN** a linked Application with a production version
- **WHEN** an owner pulls `ref = main`
- **THEN** a new `ApplicationVersion` exists with `status: draft`, the pulled
  `manifest`, `commitSha` set to the resolved commit, and `sourceRef: "main"`
- **AND** `Application.productionVersion` is unchanged
- **AND** no published version is modified

#### Scenario: Pull of a malformed repo creates nothing

- **WHEN** an owner pulls a ref whose repo fails the strict `AppRepoParser` parse
- **THEN** the response is a generic-but-actionable error carrying the parser code
  and offending file path
- **AND** no ApplicationVersion is created

#### Scenario: Public repo pull works without a credential

- **GIVEN** a linked public repo and a pull request with no `credentialId`
- **WHEN** an owner pulls a ref
- **THEN** the repo is fetched anonymously and a draft version is created

### Requirement: GitHub sync status reports linkage, provenance, and feature availability

The system SHALL expose `GET /api/applications/{slug}/github/status`
(`#[NoAdminRequired]`) returning the app's `githubRepo`, `githubDefaultBranch`,
last-pushed and last-pulled `commitSha`, and two feature-detection flags:
`brokerCredentialAvailable` (the acting user has an allowed broker `github`
credential) and `publishAvailable` (the broker is present AND its widened `github`
write allowRules permit a push). The status read MAY be available to any caller
with viewer access to the Application; the write operations (link/push/pull)
remain owner-only.

**ID:** REQ-GHAS-004

#### Scenario: Status reports the linked repo and provenance

- **WHEN** a caller with access GETs the status of a linked Application
- **THEN** the response carries `githubRepo`, `githubDefaultBranch`, the last
  pushed sha, and the last pulled sha

#### Scenario: Status reports publish unavailable when the broker rules are missing

- **WHEN** the broker is absent or its widened `github` write allowRules are not
  present
- **THEN** the status response has `publishAvailable: false`

### Requirement: Every GitHub write is broker-routed with the token never in OpenBuild

The system SHALL route every GitHub write (create-repo, set-topic, blob, tree,
commit, ref) through `CredentialBrokerService::request(...)`, resolving the broker
lazily (`class_exists` + `Server::get`, mirroring `RemoteTemplateStoreService`'s
OR-service resolution). OpenBuild SHALL NEVER read, store, log, or return a GitHub
token. The broker's own guards (owner / allowedApps=`openbuild` / the `github`
write allowRules / host-lock=`api.github.com`) SHALL be treated as authoritative;
OpenBuild SHALL NOT re-implement them and SHALL surface a broker denial as a
generic, hint-bearing failure (feeding the `publishAvailable` feature detection).
When the broker class is absent, publish SHALL be reported unavailable rather than
falling back to any token-bearing path.

**ID:** REQ-GHAS-005

#### Scenario: No token appears in OpenBuild during a push

- **WHEN** an owner pushes through the broker
- **THEN** no GitHub token is present in OpenBuild's process, logs, or response
- **AND** each GitHub call is performed by the broker with the stored credential

#### Scenario: Broker denial surfaces as a hint, not a token fallback

- **WHEN** the broker denies a push (rules missing or credential not allowed)
- **THEN** the response is a generic hint-bearing failure
- **AND** OpenBuild does not attempt any token-bearing GitHub call
