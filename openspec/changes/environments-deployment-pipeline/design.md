## Context

OpenBuilt's versioning model (ADR-002) introduced the concept of multiple ApplicationVersions per app — `development`, `staging`, `production` — each with isolated data stores and manifests. However, the transition between versions (promotion) remains ad-hoc:

- A developer publishes a manifest in dev.
- An admin manually promotes it to staging.
- There is no audit trail, no approval, no schema-migration safety, no rollback mechanism.
- An operator cannot ask "who changed what in production?" — compliance cannot verify the change path.
- If a deployment breaks, reverting requires manually editing the manifest, with no guarantee of data consistency.

Teams operating citizen-developed apps under IT-governance policy require:

1. Artifact immutability — a promotion should be to a *snapshot*, not to a mutable version.
2. Approval gates — staging/production promotions require authorization.
3. Schema-migration safety — dry-run before applying data-structure changes.
4. Operational secrets — per-environment API endpoints, API keys, feature flags.
5. Full audit trail — every promote, deploy, rollback, and config change is logged for compliance.
6. Rollback — revert to a prior Deployment in seconds with one click.

## Goals / Non-Goals

**Goals:**

- Introduce immutable DeploymentArtifacts — snapshots of a manifest + schema set at a point in time, with hash + signature.
- Require explicit PromoteRequests with approval gates before a Deployment can land in staging or production.
- Run pre-promote test gates (schema validation, smoke tests, e2e flows); block promotion if tests fail unless explicitly overridden by approver.
- Support per-environment configuration overrides — `payment.endpoint` resolves to `sandbox-url` in test, `prod-url` in production.
- Store secrets (API keys) as secret-refs, never in plaintext in the manifest.
- Enable one-click rollback to a prior Deployment within a 7-year retention window (NEN 7510).
- Emit a full AuditEntry on every promote, deploy, rollback, and config change, including actor, reason, and timestamp.
- Provision four standard environments (dev / test / staging / production) per app on app creation.
- Support data migration between environments — copy data, migrate existing data, or start empty.

**Non-Goals:**

- **Multi-tenant isolation within an environment** — per-environment data is the isolation boundary; further tenant slicing per app is out of scope.
- **Auto-promotion (cron / event triggers)** — promotions are manual in v1. Automation is a roadmap item.
- **Branching promotion DAGs** — v1 supports the linear chain only (dev → test → staging → production). ADR-002 reserves branching for future work.
- **Live re-export of a deployed app** — once deployed, the app lives in the environment; exported derivatives are independent.
- **Blue-green or canary deployment strategies** — v1 is "promote all or nothing". Staged rollout is a future enhancement.
- **Environment-specific feature branching** — feature flags are stored per-environment but toggled by the admin manually, not by CI/CD rules.

## Decisions

### Decision 1 — DeploymentArtifact immutability and hashing

A DeploymentArtifact is an immutable snapshot of:

- The ApplicationVersion's current manifest.
- The schema set referenced by the manifest.
- A hash (SHA-256) of the manifest + schema bundle.
- A SLSA v1.0 signature (ECDSA, key held by the instance).

Once created, a DeploymentArtifact cannot be edited. Promoting means creating a Deployment record that points to the artifact. If the source ApplicationVersion's manifest changes *after* the artifact was created, the artifact pointer remains unchanged — the old promotion is unaffected.

**Rationale**: Artifact immutability is a prerequisite for audit compliance. If a Deployment can point to a mutable version that changes under it, the audit trail becomes unreliable ("what was the manifest when it was deployed?"). Hashing ensures the artifact is tamper-evident.

**Refresh procedure**: If a developer wants to promote a new build, they create a new DeploymentArtifact from the current ApplicationVersion manifest.

### Decision 2 — Promotion is a two-step process: request → approval → deployment

Promoting an ApplicationVersion is no longer a one-click action. Instead:

