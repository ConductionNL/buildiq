# Implementation Tasks: Environments & Deployment Pipeline

## 1. Schemas + Data Model (declarative — ADR-031)

- [ ] 1.1 **Declare seven schemas in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-001, REQ-002, REQ-003, REQ-004, REQ-005, REQ-006, REQ-008
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: All seven schemas are declared with OpenAPI 3.0.0 JSON-schema syntax:
    - `Environment`: uuid, application (relation), appName, deploymentOrder (1-4), status (enum: actief/onderhoud/uit), dataIsolationMode (eigen_registers), configOverrides (object), featureFlagsOverrides (object), url.
    - `DeploymentArtifact`: uuid, application (relation), version (semver pattern), manifestHash (sha256), schemaHash (sha256), buildTimestamp (date-time), buildTrigger (enum: handmatig/auto/CI), changesetSummary (string).
    - `Deployment`: uuid, artifact (relation), environment (relation), status (enum: queued/running/success/failed/rolled_back), gestart_op (date-time), voltooid_op (date-time), gestart_door (relation to user), vorige_deployment (relation, optional).
    - `PromoteRequest`: uuid, van_env (relation), naar_env (relation), artifact (relation), motivatie (text), approver (relation, optional), status (enum: verzoek/goedgekeurd/uitgevoerd/afgekeurd), pre_promote_tests (array of objects with suite/status/errorLog).
    - `MigrationPlan`: uuid, deployment (relation), schemaDiff (object with added/changed/deleted), dataMigrationSteps (array), dry_run_resultaat (object with status/errorMessage).
    - `RollbackPoint`: uuid, deployment (relation), snapshotRef (artifact pointer), vervaldatum (date-time).
    - `AuditEntry`: uuid, actor (relation to user), actie (enum: promote/deploy/rollback/config_override_change), environment (relation), artifact (relation, optional), motivatie (text), timestamp (date-time), ip (string).
  - All schemas validate against OpenAPI 3.0.0 validators.
  - Implement: declarative schema patch only (no PHP service classes).
  - Test: integration test creates records via OR REST for each schema; asserts schema validation rejects invalid enums.

- [ ] 1.2 **Add `x-openregister-lifecycle` to the Deployment schema**
  - spec_ref: design.md Decision 8
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares states `queued`, `running`, `success`, `failed`, `rolled_back` and transitions:
    - `queued → running`
    - `running → success`
    - `running → failed`
    - `success → rolled_back` (for rollback operation)
    - No re-entry to `queued` on failure (terminal state).
    Each transition emits an OR audit event. Schema carries `x-openregister-lifecycle-exception` annotation.
  - Implement: declarative schema patch only.
  - Test: integration test attempts invalid transitions (e.g., `success → running`); asserts 4xx error.

- [ ] 1.3 **Add `x-openregister-lifecycle` to the PromoteRequest schema**
  - spec_ref: design.md Decision 8
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares states `verzoek`, `goedgekeurd`, `uitgevoerd`, `afgekeurd` and transitions:
    - `verzoek → goedgekeurd` (approval)
    - `verzoek → afgekeurd` (rejection)
    - `goedgekeurd → uitgevoerd` (on Deployment success)
    - Terminal states are `afgekeurd` and `uitgevoerd`.
  - Implement: declarative schema patch only.
  - Test: integration test approves a pending request; asserts transition succeeds.

## 2. Environment Provisioning Service

- [ ] 2.1 **Implement `lib/Service/EnvironmentProvisioningService.php`**
  - spec_ref: REQ-001
  - files: `lib/Service/EnvironmentProvisioningService.php`
  - acceptance_criteria: `provisionEnvironmentsForApplication(Application $app): void` creates four Environment records:
    - Names: development, test, staging, production (Dutch-friendly display names).
    - URLs: `{app-slug}.dev.openbuilt.local`, `{app-slug}.test...`, `{app-slug}.staging...`, `{app-slug}.openbuilt.local`.
    - DeploymentOrder: 1, 2, 3, 4 respectively.
    - Status: all start as `actief` except production (which starts as `onderhoud`).
    - DataIsolationMode: `eigen_registers` for all.
    - Creates four isolated OR registers per ADR-002.
    Idempotent — calling it twice on the same app does nothing (checks for existing environments).
  - Implement: PHP service class; standard Conduction docblock; SPDX tags.
  - Test: PHPUnit asserts four Environment records created; asserts four registers provisioned; asserts idempotency.

