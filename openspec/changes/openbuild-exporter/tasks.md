## 1. Schema + lifecycle (declarative — ADR-031)

- [ ] 1.1 **Declare `ExportJob` schema in `lib/Settings/openbuild_register.json`**
  - spec_ref: REQ-OBEX-001
  - files: `lib/Settings/openbuild_register.json`
  - acceptance_criteria: Schema declares `uuid`, `applicationUuid` (UUID-format,
    required), `applicationVersion` (semver-pattern, required), `target`
    (enum `zip|github`, required), `status` (enum `queued|running|succeeded|failed`,
    default `queued`, required), `githubOrg`, `githubRepo`, `githubVisibility`
    (enum `public|private`), `includeSeedData` (boolean, default false), `downloadUrl`,
    `downloadExpiresAt` (date-time), `errorMessage`, `log` (array of strings).
    Validates against OpenAPI 3.0.0.
  - Implement: declarative — no PHP service class.
  - Test: integration test creates an ExportJob via OR REST; asserts schema validation
    rejects an invalid `target` (`ftp`).

- [ ] 1.2 **Add `x-openregister-lifecycle` to the `ExportJob` schema**
  - spec_ref: REQ-OBEX-001
  - files: `lib/Settings/openbuild_register.json` (NOT a new PHP service)
  - acceptance_criteria: Declares states `queued`, `running`, `succeeded`, `failed`
    and transitions `queued → running`, `running → succeeded`, `running → failed`. No
    terminal re-entry. Each transition emits an OR audit event. No
    `ExportJobLifecycleService.php` or `ExportJobStateMachine.php` file is created.
    Schema carries `x-openregister-lifecycle-exception` annotation pointing at
    design.md Decision 7 documenting the imperative file-generation surface.
  - Implement: declarative schema patch only.
  - Test: integration test attempts `succeeded → running`; asserts a 4xx error.

## 2. Embedded template snapshot

- [ ] 2.1 **Snapshot `nextcloud-app-template/` into `lib/Resources/template/`**
  - spec_ref: REQ-OBEX-003
  - files: `lib/Resources/template/**` (full template tree from
    `apps-extra/nextcloud-app-template/` at OpenBuild's release-cut commit, minus
    `node_modules/`, `vendor/`, `.git/`),
    `lib/Resources/template/.snapshot-meta.json` (source commit SHA + ISO timestamp),
    `lib/Resources/template/.path-manifest.txt` (sorted list of every file path in
    the snapshot).
  - acceptance_criteria: Snapshot contains the full template tree. Placeholder tokens
    (`{{appId}}`, `{{appNamespace}}`, `{{appName}}`, `{{appDescription}}`,
    `{{appVersion}}`, `{{authorName}}`, `{{authorEmail}}`, `{{license}}`) are present
    in every file the exporter will populate.
  - Implement: one-off `cp -r` + `rm -rf` of vendored / generated dirs; commit.
    Do NOT scripted-edit files inside the snapshot.
  - Test: unit test asserts `.path-manifest.txt` matches the actual file list under
    `lib/Resources/template/`.

- [ ] 2.2 **Document the resnapshot procedure in `docs/releasing.md`**
  - spec_ref: REQ-OBEX-003
  - files: `docs/releasing.md`
  - acceptance_criteria: Section "Refreshing the embedded template snapshot" describes
    when to resnapshot (on meaningful upstream template churn) and how (`cp` +
    path-manifest regen + bump OpenBuild minor version + Changelog entry).

## 3. PlaceholderResolver service

- [ ] 3.1 **Implement `lib/Service/PlaceholderResolver.php`**
  - spec_ref: REQ-OBEX-003
  - files: `lib/Service/PlaceholderResolver.php`
  - acceptance_criteria: `PlaceholderResolver::resolve(string $template, array $tokens): string`
    replaces all `{{key}}` tokens with the corresponding values. The resolver raises a
    descriptive exception if a token appears in the template but is absent from
    `$tokens`. Re-running the resolver on an already-resolved string is a no-op.
    SPDX-License-Identifier + SPDX-FileCopyrightText live INSIDE the file's main
    docblock.
  - Implement: pure-function PHP class; standard Conduction docblock + EUPL-1.2 (or
    user-chosen license per Decision 6).
  - Test: PHPUnit on `PlaceholderResolver` covers token replacement, unknown-token
    exception, and idempotency.

