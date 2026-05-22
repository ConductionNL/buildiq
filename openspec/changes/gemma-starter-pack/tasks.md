## 1. Meta-schemas en OpenRegister setup

- [ ] 1.1 **Create `lib/Settings/gemma_starter_pack_register.json`**
  - spec_ref: design.md §Decisions 2, 3
  - files: `lib/Settings/gemma_starter_pack_register.json` (NEW)
  - Content: OpenAPI 3.0 + x-openregister extensions definiendo cuatro meta-schemas:
    - `template_pack` (slug, naam, versie, gemma_versie, forum_standaarden, bevat_schemas/pages/workflows/rollen, verplichte/optionele sources, taal_set)
    - `template_installation` (template_pack_id FK, application_id FK, geïnstalleerd_op/door, geïnstalleerde_versie, customisaties JSON, conformiteit_score, conformiteit_laatste_check)
    - `template_conformity_rule` (template_pack_id FK, regel_code, omschrijving, severity, check_type, check_config, bron_norm)
    - `gemma_referentielijst` (lijst_code, versie, entries JSON, bron_url, laatst_gesynchroniseerd_op)
  - Seed data per Design.md §Seed Data: 5 template_pack entries (zaakintake, klacht, subsidie, mor, kto), 20+ template_conformity_rule entries, 8+ gemma_referentielijst entries
  - acceptance_criteria: register validates tegen OpenAPI 3.0 schema; PHPUnit fixture loads zonder error; seed-data is idempotent (re-import slaat duplicaten over per slug)

- [ ] 1.2 **Create seed-data JSON files**
  - spec_ref: design.md §Seed Data
  - files: `lib/Resources/templates/template_pack_seed.json`, `template_conformity_rules_seed.json`, `gemma_referentielijsten_seed.json` (NEW)
  - Content: complete seeding payloads voor alle meta-schemas, Dutch values, realistic GEMMA references
  - acceptance_criteria: valid JSON; load via ConfigurationService fixture tests

## 2. Per-template canonieke datamodellen (schemas)

- [ ] 2.1 **Create zaakintake-formulier schema**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 3
  - files: `lib/Resources/templates/schemas/zaakintake_schema.json` (NEW)
  - Content: JSON Schema definition voor `dienstverleningsverzoek`: verzoek_nummer (auto DV-{yyyy}-{seq}), verzoek_type_code (FK), aanvrager_type, BSN (encrypted), KvK_nummer, naam_voornaam, naam_achternaam, e_mail, telefoonnummer, correspondentie_adres (BAG-link), omschrijving_verzoek, bijlagen (file_reference array), ingediend_op, status, procest_zaak_id, digid_session_id
  - Validations: BSN encrypted-at-rest (AES-256-GCM per ADR-001)
  - acceptance_criteria: validates tegen JSON Schema spec; migration test seeds 3 example records (3 verschillende aanvrager_type scenarios)

- [ ] 2.2 **Create klachtformulier schema**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 3
  - files: `lib/Resources/templates/schemas/klacht_schema.json` (NEW)
  - Content: JSON Schema voor `klacht`: klacht_nummer (auto KL-{yyyy}-{seq}), klacht_type_code (FK vng_klachttypen), klager_anoniem, klager_bsn (encrypted, nullable), klager_naam, klager_contact_e_mail, klager_contact_telefoon, betreft_onderwerp, betreft_organisatie_onderdeel_id, omschrijving_klacht (≥50 char), gewenste_oplossing, bijlagen, ingediend_op, awb_termijn_dagen (default 42, ≤84), status, afdoening_brief_id
  - Validations: awb_termijn_dagen default 42, max 84 (Awb art. 9:11)
  - acceptance_criteria: JSON Schema valid; migration seeds 3 records (anoniem variant, corporate variant, etc.)