- [ ] 2.2 **Wire EnvironmentProvisioningService into app creation**
  - spec_ref: REQ-001
  - files: `lib/Repair/InitializeSettings.php`, `appinfo/info.xml`
  - acceptance_criteria: `InitializeSettings::run()` calls `EnvironmentProvisioningService::provisionEnvironmentsForApplication()` when a new Application record is detected (repair step runs on install/upgrade). No environments are seeded with pre-made data — they are provisioned empty.
  - Implement: repair step integration.
  - Test: integration test creates a new Application; asserts four environments are auto-provisioned.

## 3. Artifact Creation and Hashing

- [ ] 3.1 **Implement DeploymentArtifact creation on ApplicationVersion publish**
  - spec_ref: REQ-002
  - files: `lib/Service/ArtifactService.php` (new service)
  - acceptance_criteria: When an ApplicationVersion is published (or saved in the draft state, depending on workflow), a DeploymentArtifact is created:
    - `version` ← ApplicationVersion.version (semver).
    - `manifestHash` ← SHA-256 of the manifest JSON (canonical, no whitespace variation).
    - `schemaHash` ← SHA-256 of the bundled schema set (all schemas referenced by the manifest, hashed as a concatenated canonical JSON).
    - `buildTimestamp` ← now.
    - `buildTrigger` ← `handmatig` (manually published) or `auto` (if CI integration is enabled).
    - `changesetSummary` ← optional developer-provided text.
  - Implement: PHP service; triggered by a publishVersion listener or webhook.
  - Test: PHPUnit asserts hash determinism (same manifest produces same hash); asserts different manifests produce different hashes.

## 4. PromoteRequest and Approval Workflow

- [ ] 4.1 **Implement `lib/Controller/DeploymentsController.php` — POST /api/applications/{slug}/promote endpoint**
  - spec_ref: REQ-003
  - files: `lib/Controller/DeploymentsController.php`, `appinfo/routes.php`
  - acceptance_criteria: `submitPromote(string $slug, array $body): JSONResponse` accepts:
    - `van_env` (source environment slug/uuid),
    - `naar_env` (target environment slug/uuid),
    - `artifact` (artifact UUID, or null to use the current published artifact),
    - `motivatie` (free-text reason, required for staging/production).
    Creates a PromoteRequest record. If `naar_env=dev` or `naar_env=test` and requester has role `app-editor`, auto-approves (status → `goedgekeurd`). Otherwise, enters `status=verzoek` and awaits approver decision.
    Returns 202 Accepted with `{ uuid, status }`.
    Validates that `artifact` exists and matches the application.
    Annotated `#[NoAdminRequired]` (RBAC enforced server-side).
    SPDX-in-docblock.
  - Test: PHPUnit + Newman cover:
    - 202 (happy path, dev→test, auto-approved).
    - 202 (staging/production, enters verzoek state).
    - 422 (invalid artifact).
    - 403 (non-editor trying to promote to staging).

- [ ] 4.2 **Implement approval logic in `lib/Service/PromoteService.php`**
  - spec_ref: REQ-003
  - files: `lib/Service/PromoteService.php`
  - acceptance_criteria: `approvePromoteRequest(PromoteRequest $request, array $options): void` approves a pending PromoteRequest. Options include:
    - `override_failing_tests: bool` — if true, proceeds despite test failures; logs override decision in audit.
    - `motivatie: string` — optional additional reason (required if overriding tests).
    Updates PromoteRequest status to `goedgekeurd`. Triggers deployment workflow (or schedules background job).
  - Implement: PHP service; SPDX tags.
  - Test: PHPUnit asserts approval succeeds; asserts override_failing_tests logic; asserts audit trail records override.