1. **Submit PromoteRequest** — `POST /api/applications/{slug}/promote` with `van_env`, `naar_env`, `artifact`, and `motivatie` (reason for change).
2. **Approval (conditional)** — If `naar_env=dev` or `naar_env=test` and the requester has app-editor role, approval is automatic (self-approval). If `naar_env=staging` or `naar_env=production`, the request enters `status=verzoek` and blocks until an Environment owner (role: environment-owner) approves it.
3. **Deployment** — On approval, a Deployment record is created in `status=queued`. The background job `ProcessDeploymentJob` runs the deployment async.

**Rationale**: Staging and production promotions require human review and explicit sign-off for compliance (NEN 7510, OWASP ASVS A01:2021). Dev/test self-approval keeps the developer's inner loop fast.

**Alternatives considered**: *Approval after deployment (post-facto review)* — rejected: post-deployment is too late to block a bad change. *CI/CD-style approval hook* — deferred; manual approval is v1 and can integrate with Slack / Teams later.

### Decision 3 — Schema migration with dry-run

When a Deployment's artifact has a schema diff vs. the target environment's current schema:

1. **Diff calculation** — SchemaMigrationService compares the artifact's schema set against the target Environment's current schema. Marks fields as added, changed (type/constraints), or deleted.
2. **Dry-run** — Create a shadow table in the target environment's register, copy a sample of existing data into it, apply the new schema, and validate all rows conform. Report success/failure + error details.
3. **Gating** — If dry-run fails, the Deployment is blocked; an approver can override with explicit motivation (logged in the AuditEntry).
4. **Data migration** — On success, apply the schema change to the live table. Use OR's schema-evolution engine (part of openregister).

**Rationale**: Schema changes are the #1 cause of deployment failures in citizen-dev platforms. Dry-running on shadow data reveals blockers before the live deployment breaks.

**Alternatives considered**: *Full backup before schema change* — too slow for 50M-row tables. *Rely on OR's schema-backward-compatibility layer* — OR handles type coercion, but data loss (dropped fields) and new required fields still need pre-flight validation.

### Decision 4 — Per-environment config and secrets

Each Environment has:

- **`configOverrides`** — a KV map of `{ "config.endpoint": "https://sandbox-api.example.com", "config.feature_x_enabled": "true" }`. On manifest resolution, the runtime merges `manifest.config` with `environment.configOverrides` (environment wins).
- **`featureFlagsOverrides`** — a KV map of feature-flag toggles per environment.
- **Secrets are stored as `secret-ref` pointers**, e.g. `{ "secret": "api-key-prod" }`. The DeploymentService resolves these at deploy-time from Nextcloud's `ISecretVault` (similar to ICredentialsManager); the resolved value is never stored on the Deployment record or logged.

**Rationale**: Manifests must be portable and storable in git. Secrets are per-environment and must never be in plaintext. ConfigOverrides let the same app logic work against different backends (sandbox vs. production API).

**Alternatives considered**: *All config in OR as a separate ConfigEntry schema* — adds complexity and per-record lookups. *Secrets embedded in configOverrides* — dangerous; too easy to log or audit the plaintext.

### Decision 5 — Rollback via snapshot pointer and forward-compatible fields

Rolling back a Deployment means:

1. **Select a prior Deployment** — the operator picks a RollbackPoint from the history of this Environment.
2. **Restore manifest + schema** — The Deployment record points to the artifact, which is immutable. Restoring that artifact's manifest + schema to the Environment takes <60 seconds.
3. **Preserve forward-compatible data** — If the original Deployment (N-1) added a field and a newer Deployment (N) added a different field, rolling N back to N-1 must preserve data written to N's unique fields (if they are forward-compatible to the old schema). This is handled by OR's schema evolution.
4. **Audit the rollback** — Log an AuditEntry with `actie=rollback`, the source and target deployments, and mandatory `motivatie`.

**Rationale**: Operators need a fast emergency escape hatch. Immutable artifacts mean rollback is just "repoint the environment's manifest to an old artifact's hash". OR's forward-compatible schema handling means data doesn't mysteriously vanish.

**Alternatives considered**: *Full data-snapshot on every Deployment* — bloats storage and restore is slow. *Semantic schema-merge during rollback* — too complex; forward-compatible fields are the simpler contract.

