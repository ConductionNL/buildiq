---
kind: code
depends_on: ["bootstrap-openbuilt", "openbuilt-versioning"]
chain:
  - bootstrap-openbuilt
  - openbuilt-versioning
  - openbuilt-exporter   # THIS spec — graduation path
---

## Why

OpenBuilt's founding commitment (spec #1, `bootstrap-openbuilt`) promised a **hybrid**
architecture: virtual apps first, exportable to real Nextcloud apps later. Specs across
the chain fleshed out the runtime, versioning model, RBAC, schema and page editors, and
templates marketplace. This change ships the "exportable later" half and closes the loop
on that hybrid commitment.

Citizen developers prototype inside OpenBuilt's nested `CnAppRoot` host. As a built app
accumulates real users, operational ownership, or a need to ship offline or on a
different stack, it must **graduate** to a standalone Nextcloud app — its own
`appinfo/info.xml`, its own register namespace, its own GitHub repo, its own CI /
release pipeline — without depending on OpenBuilt at runtime.

This spec ships that graduation path. Given a **published** `Application` record, its
companion schemas, and (optionally) its sample data, OpenBuilt generates a complete
`nextcloud-app-template`-shaped tree and either streams it as a ZIP to the user's
browser or pushes it to a new GitHub repo under an org of the user's choice. The
exported app boots Tier-4 (ADR-024): one bundled `src/manifest.json`, one
`<app>_register.json` schema bundle under the new app's own OR namespace (ADR-022),
no per-slug endpoint workaround (Decision 4 of bootstrap-openbuilt collapses because
the exported app owns exactly one manifest), no nested `<CnAppRoot>` mount.

The result closes the loop on the 9-spec chain's foundational commitment.

## What Changes

- **NEW** `ExportJob` schema in `lib/Settings/openbuilt_register.json` declaring
  `{ uuid, applicationUuid, applicationVersion, target (zip|github), status
  (queued|running|succeeded|failed), githubOrg, githubRepo, githubVisibility,
  includeSeedData, downloadUrl, downloadExpiresAt, errorMessage, log }` with
  `x-openregister-lifecycle` declaring the
  `queued → running → succeeded|failed` state machine (declarative per ADR-031 —
  **no** `ExportJobStateMachine` PHP class).
- **NEW** PHP exporter service `lib/Service/ExportService.php` — the single PHP
  surface that produces the on-disk tree from an `Application` + schema bundle
  (unavoidably code per ADR-031 §Exceptions — file generation, git ops, GitHub
  API calls are imperative by nature).
- **NEW** PHP placeholder resolver `lib/Service/PlaceholderResolver.php` — split
  out from `ExportService` for testability; resolves `{{appId}}` / `{{appName}}` /
  `{{appNamespace}}` / `{{appDescription}}` / `{{appVersion}}` / `{{authorName}}` /
  `{{authorEmail}}` / `{{license}}` tokens in text files.
- **NEW** PHP background job `lib/BackgroundJob/RunExportJob.php` (implements
  `OCP\BackgroundJob\IJob`) — async pipeline that walks an ExportJob from
  `queued → running → succeeded|failed`.
- **NEW** PHP cleanup job `lib/BackgroundJob/CleanupExpiredExports.php`
  (`OCP\BackgroundJob\TimedJob`, 24h interval) — purges expired ZIP archives from
  app-data while preserving the ExportJob audit records.
- **NEW** PHP controller `lib/Controller/ExportsController.php` with two thin endpoints:
  - `POST /api/applications/{slug}/exports` — accepts target + GitHub fields +
    version + includeSeedData; creates the ExportJob in `queued` state; returns 202
    with the job UUID.
  - `GET /api/exports/{uuid}/download` — streams the produced ZIP from Nextcloud's
    app-data area; returns 410 after `downloadExpiresAt`.
  Standard CRUD on ExportJob — list / get for frontend polling — uses OR REST
  (ADR-022; no per-controller wrappers for those).
- **NEW** PHP GitHub integration service `lib/Service/GitHubPushService.php` wrapping
  `knplabs/github-api` (Composer). Auth via a user-supplied PAT stored through
  Nextcloud's `ICredentialsManager` keyed by ExportJob UUID; credential deleted on
  terminal state.