- [ ] 4.3 **Implement `PUT /api/promote-requests/{uuid}` approval endpoint**
  - spec_ref: REQ-003
  - files: `lib/Controller/DeploymentsController.php`, `appinfo/routes.php`
  - acceptance_criteria: Approvers (role: environment-owner) can update a PromoteRequest with `status=goedgekeured` + optional `override_failing_tests=true` and new `motivatie`. Returns 200 with updated PromoteRequest. Non-approvers get 403.
  - Test: Newman test approves a staging PromoteRequest; asserts 200.

## 5. Pre-Promote Test Gates

- [ ] 5.1 **Implement test-gate runner in `lib/Service/TestGateService.php`**
  - spec_ref: REQ-007
  - files: `lib/Service/TestGateService.php`
  - acceptance_criteria: `runPrePromoteTests(DeploymentArtifact $artifact, ApplicationVersion $version): array<object>` runs:
    - **Schema validation**: validate manifest + schemas against OR's JSON-schema validator. Return `{ suite: 'schema-validation', status: 'passed'|'failed', errorLog?: string }`.
    - **Smoke tests**: boot the app, check critical routes are reachable. Return smoke-test result object.
    - **E2E flows**: if the app has defined test flows (stored on Application), run them via browser automation (out of scope for now; placeholder).
    Returns an array of result objects, stored in `PromoteRequest.pre_promote_tests`.
  - Implement: PHP service; runs synchronously or async per design.
  - Test: PHPUnit asserts schema-validation catches invalid JSON-schema; asserts smoke-test runs.

- [ ] 5.2 **Trigger test gates on PromoteRequest submission (async or inline)**
  - spec_ref: REQ-007
  - files: `lib/Service/PromoteService.php` (or background job if async)
  - acceptance_criteria: When a PromoteRequest is created, `TestGateService::runPrePromoteTests()` is called. If promoting to staging/production, tests run before approver decision (approver can see results). If promoting to dev/test with auto-approval, tests run and block deployment only if `override_failing_tests` is not set.
  - Test: integration test creates a PromoteRequest to staging; asserts pre_promote_tests array is populated before approver sees it.

## 6. Schema Migration Service (Dry-Run)

- [ ] 6.1 **Implement `lib/Service/SchemaMigrationService.php`**
  - spec_ref: REQ-004
  - files: `lib/Service/SchemaMigrationService.php`
  - acceptance_criteria: `calculateSchemaDiff(ApplicationVersion $source, Environment $target): object` returns:
    ```json
    {
      "added": [{ "field": "payment_id", "type": "string" }],
      "changed": [{ "field": "email", "oldType": "string", "newType": "email" }],
      "deleted": [{ "field": "legacy_field" }]
    }
    ```
  `dryRunMigration(DeploymentArtifact $artifact, Environment $target): object` creates a shadow OR register, copies the first 10K rows (or all, if fewer) from the target's live register, applies the new schema, validates all rows, reports results. Returns `{ status: 'success'|'failed', errors: [...] }`.
  - Implement: PHP service; uses OR's schema-validation API.
  - Test: PHPUnit asserts diff calculation; asserts dry-run succeeds on compatible schema; asserts dry-run fails on incompatible change (e.g., new NOT NULL field with no default).

- [ ] 6.2 **Create MigrationPlan record with dry-run results**
  - spec_ref: REQ-004
  - files: (part of Deployment workflow)
  - acceptance_criteria: When a Deployment starts, a MigrationPlan is created (or populated) with:
    - `schemaDiff` ← diff from SchemaMigrationService.
    - `dry_run_resultaat` ← result object from dry-run.
    If dry-run fails, Deployment is blocked and approver can override (status → `failed`, must re-approve or dismiss).
  - Test: integration test deploys an artifact with schema change; asserts MigrationPlan is created; asserts dry-run failure blocks deployment.

