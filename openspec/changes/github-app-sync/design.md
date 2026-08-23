## Context

An Buildiq app is an `Application` (identity + `permissions` RBAC +
`productionVersion`) plus N `ApplicationVersion` rows (each a full `manifest` +
`semver` + per-version register). Change `github-app-repo-format` added the
`AppRepoSerializer` / `AppRepoParser` pair and the linkage/provenance fields
(`Application.githubRepo`, `Application.githubDefaultBranch`,
`ApplicationVersion.commitSha`, `ApplicationVersion.sourceRef`). Change
`github-shop-catalogue` added `GitHubCatalogService` (fixed-host GitHub I/O,
caching, broker upgrade, `fetchRepoFiles`) and the install-via-parser seam.

Two existing pieces are the raw material for the round-trip:

- `GitHubPushService` (the exporter) already implements the **Git Data API
  tree-push**: walk a file tree, `createBlob` per file, assemble a tree + commit,
  point a ref at it, open a PR — all authenticated with a **PAT** it fetches from
  `ICredentialsManager`. This change ports those *mechanics* but replaces the PAT
  with the broker.
- OpenRegister's **credential broker**:
  `CredentialBrokerService::request(credentialId, appId, method, path, headers,
  body, actingUserId)` performs an outbound HTTP call using a stored credential
  the calling app never sees, guarded by owner / allowedApps / allowRules /
  host-lock. The parallel OR change `github-provider-shop-rules` widens the
  `github` provider's allowRules to include `POST /user/repos`,
  `POST /orgs/*/repos`, `GET /search/repositories`, `GET /user` on top of the
  existing `GET /repos/*`, `PUT /repos/*/contents/*`, `POST /repos/*/git/*`.