## 4. ExportService (code — ADR-031 §Exceptions)

- [ ] 4.1 **Implement `lib/Service/ExportService.php`**
  - spec_ref: REQ-OBEX-003, REQ-OBEX-004, REQ-OBEX-005, REQ-OBEX-008, REQ-OBEX-009
  - files: `lib/Service/ExportService.php`
  - acceptance_criteria: `ExportService::run(ExportJob $job): void` orchestrates:
    load source Application by `applicationUuid` + `applicationVersion`; load companion
    schemas from the `openbuild` namespace as referenced by the manifest; copy
    `lib/Resources/template/` into scratch dir under
    `appdata_<instance>/openbuild/work/<jobUuid>/`; resolve placeholders via
    `PlaceholderResolver` (no `sed`/`awk`); emit `lib/Settings/<newapp>_register.json`
    with companion schemas rewritten into the new namespace (excluding `Application`,
    `BuiltAppRoute`, `ExportJob`); emit `src/manifest.json` with `config.register`
    references rewritten to the new appId; emit `appinfo/info.xml` carrying navigation
    entries derived from the manifest's `menu`. Tier-4 mount in `src/main.js` uses
    `useAppManifest('<newapp>', bundledManifest)` directly; no per-slug fetcher.
  - Implement: PHP service class; standard Conduction docblock.
  - Test: PHPUnit integration test on `ExportService::run` with the seeded `hello-world`
    Application asserts the produced tree matches the path manifest from task 2.1.

- [ ] 4.2 **Verify exported app boots standalone (REQ-OBEX-010)**
  - spec_ref: REQ-OBEX-010
  - files: `tests/Integration/ExporterStandaloneTest.php`
  - acceptance_criteria: Integration test scans the produced tree's `composer.json`,
    `package.json`, and `appinfo/info.xml` and asserts none contains the substring
    `openbuild` (case-insensitive) as a dependency reference. Asserts `src/main.js`
    calls `useAppManifest('<newapp>', bundledManifest)` and does NOT contain an
    `options.fetcher` redirect. Asserts `appinfo/routes.php` contains NO
    `getManifest` route mapping.

## 5. ZIP delivery target

- [ ] 5.1 **Implement ZIP packaging in `ExportService::packageZip`**
  - spec_ref: REQ-OBEX-006, REQ-OBEX-008
  - files: `lib/Service/ExportService.php`
  - acceptance_criteria: Uses PHP's `ZipArchive`; outputs to
    `appdata_<instance>/openbuild/exports/<jobUuid>/export.zip`; sets ExportJob
    `downloadUrl = /index.php/apps/openbuild/api/exports/<jobUuid>/download`,
    `downloadExpiresAt = now() + 24h`. ZIP entries SHALL use a fixed timestamp
    (`2026-01-01T00:00:00Z`, or PHP ZipArchive deterministic mode) to keep re-exports
    byte-equivalent (REQ-OBEX-008).
  - Implement: deterministic ZipArchive flags.
  - Test: PHPUnit runs the export twice on the same version; asserts byte equality (or,
    if PHP's ZipArchive cannot be made fully byte-deterministic, asserts identical
    SHA-256 across all unzipped files).

- [ ] 5.2 **Implement `GET /api/exports/{uuid}/download` endpoint**
  - spec_ref: REQ-OBEX-006
  - files: `lib/Controller/ExportsController.php`, `appinfo/routes.php`
  - acceptance_criteria: `download(string $uuid): StreamResponse` resolves the
    ExportJob, asserts `downloadExpiresAt > now()` (else returns 410 Gone), streams
    the ZIP with `Content-Type: application/zip`. Annotated `#[NoAdminRequired]`.
    SPDX-in-docblock.
  - Implement: ~30 LOC controller method.
  - Test: Newman test covers 200 (within 24h), 410 (after expiry — simulate by setting
    `downloadExpiresAt` to the past via OR REST), 404 (unknown UUID).