## 7. Deployment Workflow and Background Job

- [ ] 7.1 **Implement `lib/BackgroundJob/ProcessDeploymentJob.php`**
  - spec_ref: design.md
  - files: `lib/BackgroundJob/ProcessDeploymentJob.php`, `appinfo/info.xml`
  - acceptance_criteria: Implements `OCP\BackgroundJob\IJob`; picks up `queued` Deployments (limit 1 per tick to bound runtime). For each Deployment:
    1. Transition to `running` via OR lifecycle.
    2. Validate artifact exists and matches environment's application.
    3. Run schema migration dry-run (SchemaMigrationService).
    4. If dry-run fails, transition to `failed` (terminal); log error in MigrationPlan.
    5. If dry-run succeeds, apply the artifact's manifest + schema to the target environment's register.
    6. Update all environment routes/URLs.
    7. Transition to `success`.
    8. Create AuditEntry with `actie=deploy`.
    NO auto-retry on failure (terminal).
  - Implement: PHP background job; SPDX-in-docblock.
  - Test: PHPUnit asserts state transitions; asserts schema migration is run; asserts AuditEntry is created on success.

- [ ] 7.2 **Implement `POST /api/deployments` trigger endpoint**
  - spec_ref: design.md
  - files: `lib/Controller/DeploymentsController.php`
  - acceptance_criteria: `triggerDeployment(array $body): JSONResponse` creates a Deployment record from an approved PromoteRequest:
    - Input: `promote_request_uuid`.
    - Validates that the PromoteRequest is in `goedgekeurd` state.
    - Creates a Deployment record with `status=queued`, `artifact=<from-request>`, `environment=<from-request>`.
    - Returns 202 Accepted with deployment UUID.
    - Background job begins execution immediately (via `IJobList::add()`).
  - Annotated `#[NoAdminRequired]` (RBAC enforced server-side).
  - Test: Newman test approves a PromoteRequest, triggers deployment, polls until success.

## 8. Rollback Functionality

- [ ] 8.1 **Implement `POST /api/deployments/{uuid}/rollback` endpoint**
  - spec_ref: REQ-006
  - files: `lib/Controller/DeploymentsController.php`
  - acceptance_criteria: `rollback(string $deploymentUuid, array $body): JSONResponse` accepts:
    - `target_deployment_uuid` (the prior Deployment to roll back to).
    - `motivatie` (required reason for rollback).
    Validates that the target Deployment is older and in a terminal success state.
    Creates a new Deployment record with:
    - `artifact` ← target Deployment's artifact.
    - `environment` ← current environment.
    - `vorige_deployment` ← current Deployment (for rollback chain).
    - `status=queued`.
    Returns 202 Accepted. Background job executes. Creates AuditEntry with `actie=rollback`.
  - Annotated `#[NoAdminRequired]` (RBAC enforced server-side; only environment-owners can initiate).
  - Test: Newman test deploys A, then B, then rolls back to A; asserts manifest/schema revert to A's artifact.

- [ ] 8.2 **Implement rollback confirmation dialog in frontend**
  - spec_ref: REQ-006
  - files: `src/dialogs/RollbackConfirmDialog.vue`
  - acceptance_criteria: Dialog displays:
    - Currently live Deployment (with artifact version, timestamp).
    - Target Deployment to roll back to (artifact version, timestamp).
    - Warning: "Rolling back will hide data added after the target deployment."
    - Checkbox: "I understand data loss risk" (must be checked).
    - Required `motivatie` text field.
    - Confirm button is disabled until checkbox is checked and motivatie is non-empty.
  - Test: Playwright opens dialog, verifies form validation.

## 9. Audit Trail and Compliance