- [ ] 2.3 **Create subsidie-aanvraag schema**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 3
  - files: `lib/Resources/templates/schemas/subsidie_aanvraag_schema.json` (NEW)
  - Content: JSON Schema voor `subsidie_aanvraag`: aanvraag_nummer, regeling_code (FK), aanvrager_rechtsvorm (enum), KvK_nummer, bestuurssamenstelling (JSON array), aangevraagd_bedrag (decimal), subsidiabel_doel, start_activiteit_datum, eind_activiteit_datum, begroting_bijlage_id, co_financiering (JSON), verklaring_de_minimis (bool), verklaring_anbi (bool), verklaring_groepsregeling (bool), bijlagen, ingediend_op, status, verleningsbeschikking_id, vaststellingsbeschikking_id
  - Validations: verklaring_de_minimis required voor subsidies < 200K EUR
  - acceptance_criteria: JSON Schema valid; migration seeds 3 records (stichting, natuurlijk persoon, coöperatie)

- [ ] 2.4 **Create MOR (Melding Openbare Ruimte) schema**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 3
  - files: `lib/Resources/templates/schemas/mor_melding_schema.json` (NEW)
  - Content: JSON Schema voor `mor_melding`: melding_nummer (auto MOR-{yyyy}-{seq}), categorie_code (FK vng_mor_categorieën), subcategorie_code, locatie_geo (geojson Point/LineString), locatie_bag_adres_id (nullable), locatie_omschrijving, foto_bijlagen, omschrijving, melder_anoniem, melder_naam, melder_contact_e_mail, melder_contact_telefoon, melding_kanaal (enum), prio (enum), gemeld_op, status, opgelost_op, terugmelding_aan_melder
  - Validations: locatie_geo must within commune-boundary (geofence check op gemeente-orgaan polygon)
  - acceptance_criteria: JSON Schema valid; migration seeds 3 records (verschillende categorieën en locaties)

- [ ] 2.5 **Create KTO (Klant-tevredenheidsonderzoek) schemas**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 3
  - files: `lib/Resources/templates/schemas/kto_uitnodiging_schema.json`, `kto_response_schema.json` (NEW)
  - Content: 
    - `kto_uitnodiging`: uuid, gerelateerd_zaak_id (FK procest), zaaktype_code, verstuurd_aan_e_mail, verstuurd_op, unieke_response_token, response_ontvangen
    - `kto_response`: uuid, uitnodiging_id (FK), gemeente_orgaan_id, zaaktype_code, ingevuld_op, score_bereikbaarheid/deskundigheid/snelheid/resultaat (1-10), score_totaal (auto-calc), nps_score (-100..+100), toelichting, pseudonimisatie_token (sha256)
  - Validations: pseudonimisatie_token 1-way hash (nooit BSN/e-mail raw opgeslagen)
  - acceptance_criteria: JSON Schema valid; migration seeds 3 kto_uitnodiging + 8 kto_response records (varied scores, anoniem responses)

## 3. Template-pack registration service

- [ ] 3.1 **Create `lib/Service/TemplatePackService.php`**
  - spec_ref: proposal.md §What Changes 1–3, design.md §Decision 4
  - files: `lib/Service/TemplatePackService.php` (NEW)
  - Public methods:
    - `getAvailablePacks(): array` — returns all 5 `template_pack` records from OR
    - `installPack(string $packSlug, string $applicationId, array $options): TemplateInstallationDto` — one-click install logic per Decision 4:
      1. Pre-flight check: validate verplichte sources aanwezig op app
      2. Create schemas (bijv. 1 schema per pack: dienstverleningsverzoek, klacht, etc.)
      3. Create pages (bijv. 3 pages zaakintake: form, confirmation, track)
      4. Create workflows (bijv. Awb-termijn tracking workflow)
      5. Create RBAC-rollen (burger, intaker, etc.)
      6. Seed referentielijsten
      7. Create template_installation record + run conformity check → score
      8. Return installation DTO with timing (must be < 60s)
    - `validatePreFlight(string $packSlug, string $applicationId): array` — returns [] on success or error array listing missing sources
  - Constructor-injected: `ObjectService`, `SchemaService`, `WorkflowEngineRegistry`, `AuthorizationService`, `ConfigurationService`, `LoggerInterface`
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests for happy path (all 5 packs), pre-flight validation, 60s timing assertion, rollback-on-error (delete all installed artifacts)

## 4. Template-conformity validation service

