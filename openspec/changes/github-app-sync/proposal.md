---
kind: code
depends_on:
  - github-app-repo-format
  - github-shop-catalogue
chain:
  - github-app-repo-format
  - github-shop-catalogue
  - github-app-sync
---

## Why

The first two changes gave Buildiq a GitHub *format* (`github-app-repo-format`)
and a GitHub *shop* that installs public apps (`github-shop-catalogue`). What's
still missing is the **owner round-trip**: from an app's admin/detail screen, an
owner should be able to **link** the app to a GitHub repo, **publish** (push) its
current version there, and **pull** an updated definition back in — the two-way
Git-backed workflow every serious low-code platform offers. The exporter can push
a one-way PHP scaffold, and OpenRegister ships a credential **broker** that lets an
app make authenticated GitHub calls *without ever seeing the token*; this change
ties them together into a real round-trip over the change-1 data format.

This is the final change of the three-change chain — the *publish + pull* half.
Every GitHub write is routed through
`CredentialBrokerService::request(...)` (the secret stays broker-side), the push
serializes via change 1's `AppRepoSerializer`, and the pull parses via change 1's
`AppRepoParser` and reuses change 2's repo-fetch. The conflict model is
deliberately non-destructive: **push always creates a new commit** (never a force
overwrite) and **pull always creates a new DRAFT `ApplicationVersion`** (never
overwrites the production version) — the existing version-promotion flow
(`application-versions` release endpoint) handles activation. Owner-RBAC gates
every operation, reusing the app's `permissions` model.

## What Changes

- **NEW** `lib/Service/GitHubAppSyncService.php` — the owner round-trip engine:
  - `push(slug, credentialId, repo?)` — serialize the app's chosen version via
    change 1's `AppRepoSerializer` into an in-memory file map, then commit it to
    GitHub by **porting the Git Data API tree-push mechanics from
    `GitHubPushService`** (blobs → tree → commit → ref) — but routing **EVERY**
    HTTP call through `CredentialBrokerService::request(credentialId, 'buildiq',
    method, path, headers, body, actingUserId)` instead of a PAT. When the app is
    not yet linked to a repo, it creates one (`POST /user/repos` or
    `POST /orgs/{org}/repos`) and sets the discovery **topic `buildiq-app`**.
    Push always adds a new commit (non-destructive); it records the resulting
    `commitSha` on the pushed `ApplicationVersion`.
  - `pull(slug, ref, credentialId?)` — fetch the repo's file map (reusing change
    2's `GitHubCatalogService::fetchRepoFiles`: broker path for private repos,
    anonymous for public), parse it with change 1's `AppRepoParser`, and create a
    **new draft `ApplicationVersion`** on the existing Application, stamped with
    `commitSha` + `sourceRef` and linked by the Application's `githubRepo`. It
    NEVER overwrites the production version; activation is a separate,
    already-existing promotion step.
  - Both paths resolve the broker lazily (`class_exists` + `Server::get`, the
    `RemoteTemplateStoreService` pattern) and never place a token in Buildiq.
- **NEW** `lib/Controller/GitHubSyncController.php` — owner-RBAC-guarded endpoints:
  - `POST /api/applications/{slug}/github/link` — link the app to a repo
    (`{ owner, name }`), resolving + storing `githubRepo` + `githubDefaultBranch`.
  - `POST /api/applications/{slug}/github/push` — publish the chosen version.
  - `POST /api/applications/{slug}/github/pull` — pull a ref into a new draft
    version.
  - `GET /api/applications/{slug}/github/status` — linked repo, last-pushed /
    last-pulled `commitSha`, broker-availability, and whether the widened rules
    permit publish.
  - All carry `#[NoAdminRequired]` with an **owner** check in the method body
    (reusing the `permissions`/`ApplicationVersionOwnerGuard` owner gate; a
    Nextcloud admin is NOT auto-granted) and per-object guards (no-admin-idor).
- **MODIFIED** the application detail cockpit (`application-detail-ui`) — a new
  **GitHub** section in the maintainer detail/admin area: a credential picker
  (listing the user's github credentials via OpenRegister's
  `GET /apps/openregister/api/credentials`, with a link to the CnCredentials pane
  to add one), a link-repo dialog, **Publish** and **Pull** actions, and a status
  readout (linked repo, last pushed/pulled sha). The section **feature-detects**
  the broker + widened allowRules and renders a disabled state with a clear hint
  when publish is unavailable. Every new dialog/modal lives in its own file
  (`src/modals/`, modal-isolation).
- **NO** change to the exporter (`GitHubPushService` / `ExportJobService` /
  `exportJob`) — the sync engine ports its *mechanics* into a new broker-routed
  service; the PAT-based scaffold exporter is untouched. **NO** force-push / no
  overwrite of production (non-destructive conflict model). **NO** new
  OpenRegister schema — it writes the linkage/provenance fields added by change 1.

## Capabilities

### New Capabilities

- `github-app-sync`: the `GitHubAppSyncService` (`push` — serialize via change 1
  + broker-routed Git Data API tree push + create-repo/set-topic when unlinked;
  `pull` — broker/anonymous repo fetch + change-1 parse → new draft
  ApplicationVersion), the `GitHubSyncController` link/push/pull/status endpoints,
  owner-RBAC gating (reusing the app `permissions` model, admins not auto-granted),
  the non-destructive conflict model (push=new commit, pull=new draft version),
  and provenance stamping (`githubRepo`, `githubDefaultBranch`, `commitSha`,
  `sourceRef`).

### Modified Capabilities

- `application-detail-ui`: the maintainer detail cockpit gains a GitHub section —
  credential picker, link-repo dialog, Publish + Pull actions, and a status
  readout — that feature-detects the broker + widened rules and shows a disabled
  state with a hint when publish is unavailable.

## Impact

- **New PHP**: `lib/Service/GitHubAppSyncService.php` (broker-routed Git Data API
  push ported from `GitHubPushService`, repo-fetch-reusing pull, provenance
  stamping) and `lib/Controller/GitHubSyncController.php` (four owner-gated
  endpoints), plus route entries in `appinfo/routes.php` (specific-first). Reuses
  change 1's `AppRepoSerializer` / `AppRepoParser` and change 2's
  `GitHubCatalogService::fetchRepoFiles` and install seam conventions — no new
  clone/serialize/parse logic.
- **New frontend**: a GitHub section in the application detail cockpit
  (`application-detail-ui`) with a credential picker, a link-repo modal, Publish +
  Pull actions, and a status readout; new modals in their own files; Vitest
  coverage.
- **Config**: the acting owner's broker `github` credential (listed via OR's
  `GET /apps/openregister/api/credentials`); the OR change `github-provider-shop-rules`
  must have widened the `github` write allowRules (`POST /user/repos`,
  `POST /orgs/*/repos`, on top of `POST /repos/*/git/*`, `PUT /repos/*/contents/*`)
  for publish to work — the UI feature-detects and disables publish with a hint
  otherwise.
- **Network/Security**: outbound GitHub writes only via the OR credential broker
  (token never in Buildiq; broker guards owner / allowedApps / allowRules /
  host-lock=`api.github.com`); pull reads via the broker (private) or anonymously
  (public). Every endpoint is owner-RBAC-gated; admins are not auto-granted.
- **Dependencies**: `github-app-repo-format` (serializer/parser + linkage fields)
  and `github-shop-catalogue` (repo fetch + install seam conventions) — both hard
  deps; OpenRegister (broker + app `permissions` + version model) already a hard
  dep. Hydra's supervisor blocks this change from building until both predecessor
  issues are closed.