- [ ] 9.1 **Emit AuditEntry on every promote/deploy/rollback/config-change**
  - spec_ref: REQ-008
  - files: `lib/Service/AuditService.php` (new service)
  - acceptance_criteria: `logAction(string $actie, User $actor, ?Environment $environment, ?DeploymentArtifact $artifact, string $motivatie, string $ip): void` creates an AuditEntry record.
    - Called from PromoteService (on approval), ProcessDeploymentJob (on deploy), rollback endpoint, environment config endpoint.
    - AuditEntry is immutable (no updates, only creates).
    - Required fields are enforced (especially `motivatie` for staging/production).
  - Implement: PHP service; SPDX tags.
  - Test: PHPUnit asserts AuditEntry is created; asserts all fields are populated.

- [ ] 9.2 **Implement `GET /api/applications/{slug}/audit` endpoint**
  - spec_ref: REQ-008
  - files: `lib/Controller/DeploymentsController.php`
  - acceptance_criteria: `listAudit(string $slug, array $query): JSONResponse` lists AuditEntries for the application, filterable by:
    - `environment` (filter by environment name).
    - `actie` (promote | deploy | rollback | config_override_change).
    - `from_date`, `to_date` (ISO 8601).
    Paginated (limit 100 per page). Exportable as JSON or CSV. Annotated `#[NoAdminRequired]` (but RBAC-enforced: only app-editors and above see audit).
  - Test: Newman test queries audit with filters; asserts results match expectations.

- [ ] 9.3 **Implement audit-retention background job**
  - spec_ref: REQ-008
  - files: `lib/BackgroundJob/PurgeExpiredAuditEntries.php`, `appinfo/info.xml`
  - acceptance_criteria: Daily background job (TimedJob, 24h interval):
    - For production Environments: never delete AuditEntries (7-year minimum policy, documented).
    - For dev/test Environments: delete entries older than 90 days.
    - Each purge is itself logged as a system AuditEntry.
    - Idempotent.
  - Test: PHPUnit asserts entries are purged correctly; asserts production entries are never deleted.

## 10. Environment-Aware Config UI

- [ ] 10.1 **Implement environment config override form**
  - spec_ref: REQ-005
  - files: `src/views/EnvironmentConfigForm.vue` (or integrated into environment detail view)
  - acceptance_criteria: Per-environment form showing:
    - `configOverrides` — key-value pairs (text input, key/value rows, add/delete buttons).
    - `featureFlagsOverrides` — key-value pairs for feature flags.
    - Save button posts to `PUT /api/environments/{uuid}` with the new overrides.
    - Required `motivatie` field (for audit trail).
    - Confirmation that changes are logged in AuditEntry.
  - Test: Playwright edits config, saves, asserts POST body includes motivatie.

- [ ] 10.2 **Implement environment detail page**
  - spec_ref: REQ-001, REQ-006, REQ-009
  - files: `src/views/EnvironmentDetail.vue`
  - acceptance_criteria: Displays:
    - Environment name, status, URL.
    - Current active Deployment (with artifact version, timestamp, who deployed).
    - Deployment history (timeline of Deployments, filterable by status).
    - "Rollback" button (launches RollbackConfirmDialog when current Deployment is not the first).
    - Config override form (from task 10.1).
    - Audit trail for this environment (from task 9.2, filtered by environment).
  - Test: Playwright opens environment detail; verifies Deployment history is shown; verifies rollback button is disabled if no prior deployment.

## 11. Promotion Workflow UI

- [ ] 11.1 **Implement PromoteDialog.vue (promotion wizard)**
  - spec_ref: REQ-003
  - files: `src/dialogs/PromoteDialog.vue` (standalone, modal-isolation ADR-004)
  - acceptance_criteria: Multi-step wizard:
    - **Step 1**: Source Deployment selector (dropdown: select a prior Deployment to promote from). Shows version, timestamp, summary.
    - **Step 2**: Target Environment selector (dropdown: dev/test/staging/production). Disables production if not an environment-owner.
    - **Step 3**: Reason/motivatie text field (required). Shows approval gates info (auto-approved for dev/test, requires approver for staging/production).
    - **Step 4**: Review — shows source/target, displays test-gate status (if already run). If tests are failing, shows warning + checkbox to override.
    - Submit button posts to `POST /api/applications/{slug}/promote`.
    - On success (202), closes dialog, shows confirmation toast, redirects to environment detail or Deployment list.
  - Test: Playwright steps through wizard; verifies submit payload matches spec.

