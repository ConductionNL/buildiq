## Status

Draft — openbuilt spec brief, 2026-05-21.

# Environments & Deployment Pipeline

## Placement & Information Architecture

**Placement type:** `TOP_MENU` — Top-level menu entry — this functionality earns its own item in the app's left-nav.

**Lives at:** Deploy

**Rationale:** pipeline + environments share Deploy  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Citizen-developer apps built in openbuilt currently live in one environment — the environment where they were drag-built. That is fine for prototypes but unacceptable for any app the organisation actually depends on (e.g. an inkoop-aanvraag-flow used daily by 200 mensen). This spec introduces a multi-environment model — **dev / test / staging / production** — with a controlled promote-pipeline between them. Each environment has its own data store (registers + schemas), its own config (API-keys, endpoints, feature-flags), and its own running URL. A build in dev does not affect data in production; a schema change in test does not migrate production data until explicitly promoted. The pipeline is one-click but audited: every promote captures who, when, what changed (manifest diff + schema diff), test-results, and a rollback-point. Rollback is symmetric — promote v5 to production, find a bug, rollback to v4 with one button and the previous version is live again in seconds. This is the difference between "openbuilt is a fun toy" and "openbuilt is approved as a citizen-dev platform under the IT-governance policy".

## Data Model

- **Environment**: app, naam (dev/test/staging/production), url, status (actief/onderhoud/uit), data-isolatie-mode (eigen_registers/gedeelde_registers_lees), feature-flags-overrides, env-config (KV-pairs, secrets-refs).
- **DeploymentArtifact**: app, versie (semver), manifest-hash, schema-hash, build-timestamp, build-trigger (handmatig/auto/CI), changeset-summary.
- **Deployment**: artifact, environment, status (queued/running/success/failed/rolled_back), gestart_op, voltooid_op, gestart_door, vorige_deployment (voor rollback-link).
- **PromoteRequest**: van_env, naar_env, artifact, motivatie, approver, status (verzoek/goedgekeurd/uitgevoerd/afgekeurd), pre_promote_tests (resultaten).
- **MigrationPlan**: deployment, schema-diff (added/changed/deleted velden), data-migratie-stappen, dry_run_resultaat.
- **RollbackPoint**: deployment, snapshot-ref, vervaldatum (retention).
- **AuditEntry**: actor, actie, env, artifact, motivatie, timestamp, ip.

## Requirements

**REQ-001: Vier standaard-environments per app.** GIVEN een nieuwe openbuilt-app, WHEN deze wordt aangemaakt, THEN openbuilt provisiont vier Environment-records (dev, test, staging, production) met geïsoleerde registers, ieder met een unieke URL (bv. `{app}.dev.openbuilt.local`, `{app}.test...`, etc.); production is initieel disabled tot eerste succesvolle promote.

**REQ-002: Promote van artifact, niet van running-state.** GIVEN een build in dev, WHEN de developer "promote naar test" klikt, THEN openbuilt maakt een onveranderlijke DeploymentArtifact (manifest + schemas + assets, gehasht en gesigned), creëert een PromoteRequest, en deployt het artifact naar test — niet de live dev-state; latere mutaties in dev breken de promote-snapshot niet.

**REQ-003: Approval-policy per ziel-environment.** GIVEN een PromoteRequest met naar_env=staging of production, WHEN deze wordt ingediend, THEN tenminste één approver met rol environment-owner moet goedkeuren voordat de deployment uitvoert; promotes naar dev/test mogen automatisch goedgekeurd zijn voor de auteur (self-approval ok).

**REQ-004: Schema-migratie met dry-run.** GIVEN een DeploymentArtifact met schema-diff vs de doel-environment, WHEN de deployment start, THEN openbuilt voert eerst een dry-run uit (kopieert data naar een tijdelijke schema, valideert), rapporteert resultaat in MigrationPlan, en pas bij dry-run=success voert de echte migratie uit; failed dry-run blokkeert deployment.

**REQ-005: Per-environment config en secrets.** GIVEN een app vertrouwt op een externe API-endpoint, WHEN het manifest een config-variable `payment.endpoint` declareert, THEN elke Environment heeft een eigen waarde (dev=sandbox-url, test=sandbox-url, staging=acc-url, production=prod-url); secrets (API-keys) worden als secret-ref opgeslagen, nooit in plaintext in het manifest, en worden per-env apart geconfigureerd.

**REQ-006: Rollback met één klik.** GIVEN een succesvolle Deployment N op production met een RollbackPoint, WHEN een operator "rollback naar vorige" klikt, THEN openbuilt herstelt de manifest + schemas van Deployment N-1 binnen 60 seconden, behoudt data-mutaties die sindsdien op gedeelde velden zijn gedaan (forward-compatibele velden), en logt de rollback als nieuwe AuditEntry met motivatie-verplicht.

**REQ-007: Pre-promote test-gates.** GIVEN een PromoteRequest met naar_env=staging of production, WHEN ingediend, THEN openbuilt draait eerst de aan het manifest gekoppelde tests (schema-validatie, smoke-tests, e2e-flows), legt resultaten vast op de PromoteRequest, en blokkeert promote indien tests falen tenzij een approver expliciet overrult met motivatie (opgeslagen in audit).

**REQ-008: Volledig audit-spoor per environment.** GIVEN een willekeurige promote, rollback, configwijziging of deployment, WHEN deze plaatsvindt, THEN openbuilt schrijft een AuditEntry met actor, actie, env, artifact-hash, motivatie en timestamp; het audit-log is per-app filterbaar, exporteerbaar (CSV/JSON), en gedurende minimaal 7 jaar bewaard voor production-events.

## Standards

- **OpenSpec ADR-019** — pluggable integration registry (voor env-specifieke integration-config).
- **OCI image-spec** — voor het onderliggende packaging-model van DeploymentArtifact.
- **SemVer 2.0.0** — versienummering van artifacts.
- **SLSA v1.0** — supply-chain levels voor signed artifacts.
- **OWASP ASVS** — application-security verificatie van environment-isolatie.
- **NEN 7510 / ISO 27001** — toegang en audit op productie-environments.

## Cross-app

- **openregister** — Environment / DeploymentArtifact / Deployment / AuditEntry schemas; registers per environment geïsoleerd via tenant-scoping.
- **openconnector** — environment-aware bronnen (test-API-endpoint vs prod-API-endpoint zijn aparte source-records gekoppeld aan environment).
- **decidesk** — formele goedkeuring van first-time production-promote (compliance-besluit).
- **docudesk** — audit-log archivering met retentie 7 jaar.
- **hydra / nextcloud-vue** — UI-componenten voor promote-wizard, diff-view, rollback-confirm.

## Target users

- **Citizen developer (app-auteur)** — bouwt in dev, klikt promote-naar-test.
- **Environment owner / IT-coördinator** — keurt promotes naar staging/production goed, bewaakt audit.
- **Eindgebruiker** — werkt op production zonder schokken bij rollback.
- **Compliance officer / FG** — verifieert audit-spoor en data-isolatie tussen omgevingen.
- **DevOps / platformbeheerder** — provisiont environments, beheert secrets-vault, bewaakt resource-gebruik per env.
- **Security officer** — ziet wie wanneer wat heeft gepromoot; controleert overrules van failing tests.