- [ ] 4.1 **Create `lib/Service/TemplateConformityService.php`**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 6
  - files: `lib/Service/TemplateConformityService.php` (NEW)
  - Public methods:
    - `validateInstallation(string $installationId): ConformityReportDto` — per Decision 6:
      1. Load template_pack + template_conformity_rules
      2. For each rule: execute check (check_type + check_config)
      3. Count passes/failures
      4. Compute score = passes / total * 100
      5. Update template_installation.conformiteit_score + conformiteit_laatste_check
      6. Return report with breakdown per rule (groen/oranje/rood)
    - `check_schema_field_required(schema, field, condition?)` — validates field present
    - `check_schema_field_type(schema, field, expected_type)` — validates field type
    - `check_source_configured(sourceSlug)` — validates openconnector source exist + reachable
    - `check_rbac_role_present(roleSlug)` — validates RBAC-role exist on app
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests for each check_type; compliance scoring logic verified; report DTO serializes to JSON

- [ ] 4.2 **Create scheduled conformity-check job**
  - spec_ref: proposal.md §What Changes 2, design.md §Decision 6
  - files: `lib/BackgroundJob/TemplateConformityCheckJob.php` (NEW)
  - Run daily (default: 03:00 UTC), loopt over alle template_installation records, roept TemplateConformityService per installatie
  - Bij score dip (e.g., 100 → 92): generates admin-notificatie met impact-summary
  - acceptance_criteria: job runs via Nextcloud's background-job scheduler; admin receives notification when conformiteit dips

## 5. Referentielijst synchronisatie

- [ ] 5.1 **Create `lib/Service/ReferentielijstSyncService.php`**
  - spec_ref: proposal.md §What Changes 3, design.md §Decision 7
  - files: `lib/Service/ReferentielijstSyncService.php` (NEW)
  - Public method:
    - `syncReferentielijsten(array $listCodes = null): array` — per Decision 7:
      1. For each `gemma_referentielijst` (or specified codes):
      2. Fetch huidige entries van bron_url
      3. Diff tegen opgeslagen entries (added, removed, modified)
      4. Bij wijziging: update OR record, generate diff-summary
      5. Return array van wijzigingen per lijst
  - Generates admin-notificatie met diff-modal ("3 nieuwe MOR-categorieën toegevoegd")
  - Constructor-injected: `ObjectService`, `HttpClient`, `NotificationService`, `LoggerInterface`
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests mock HTTP sources, verify diff-detection, notification generation

- [ ] 5.2 **Create scheduled sync job**
  - spec_ref: proposal.md §What Changes 3, design.md §Decision 7
  - files: `lib/BackgroundJob/ReferentielijstSyncJob.php` (NEW)
  - Run daily (default: 04:00 UTC), delegates to ReferentielijstSyncService
  - On failure: logged + admin notificatie
  - acceptance_criteria: job runs via scheduler, logs successful + failed syncs

## 6. Customisatie-overlay system

- [ ] 6.1 **Create `lib/Service/CustomizationService.php`**
  - spec_ref: proposal.md §What Changes 4, design.md §Decision 5
  - files: `lib/Service/CustomizationService.php` (NEW)
  - Public methods:
    - `applyCustomization(installationId, patch)` — applies RFC 6902 JSON Patch to schema/page/workflow, stores in template_installation.customisaties
    - `resolveConflict(installationId, patchFromUpgrade, userChoice)` — handles Decision 5 conflict scenario (choose accept+merge, skip-upgrade, or see-diff)
    - `getCustomizationDiff(installationId): array` — returns diff of current state vs vanilla template
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests patch application, conflict-resolution logic, idempotency on re-apply

## 7. Template-upgrade + promotion

- [ ] 7.1 **Create `lib/Service/TemplateUpgradeService.php`**
  - spec_ref: proposal.md §What Changes 4, design.md §Decision 5
  - files: `lib/Service/TemplateUpgradeService.php` (NEW)
  - Public method:
    - `upgradeInstallation(installationId, newPackVersion, strategy)` — checks for conflicts, applies upgrade per Decision 5:
      1. Load current customisaties (JSON patches)
      2. Detect conflicts (upgrade touches customized field?)
      3. If conflict: show admin conflict-modal, await choice
      4. Apply upgrade patches
      5. Merge customizations back (RFC 6902 re-apply post-upgrade)
      6. Re-run conformity check
  - strategy: "accept_merge" | "skip" | "accept_and_reset_customization"
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests upgrade scenarios (no conflict, conflict-resolved, skip), customization preservation verified