- [ ] 11.2 **Implement promotion request list / approval dashboard**
  - spec_ref: REQ-003
  - files: `src/views/PromoteRequestsList.vue`
  - acceptance_criteria: Lists all PromoteRequests for the app. Columns:
    - From → To (environment names).
    - Artifact version.
    - Status (verzoek / goedgekeurd / uitgevoerd / afgekeurd).
    - Test gate results (pass/fail count).
    - Submitted by, submitted on.
    - Action buttons: if status=verzoek and user has environment-owner role, show "Approve" + "Reject" buttons.
    - "Approve" button opens a dialog to enter override-reason (if tests failed) and confirm.
  - Test: Playwright lists requests; approves a pending request; asserts status updates.

## 12. Verification + Security

- [ ] 12.1 **Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)** — all green; fix any issues in new files. SPDX tags in docblocks only (not line comments).

- [ ] 12.2 **PHPUnit test suite**:
  - `tests/Unit/Service/ArtifactServiceTest.php` — artifact creation, hashing.
  - `tests/Unit/Service/EnvironmentProvisioningServiceTest.php` — environment provisioning.
  - `tests/Unit/Service/PromoteServiceTest.php` — promotion request, approval, test-gate handling.
  - `tests/Unit/Service/SchemaMigrationServiceTest.php` — schema diff, dry-run.
  - `tests/Unit/Service/AuditServiceTest.php` — audit trail creation.
  - `tests/Unit/BackgroundJob/ProcessDeploymentJobTest.php` — deployment workflow.
  - `tests/Unit/BackgroundJob/PurgeExpiredAuditEntriesTest.php` — audit purge logic.
  - `tests/Unit/Controller/DeploymentsControllerTest.php` — all endpoints.

- [ ] 12.3 **Integration test**:
  - `tests/Integration/DeploymentPipelineE2ETest.php` — end-to-end flow:
    1. Create an Application (triggers environment provisioning).
    2. Publish an ApplicationVersion (creates artifact).
    3. Submit a PromoteRequest (dev→test, auto-approved).
    4. Verify Deployment transitions to success.
    5. Verify AuditEntry is created.
    6. Modify schema and promote again to staging.
    7. Verify dry-run is run (and passes).
    8. Roll back to first Deployment.
    9. Verify rollback Deployment succeeds.

- [ ] 12.4 **Newman tests** (REST API validation):
  - Promote endpoint (dev→test, staging, production with approval).
  - Pre-promote test results are visible.
  - Rollback endpoint.
  - Audit endpoint (filtering, pagination, export).
  - Config override endpoint.
  - Secrets are never echoed in responses.

- [ ] 12.5 **Playwright tests** (UI/UX validation):
  - PromoteDialog wizard (all steps, form validation).
  - PromoteRequestsList (approval flow).
  - EnvironmentDetail (Deployment history, rollback).
  - RollbackConfirmDialog (form validation, data-loss warning).
  - EnvironmentConfigForm (override editing, audit trail).
  - Audit trail filtering and export.

- [ ] 12.6 **RBAC verification** (ADR-023 gate):
  - `app-editor` can promote to dev/test (auto-approved).
  - `app-editor` cannot promote to staging/production (gets 403).
  - `environment-owner` can approve staging/production promotions.
  - Non-owners cannot see staging/production environments (403).
  - Config changes are logged as audit events.

- [ ] 12.7 **CI/CD integration** — add `.github/workflows/deployment-pipeline-e2e.yml`:
  - Runs integration test (12.3) on every PR.
  - Runs Newman tests (12.4) on every PR.
  - Runs Playwright tests (12.5) on every PR.
  - Parallelise with existing CI jobs.

## 13. Documentation + i18n