- [ ] 5.3 **Implement daily cleanup background job for expired ZIPs**
  - spec_ref: REQ-OBEX-006
  - files: `lib/BackgroundJob/CleanupExpiredExports.php`,
    `appinfo/info.xml` (register the job)
  - acceptance_criteria: Implements `OCP\BackgroundJob\TimedJob` with a 24h interval;
    iterates ExportJobs with `downloadExpiresAt < now()` and deletes the corresponding
    ZIP files from app-data; preserves the ExportJob record itself (only the ZIP is
    purged; the OR audit trail remains). Idempotent.
  - Implement: PHP job class; SPDX-in-docblock.
  - Test: PHPUnit asserts the ZIP file is deleted; asserts the ExportJob record still
    exists post-cleanup.

## 6. GitHub delivery target

- [ ] 6.1 **Add `knplabs/github-api` to `composer.json`**
  - spec_ref: REQ-OBEX-007
  - files: `composer.json`, `composer.lock`
  - acceptance_criteria: Dependency added; lockfile regenerated; `composer audit` clean
    (no CVEs); ADR-014 license overrides updated if knplabs ships under a
    non-allowlisted license.

- [ ] 6.2 **Implement `lib/Service/GitHubPushService.php`**
  - spec_ref: REQ-OBEX-007
  - files: `lib/Service/GitHubPushService.php`
  - acceptance_criteria: Methods: `createRepo($org, $repo, $visibility, $pat): array`,
    `pushTree($org, $repo, $branch, $treeDir, $pat): string` (returns commit SHA),
    `openPullRequest($org, $repo, $fromBranch, $toBranch, $title, $body, $pat): string`
    (returns PR URL), `resolveDefaultBranch($org, $repo, $pat): string` (returns
    `development` if the org has the Conduction ruleset per OQ-2, else `main`). PAT is
    a method-scoped argument; never persisted on the service instance.
  - Implement: PHP service wrapping `Github\Client`; standard Conduction docblock.
  - Test: PHPUnit against a mocked `Github\Client` covers each method. NO live-GitHub
    call in CI.

- [ ] 6.3 **Wire GitHub PAT through `ICredentialsManager`**
  - spec_ref: REQ-OBEX-007 (security checklist in design.md Decision 3)
  - files: `lib/Service/ExportService.php`, `lib/Controller/ExportsController.php`
  - acceptance_criteria: Controller's POST endpoint accepts `githubPat` in the request
    body when `target=github`, immediately stores it via `ICredentialsManager` under
    key `openbuild.export.<jobUuid>.pat`, and removes the PAT from the in-memory
    request payload before any logging / audit emission. Background job fetches the PAT
    once at the GitHub phase, passes it to `GitHubPushService` methods, and deletes the
    credential record on terminal state (succeeded or failed). The ExportJob's `log`
    array SHALL NOT contain the PAT.
  - Test: Newman test posts an export with a known PAT pattern, polls to terminal state,
    then GETs the ExportJob via OR REST and asserts the PAT pattern appears in NO field
    of the returned object (especially `log` and `errorMessage`).

## 7. Background job + controller

- [ ] 7.1 **Implement `lib/BackgroundJob/RunExportJob.php`**
  - spec_ref: REQ-OBEX-009
  - files: `lib/BackgroundJob/RunExportJob.php`,
    `appinfo/info.xml` (`<background-jobs>` registration)
  - acceptance_criteria: Implements `OCP\BackgroundJob\IJob`; picks up `queued`
    ExportJobs (limit 1 per tick to bound runtime), transitions to `running` via OR's
    lifecycle engine, calls `ExportService::run`, transitions to `succeeded` or
    `failed`. NO auto-retry on failure. Failure cause is recorded in `errorMessage` +
    `log` (PAT never included). SPDX-in-docblock.
  - Test: PHPUnit asserts state transitions; asserts NO auto-retry on a forced failure.

- [ ] 7.2 **Implement `POST /api/applications/{slug}/exports` endpoint**
  - spec_ref: REQ-OBEX-002, REQ-OBEX-009
  - files: `lib/Controller/ExportsController.php`, `appinfo/routes.php`
  - acceptance_criteria: `submit(string $slug, array $body): JSONResponse` validates
    `target`, `applicationVersion` (must resolve to a published version per
    openbuild-versioning — else 422), `includeSeedData` (boolean), and GitHub fields
    (when `target=github`); stores PAT via `ICredentialsManager` if needed; creates
    the ExportJob in OR (status `queued`); returns 202 Accepted with `{ uuid }`.
    Responds in <500ms. Annotated `#[NoAdminRequired]`. SPDX-in-docblock.
  - Test: PHPUnit + Newman cover 202 (happy path), 422 (unknown version), 422 (draft
    version), 422 (missing org for `target=github`).

