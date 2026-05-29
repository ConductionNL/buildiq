---
kind: code
depends_on: [bootstrap-openbuild, openbuild-versioning]
chain:
  - bootstrap-openbuild
  - openbuild-versioning
  - openbuild-export-to-real-app   # THIS spec (#9 of 9)
---

## Why

OpenBuild's spec #1 proposal committed to a **hybrid** model: virtual apps now,
exportable to real Nextcloud apps later. Citizen developers prototype inside
OpenBuild's nested `CnAppRoot` host, but as a built app accumulates real users,
operational ownership, or a need to ship offline / on a different stack, it
must **graduate** to a standalone Nextcloud app — its own
`appinfo/info.xml`, its own namespace, its own GitHub repo, its own CI / release
pipeline — without depending on OpenBuild at runtime.

This spec ships the **graduation path**. Given a published `Application`
record + its companion schemas + sample data, OpenBuild generates a complete
nextcloud-app-template-shaped tree on disk and either streams it as a ZIP to
the user's browser or pushes it to a new GitHub repo under an org of the
user's choice. The exported app boots Tier-4 (per ADR-024): one bundled
`src/manifest.json`, one `<app>_register.json` schema bundle, no per-slug
endpoint workaround (Decision 4 of bootstrap-openbuild collapses because the
exported app has exactly one manifest), no nested mount (Decision 5
collapses because the exported app **is** the top-level app).

The result closes the loop on the foundational commitment of the 9-spec chain.

## What Changes

- **NEW** `ExportJob` schema in `lib/Settings/openbuild_register.json`:
  `{ uuid, applicationUuid, applicationVersion, target (zip|github), status
  (queued|running|succeeded|failed), githubOrg, githubRepo, githubVisibility,
  includeSeedData, downloadUrl, downloadExpiresAt, errorMessage, log }` with
  `x-openregister-lifecycle` declaring the
  `queued → running → succeeded|failed` state machine (declarative per
  ADR-031 — **no** `ExportJobStateMachine` PHP class).
- **NEW** PHP exporter service `lib/Service/ExportService.php` (this is
  unavoidably code per ADR-031 §Exceptions — file generation, git ops, GitHub
  API calls are imperative by nature). The service is the single PHP surface
  that produces the on-disk tree from an `Application` + schema bundle.
- **NEW** PHP background job `lib/BackgroundJob/RunExportJob.php`
  (implements `OCP\BackgroundJob\IJob`) — async pipeline that walks an
  ExportJob from `queued → running → succeeded|failed`.
- **NEW** PHP controller `lib/Controller/ExportsController.php` with two
  thin endpoints:
  - `POST /api/applications/{slug}/exports` — accepts target + GH org +
    visibility + version + includeSeedData; creates the ExportJob, schedules
    the background job, returns 202 with the job UUID.
  - `GET /api/exports/{uuid}/download` — streams the produced ZIP from
    Nextcloud's app-data area; 410 after `downloadExpiresAt`.
  (Standard CRUD on ExportJob — list / get for polling — uses OR REST per
  ADR-022; no per-controller wrappers for those.)
- **NEW** **embedded template snapshot** under
  `lib/Resources/template/` — a check-in copy of the
  `nextcloud-app-template/` baseline at OpenBuild's build time, so exports
  are reproducible across upstream template churn (Decision 1 in `design.md`).
- **NEW** GitHub integration via Composer-pulled `knplabs/github-api` (Octokit
  is a Node lib; OpenBuild's exporter runs in PHP). Auth via a user-supplied
  PAT stored through Nextcloud's `ICredentialsManager` (Decision 3).
- **NEW** Frontend "Export" action wired into `src/views/ApplicationEditor.vue`
  (or its detail-view sibling) that opens an `ExportDialog.vue`:
  - Pick version (defaults to current published)
  - Pick target — ZIP or GitHub
  - For GitHub: org, repo name, visibility (public|private), PAT (one-time
    paste; never echoed back, never persisted in plain text)
  - Toggle "include seed data" (the sample objects from the source
    Application's namespace)
- **NEW** Frontend `ExportJobsList.vue` polling job status via OR REST,
  surfacing the ZIP download link on success or the GitHub repo URL +
  placeholder PR URL.
- **NEW** Placeholder PR on the GitHub target — when the GitHub target
  finishes, OpenBuild pushes the initial scaffold to a `bootstrap` branch
  and opens a PR against `development` (or the repo's default branch).

### Capabilities

#### New Capabilities

- `openbuild-exporter`: The export pipeline that turns a virtual
  Application into a real Nextcloud app on disk and either downloads it as
  a ZIP or pushes it to a new GitHub repo. Owns the ExportJob schema, the
  exporter service, the background job, the controller endpoints, and the
  frontend dialog + jobs list. Honours ADR-024 (the exported app is a
  Tier-4 manifest consumer), ADR-022 (its companion schemas live in OR
  under the **new** app's namespace, not OpenBuild's), ADR-031 (ExportJob
  lifecycle is declarative; only the file-generation pipeline is code).

#### Modified Capabilities

None. This spec adds a new capability; it does not change the
requirements of `openbuild-application-register`,
`openbuild-runtime`, or any earlier spec in the chain.

## Impact

- **New code** — `lib/Controller/ExportsController.php`,
  `lib/Service/ExportService.php`,
  `lib/BackgroundJob/RunExportJob.php`,
  `lib/Resources/template/**` (template snapshot, ~200 files copied from
  the nextcloud-app-template baseline at OpenBuild's build time),
  `src/views/ExportDialog.vue`, `src/views/ExportJobsList.vue`,
  `src/store/exports.js`,
  `appinfo/routes.php` (two new routes), `appinfo/info.xml`
  (`<background-jobs>` registration).
- **Schema patch** — `lib/Settings/openbuild_register.json` adds the
  `ExportJob` schema + its `x-openregister-lifecycle` declaration.
- **External dependency** — `knplabs/github-api` (Composer), pulled at
  install time. Storage of user GitHub PATs uses Nextcloud's
  `ICredentialsManager` (built-in; no new dependency).
- **OpenRegister** — uses OR's existing REST + lifecycle engine; no
  changes to OR required for this spec.
- **Exported app** — when installed in Nextcloud, runs entirely
  standalone with no OpenBuild dependency. Its companion schemas live in
  the exported app's **own** register namespace (`<newapp>`), not in
  `openbuild`. The Tier-4 mount uses the bundled `src/manifest.json`
  directly via `useAppManifest(appId, bundledManifest)` — no per-slug
  endpoint workaround.
- **No breaking changes** — this is purely additive. Existing virtual
  apps continue to render through the bootstrap-openbuild host.
- **Foundational ADRs honoured** — ADR-022 (the exporter ships a real OR
  register for the new app, not app-local tables), ADR-024 (the exported
  app is a canonical Tier-4 manifest consumer), ADR-031 (ExportJob
  lifecycle is declarative; the exporter service is the documented code
  exception), ADR-032 (`kind: code`; the exporter is unavoidably
  imperative and the largest single spec in the chain).
