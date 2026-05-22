---
kind: code
depends_on: ["bootstrap-openbuilt", "openbuilt-versioning"]
chain:
  - bootstrap-openbuilt
  - openbuilt-versioning
  - environments-deployment-pipeline   # THIS spec — multi-environment promotion + audit
---

## Why

OpenBuilt's founding commitment was to empower citizen developers to build, deploy, and operate applications. The versioning model (ADR-002) introduced isolated ApplicationVersions — `development`, `staging`, `production` — each with its own register and data store. This spec closes the gap between isolated versions and *operational deployment*: moving code and schema changes between environments safely, auditing every step, and enabling rollback when something breaks.

Currently, promoting an ApplicationVersion is a one-click manifest update. A developer publishes a build in dev; an admin clicks "promote to staging"; the manifest lands in staging. But there is no:

- **Controlled promotion pipeline** — staging promotions should require approval; production promotions need pre-flight tests and full audit trails.
- **Artifact immutability** — a promotion is a pointer to a manifest snapshot, but the source version's manifest can still change in place, breaking the promote link.
- **Schema migration safety** — demoting a column is a silent data-loss operation; promoting a schema with new required fields can break existing records; there is no dry-run or rollback.
- **Audit trail** — "who promoted what, when, and why" is not stored; compliance cannot verify a change path.
- **Operational secrets** — the same `config.endpoint` API URL is shared across environments (sandbox vs. production); there is no per-environment override.
- **Rollback** — if a production deployment breaks, operators have to manually revert the manifest to a prior version — a painful, error-prone process.

This spec ships the complete deployment pipeline: immutable DeploymentArtifacts, environment-specific config, approval gates, pre-promote test gates, schema-migration dry-runs, rollback with one click, and a full audit trail of every promote, deploy, and rollback.

## What Changes

- **NEW** `Environment` schema in `lib/Settings/openbuilt_register.json` declaring `{ uuid, application, appName (friendly name), deploymentOrder (1-4), status (actief/onderhoud/uit), dataIsolationMode (eigen_registers/gedeelde_registers_lees), configOverrides (KV-pairs), featureFlagsOverrides (KV-pairs), url }` with constraints enforcing the four standard environments per app.
- **NEW** `DeploymentArtifact` schema declaring `{ uuid, application, version (semver), manifestHash, schemaHash, buildTimestamp, buildTrigger (handmatig/auto/CI), changesetSummary }` — immutable snapshot of a built manifest + schema set.
- **NEW** `Deployment` schema declaring `{ uuid, artifact, environment, status (queued/running/success/failed/rolled_back), gestart_op (timestamp), voltooid_op (timestamp), gestart_door (user), vorige_deployment (for rollback-link) }` — a DeploymentArtifact landing in an Environment.
- **NEW** `PromoteRequest` schema declaring `{ uuid, van_env, naar_env, artifact, motivatie (reason text), approver, status (verzoek/goedgekeurd/uitgevoerd/afgekeurd), pre_promote_tests (test results array) }` — a formal promotion request with approval and test-gate tracking.
- **NEW** `MigrationPlan` schema declaring `{ uuid, deployment, schemaDiff (added/changed/deleted field details), dataMigrationSteps (instructions), dry_run_resultaat (success/failed + error message) }` — schema-change safety.
- **NEW** `RollbackPoint` schema declaring `{ uuid, deployment, snapshotRef (artifact pointer), vervaldatum (retention date) }` — immutable anchor for rollback.
- **NEW** `AuditEntry` schema declaring `{ uuid, actor, actie (promote/deploy/rollback/config-change), environment, artifact, motivatie, timestamp, ip }` — full compliance audit trail.
- **NEW** PHP controller `lib/Controller/DeploymentsController.php` with endpoints:
  - `POST /api/applications/{slug}/promote` — submit a PromoteRequest.
  - `POST /api/applications/{slug}/deployments` — trigger a Deployment via an approved PromoteRequest.
  - `POST /api/deployments/{uuid}/rollback` — rollback to a prior Deployment.
  - `GET /api/applications/{slug}/audit` — list AuditEntries for the app.
- **NEW** PHP service `lib/Service/DeploymentService.php` — orchestrates artifact creation, promotion, deployment, schema-migration dry-runs, and rollback.
- **NEW** PHP service `lib/Service/SchemaM igrationService.php` — calculates schema diffs, runs dry-runs on a shadow table, and reports blockers.
- **NEW** PHP environment provisioning service `lib/Service/EnvironmentProvisioningService.php` — creates the four standard environments on app creation with isolated registers and URLs.
- **NEW** PHP background job `lib/BackgroundJob/ProcessDeploymentJob.php` — async deployment pipeline (queued → running → success/failed).
- **NEW** Frontend promotion wizard `src/dialogs/PromoteDialog.vue` (per modal-isolation ADR-004) — displays approval gates, test results, schema-diff preview, rollback-point selection.
- **NEW** Frontend environment detail views — lists Deployments per environment, shows current active Deployment, surfaces rollback button.
- **NEW** environment-aware config UI — per-environment override of `config.*` values.

### Capabilities

#### New Capabilities

- `environments-deployment-pipeline`: The controlled promotion and deployment system. Owns the seven new schemas (Environment, DeploymentArtifact, Deployment, PromoteRequest, MigrationPlan, RollbackPoint, AuditEntry), the DeploymentService, SchemaMigrationService, EnvironmentProvisioningService, ProcessDeploymentJob, PromoteDialog.vue, environment detail views, and environment-config UI. Honours ADR-002 (versions define promotion chain), ADR-023 (action-authorization for promotions), OWASP ASVS (environment isolation), NEN 7510 (audit trail for production).

#### Modified Capabilities

- `openbuilt-application-register` — Application record gains an environment-provisioning hook on create: when a new Application lands, EnvironmentProvisioningService creates four Environment records and links them to the Application.
- `openbuilt-versioning` — ApplicationVersion promotion now creates a PromoteRequest (formal request) rather than directly updating manifests. The UI changes to show approval gates and test results.

## Impact

- **New code**: ~15 PHP service + controller + background-job files; ~8 Vue components (dialogs, detail views, config UI); schema definitions in `lib/Settings/openbuilt_register.json`; routes in `appinfo/routes.php`.
- **External dependencies**: none beyond those already in use (OpenRegister, Nextcloud framework).
- **Data migration**: Existing apps gain four Environment records on the next install/upgrade; ApplicationVersion.promote pointers are migrated to PromoteRequest records.
- **Breaking changes**: None. The old version-to-version promotion path is deprecated in the UI but continues to work (creates an auto-approved PromoteRequest).
- **Foundational ADRs honoured**: ADR-002 (multi-version model), ADR-023 (authorization), OWASP ASVS (environment isolation + audit trail compliance).