### Decision 6 — Audit trail — 7-year retention for production

Every promote, deploy, rollback, and config change emits an AuditEntry with:

- `actor` — authenticated user UUID.
- `actie` — `promote` | `deploy` | `rollback` | `config_override_change`.
- `environment` — target environment.
- `artifact` — artifact UUID (or null for config changes).
- `motivatie` — free text reason (required for production changes).
- `timestamp` — ISO 8601.
- `ip` — source IP of the request.

Entries are stored in the `openbuilt` register as immutable records. For production Environments, retention is 7 years (per NEN 7510). For dev/test, retention is 90 days (auto-purged).

**Rationale**: Compliance (NEN 7510, ISO 27001, OWASP ASVS) requires demonstrable audit trails. The 7-year baseline is a legal minimum; 90 days for dev/test saves storage.

**Alternatives considered**: *Log to syslog only* — harder to query and filter by app. *Retention as a Nextcloud config setting* — deferred; 7 years is the production default.

### Decision 7 — Environment provisioning on app creation

When a new Application is created, the appinfo/lib/Repair/InitializeSettings.php repair step runs and calls EnvironmentProvisioningService, which:

1. Creates four Environment records: `name=dev/test/staging/production`, each with a unique URL slug (`{app}.dev.openbuilt.local`, `{app}.test...`, etc.).
2. Creates four isolated OR registers: `openbuilt-{slug}-dev`, `openbuilt-{slug}-test`, `openbuilt-{slug}-staging`, `openbuilt-{slug}-production`.
3. Sets `status=actief` (active) for dev/test/staging; `status=onderhoud` (maintenance) for production until the first successful promote to production.
4. Links each Environment to the Application via a relation.

**Rationale**: Admins should not manually provision environments. The standard four-tier chain is the default; admins can disable/rename tiers later. Doing it on app-create ensures the registers exist before the first manifest is published.

**Alternatives considered**: *On-demand provisioning (create an environment when first promoted to)* — delays the promote UX and forces a schema migration mid-flow. *One shared register for all versions* — violates the data-isolation requirement.

### Decision 8 — Test-gate results storage and override

Pre-promote tests are run by the background job when a PromoteRequest transitions `verzoek → goedgekeurd`. Results are stored in the PromoteRequest record:

```json
{
  "pre_promote_tests": [
    { "suite": "schema-validation", "status": "passed" },
    { "suite": "smoke-tests", "status": "passed" },
    { "suite": "e2e-flows", "status": "failed", "errorLog": "..." }
  ]
}
```

If all tests pass, the Deployment is auto-created. If any fail, the request is blocked and the approver must explicitly `override_failing_tests=true` + new `motivatie` to proceed (logged in the AuditEntry as an override).

**Rationale**: Tests catch common bugs before production breakage. But tests are not gates in the legal sense — a human must be able to override with documented justification. The override decision is captured in audit.

**Alternatives considered**: *Hard gate (failing tests block all promotions)* — too rigid; emergencies require bypass paths. *No test gating at all* — loses the safety net.

## Seed Data

Every schema-shipping change documents seed data per ADR-031 conventions. The following examples document the expected shape of Environment, DeploymentArtifact, Deployment, PromoteRequest, and AuditEntry objects in a Dutch municipality context (for reference during testing and QA).

### Environment

