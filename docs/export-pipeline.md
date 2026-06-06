# Export pipeline

OpenBuild's founding commitment is a **hybrid** architecture: prototype as a virtual
app inside OpenBuild's nested `CnAppRoot` host, then **graduate** to a standalone
Nextcloud app — its own `appinfo/info.xml`, its own OpenRegister namespace, its own
repository, and its own CI/release pipeline — with **zero** runtime dependency on
OpenBuild.

This document describes how the exporter turns a published virtual `Application` into a
real app and delivers it either as a ZIP download or a GitHub repository push.

## Overview

```
POST /api/applications/{slug}/exports
  → ExportJob created (status: queued)            [ExportsController::submit]
  → RunExportJob enqueued                          [ExportJobService::queue]
       → transition queued → running               [OR TransitionEngine]
       → copy embedded template snapshot            [ExportService::copyTemplate]
       → resolve {{placeholder}} tokens             [PlaceholderResolver]
       → emit <newapp>_register.json + manifest     [ExportService]
       → package deterministic ZIP                  [ExportService::packageZip]
       → (target=github) create repo + push + PR    [GitHubPushService]
       → transition running → succeeded|failed      [OR TransitionEngine]
       → clear PAT credential                        [ExportJobService::clearPat]
GET /api/exports/{uuid}/download
  → stream ZIP (410 after downloadExpiresAt)        [ExportsController::download]
```

The pipeline is **always asynchronous** (Decision 2): the controller returns 202
immediately and the frontend polls the ExportJob via OpenRegister REST every 2 seconds
until a terminal state.

## ZIP delivery

When `target: "zip"`, the exporter writes a single `.zip` under the app-data work area,
sets `downloadUrl` to `/index.php/apps/openbuild/api/exports/{uuid}/download`, and sets
`downloadExpiresAt` to 24 hours after completion. After expiry the download endpoint
returns `410 Gone` and the daily `CleanupExpiredExports` job purges the archive while
preserving the ExportJob audit record.

ZIP entries use a fixed timestamp and sorted ordering so re-exports of the same version
are byte-equivalent (REQ-OBEX-008). The exporter never runs `composer install` or
`npm install`; it copies the snapshot's lockfiles verbatim.

## GitHub delivery

When `target: "github"`, `GitHubPushService` (backed by Nextcloud's `IClientService`
against the GitHub REST + Git Data API) performs:

1. **Repo-exists guard** — a `GET /repos/{org}/{repo}` that fails fast with
   `Repository {org}/{repo} already exists` if the repository is present
   (no destructive push).
2. **Create repo** — `POST /orgs/{org}/repos` with the requested visibility.
3. **Push tree** — one blob per file via `POST /git/blobs`, assembled into a tree
   (`POST /git/trees`), a commit (`POST /git/commits`), and a `bootstrap` branch ref
   (`POST /git/refs`).
4. **Open PR** — `POST /repos/{org}/{repo}/pulls` from `bootstrap` to the default
   branch, titled `chore: bootstrap from OpenBuild`.

### Default-branch heuristic (OQ-2)

`resolveDefaultBranch()` returns `development` when the org name matches the Conduction
ruleset heuristic (contains `conduction`), otherwise `main`. The PR targets that branch.

## PAT handling (Decision 3 — security contract)

- The GitHub PAT is collected once in the Export dialog (`<input type="password">`).
- It is transmitted over the standard authenticated Nextcloud REST channel and stored
  **only** via `ICredentialsManager`, keyed `openbuild.export.<jobUuid>.pat`.
- The PAT is **never** persisted on the ExportJob object, **never** logged, and **never**
  written to the `log` or `errorMessage` fields. `GitHubPushService` additionally scrubs
  any PAT-shaped token out of error messages before they surface.
- The background job fetches the PAT once at the GitHub phase and **deletes** the
  credential record on every terminal state (succeeded **and** failed).

## Scratch storage (OQ-3)

The in-flight tree is staged under the app-data work area keyed by ExportJob UUID
(`ExportService::scratchTreeDir`). Each job has its own scratch directory, so concurrent
exports are isolated by construction (OQ-4). The scratch tree is the source the GitHub
push target reads from.

## What to do next after a GitHub export

1. Review the open `chore: bootstrap from OpenBuild` pull request.
2. Clone the repository locally.
3. Run `composer install` and `npm install` (the exporter ships pinned lockfiles but does
   not vendor dependencies — OQ-5).
4. Run `composer check:strict` and `npm run build` to confirm the graduated app passes the
   standard Conduction quality gates.
5. Merge the bootstrap PR and take over version bumps via the new repo's release pipeline.

## Idempotency

Re-exporting the same Application version with the same `includeSeedData` flag produces a
byte-equivalent archive. The exporter embeds no creation timestamps, random UUIDs, or
instance identity into any committed text file.