- [ ] 7.3 **Standard CRUD on ExportJob uses OR REST directly (ADR-022)**
  - spec_ref: REQ-OBEX-009
  - files: none (verification step)
  - acceptance_criteria: NO `list` / `get` / `update` / `delete` ExportJob methods
    exist in `ExportsController`. Frontend polls via OR REST directly.
  - Test: code-review check during apply; ADR-022 review gate.

## 8. Verification + security

- [ ] 8.1 **Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)** — all green;
      fix any pre-existing issues in touched files. No `// SPDX-` line comments —
      SPDX tags live inside the docblock.

- [ ] 8.2 **PHPUnit test suite** — `tests/Unit/Service/ExportServiceTest.php`,
      `tests/Unit/Service/GitHubPushServiceTest.php` (mocked GitHub client),
      `tests/Unit/Service/PlaceholderResolverTest.php`,
      `tests/Unit/BackgroundJob/RunExportJobTest.php`,
      `tests/Unit/BackgroundJob/CleanupExpiredExportsTest.php`,
      `tests/Unit/Controller/ExportsControllerTest.php`.

- [ ] 8.3 **Integration test** — `tests/Integration/ExporterEndToEndTest.php` runs an
      export of the seeded `hello-world` Application end-to-end (ZIP target), unzips
      the result, runs `composer check:strict` against the unzipped tree (must be
      green), and asserts the path manifest from task 2.1 matches.

- [ ] 8.4 **CI extension** — add `.github/workflows/exporter-e2e.yml` that runs the
      integration test from 8.3 on every PR. Parallelise with existing Newman +
      Playwright jobs per ADR-008.

- [ ] 8.5 **Security review checklist (design.md Decision 3)** — verify by inspection
      and automated test:
  - PAT never echoed in any API response (Newman test).
  - PAT never written to the ExportJob's `log` / `errorMessage` (Newman test).
  - PAT never written to PHP error logs (manual review of every `error_log` call site).
  - `ICredentialsManager` record deleted on both terminal states (PHPUnit test on
    `RunExportJobTest::testCredentialCleared{Success,Failure}`).
  - Audit-trail entry on PAT use names only the org / repo (PHPUnit test).
  - Token-scope guidance copy is present in the ExportDialog when `target=github` is
    selected (Playwright test).

- [ ] 8.6 **Confirm no state-machine service class exists** — ADR-031 review gate.
      Grep `lib/Service/` and `lib/StateMachine/` for `ExportJobStateMachine`,
      `ExportJobLifecycleService`, or similar; any hit is a fail.

## 9. Frontend

- [ ] 9.1 **Build `src/dialogs/ExportDialog.vue`**
  - spec_ref: REQ-OBEX-002, REQ-OBEX-006, REQ-OBEX-007, REQ-OBEX-009
  - files: `src/dialogs/ExportDialog.vue`
  - acceptance_criteria: Standalone `<NcDialog>` (modal-isolation gate — modal lives
    in `src/dialogs/`, not inline in any parent). Form fields:
    - `NcSelect` version picker (defaults to current published — REQ-OBEX-002);
      `inputLabel` required (nc-input-labels gate).
    - `NcSelect` target (`zip` | `github`); `inputLabel` required.
    - `NcSelect` license (EUPL-1.2 default, MIT, Apache-2.0 — Decision 6); `inputLabel`
      required.
    - `NcCheckbox` includeSeedData.
    - Conditional GitHub fields: org (NcInputField), repo name (NcInputField),
      `NcSelect` visibility (public/private; `inputLabel` required), PAT
      (`<input type="password">`; never displayed back).
    - Token-scope guidance copy visible when `target=github` (i18n key
      `openbuild.export.github.scopeHint`).
    On submit: POST to `/api/applications/{slug}/exports`; close dialog; return the
    ExportJob UUID. Options API; no custom Pinia store over `createObjectStore`.
  - Test: Playwright opens the dialog, fills all fields, submits, asserts the POST body
    has the expected shape (no PAT in the URL, only in the POST body over the Nextcloud
    REST channel).

