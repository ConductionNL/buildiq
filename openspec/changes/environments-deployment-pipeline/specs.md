# Specifications: Environments & Deployment Pipeline

## REQ-001: Four standard environments per application

**Scenario 1.1 — Automatic provisioning on app creation**

GIVEN a citizen developer creates a new Application in OpenBuilt with name "Helloworld",
WHEN the appinfo/Repair/InitializeSettings repair step completes,
THEN openbuilt creates four Environment records:
- `name=development, deploymentOrder=1, status=actief, url=helloworld.dev.openbuilt.local`
- `name=test, deploymentOrder=2, status=actief, url=helloworld.test.openbuilt.local`
- `name=staging, deploymentOrder=3, status=actief, url=helloworld.staging.openbuilt.local`
- `name=production, deploymentOrder=4, status=onderhoud, url=helloworld.openbuilt.local`

Each Environment has its own isolated OR register (`openbuilt-helloworld-dev`, etc.) and all are linked to the Application via a relation.

**Scenario 1.2 — Production environment starts disabled**

GIVEN the four environments have been provisioned for a new app,
WHEN a user navigates to the Application detail page,
THEN the production Environment shows `status=onderhoud` (maintenance) and the deploy button is disabled until the first successful Deployment to production completes, at which point it transitions to `status=actief`.

---

## REQ-002: DeploymentArtifact immutability and versioning

**Scenario 2.1 — Artifact creation on build**

GIVEN a developer publishes an ApplicationVersion (manifest + schema set) in development,
WHEN the publish action completes,
THEN openbuilt creates a DeploymentArtifact record with:
- `version` — copied from the ApplicationVersion's semver.
- `manifestHash` — SHA-256 of the ApplicationVersion's manifest JSON.
- `schemaHash` — SHA-256 of the bundled schema set.
- `buildTimestamp` — ISO 8601 now.
- `buildTrigger` — `handmatig` (user-initiated) or `auto` (if CI integration is enabled).
- `changesetSummary` — free-text summary from the developer (e.g., "Add payment schema, update form layout").

**Scenario 2.2 — Artifact hash immutability**

GIVEN a DeploymentArtifact has been created with `manifestHash=sha256-abc123`,
WHEN the source ApplicationVersion's manifest is later edited,
THEN the artifact's hash remains unchanged. Promoting uses the old artifact snapshot, not the new manifest.

**Scenario 2.3 — Multiple artifacts per version**

GIVEN a developer publishes ApplicationVersion 1.0.0, then edits the manifest and publishes again,
WHEN the second publish completes,
THEN a second DeploymentArtifact is created with a different `manifestHash`, version still 1.0.0, and a later `buildTimestamp`. The first artifact remains immutable.

---

## REQ-003: Promotion as PromoteRequest with approval gates

**Scenario 3.1 — Dev→test promotion (auto-approved)**

GIVEN a developer has published an ApplicationVersion in development and a DeploymentArtifact exists,
WHEN the developer clicks "Promote to test" and submits a PromoteRequest with `van_env=dev, naar_env=test, artifact=<uuid>, motivatie=<reason>`,
THEN:
- If the developer has role `app-editor`, the PromoteRequest is auto-approved (status → `goedgekeurd`).
- Pre-promote tests are triggered (schema-validation, smoke-tests).
- If all tests pass, a Deployment is created in `status=queued`.
- The background job begins execution.

**Scenario 3.2 — Staging promotion (requires explicit approval)**

GIVEN a PromoteRequest from test to staging with `naar_env=staging` has been submitted,
WHEN the request lands in the system,
THEN:
- The request enters `status=verzoek` (pending approval).
- Pre-promote tests are triggered asynchronously.
- An environment-owner (role) is notified and must explicitly approve via `PUT /api/promote-requests/{uuid}` with `status=goedgekeurd` + optional `overrideFailingTests=true`.
- Only on approval does a Deployment get created and executed.

**Scenario 3.3 — Production promotion with test-failure override**

GIVEN a PromoteRequest to production has test results showing `schema-validation=failed`,
WHEN an approver with role `environment-owner` reviews the request,
THEN they can approve with `overrideFailingTests=true` and a new `motivatie` (e.g., "Schema change is backward-compatible; manual validation passed"). This override is logged in the AuditEntry as a security-relevant decision.

---

## REQ-004: Schema migration with dry-run and rollback point

**Scenario 4.1 — Dry-run on schema change**