- [ ] 13.1 **Add `docs/deployment-pipeline.md`**
  - spec_ref: design.md
  - files: `docs/deployment-pipeline.md`
  - acceptance_criteria: Comprehensive guide covering:
    - Multi-environment model overview (dev→test→staging→production).
    - Environment provisioning (auto on app creation).
    - DeploymentArtifact immutability and hashing.
    - PromoteRequest approval flow (auto-approval for dev/test, explicit for staging/production).
    - Pre-promote tests and overriding failures.
    - Schema migration dry-run process.
    - Per-environment config and secrets resolution.
    - Rollback procedure and data-loss risks.
    - Audit trail retention policy (7 years for production, 90 days for dev/test).
    - Compliance notes (NEN 7510, OWASP ASVS).

- [ ] 13.2 **i18n keys** — add English + Dutch translations in `l10n/en.json` + `l10n/nl.json`:
  - `openbuilt.deployment.environment.dev`, `.test`, `.staging`, `.production`
  - `openbuilt.deployment.status.queued`, `.running`, `.success`, `.failed`, `.rolled_back`
  - `openbuilt.deployment.action.promote`, `.deploy`, `.rollback`
  - `openbuilt.promote.title`, `.fromEnv`, `.toEnv`, `.artifact`, `.reason`, `.submit`, `.cancel`
  - `openbuilt.promote.testGates.passed`, `.failed`, `.override`
  - `openbuilt.deployment.rollback.title`, `.confirm`, `.dataLossWarning`, `.reason`, `.cancel`
  - `openbuilt.config.override.title`, `.addKey`, `.saveChanges`
  - `openbuilt.audit.title`, `.filterEnv`, `.filterAction`, `.filterDate`, `.export`, `.retention`
  - Error messages (e.g., `openbuilt.error.invalidArtifact`, `.deploymentFailed`, `.schemaValidationFailed`).

- [ ] 13.3 **NL Design (ADR-010)** — confirm all new UI components use Nextcloud CSS variables only; no hardcoded colours. Test dark mode.

- [ ] 13.4 **Update `openspec/app-config.json`** — add `"environments-deployment-pipeline"` to the `capabilities` array.

## 14. Hydra Mechanical Gates (pre-merge)

- [ ] 14.1 Run `/hydra-gates` against the apply PR and confirm all gates green:
  - `hydra-gate-spdx`: all new PHP files carry SPDX tags inside docblocks.
  - `hydra-gate-forbidden-patterns`: no `var_dump`, `die`, `error_log`, `print_r`, `dd`, `dump` in new files.
  - `hydra-gate-stub-scan`: no stub files left behind.
  - `hydra-gate-composer-audit`: dependencies are audited, no CVEs.
  - `hydra-gate-route-auth`: all new controller methods carry correct auth annotations (`#[NoAdminRequired]` where appropriate; RBAC enforced server-side).
  - `hydra-gate-orphan-auth`: no orphan auth checks; all authorization is used.
  - `hydra-gate-no-admin-idor`: no IDOR vulnerabilities on environment/deployment endpoints.
  - `hydra-gate-admin-router`: admin-only routes are marked correctly.
  - `hydra-gate-semantic-auth`: RBAC roles (`app-editor`, `environment-owner`) are enforced per spec.
  - `hydra-gate-initial-state`: schema initial-state defaults are sensible (e.g., `status: queued` for Deployment).
  - `hydra-gate-nc-input-labels`: all form inputs in dialogs carry `inputLabel`.
  - `hydra-gate-modal-isolation`: all modals (PromoteDialog, RollbackConfirmDialog) live in `src/dialogs/`, not inline.

- [ ] 14.2 Specifically verify:
  - PromoteDialog.vue is in `src/dialogs/`, not inline in a parent component.
  - RollbackConfirmDialog.vue is in `src/dialogs/`.
  - All `NcSelect` elements in dialogs carry `inputLabel`.
  - `#[NoAdminRequired]` is applied to all public promotion/deployment endpoints.
  - No plaintext secrets in logging, error messages, or AuditEntry.logs.
