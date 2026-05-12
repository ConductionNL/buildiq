## 1. Schema + lifecycle (declarative — ADR-031)

- [ ] 1.1 **Declare `ExportJob` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-OBEX-001
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `uuid`, `applicationUuid` (UUID-format, required), `applicationVersion` (semver pattern, required), `target` (enum `zip|github`, required), `status` (enum `queued|running|succeeded|failed`, default `queued`, required), `githubOrg`, `githubRepo`, `githubVisibility` (enum `public|private`), `includeSeedData` (boolean, default false), `downloadUrl`, `downloadExpiresAt` (date-time), `errorMessage`, `log` (array of strings). Validates against OpenAPI 3.0.0.
  - Implement: declarative — no PHP service class.
  - Test: integration test creates an ExportJob via OR REST, asserts schema validation rejects an invalid `target`.

- [ ] 1.2 **Add `x-openregister-lifecycle` to the `ExportJob` schema**
  - spec_ref: REQ-OBEX-001
  - files: `lib/Settings/openbuilt_register.json` (NOT a new PHP service)
  - acceptance_criteria: Declares states `queued`, `running`, `succeeded`, `failed` and transitions `queued → running`, `running → succeeded`, `running → failed`. No terminal re-entry. Each transition emits an OR audit event. No `ExportJobLifecycleService.php` / `ExportJobStateMachine.php` file is created. Schema carries `x-openregister-lifecycle-exception` annotation pointing at design.md Decision 7 documenting the imperative file-generation surface.
  - Implement: declarative schema patch only.
  - Test: integration test attempts `succeeded → running`, asserts a 4xx error.

## 2. Embedded template snapshot