## 8. Localization (i18n) + gemeente-jargon overlay

- [ ] 8.1 **Create i18n-overlay service**
  - spec_ref: proposal.md §What Changes 5, design.md §Decision 12
  - files: `lib/Service/LocalizationOverlayService.php` (NEW)
  - Public method:
    - `applyOverlay(installationId, locale, overlayKeys)` — applies gemeente-jargon (e.g., "burger" → "inwoner Amsterdam") on top of basis NL/EN
    - `getFallbackLabel(templateId, labelKey, locale)` — returns basis-label if overlay absent, logs fallback in debug
  - Constructor-injected: `ObjectService`
  - acceptance_criteria: PHPUnit tests overlay application, EN-fallback logic

- [ ] 8.2 **Create i18n seed data**
  - spec_ref: design.md §Seed Data
  - files: `l10n/en.json`, `l10n/nl.json` (NEW or MODIFIED)
  - Content: all template labels (page titles, form labels, validation messages, status enums, error messages) in NL + EN
  - acceptance_criteria: completeness check (all hardcoded strings translated), JSON valid

## 9. DigiD/eHerkenning integration

- [ ] 9.1 **Create `lib/Service/AuthenticationPrefillService.php`**
  - spec_ref: proposal.md §What Changes 6, design.md §Decision 8
  - files: `lib/Service/AuthenticationPrefillService.php` (NEW)
  - Public method:
    - `prefillFromDigiD(digiDToken, targetSchema)` — per Decision 8:
      1. Extract BSN from DigiD-token
      2. Call BRP-API via openconnector BRP source
      3. Return prefill DTO: { bsn, naam_voornaam, naam_achternaam, geboortedatum, correspondentie_adres, verifiedBy: "BRP" }
    - `prefillFromEHerkenning(eherkToken, targetSchema)` — per Decision 8:
      1. Extract KvK from eHerk-token
      2. Call KvK-API
      3. Return prefill DTO: { kvk_nummer, organisatienaam, verifiedBy: "KvK" }
  - Constructor-injected: `OpenconnectorService`
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests mock DigiD + eHerk tokens, prefill DTO correct; integration test hits real openconnector source (or mock)

## 10. Procest handoff

- [ ] 10.1 **Create `lib/Service/ProcestHandoffService.php`**
  - spec_ref: proposal.md §What Changes 7, design.md §Decision 9
  - files: `lib/Service/ProcestHandoffService.php` (NEW)
  - Public method:
    - `createZaakOnSubmit(formulierInstanceId, templateSlug)` — per Decision 9:
      1. Load formulier-instance + template-pack
      2. Resolve vng_zaaktype_mapping (verzoek_type_code / regeling_code / klacht_type → zaaktype code)
      3. POST procest API: POST /api/zaken với zaaktype + initiator + omschrijving + bijlagen
      4. Capture procest_zaak_id + send email to indiener
      5. On timeout: enqueue retry via n8n (3x exponential backoff, max 24u)
  - Constructor-injected: `HttpClient`, `ObjectService`, `NotificationService`, `N8nService` (or queue), `LoggerInterface`
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests zaak-creation, retry-queue logic, email-dispatch (mock); integration test hits procest API (or mock)

- [ ] 10.2 **Create retry-queue integration**
  - spec_ref: proposal.md §What Changes 7, design.md §Decision 9
  - files: `lib/BackgroundJob/ProcestHandoffRetryJob.php` (NEW)
  - Retry up to 3 times with exponential backoff (60s, 300s, 900s)
  - On final failure: admin-notificatie + formulier marked "awaiting-procest-retry"
  - acceptance_criteria: job runs via scheduler, retries succeed/fail correctly, admin notified