- **NEW** **embedded template snapshot** under `lib/Resources/template/` — a checked-in
  copy of the `nextcloud-app-template/` baseline at OpenBuilt's build time, so exports
  are reproducible across upstream template churn.
- **NEW** Frontend Export dialog `src/dialogs/ExportDialog.vue` (standalone `<NcDialog>`
  per ADR-004 modal-isolation rule):
  - Version picker (defaults to current published — REQ-OBEX-002)
  - Target selector: ZIP or GitHub
  - License picker: EUPL-1.2 (default) | MIT | Apache-2.0
  - Toggle: include seed data
  - GitHub-conditional fields: org, repo name, visibility (public/private), PAT
    (`<input type="password">`, never echoed back, never persisted in plaintext)
  - Token-scope hint copy surfaced when `target=github`
- **NEW** Frontend jobs list `src/views/ExportJobsList.vue` — polls ExportJobs via OR
  REST every 2 s while non-terminal; surfaces ZIP download button or GitHub PR URL on
  success; surfaces `errorMessage` on failure.
- **NEW** "Export" action wired into the Application detail toolbar — opens
  `ExportDialog.vue` (lazy-imported); disabled when the Application has no published
  version.

### Capabilities

#### New Capabilities

- `openbuilt-exporter`: The export pipeline that turns a published virtual Application
  into a real standalone Nextcloud app and delivers it as a ZIP download or a GitHub
  repo push + placeholder PR. Owns the `ExportJob` schema (declarative lifecycle per
  ADR-031), `ExportService` (imperative tree generation, the documented ADR-031
  exception), `PlaceholderResolver`, `GitHubPushService`, `RunExportJob`,
  `CleanupExpiredExports`, `ExportsController`, the embedded template snapshot under
  `lib/Resources/template/`, and the frontend `ExportDialog.vue` +
  `ExportJobsList.vue`. Honours ADR-022 (companion schemas emitted into the new app's
  own OR namespace), ADR-024 (exported app is a canonical Tier-4 manifest consumer),
  ADR-031 (ExportJob lifecycle declarative; file-generation pipeline is the documented
  code exception).

#### Modified Capabilities

None. This change is purely additive. The `openbuilt-application-register` and
`openbuilt-runtime` capabilities are consumed but not modified.

## Impact

- **New code**:
  `lib/Controller/ExportsController.php`,
  `lib/Service/ExportService.php`,
  `lib/Service/PlaceholderResolver.php`,
  `lib/Service/GitHubPushService.php`,
  `lib/BackgroundJob/RunExportJob.php`,
  `lib/BackgroundJob/CleanupExpiredExports.php`,
  `lib/Resources/template/**` (~200 files snapshotted from `nextcloud-app-template`
  at OpenBuilt's build time),
  `src/dialogs/ExportDialog.vue`,
  `src/views/ExportJobsList.vue`,
  `src/store/exports.js`,
  `appinfo/routes.php` (two new routes),
  `appinfo/info.xml` (`<background-jobs>` registration for both background jobs).
- **Schema patch** — `lib/Settings/openbuilt_register.json` adds the `ExportJob`
  schema with `x-openregister-lifecycle`.
- **External dependency** — `knplabs/github-api` via Composer. GitHub PAT storage
  uses Nextcloud's built-in `ICredentialsManager` — no new dependency.
- **OpenRegister** — uses OR's existing REST + lifecycle engine; no OR changes required.
- **Exported app** — when installed in Nextcloud, runs entirely standalone with no
  OpenBuilt dependency. Companion schemas live in the exported app's own register
  namespace (`<newapp>`). Tier-4 mount uses the bundled `src/manifest.json` directly
  via `useAppManifest(appId, bundledManifest)` — no per-slug endpoint workaround.
- **No breaking changes** — purely additive. Existing virtual apps continue to render
  through the bootstrap-openbuilt host unaffected.
- **Foundational ADRs honoured** — ADR-022 (new app gets its own OR register),
  ADR-024 (exported app is a canonical Tier-4 manifest consumer), ADR-031 (ExportJob
  lifecycle declarative; exporter service is the documented code exception), ADR-032
  (`kind: code`; this is the largest single spec in the chain and unavoidably
  imperative).