- [ ] 9.2 **Build `src/views/ExportJobsList.vue`**
  - spec_ref: REQ-OBEX-009
  - files: `src/views/ExportJobsList.vue`, `src/store/exports.js`
  - acceptance_criteria: Lists ExportJobs for the current Application via OR REST
    (`createObjectStore`), polls every 2 s while any job is non-terminal, surfaces the
    ZIP `downloadUrl` (as a download button) or the GitHub PR URL on success, surfaces
    `errorMessage` on failure. Options API; standard `createObjectStore` pattern.
  - Test: Playwright triggers an export, watches the row transition
    `queued → running → succeeded`, clicks the download button, asserts the ZIP
    downloads.

- [ ] 9.3 **Wire the "Export" action into the Application detail toolbar**
  - spec_ref: REQ-OBEX-002
  - files: `src/views/ApplicationDetail.vue` (or its detail-view sibling)
  - acceptance_criteria: An "Export" button in the detail toolbar opens
    `ExportDialog.vue` (lazy-imported per the modal-isolation gate). Button is disabled
    when the Application has no published version (no `productionVersion`).

## 10. Documentation + i18n

- [ ] 10.1 **Add `docs/export-pipeline.md`**
  - spec_ref: design.md OQ-2, OQ-3
  - files: `docs/export-pipeline.md`
  - acceptance_criteria: Describes the ZIP + GitHub flows end-to-end; the embedded
    template snapshot and resnapshot procedure; the PAT-handling contract; OQ-2's
    default-branch heuristic; OQ-3's scratch-dir layout; and the user-facing "what to
    do next" steps after a successful GitHub export (review the PR, run
    `composer install` + `npm install` locally, etc.).

- [ ] 10.2 **i18n keys (ADR-007)** — add English + Dutch translations in
      `l10n/en.json` + `l10n/nl.json`:
      `openbuild.export.title`,
      `openbuild.export.version.label`,
      `openbuild.export.target.label`,
      `openbuild.export.license.label`,
      `openbuild.export.github.org.label`,
      `openbuild.export.github.repo.label`,
      `openbuild.export.github.visibility.label`,
      `openbuild.export.github.pat.label`,
      `openbuild.export.github.scopeHint`,
      `openbuild.export.includeSeedData.label`,
      `openbuild.export.submit`,
      `openbuild.export.cancel`,
      `openbuild.export.status.queued`,
      `openbuild.export.status.running`,
      `openbuild.export.status.succeeded`,
      `openbuild.export.status.failed`,
      `openbuild.export.download.button`,
      `openbuild.export.viewPR.button`,
      `openbuild.export.error.unknownVersion`,
      `openbuild.export.error.draftVersion`,
      `openbuild.export.error.repoExists`,
      `openbuild.export.error.authFailed`.

- [ ] 10.3 **NL Design (ADR-010)** — confirm new dialog uses Nextcloud CSS variables
      only; no hardcoded colours.

- [ ] 10.4 **Update `openspec/app-config.json`** — add `"openbuild-exporter"` to the
      `capabilities` array.

## 11. Hydra mechanical gates (pre-merge)

- [ ] 11.1 Run `/hydra-gates` against the apply PR and confirm all 13 gates green:
      SPDX, forbidden-patterns, stub-scan, composer-audit, route-auth, orphan-auth,
      no-admin-idor, unsafe-auth-resolver, semantic-auth, initial-state, admin-router,
      nc-input-labels, modal-isolation.
      Specifically verify:
  - `hydra-gate-modal-isolation`: `ExportDialog.vue` is in `src/dialogs/`, not inline.
  - `hydra-gate-nc-input-labels`: all `NcSelect` elements in `ExportDialog.vue` carry
    `inputLabel`.
  - `hydra-gate-route-auth`: both new controller methods carry `#[NoAdminRequired]`.
  - `hydra-gate-spdx`: all new PHP files carry SPDX tags inside the docblock.
  - `hydra-gate-forbidden-patterns`: no `var_dump`, `die`, `error_log`, `print_r`,
    `dd`, or `dump` calls in any new file.