```json
[
  {
    "uuid": "env-001-dev",
    "application": "app-helloworld",
    "appName": "Helloworld Development",
    "deploymentOrder": 1,
    "status": "actief",
    "dataIsolationMode": "eigen_registers",
    "configOverrides": {},
    "featureFlagsOverrides": {},
    "url": "https://helloworld.dev.openbuilt.local"
  },
  {
    "uuid": "env-001-test",
    "application": "app-helloworld",
    "appName": "Helloworld Test",
    "deploymentOrder": 2,
    "status": "actief",
    "dataIsolationMode": "eigen_registers",
    "configOverrides": { "config.payment_endpoint": "https://sandbox-payment.example.com" },
    "featureFlagsOverrides": { "feature_batch_processing": false },
    "url": "https://helloworld.test.openbuilt.local"
  },
  {
    "uuid": "env-001-staging",
    "application": "app-helloworld",
    "appName": "Helloworld Staging",
    "deploymentOrder": 3,
    "status": "actief",
    "dataIsolationMode": "eigen_registers",
    "configOverrides": { "config.payment_endpoint": "https://acc-payment.example.com" },
    "featureFlagsOverrides": { "feature_batch_processing": true },
    "url": "https://helloworld.staging.openbuilt.local"
  },
  {
    "uuid": "env-001-production",
    "application": "app-helloworld",
    "appName": "Helloworld Production",
    "deploymentOrder": 4,
    "status": "onderhoud",
    "dataIsolationMode": "eigen_registers",
    "configOverrides": { "config.payment_endpoint": "https://production-payment.example.com" },
    "featureFlagsOverrides": { "feature_batch_processing": true },
    "url": "https://helloworld.openbuilt.local"
  }
]
```

### DeploymentArtifact

```json
[
  {
    "uuid": "artifact-001",
    "application": "app-helloworld",
    "version": "1.0.0",
    "manifestHash": "sha256-abcd1234...",
    "schemaHash": "sha256-efgh5678...",
    "buildTimestamp": "2026-05-20T10:00:00Z",
    "buildTrigger": "handmatig",
    "changesetSummary": "Add payment schema, update form layout"
  },
  {
    "uuid": "artifact-002",
    "application": "app-helloworld",
    "version": "1.0.1",
    "manifestHash": "sha256-ijkl9012...",
    "schemaHash": "sha256-mnop3456...",
    "buildTimestamp": "2026-05-21T14:30:00Z",
    "buildTrigger": "handmatig",
    "changesetSummary": "Fix form validation, optimize queries"
  }
]
```

### PromoteRequest

```json
[
  {
    "uuid": "promote-req-001",
    "van_env": "env-001-dev",
    "naar_env": "env-001-test",
    "artifact": "artifact-001",
    "motivatie": "Ready for QA testing — form layout updated, payment integration tested locally",
    "approver": "user-jan-willem",
    "status": "goedgekeurd",
    "pre_promote_tests": [
      { "suite": "schema-validation", "status": "passed" },
      { "suite": "smoke-tests", "status": "passed" }
    ]
  },
  {
    "uuid": "promote-req-002",
    "van_env": "env-001-staging",
    "naar_env": "env-001-production",
    "artifact": "artifact-002",
    "motivatie": "Ready for production rollout — all UAT passed, performance tests OK",
    "approver": "user-annemarie",
    "status": "goedgekeurd",
    "pre_promote_tests": [
      { "suite": "schema-validation", "status": "passed" },
      { "suite": "smoke-tests", "status": "passed" },
      { "suite": "e2e-flows", "status": "passed" }
    ]
  },
  {
    "uuid": "promote-req-003",
    "van_env": "env-001-dev",
    "naar_env": "env-001-test",
    "artifact": "artifact-001",
    "motivatie": "Retrying promotion — schema-migration dry-run failed, now fixed",
    "approver": "user-sem",
    "status": "afgekeurd",
    "pre_promote_tests": [
      { "suite": "schema-validation", "status": "failed", "errorLog": "Field 'payment_id' type mismatch" }
    ]
  }
]
```

### Deployment

```json
[
  {
    "uuid": "deploy-001",
    "artifact": "artifact-001",
    "environment": "env-001-test",
    "status": "success",
    "gestart_op": "2026-05-20T10:05:00Z",
    "voltooid_op": "2026-05-20T10:07:30Z",
    "gestart_door": "user-build-system",
    "vorige_deployment": null
  },
  {
    "uuid": "deploy-002",
    "artifact": "artifact-002",
    "environment": "env-001-staging",
    "status": "success",
    "gestart_op": "2026-05-21T15:00:00Z",
    "voltooid_op": "2026-05-21T15:02:15Z",
    "gestart_door": "user-build-system",
    "vorige_deployment": "deploy-001"
  },
  {
    "uuid": "deploy-003",
    "artifact": "artifact-002",
    "environment": "env-001-production",
    "status": "success",
    "gestart_op": "2026-05-22T09:00:00Z",
    "voltooid_op": "2026-05-22T09:02:45Z",
    "gestart_door": "user-build-system",
    "vorige_deployment": null
  }
]
```