GIVEN a DeploymentArtifact introduces a new schema field `payment_id` (not nullable) and is being deployed to staging,
WHEN the Deployment starts,
THEN:
- A temporary OR register is created (`openbuilt-helloworld-staging-dry-run-<timestamp>`).
- A sample of the current data (first 10K rows) is copied into the shadow register.
- The new schema is applied to the shadow register.
- All rows are validated against the new schema.
- The shadow register is deleted and the dry-run result is recorded in a MigrationPlan record.

**Scenario 4.2 — Dry-run failure blocks deployment**

GIVEN the dry-run fails (e.g., existing rows cannot be coerced to the new type),
WHEN the Deployment reaches the schema-migration phase,
THEN:
- The Deployment enters `status=failed`.
- The `MigrationPlan.dry_run_resultaat` captures the error (e.g., "Row 5432: 'payment_id' value is NULL but field is NOT NULL").
- The live register is untouched.
- The developer is notified and can retry the Deployment after fixing the schema or data.

**Scenario 4.3 — Successful schema migration**

GIVEN the dry-run passes,
WHEN the schema-migration phase proceeds,
THEN:
- The new schema is applied to the live register.
- All existing rows are updated/coerced per OR's schema-evolution rules.
- Data loss is acceptable only if the schema declares a field as deleted and the developer has acknowledged the data loss risk.

---

## REQ-005: Per-environment config and secrets

**Scenario 5.1 — Config override resolution**

GIVEN an Application's manifest declares `config.payment_endpoint = "https://sandbox-api.example.com"`,
AND the staging Environment has `configOverrides = { "config.payment_endpoint": "https://acc-api.example.com" }`,
WHEN the app loads in staging,
THEN the runtime resolver returns `https://acc-api.example.com` (Environment value wins).

**Scenario 5.2 — Feature flag override per environment**

GIVEN the staging Environment has `featureFlagsOverrides = { "batch_processing": false }`,
AND the production Environment has `featureFlagsOverrides = { "batch_processing": true }`,
WHEN code checks the feature flag,
THEN staging sees `false`, production sees `true`, without code changes.

**Scenario 5.3 — Secret references are never stored in plaintext**

GIVEN a developer wants to use an API key in production,
WHEN they edit the manifest and add `secret: "api-key-prod"`,
THEN:
- The manifest stores only the reference string `"api-key-prod"`, not the key itself.
- At deploy-time, DeploymentService resolves `"api-key-prod"` by calling Nextcloud's `ISecretVault::findOne('api-key-prod')`.
- If the secret is missing or revoked, the Deployment fails immediately with a clear error.
- The resolved value is never written to the Deployment record, log, or error messages.

---

## REQ-006: One-click rollback with forward-compatible data preservation

**Scenario 6.1 — Rollback to prior deployment**

GIVEN Deployment A (with DeploymentArtifact 1) is live in production,
AND Deployment B (with DeploymentArtifact 2) was deployed after A and broke the app,
WHEN an operator clicks "Rollback" in the Deployment history and selects Deployment A,
THEN:
- The production Environment's manifest + schema are reverted to Deployment A's artifact (from `manifestHash` + `schemaHash`).
- Within 60 seconds, the app is live on Deployment A.
- A RollbackPoint is created linking to Deployment A as the anchor.
- An AuditEntry is created with `actie=rollback, artifact=<artifact-1-uuid>, motivatie=<operator-reason>`.

**Scenario 6.2 — Data in new fields is preserved on rollback (forward-compatible schema)**

GIVEN Deployment A's schema has fields `name, email`,
AND Deployment B added fields `phone, address`,
AND production data now has values in all four fields,
WHEN rolling back to Deployment A,
THEN:
- Deployment A's schema (with only `name, email`) is restored.
- Queries over the live schema return only `name, email`.
- The `phone, address` data is not deleted; it persists in the register but is hidden by the restored schema.
- If the operator re-deploys B later, the `phone, address` data re-surfaces (forward-compatibility).

**Scenario 6.3 — Rollback confirmation requires mandatory reason**

GIVEN an operator has selected a prior Deployment to roll back to,
WHEN the rollback confirmation dialog appears,
THEN it displays:
- The source Deployment (currently live).
- The target Deployment (to roll back to).
- A warning: "Rolling back will hide data added since the target deployment."
- A required `motivatie` text field (e.g., "Critical bug in payment-processing; reverting to stable version").
- A checkbox: "I understand data loss risk" (must be checked).
- Only if the checkbox is checked and motivatie is non-empty can the Rollback button be clicked.

---

## REQ-007: Pre-promote test gates

**Scenario 7.1 — Standard test suites run on promote**