- [ ] 2.1 **Snapshot `nextcloud-app-template/` into `lib/Resources/template/`**
  - spec_ref: REQ-OBEX-003
  - files: `lib/Resources/template/**` (every file from the upstream `apps-extra/nextcloud-app-template/` working tree at OpenBuilt's release-cut commit), `lib/Resources/template/.snapshot-meta.json` (records the source commit SHA + ISO timestamp of the snapshot for reproducibility).
  - acceptance_criteria: Snapshot contains the full template tree minus `node_modules/`, `vendor/`, `.git/`. Placeholder tokens (`{{appId}}`, `{{appNamespace}}`, `{{appName}}`, `{{appDescription}}`, `{{appVersion}}`, `{{authorName}}`, `{{authorEmail}}`, `{{license}}`) are present in every file the exporter will populate. The snapshot's path manifest is dumped to `lib/Resources/template/.path-manifest.txt` to support the byte-equivalence test below.
  - Implement: one-off `cp -r` then `rm -rf` of vendored / generated dirs; commit. Do NOT scripted-edit files inside the snapshot (memory rule).
  - Test: a unit test asserts `.path-manifest.txt` matches the actual file list under `lib/Resources/template/`.

- [ ] 2.2 **Document the resnapshot procedure in `docs/releasing.md`**
  - spec_ref: REQ-OBEX-003
  - files: `docs/releasing.md`
  - acceptance_criteria: Section "Refreshing the embedded template snapshot" describes when to resnapshot (on meaningful upstream template churn) and how (cp + path-manifest regen + bump OpenBuilt minor version + Changelog entry).

## 3. Exporter service (code — ADR-031 §Exceptions)

- [ ] 3.1 **Implement `lib/Service/ExportService.php`**
  - spec_ref: REQ-OBEX-003, REQ-OBEX-004, REQ-OBEX-005, REQ-OBEX-006, REQ-OBEX-008, REQ-OBEX-009
  - files: `lib/Service/ExportService.php`, `lib/Service/PlaceholderResolver.php` (split out for testability)
  - acceptance_criteria: `ExportService::run(ExportJob $job): void` orchestrates: load source Application by `applicationUuid` + `applicationVersion`; load companion schemas from the `openbuilt` namespace as referenced by the manifest; copy `lib/Resources/template/` into a scratch dir under `appdata_<instance>/openbuilt/work/<jobUuid>/`; resolve placeholders via `PlaceholderResolver` (no scripted sed/awk — read each text file via `\OCP\Files`, resolve tokens, write back); emit `lib/Settings/<newapp>_register.json` with companion schemas rewritten into the new namespace; emit `src/manifest.json` with `config.register` references rewritten; emit `appinfo/info.xml` carrying navigation entries derived from the manifest's `menu`. SPDX-License-Identifier + SPDX-FileCopyrightText live INSIDE the file's main docblock (memory rule). Tier-4 mount in `src/main.js` uses `useAppManifest('<newapp>', bundledManifest)` directly; no per-slug fetcher.
  - Implement: PHP service class; standard Conduction docblock + EUPL-1.2 (or user-chosen license — Decision 6).
  - Test: PHPUnit on `PlaceholderResolver` covers token replacement + idempotency (re-running resolution on an already-resolved file is a no-op). Integration test on `ExportService::run` with the seeded `hello-world` Application asserts the produced tree matches the path manifest from task 2.1.

- [ ] 3.2 **Verify exported app boots standalone (no OpenBuilt dependency)**
  - spec_ref: REQ-OBEX-010
  - files: `tests/Integration/ExporterStandaloneTest.php`
  - acceptance_criteria: Integration test scans the produced tree's `composer.json`, `package.json`, and `appinfo/info.xml` and asserts none contains the substring `openbuilt` (case-insensitive). Asserts `src/main.js` calls `useAppManifest('<newapp>', bundledManifest)` and does NOT contain an `options.fetcher` redirect. Asserts `appinfo/routes.php` contains NO `getManifest` mapping.

## 4. ZIP delivery target

- [ ] 4.1 **Implement ZIP packaging in `ExportService::packageZip`**
  - spec_ref: REQ-OBEX-006
  - files: `lib/Service/ExportService.php`
  - acceptance_criteria: Uses PHP's `ZipArchive`; outputs to `appdata_<instance>/openbuilt/exports/<jobUuid>/export.zip`; sets ExportJob `downloadUrl = /index.php/apps/openbuilt/api/exports/<jobUuid>/download`, `downloadExpiresAt = now() + 24h`. ZIP entries SHALL use a fixed timestamp (`2026-01-01T00:00:00Z`, or the upstream PHP-ZipArchive deterministic mode) to keep re-exports byte-equivalent (REQ-OBEX-008).
  - Implement: deterministic ZipArchive flags.
  - Test: PHPUnit runs the export twice on the same version, asserts byte equality (or, if PHP's ZipArchive can't be made fully byte-deterministic, asserts identical SHA-256 across all unzipped files — see REQ-OBEX-008 scenario).

- [ ] 4.2 **Implement `GET /api/exports/{uuid}/download` endpoint**
  - spec_ref: REQ-OBEX-006
  - files: `lib/Controller/ExportsController.php`, `appinfo/routes.php`
  - acceptance_criteria: `download(string $uuid): StreamResponse` resolves ExportJob, asserts `downloadExpiresAt > now()` (else returns 410 Gone), streams the ZIP with `Content-Type: application/zip`. `#[NoAdminRequired]`. SPDX-in-docblock.
  - Implement: ~30 LOC controller method.
  - Test: Newman test covers 200 (within 24h), 410 (after expiry — simulate by setting `downloadExpiresAt` to the past via OR REST), 404 (unknown UUID).

- [ ] 4.3 **Implement daily cleanup background job for expired ZIPs**
  - spec_ref: REQ-OBEX-006
  - files: `lib/BackgroundJob/CleanupExpiredExports.php`, `appinfo/info.xml` (register the job)
  - acceptance_criteria: Implements `OCP\BackgroundJob\TimedJob` with a 24h interval; iterates ExportJobs with `downloadExpiresAt < now()` and deletes the corresponding files from app-data; preserves the ExportJob record itself (only the ZIP is purged; the audit trail remains). Idempotent.
  - Implement: PHP job class.
  - Test: PHPUnit asserts the file is deleted; asserts the ExportJob record still exists post-cleanup.

## 5. GitHub delivery target

- [ ] 5.1 **Add `knplabs/github-api` to `composer.json`**
  - spec_ref: REQ-OBEX-007
  - files: `composer.json`, `composer.lock`
  - acceptance_criteria: Dep added, lockfile regenerated, `composer audit` clean (no CVEs); ADR-018 license overrides updated if knplabs ships under a non-allowlisted license.

- [ ] 5.2 **Implement `lib/Service/GitHubPushService.php`**
  - spec_ref: REQ-OBEX-007
  - files: `lib/Service/GitHubPushService.php`
  - acceptance_criteria: Methods: `createRepo($org, $repo, $visibility, $pat): array`, `pushTree($org, $repo, $branch, $treeDir, $pat): string` (returns commit SHA), `openPullRequest($org, $repo, $fromBranch, $toBranch, $title, $body, $pat): string` (returns PR URL), `resolveDefaultBranch($org, $repo, $pat): string` (returns `development` if the org has the Conduction ruleset, else `main` — OQ-2). PAT is passed as method-scoped argument; never persisted on the service instance.
  - Implement: PHP service wrapping `Github\Client`; standard Conduction docblock.
  - Test: PHPUnit against a mocked `Github\Client` covers each method. NO live-GitHub call in CI.

- [ ] 5.3 **Wire GitHub PAT through `ICredentialsManager`**
  - spec_ref: REQ-OBEX-007 (security checklist in design.md Decision 3)
  - files: `lib/Service/ExportService.php`, `lib/Controller/ExportsController.php`
  - acceptance_criteria: Controller's POST endpoint accepts `githubPat` in the request body when `target=github`, immediately stores it via `ICredentialsManager` under key `openbuilt.export.<jobUuid>.pat`, and removes the PAT from the in-memory request payload before any logging / audit emission. Background job fetches the PAT once at the GitHub phase, passes it to `GitHubPushService` methods, and deletes the credential record on terminal state (succeeded or failed). The ExportJob's `log` array SHALL NOT contain the PAT (assert in a Newman test below).
  - Implement: standard `ICredentialsManager` calls.
  - Test: Newman test posts an export with a known PAT pattern, polls to terminal state, then GETs the ExportJob via OR REST and asserts the PAT pattern appears in NO field of the returned object (especially `log` and `errorMessage`).

## 6. Background job + controller

- [ ] 6.1 **Implement `lib/BackgroundJob/RunExportJob.php`**
  - spec_ref: REQ-OBEX-009
  - files: `lib/BackgroundJob/RunExportJob.php`, `appinfo/info.xml` (`<background-jobs>` registration)
  - acceptance_criteria: Implements `OCP\BackgroundJob\IJob`; picks up `queued` ExportJobs (limit 1 per tick to bound runtime), transitions to `running` via OR's lifecycle engine, calls `ExportService::run`, transitions to `succeeded` or `failed`. NO auto-retry on failure (memory rule: crashes → needs-input). Failure cause is recorded in `errorMessage` + `log` (no PAT).
  - Implement: PHP job class; SPDX-in-docblock.
  - Test: PHPUnit asserts state transitions; asserts NO auto-retry on a forced failure.

- [ ] 6.2 **Implement `POST /api/applications/{slug}/exports` endpoint**
  - spec_ref: REQ-OBEX-002, REQ-OBEX-009
  - files: `lib/Controller/ExportsController.php`, `appinfo/routes.php`
  - acceptance_criteria: `submit(string $slug, array $body): JSONResponse` validates `target`, `applicationVersion` (must resolve to a published version per openbuilt-versioning — else 422), `includeSeedData` (boolean), GitHub fields (when `target=github`), stores PAT via `ICredentialsManager` if needed, creates the ExportJob in OR (status `queued`), returns 202 Accepted with `{ uuid }`. Responds in <500ms. `#[NoAdminRequired]`. SPDX-in-docblock.
  - Implement: ~50 LOC controller method.
  - Test: PHPUnit + Newman cover 202 (happy path), 422 (unknown version), 422 (draft version), 422 (missing org for `target=github`).

- [ ] 6.3 **Standard CRUD on ExportJob uses OR REST directly (ADR-022)**
  - spec_ref: REQ-OBEX-009
  - files: none (verification step)
  - acceptance_criteria: NO `list` / `get` / `update` / `delete` ExportJob methods exist in `ExportsController`. Frontend polls via OR REST directly.
  - Test: code review check during apply; ADR-022 review gate.

## 7. Verification + security

- [ ] 7.1 **Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)** — all green; fix any pre-existing issues in touched files (memory rule).