## 11. KTO pseudonimisatie + geanonimiseerd dashboard

- [ ] 11.1 **Create `lib/Service/KtoPseudonymizationService.php`**
  - spec_ref: proposal.md §What Changes 8, design.md §Decision 10
  - files: `lib/Service/KtoPseudonymizationService.php` (NEW)
  - Public method:
    - `pseudonymizeResponse(ktoResponseData)` — per Decision 10:
      1. Remove BSN / e-mail / telefoon
      2. Generate pseudonimisatie_token = sha256(e_mail + secret_pepper) via `$config->getSystemValue('pseudonymization_pepper')`
      3. Store token only (not email)
      4. Mark kto_response as pseudonymized
  - Constructor-injected: `IConfig`
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests pseudonymization, token generation, one-way hashing verified

- [ ] 11.2 **Create `lib/Service/KtoDashboardService.php`**
  - spec_ref: proposal.md §What Changes 8, design.md §Decision 10
  - files: `lib/Service/KtoDashboardService.php` (NEW)
  - Public method:
    - `getAggregatedMetrics(zaaktype, periodo, municipalityId)` — per Decision 10 k-anonimiteit:
      1. Query kto_responses aggregated by zaaktype + periode
      2. For each zaaktype group with n < 5: merge into "overig"
      3. Return aggregated scores (avg, median, NPS)
      4. Return no individual respondent data
  - Constructor-injected: `ObjectService`
  - acceptance_criteria: PHPUnit tests k-anonimiteit logic, aggregation correctness

- [ ] 11.3 **Create KTO-dashboard mydash widget**
  - spec_ref: proposal.md §What Changes 8, design.md §Decision 10
  - files: `src/components/KtoDashboardWidget.vue` (NEW)
  - Displays aggregated KTO metrics (scores per zaaktype, NPS distribution, satisfaction trends)
  - Uses KtoDashboardService backend
  - Conforms to mydash widget interface (CnDashboardPage compatible)
  - acceptance_criteria: Vitest mounting test, widget renders metrics correctly

## 12. Audit-trail + AVG-retention

- [ ] 12.1 **Create `lib/Service/TemplateAuditService.php`**
  - spec_ref: proposal.md §What Changes 9, design.md §Decision 11
  - files: `lib/Service/TemplateAuditService.php` (NEW)
  - Public method:
    - `logSubmission(formulierInstanceId, templateSlug, userId)` — logs submission action
    - `logPiiAccess(formulierInstanceId, accessType, userId)` — logs PII-read (BSN, email)
    - `logExport(formulierInstanceId, exportFormat, userId)` — logs export action
    - `getAuditTrail(formulierInstanceId)` — returns audit records
  - Append-only design (no deletes, only inserts)
  - Constructor-injected: `AuditTrailService`, `LoggerInterface`
  - SPDX + EUPL-1.2 docblock
  - acceptance_criteria: PHPUnit tests append-only logging, read-only audit trail verification

- [ ] 12.2 **Create retention-policy scheduler**
  - spec_ref: proposal.md §What Changes 9, design.md §Decision 11
  - files: `lib/BackgroundJob/TemplateRetentionJob.php` (NEW)
  - Runs daily (default: 02:00 UTC per DecisionDecision 11), per template-instance:
    1. Check retention-termin (zaak 7j, KTO 1j, klacht 5j)
    2. On expiry: pseudonymize (erase PII, keep non-PII stats)
    3. Log retention_pseudonymized
    4. Generate monthly admin-rapportage
  - Constructor-injected: `ObjectService`, `KtoPseudonymizationService`, `LoggerInterface`, `NotificationService`
  - acceptance_criteria: PHPUnit tests retention-check per template, pseudonymization called, admin-rapportage generated

## 13. Template-catalogue integration

- [ ] 13.1 **Register five templates in openbuilt-template-catalogue**
  - spec_ref: proposal.md §What Changes 1
  - Modify `openbuilt-template-catalogue` to expose endpoint that returns metadata for:
    1. Zaakintake-formulier
    2. Klachtformulier
    3. Subsidie-aanvraag
    4. Melding Openbare Ruimte
    5. Klant-tevredenheidsonderzoek
  - Each template exposes: slug, naam, omschrijving, gemma_versie, forum_standaarden, preview-link, install-endpoint
  - acceptance_criteria: catalogue lists all 5 templates, install-button wired to TemplatePackService::installPack endpoint