GIVEN a PromoteRequest to staging or production has been submitted,
WHEN the system processes the request,
THEN it runs:
- **Schema-validation**: validates the artifact's schema against OR's JSON-schema validator. Checks for required-field syntax, unique constraints, etc.
- **Smoke-tests**: basic runtime checks (e.g., can the app instance boot, are critical routes reachable).
- **E2E flows**: runs a list of recorded user journeys (defined per app, optional).

Results are stored in `PromoteRequest.pre_promote_tests` as an array of `{ suite, status, errorLog }` objects.

**Scenario 7.2 — Failing tests block promotion unless overridden**

GIVEN the schema-validation test fails (e.g., invalid JSON schema syntax),
WHEN the PromoteRequest is processed,
THEN:
- The Deployment is not created.
- The approver can see the error in the PromoteRequest detail view.
- The approver can approve with `overrideFailingTests=true` + new `motivatie` (e.g., "Schema is valid per our internal tool; OR validation is overly strict. Approving to unblock.").
- The override decision is captured in an AuditEntry with both `motivatie` lines.

---

## REQ-008: Audit trail for compliance (7-year retention for production)

**Scenario 8.1 — Every promote is audited**

GIVEN a PromoteRequest is approved and a Deployment is created,
WHEN the Deployment transitions to `success`,
THEN an AuditEntry is created with:
- `actor` — the approver's user UUID.
- `actie` — `promote`.
- `environment` — the target environment.
- `artifact` — the artifact UUID.
- `motivatie` — the reason text from the PromoteRequest.
- `timestamp` — ISO 8601.
- `ip` — the request IP from the approval action.

**Scenario 8.2 — Config changes are audited**

GIVEN an admin updates an Environment's `configOverrides` (e.g., changes the payment endpoint),
WHEN the change is saved,
THEN an AuditEntry is created with `actie=config_override_change, artifact=null, motivatie=<admin-provided-reason>`.

**Scenario 8.3 — Rollbacks are audited**

GIVEN an operator completes a rollback,
WHEN the rollback Deployment completes,
THEN an AuditEntry is created with `actie=rollback, artifact=<target-artifact-uuid>, motivatie=<operator-reason>`.

**Scenario 8.4 — Audit retention: 7 years for production, 90 days for dev/test**

GIVEN AuditEntries exist for various Environments,
WHEN a background job runs daily,
THEN:
- Entries for production Environments older than 7 years are preserved (never purged).
- Entries for dev/test Environments older than 90 days are deleted.
- Deletion is itself logged (an AuditEntry for the purge action).

---

## REQ-009: Environment-aware routing and visibility

**Scenario 9.1 — Dev environment accessible by app editors**

GIVEN an app has four Environments,
WHEN a user with role `app-editor` navigates to `/apps/openbuilt/{slug}?version=development`,
THEN the development ApplicationVersion is rendered, and the data comes from the `openbuilt-{slug}-dev` register.

**Scenario 9.2 — Staging/production access restricted to environment-owners**

GIVEN a user with role `app-viewer` (read-only) tries to access `?version=staging`,
WHEN the request reaches the server,
THEN the server returns 403 Forbidden (enforced server-side, not just hidden in the UI).

**Scenario 9.3 — Production is the default URL**

GIVEN an app is deployed to production,
WHEN a user navigates to the canonical URL `/apps/openbuilt/{slug}` (no query param),
THEN the production version is served (the one pointed to by `Application.productionVersion`).

---

## REQ-010: Exported apps maintain environment model (optional future integration)

**Scenario 10.1 — Exported app carries environment setup (forward-compatible)**

GIVEN an app is exported from openbuilt,
WHEN the exported Nextcloud app is installed in a new Nextcloud instance,
THEN the app's `InitializeSettings` repair step can optionally wire up the same environment-based promotion model (if openbuilt is available in that instance), or fall back to a single-environment mode if not. This is forward-compatible; v1 exports do not require it.

---

## REQ-011: Data isolation between environments

**Scenario 11.1 — Each environment has its own register**

GIVEN four Environments for one app,
WHEN a developer publishes a schema in development,
THEN:
- The schema is created in the `openbuilt-{slug}-dev` register.
- The test, staging, and production registers are unaffected.
- No cross-environment data leakage is possible by construction (physically separate OR registers).

**Scenario 11.2 — Shared-register mode is not supported in v1**

GIVEN the design mentions `dataIsolationMode`, with options `eigen_registers | gedeelde_registers_lees`,
WHEN designing the v1 implementation,
THEN only `eigen_registers` (physically isolated registers) is implemented. The `gedeelde_registers_lees` (read-sharing) mode is a roadmap item.