### AuditEntry

```json
[
  {
    "uuid": "audit-001",
    "actor": "user-jan-willem",
    "actie": "promote",
    "environment": "env-001-test",
    "artifact": "artifact-001",
    "motivatie": "Ready for QA testing — form layout updated, payment integration tested locally",
    "timestamp": "2026-05-20T10:05:00Z",
    "ip": "192.168.1.100"
  },
  {
    "uuid": "audit-002",
    "actor": "user-build-system",
    "actie": "deploy",
    "environment": "env-001-test",
    "artifact": "artifact-001",
    "motivatie": "Automatic deployment on PromoteRequest approval",
    "timestamp": "2026-05-20T10:05:00Z",
    "ip": "127.0.0.1"
  },
  {
    "uuid": "audit-003",
    "actor": "user-sem",
    "actie": "config_override_change",
    "environment": "env-001-staging",
    "artifact": null,
    "motivatie": "Updated payment endpoint to production ACC account",
    "timestamp": "2026-05-21T13:30:00Z",
    "ip": "192.168.1.105"
  },
  {
    "uuid": "audit-004",
    "actor": "user-noor",
    "actie": "rollback",
    "environment": "env-001-production",
    "artifact": "artifact-001",
    "motivatie": "Payment validation bug in artifact-002 discovered in production; rolling back to stable artifact-001",
    "timestamp": "2026-05-22T10:15:00Z",
    "ip": "192.168.1.110"
  }
]
```

## Risks / Trade-offs

- **Risk** — *Dry-run on shadow table is slow for 50M-row tables.* → Mitigation: run the dry-run on a sample (first 10K rows) rather than the full table; add an admin toggle to do full validation if needed.
- **Risk** — *Approval delays block urgent hotfixes.* → Mitigation: design fast approval UX (Slack / Teams notification on new PromoteRequests with one-click approval); consider expedited approval for hot-fixes later.
- **Risk** — *Secret resolution at deploy-time fails; deployment hangs.* → Mitigation: fetch and validate all secret-refs before transitioning to `running` state; fail fast with a clear error message if any ref is missing or revoked.
- **Risk** — *RBAC enforcement on environment-owner role is weak.* → Mitigation: the role is enforced server-side via ADR-023 action-authorization; no UI-only hiding.
- **Risk** — *Rolling back a Deployment that had data added in subsequent deployments loses the new data.* → Mitigation: document the data-loss risk in the rollback confirmation dialog; require explicit checkbox confirmation.
- **Risk** — *Audit-log retention becomes a compliance liability if purging fails.* → Mitigation: implement audit-purge as a careful, audited background job with a dry-run log; test retention policies in CI.
- **Trade-off** — *Four environments per app multiplies register count 4x.* → Acceptable: registers are cheap. The isolation is worth the overhead.
- **Trade-off** — *Promotion approval adds latency to the dev→test path.* → Mitigated by auto-approval for app editors on dev/test; only staging/production require explicit approval.

## Open Questions

- **OQ-1** — Should rollback options be time-limited (e.g., only the last 10 Deployments)?* Provisional: No hard time limit, but retention purges old AuditEntries. If an operator wants to roll back beyond the audit-retention window, they must restore from a backup (not in scope for this spec).
- **OQ-2** — Should pre-promote tests be configurable per app?* Provisional: v1 runs the standard suite (schema-validation, smoke-tests). Per-app test configuration is a roadmap item.
- **OQ-3** — Should we support "promote to multiple environments at once" (e.g., dev→test→staging in one batch)?* Provisional: No, v1 is one environment at a time to keep promotion semantics clear. Batch promotion is a future enhancement.