- [ ] 7.2 **PHPUnit** — `tests/Unit/Service/ExportServiceTest.php`, `tests/Unit/Service/GitHubPushServiceTest.php` (mocked GitHub client), `tests/Unit/Service/PlaceholderResolverTest.php`, `tests/Unit/BackgroundJob/RunExportJobTest.php`, `tests/Unit/Controller/ExportsControllerTest.php`.

- [ ] 7.3 **Integration test** — `tests/Integration/ExporterEndToEndTest.php` runs an export of the seeded `hello-world` Application end-to-end (ZIP target), unzips the result, runs `composer check:strict` against the unzipped tree (must be green), and asserts the path manifest from task 2.1 matches.

- [ ] 7.4 **CI extension** — add a `.github/workflows/exporter-e2e.yml` job that runs the integration test from 7.3 on every PR. Parallelize with the existing Newman + Playwright jobs per ADR-008.

- [ ] 7.5 **Security review checklist (design.md Decision 3)** — verify by inspection + automated test:
  - PAT never echoed in any API response (Newman test).
  - PAT never written to the ExportJob's `log` / `errorMessage` (Newman test).
  - PAT never written to PHP error logs (manual review of every `error_log` call site).
  - `ICredentialsManager` record deleted on both terminal states (PHPUnit test on `RunExportJobTest::testCredentialCleared{Success,Failure}`).
  - Audit-trail entry on PAT use names only the org / repo (PHPUnit test).
  - Token scope guidance copy is present in the ExportDialog (Playwright test).