The app `permissions` model (`buildiq-rbac`) already gives an owner gate
(`ApplicationVersionOwnerGuard` / the publish controller's owner check), which
this change reuses verbatim — a Nextcloud admin is NOT auto-granted (matching the
release endpoint, REQ-OBV-110).

## Goals / Non-Goals

**Goals:**

- Let an **owner** link an app to a GitHub repo, **push** its current version, and
  **pull** a ref back — from the app detail cockpit.
- Route **every** GitHub write through the OR credential broker so the token never
  enters Buildiq; read (pull) via the broker for private repos, anonymously for
  public.
- Reuse change 1's serializer/parser and change 2's repo fetch — no new
  serialize/parse/clone logic.
- Non-destructive conflict model: push always adds a new commit; pull always
  creates a new **draft** `ApplicationVersion`; production is never overwritten.
- Owner-RBAC gate every operation; feature-detect broker + widened rules and
  degrade the UI cleanly when publish is unavailable.

**Non-Goals:**

- Any change to the exporter (`GitHubPushService` / `ExportJobService` /
  `exportJob`) — its PAT scaffold path stays.
- Force-push, history rewrite, or overwriting the production version.
- Automatic merge/diff resolution of divergent local+remote edits (surface the
  divergence; the owner chooses — pull makes a draft, they promote it).
- Multi-repo linkage (one app ↔ one repo this cut).
- A general GitHub client beyond the fixed endpoints push/pull need.

## Decisions

### Decision 1 — `GitHubAppSyncService::push`: serialize (change 1) + broker-routed Git Data API

`push(string $slug, string $credentialId, ?array $repo = null): array`:

1. **Owner gate** (Decision 6) — resolve the Application, require the caller is an
   owner.
2. **Serialize** the chosen `ApplicationVersion` via change 1's
   `AppRepoSerializer::serialize(application, version)` → in-memory `path =>
   contents` file map (descriptor + manifest + `schemas/*` + README). No on-disk
   tree (unlike the exporter) — the broker calls take blob contents directly.
3. **Ensure a repo** — if `Application.githubRepo` is unset (or `$repo` overrides
   it), create one via the broker: `POST /user/repos` (user-owned) or
   `POST /orgs/{org}/repos` (org-owned), then set the discovery **topic
   `buildiq-app`** (`PUT /repos/{owner}/{repo}/topics`), and store `githubRepo`
   + `githubDefaultBranch` on the Application. The create-repo body sets
   **`"private": false` (PUBLIC by default)** — rationale: a freshly published app
   must be discoverable in the shop's *anonymous* catalogue search, and a private
   repo is invisible to it. Visibility is overridable via an optional `visibility`
   push param (`'public'` | `'private'`, default `'public'`) threaded
   controller → `push()` → `ensureRepo()`; `'private'` sets `"private": true`.
4. **Tree push (ported from `GitHubPushService`, broker-routed)** — for each file:
   `POST /repos/{o}/{r}/git/blobs`; then `POST …/git/trees` (assemble the tree),
   `POST …/git/commits` (parented on the current default-branch head, fetched via
   `GET …/git/ref/heads/{branch}` — a `GET /repos/*` call), then advance the
   branch ref (`PATCH …/git/refs/heads/{branch}`). **Every** one of these calls
   goes through `CredentialBrokerService::request(credentialId, 'buildiq',
   <method>, <path>, headers, body, actingUserId)` — no PAT, no token in
   Buildiq.
5. **Stamp provenance** — record the resulting `commitSha` on the pushed
   `ApplicationVersion` and return `{ repoUrl, commitSha, branch }`.

**Non-destructive:** the commit is parented on the current head, so push **adds a
commit**; it never force-updates or rewrites history. If the remote head has moved
since the last pull (divergence), the ref advance is a fast-forward when possible
and otherwise surfaced as a `push_conflict` outcome (the owner pulls first) —
never a force overwrite. **Alternative considered:** `PUT /repos/*/contents/*`
per file — rejected: one commit per file, no atomic multi-file commit; the Git
Data API produces one clean commit like the exporter.

> **Broker allowRule note (RESOLVED):** the ref advance uses `PATCH …/git/refs/*`.
> The `github-provider-shop-rules` change (OpenRegister) includes
> `PATCH /repos/*/git/refs/*` in the widened `github` allowRules precisely for this
> call, alongside `POST /repos/*/git/*` (blobs/trees/commits/refs *create*). No
> PR-based fallback is needed — and none would work, since pull-request endpoints
> are explicitly denied by the broker's github rules.

### Decision 2 — `GitHubAppSyncService::pull`: fetch (change 2) + parse (change 1) → new DRAFT version

`pull(string $slug, string $ref, ?string $credentialId = null): array`:

1. **Owner gate** (Decision 6).
2. **Fetch** the repo file map via change 2's
   `GitHubCatalogService::fetchRepoFiles(owner, repo, ref, actingUserId)` — broker
   path when a `credentialId` is supplied (private repos), anonymous otherwise
   (public). The `owner`/`repo` come from `Application.githubRepo`.
3. **Parse** with change 1's `AppRepoParser::parse($files)` — strict,
   all-or-nothing; a malformed repo fails loudly with an actionable per-file code
   and nothing is created.
4. **Create a new DRAFT `ApplicationVersion`** on the existing Application carrying
   the parsed `manifest`, stamped with `commitSha` (the resolved commit for `ref`)
   and `sourceRef` (`ref`), `status: draft`, and `application` → this app. It
   **never** touches `productionVersion` or any published version. `semver` follows
   the existing model (auto-bump on content, REQ-OBV-103) or is seeded from the
   descriptor `version`; the provenance write itself does not bump.
5. **Companion schema reconciliation** — parsed `companionSchemas` are applied to
   the app's register the same way the clone seam namespaces them; when a schema
   changed shape, the draft version's register carries the updated schema so the
   owner can test the draft before promoting. (Schema divergence is surfaced, not
   silently force-applied to production — the draft is isolated.)
6. Return `{ versionUuid, commitSha, sourceRef, status: 'draft' }`.

**Non-destructive:** a pull is always a new draft the owner reviews and promotes
via the existing release endpoint (REQ-OBV-110) — production is never overwritten
by a pull. **Alternative considered:** pull-overwrites-current-version — rejected
outright (the user-approved conflict model forbids it; it would destroy local
edits and production data).

### Decision 3 — Reuse, don't rebuild

Push reuses `AppRepoSerializer` (change 1); pull reuses `GitHubCatalogService::fetchRepoFiles`
(change 2) + `AppRepoParser` (change 1) + the clone seam's namespacing
conventions. The only genuinely new code is (a) the broker-routed Git Data API
sequence (a port of `GitHubPushService`'s blob/tree/commit/ref mechanics with the
PAT swapped for `CredentialBrokerService::request`), (b) the draft-version
creation on pull, and (c) the owner-gated controller + UI section. **No** new
serialize/parse/clone logic. **Alternative considered:** call the exporter's
`GitHubPushService` directly — rejected: it is PAT-based and produces a PHP
scaffold, not the change-1 data format; sharing its private methods would couple
two different formats and two different auth models.

### Decision 4 — Endpoints on `GitHubSyncController`

A new `lib/Controller/GitHubSyncController.php`:

- `POST /api/applications/{slug}/github/link` — body `{ owner, name, org? }`;
  resolve the repo's default branch (via the broker or anonymously), store
  `githubRepo = { owner, name }` + `githubDefaultBranch` on the Application. When
  the repo does not yet exist, link records the intended `{ owner, name }` and the
  first push creates it (Decision 1 step 3).
- `POST /api/applications/{slug}/github/push` — body `{ credentialId, versionSlug?,
  repo? }`; run `push`.
- `POST /api/applications/{slug}/github/pull` — body `{ ref, credentialId? }`; run
  `pull`.
- `GET /api/applications/{slug}/github/status` — return `{ githubRepo,
  githubDefaultBranch, lastPushedSha, lastPulledSha, brokerCredentialAvailable,
  publishAvailable }` (the last two are the feature-detection the UI consumes).

All carry `#[NoAdminRequired]`; each method body loads the Application by `slug`
(404 when absent), runs the owner check (403 for non-owner, including a bare
Nextcloud admin), then acts — the per-object guard closes the no-admin-idor
surface. Routes are registered specific-first in `appinfo/routes.php` before the
SPA catch-all; `{slug}` carries a kebab-case `requirements` constraint.

### Decision 5 — Frontend: a GitHub section in the detail cockpit

The application detail cockpit (`application-detail-ui`,
`ApplicationDetailActions` / tabs) gains a **GitHub** section:

- **Credential picker** — lists the user's `github` credentials via OR's
  `GET /apps/openregister/api/credentials`, with a link to the CnCredentials pane
  to add one when none exists.
- **Link-repo dialog** (own file under `src/modals/`) — owner + name (+ optional
  org) → `POST …/github/link`.
- **Publish** action — `POST …/github/push` with the chosen credential + version;
  shows the resulting commit sha.
- **Pull** action — `POST …/github/pull` with a ref + credential; on success links
  to the new draft version (which the owner promotes via the existing flow).
- **Status readout** — linked repo, last pushed / last pulled sha, from
  `GET …/github/status`.
- **Feature detection** — the section reads `publishAvailable` /
  `brokerCredentialAvailable` from `status`; when publish is unavailable (broker
  absent, rules not widened, or no credential) the Publish control is disabled with
  a clear hint (what's missing + how to fix). Pull of a public repo stays available
  anonymously.

Every new dialog/modal is its own `.vue` under `src/modals/` (modal-isolation);
no inline `NcModal`/`NcDialog`.

### Decision 6 — Owner-RBAC (reuse `buildiq-rbac`, admins not auto-granted)

Every operation requires the caller be an **owner** of the Application, reusing the
existing owner gate (`ApplicationVersionOwnerGuard` / the publish controller's
owner check against `permissions.owners`). A caller in `editors`/`viewers` gets
403; a Nextcloud admin who is not in `permissions.owners` also gets 403 (matching
the release endpoint, REQ-OBV-110 — admin power does not auto-grant a
GitHub-write). Read-only `status` MAY be available to any role with viewer access
to the app; the write operations (link/push/pull) are owner-only. This keeps the
GitHub round-trip a first-class owner action, not an admin backdoor.

### Decision 7 — Declarative vs imperative (ADR-031)

This change adds **no** declarative behaviour matching the ADR-031 triggers and
**no new schema** (it writes the fields change 1 declared). The one lifecycle-ish
concern — a pull producing a new draft version — is **not** a new state machine:
it creates an `ApplicationVersion` in the existing `draft` initial state via the
existing model; activation uses the existing declarative
lifecycle/release path. `GitHubAppSyncService` is a genuine **external-integration
imperative** path (GitHub Git Data API calls via the broker) — the ADR-031
external-integration exception, the same posture as the exporter's
`GitHubPushService` and change 2's `GitHubCatalogService`. So: imperative only for
the network/broker leg; the version it creates flows through the existing
declarative version model; zero new declarative surface.

### Decision 8 — Seed Data (ADR-001)

This change introduces **no new OpenRegister schema** and seeds **no new objects**.
It writes the optional linkage/provenance fields (declared by change 1) on
existing `Application` / `ApplicationVersion` objects, and a pull creates a normal
`ApplicationVersion` via the existing model (not a seed). The only objects it
touches already have their seed/creation home in the existing capabilities. Seed
data for this change = **N/A**. Test fixtures use an example linked Application + a
`permit-tracker` repo file map (test data, not OR seed data).

### Decision 9 — Security (ADR-005)

- **Token never in Buildiq.** Every GitHub write goes through
  `CredentialBrokerService::request` with a `credentialId`; the broker holds and
  uses the token. Buildiq never reads, logs, or returns a token. Pull of a
  private repo uses the same broker path; public pull is anonymous.
- **Broker guards are authoritative.** owner / allowedApps=`buildiq` /
  allowRules (github write set) / host-lock=`api.github.com` are enforced by the
  broker; Buildiq does not re-implement them and treats a broker denial as a
  clean, hint-bearing failure (feature detection).
- **Owner-RBAC on every write.** link/push/pull are owner-only (Decision 6);
  admins not auto-granted; per-object guard closes no-admin-idor.
- **Non-destructive by construction.** Push adds a commit (never force); pull
  makes a draft (never overwrites production) — a compromised or buggy call can at
  worst create an extra commit/draft, not destroy production or history.
- **Fail-closed, no leakage.** Broker/transport/GitHub errors map to generic
  outcomes (`push_conflict`, `github_unreachable`, `broker_denied`); the raw
  GitHub body and any token are never surfaced. A malformed pulled repo is rejected
  by change 1's strict parser before any local write.
- **Input hygiene.** `{slug}`, `owner`, `repo`, `org`, `ref`, `credentialId`,
  `versionSlug` are pattern-validated before use.

## Risks / Trade-offs

- **[Ref-update allowRule (`PATCH …/git/refs/*`)] RESOLVED** —
  `github-provider-shop-rules` (OpenRegister) includes `PATCH /repos/*/git/refs/*`
  in the widened `github` allowRules, so push advances the linked branch directly.
  The feature-detection risk below still covers running against an OpenRegister
  release without the widened rules.
- **[Divergent local + remote edits]** push is parented on the current head; a
  moved remote head yields a `push_conflict` (owner pulls first, gets a draft,
  promotes) — never a force overwrite. Trade-off: the owner arbitrates; no
  auto-merge.
- **[Broker not yet widened for writes]** publish is disabled with a clear hint
  (feature detection via `status.publishAvailable`); pull of public repos still
  works anonymously.
- **[Companion-schema divergence on pull]** a pulled schema change lands in the
  draft version's register, isolated from production until promotion — surfaced,
  not force-applied.
- **[Create-repo scope]** `POST /user/repos` vs `POST /orgs/*/repos` depends on the
  credential's account/permissions; a create failure surfaces a generic
  `broker_denied` with a hint to check the credential's scope. No token is exposed.
- **[Trade-off: one repo per app]** single linkage keeps the model + UI simple;
  multi-repo is a clean follow-up.

## Migration Plan

1. Land `github-app-repo-format` (serializer/parser + linkage fields) and
   `github-shop-catalogue` (repo fetch + install seam) first — both hard deps;
   hydra's supervisor blocks this change until their issues close.
2. Ensure the OR `github-provider-shop-rules` change has widened the `github` write
   allowRules; otherwise publish is feature-detected as unavailable.
3. Ship `GitHubAppSyncService`, `GitHubSyncController`, the four routes, and the
   detail-cockpit GitHub section. Purely additive; no schema, no migration (fields
   already exist from change 1), no seed change.
4. **Rollback:** removing the routes + the UI section leaves the app, its versions,
   and its linkage fields intact (the fields are inert without the sync surface);
   no data is destroyed (push/pull are non-destructive).

## Open Questions

- **OQ-1 (`PATCH …/git/refs/*` allowRule): RESOLVED** — the rule is included in
  `github-provider-shop-rules` (a PR-based fallback would not work anyway:
  pull-request endpoints are explicitly denied by the broker's github rules); push
  advances the branch ref via `PATCH` with no fallback path.
- **OQ-2 (create-repo owner vs org):** `link`/`push` accept an optional `org`;
  absent → `POST /user/repos` (the credential's user), present →
  `POST /orgs/{org}/repos`. Which is the default when both are possible is
  provisionally **user-owned** unless an `org` is supplied.
- **OQ-3 (pull companion-schema reconciliation depth):** provisionally apply pulled
  schema changes to the draft version's register (isolated from production);
  full three-way schema-merge fidelity is a follow-up. This cut guarantees the
  draft is testable and production is untouched.
- **OQ-4 (status read authorization):** provisionally `status` is viewer-readable
  (so a non-owner maintainer sees the linked repo) while link/push/pull are
  owner-only; revisit if even the linked-repo readout should be owner-gated.