## 14. API endpoints

- [ ] 14.1 **Register template install endpoint**
  - spec_ref: proposal.md §What Changes 1, design.md §Decision 4
  - files: `lib/Controller/TemplatePackController.php` (NEW), `appinfo/routes.php` (MODIFIED)
  - Endpoint: `POST /api/templates/{packSlug}/install`
  - Delegates to `TemplatePackService::installPack()`
  - On success: 201 + installation DTO
  - On pre-flight failure: 422 + error array
  - On error: 500 + rollback details
  - acceptance_criteria: Newman tests install success + failure paths, timing < 60s verified

- [ ] 14.2 **Register conformity-check endpoint**
  - files: `lib/Controller/TemplateConformityController.php` (NEW), `appinfo/routes.php` (MODIFIED)
  - Endpoint: `GET /api/templates/installations/{installationId}/conformity` or trigger `POST`
  - On GET: returns latest conformity report (score + breakdown)
  - On POST: forces immediate conformity-check
  - acceptance_criteria: Newman tests GET + POST, report structure verified

- [ ] 14.3 **Register customization endpoint**
  - files: `lib/Controller/TemplateCustomizationController.php` (NEW), `appinfo/routes.php` (MODIFIED)
  - Endpoints: `POST /api/templates/installations/{installationId}/customize`, `GET /api/templates/installations/{installationId}/customizations`
  - POST applies patch (RFC 6902), triggers re-validation
  - GET returns current customization diff
  - acceptance_criteria: Newman tests apply + retrieve customizations

## 15. Frontend integration

- [ ] 15.1 **Create template-installation dialog**
  - spec_ref: proposal.md §What Changes 1, design.md §Decision 4
  - files: `src/dialogs/InstallTemplateDialog.vue` (NEW)
  - Modal showing:
    1. Template name + description + GEMMA version + Forum Standaarden
    2. Pre-flight check spinner ("Validating required sources...")
    3. Installation progress (Schemas... Pages... Workflows... RBAC... Done)
    4. Success screen with "Test it now" link
  - Calls `POST /api/templates/{packSlug}/install`
  - Conforms to ADR-004 modal isolation (own file under `src/dialogs/`)
  - acceptance_criteria: Vitest mounting test, progress-updates render, timing displayed

- [ ] 15.2 **Create template-installation entry on template-catalogue**
  - spec_ref: proposal.md §What Changes 1
  - Modify openbuilt-template-catalogue list to show install-button per template
  - On click: opens InstallTemplateDialog
  - acceptance_criteria: integration test via browser

- [ ] 15.3 **Create conformity-status indicator on template-installation detail**
  - spec_ref: proposal.md §What Changes 2
  - files: `src/components/TemplateConformityIndicator.vue` (NEW)
  - Shows: conformity-score (0-100) + last-check timestamp + rule breakdown (greed/orange/red)
  - On click rule: shows remediation guidance
  - acceptance_criteria: Vitest test, score display + breakdown verified

- [ ] 15.4 **Create referentielijst-sync notification UI**
  - spec_ref: proposal.md §What Changes 3
  - On sync-diff: admin sees toast + modal with accept/reject buttons
  - "3 neue MOR-categorieën toegevoegd: laadpalen, e-bikes, koolmonoxide"
  - Accept: applies to all installations
  - Reject: skips
  - acceptance_criteria: integration test, modal triggered on mock sync-diff

## 16. Documentation + seed data generation

- [ ] 16.1 **Create template documentation (NL + EN)**
  - spec_ref: proposal.md, proposal.md §Out of scope
  - files: `docs/templates/nl/zaakintake.md`, `klacht.md`, `subsidie.md`, `mor.md`, `kto.md`, `en/*` (NEW)
  - Per-template: use-case, data-model diagram, page-flow diagrams, workflow-trigger explanations, GEMMA + Awb references, customization guide
  - acceptance_criteria: completeness check, link validation, screenshots