- [ ] 7.6 **Confirm no state-machine service class exists** — ADR-031 review gate. Grep `lib/Service/` and `lib/StateMachine/` for `ExportJobStateMachine`, `ExportJobLifecycleService`, or similar; any hit is a fail.

## 8. Frontend

- [ ] 8.1 **Build `src/views/ExportDialog.vue`**
  - spec_ref: REQ-OBEX-002, REQ-OBEX-006, REQ-OBEX-007, REQ-OBEX-009
  - files: `src/views/ExportDialog.vue`, `src/dialogs/` (per modal-isolation gate — modal lives in its own SFC)
  - acceptance_criteria: NcDialog wrapping the form: NcSelect (version, defaults to current published — REQ-OBEX-002), NcSelect (target = zip|github), NcSelect (license, defaults to EUPL-1.2 — Decision 6), NcCheckbox (includeSeedData), conditional fields for GitHub (org, repo, visibility, PAT — `<input type="password">`, never displayed back). Every NcSelect carries `inputLabel` (nc-input-labels gate). On submit, POSTs to `/api/applications/{slug}/exports`, then closes and returns the ExportJob UUID. Token scope guidance copy is visible when `target=github` is selected (i18n key `openbuilt.export.github.scopeHint`).
  - Implement: Options API; no custom Pinia store layered over `useObjectStore` (memory rule: use `createObjectStore`).
  - Test: Playwright opens the dialog, fills it, submits, asserts the network POST went through with the expected body (no PAT in the URL, only in the POST body over TLS-internal Nextcloud channel).

- [ ] 8.2 **Build `src/views/ExportJobsList.vue`**
  - spec_ref: REQ-OBEX-009
  - files: `src/views/ExportJobsList.vue`, `src/store/exports.js`
  - acceptance_criteria: Lists ExportJobs for the current Application via OR REST (`createObjectStore`), polls every 2s while any job is non-terminal, surfaces the ZIP `downloadUrl` (as a download button) or the GitHub PR URL on success. Surfaces `errorMessage` on failure.
  - Implement: Options API; standard `createObjectStore` pattern.
  - Test: Playwright triggers an export, watches the row transition `queued → running → succeeded`, clicks the download button, asserts the ZIP downloads.

- [ ] 8.3 **Wire the "Export" action into the Application detail view**
  - spec_ref: REQ-OBEX-002
  - files: `src/views/ApplicationDetail.vue` (or its sibling, depending on bootstrap-openbuilt's final layout)
  - acceptance_criteria: An "Export" button in the detail toolbar opens `ExportDialog.vue` (lazy-imported per the modal-isolation gate). Listed alongside the existing edit / publish actions; respects the Application's lifecycle state — disabled when `status != published`.

## 9. Documentation + i18n

- [ ] 9.1 **Add `docs/export-pipeline.md`**
  - spec_ref: design.md OQ-2, OQ-3
  - files: `docs/export-pipeline.md`
  - acceptance_criteria: Describes the ZIP + GitHub flows end-to-end, the embedded template snapshot, the PAT-handling contract, OQ-2's default-branch heuristic, OQ-3's scratch-dir layout, and the user-facing "what to do next" steps after a successful GitHub export (review the PR, run `composer install` + `npm install` locally, etc.).

- [ ] 9.2 **i18n keys (ADR-005, ADR-007)** — add English + Dutch translations for every new dialog string in `l10n/en.json` + `l10n/nl.json`: `openbuilt.export.title`, `openbuilt.export.version.label`, `openbuilt.export.target.label`, `openbuilt.export.license.label`, `openbuilt.export.github.org.label`, `openbuilt.export.github.repo.label`, `openbuilt.export.github.visibility.label`, `openbuilt.export.github.pat.label`, `openbuilt.export.github.scopeHint`, `openbuilt.export.includeSeedData.label`, `openbuilt.export.submit`, `openbuilt.export.cancel`, `openbuilt.export.status.queued|running|succeeded|failed`, `openbuilt.export.download.button`, `openbuilt.export.viewPR.button`, `openbuilt.export.error.unknownVersion`, `openbuilt.export.error.draftVersion`, `openbuilt.export.error.repoExists`, `openbuilt.export.error.authFailed`.

- [ ] 9.3 **NL Design (ADR-010)** — confirm new dialog uses Nextcloud CSS variables only; no hardcoded colours.

- [ ] 9.4 **Update `openspec/app-config.json`** to list `openbuilt-exporter` under capabilities.

## 10. Hydra mechanical gates (pre-merge)

- [ ] 10.1 Run `/hydra-gates` against the apply PR and confirm all 13 gates green (SPDX, forbidden-patterns, stub-scan, composer-audit, route-auth, orphan-auth, no-admin-idor, unsafe-auth-resolver, semantic-auth, initial-state, admin-router, nc-input-labels, modal-isolation).