- [ ] 16.2 **Create configuration guide (OpenConnector sources, DigiD, procest)**
  - files: `docs/setup/openconnector-setup.md`, `digid-setup.md`, `procest-setup.md` (NEW)
  - Step-by-step: how to configure BAG, BRP, KvK, DigiD sources in openconnector; how to wire procest integration
  - acceptance_criteria: walkthrough tested

- [ ] 16.3 **Seed-data generation task**
  - spec_ref: design.md §Seed Data, ADR-001 seed-data requirement
  - Generate realistic 3-5 example records per template-schema (dienstverleningsverzoek, klacht, etc.)
  - Use Dutch municipality names, valid postcodes, realistic BSN (11-proef), KvK numbers
  - acceptance_criteria: records generated via fixture + loaded successfully via ConfigurationService

## 17. Testing + quality assurance

- [ ] 17.1 **Create PHPUnit tests for all services**
  - spec_ref: Throughout tasks 1–12
  - Coverage: TemplatePackService, TemplateConformityService, ReferentielijstSyncService, CustomizationService, TemplateUpgradeService, AuthenticationPrefillService, ProcestHandoffService, KtoPseudonymizationService, TemplateAuditService, LocalizationOverlayService
  - Acceptance criteria: PHPUnit coverage ≥ 80%; `composer check:strict` passes

- [ ] 17.2 **Create Vitest tests for Vue components**
  - spec_ref: Tasks 15.1–15.4
  - Coverage: InstallTemplateDialog, TemplateConformityIndicator, KtoDashboardWidget
  - Acceptance criteria: Vitest passes; `npm run lint` passes

- [ ] 17.3 **Create e2e tests (Playwright)**
  - spec_ref: Throughout
  - Scenarios:
    1. Install zaakintake template → verify pages/schemas present → fill form → submit → procest-zaak created
    2. Customize klacht template → add field → run conformity-check → score updates
    3. Receive KTO-survey → fill scores → response pseudonymized → admin sees aggregated dashboard
  - Acceptance criteria: Playwright tests pass in CI

- [ ] 17.4 **Deduplication check task**
  - spec_ref: design.md §Deduplication Check
  - Document that templates leverage existing OpenBuilt/OpenRegister services (ObjectService, SchemaService, WorkflowEngineRegistry, AuthorizationService, etc.) — no reimplementation
  - No overlap found with existing specs
  - acceptance_criteria: document signed off in commit

## 18. Migration + deployment

- [ ] 18.1 **Create repair step for seed data import**
  - spec_ref: design.md §Seed Data, ADR-001 seed-data requirement
  - files: `lib/Migration/SeedGemmaPack.php` (NEW)
  - Imports template_pack + template_conformity_rule + gemma_referentielijst seed data on app install
  - Idempotency: re-run skips existing records (match by slug via ObjectService search)
  - acceptance_criteria: fresh install loads all seed data; re-run is idempotent

- [ ] 18.2 **Update appinfo/routes.php**
  - files: `appinfo/routes.php` (MODIFIED)
  - Register all new controller endpoints from Task 14
  - acceptance_criteria: route registration verified in Newman tests

## 19. Deduplication check

- [ ] 19.1 **Audit for service duplication**
  - spec_ref: design.md §Deduplication Check
  - Verify no reimplementation of existing functionality
  - Document which existing OpenBuilt/OpenRegister services are leveraged per template
  - acceptance_criteria: document findings, no new code duplicates existing patterns

## 20. Final integration + smoke tests

- [ ] 20.1 **Full-stack smoke test: Install template → customize → check conformity → upgrade**
  - Install zaakintake template on fresh app
  - Customize: add a field
  - Run conformity-check → verify score present
  - Simulate template-pack version upgrade → verify customization preserved
  - Clean up
  - acceptance_criteria: all steps succeed, timing < 5 min for full flow

- [ ] 20.2 **All 5 templates installable one-click**
  - For each template (zaakintake, klacht, subsidie, mor, kto): install on separate fresh app, verify install succeeds, test one page
  - acceptance_criteria: all 5 installable + working
