## Context

De GEMMA-referentie-architectuur van VNG Realisatie beschrijft standaard gegevenscatalogi, bedrijfsfuncties, processen en open standaarden (Forum Standaardisatie pas-toe-of-leg-uit: BAG, BRK, KvK, DigiD, eIDAS). Voor overheidsorganisaties is het implementeren hiervan via OpenBuilt vandaag niet toegankelijk — citizen developers missen kant-en-klare bouwblokken. Deze spec levert vijf direct-installeerbare templates die de meest voorkomende use-cases dekken (zaakintake, klachtformulier, subsidie-aanvraag, MOR, KTO), elk volledig geconfigureerd conform GEMMA + Forum Standaarden, installeerbaar in 60 seconden via één klik.

## Goals / Non-Goals

**Goals:**

- Vijf product-ready templates voorzien via openbuilt-template-catalogue die één-klik installeerbaar zijn
- Elke template bevat volledige configuratie: schemas + pages + workflows + RBAC-rollen + seed-data
- GEMMA-conformiteit valideren per template (scheduled check + admin dashboard)
- Template-upgrades toepassen zonder gemeente-customisaties te overschrijven
- VNG-referentielijsten automatisch synchroniseren met diff-notificatie
- DigiD/eHerkenning authenticatie integreren met BSN/KvK-prefill
- Automatische zaak-creatie (procest handoff) bij formulier-submit
- KTO-responses pseudonimiseren + geanonimiseerd dashboard met k-anonimiteit
- Audit-trail + AVG-conforme bewaartermijnen per template
- Multi-taal (NL/EN) + gemeente-jargon-overlay

**Non-Goals:**

- Vervolg-templates voor specifieke domeinen (vergunningen, BIBOB, leerlingenvervoer) — separate changes
- Community-customisatie federatie — toekomstige uitbreiding
- DPIA/privacyimpactanalyses — buiten scope spec
- Volledige BRK/BRP/KvK-API gateway — via openconnector sources
- Enterprise-koppelingen aan legacy zaaksystemen — via openconnector + procest

## Decisions

### Decision 1 — Vijf templates als canonical product

De spec voorziet exact vijf templates, elk gericht op een high-frequency overheids-use-case:

1. **Zaakintake-formulier** — generiek dienstverleningsverzoek (informatieverzoeken, aanvraagformulieren, contactformulieren)
2. **Klachtformulier** — Awb-conforme klachtenafhandeling met 42-daagse termijn
3. **Subsidie-aanvraag** — Awb-conforme subsidieaanvraag met bijlagen + verleningsbesluit
4. **Melding Openbare Ruimte (MOR)** — burgerbevragingen over openbare ruimte (riolering, straatmeubilair, groen)
5. **Klant-tevredenheidsonderzoek (KTO)** — post-dienstverlening met geanonimiseerd dashboard

Deze vijf dekken ~80% van gemeentelijke formulieren die vandaag buiten OpenBuilt worden gebouwd. Verdere templates (vergunningen, BIBOB, leerlingenvervoer) worden als separate packs uitgeleverd.

### Decision 2 — Meta-schemas voor template-lifecycle

Vier meta-schemas beheren de template-levenscyclus (installatie, conformiteits-controle, customisatie-tracking):

- **template_pack** — beschrijft template: naam, schemas, pages, workflows, RBAC-rollen, verplichte sources, versie, GEMMA-versie, taal-set, documentatie-URL
- **template_installation** — registreert installatie: template + app + installatie-datum/door, geïnstalleerde versie, customisatie-diff, conformiteit-score
- **template_conformity_rule** — definieert per-template validatieregels (GEMMA-ZIK-001: "BSN MUST present voor natuurlijk persoon", severity, check-type)
- **gemma_referentielijst** — centraal opgeslagen VNG/CBS referentielijsten: zaaktypecatalogus, klachttypen, MOR-categorieën, dienstverleningstypen (sync-doel)

Reden: elk template moet GEMMA-conformiteit kunnen rapporteren naar admins en Forum Standaardisatie; customisaties moeten traceerbaar zijn zodat upgrades veilig kunnen worden gepubliceerd.

### Decision 3 — Canonieke datamodellen per template

Elk template beschrijft de canonieke schema-shape conform GEMMA-gegevenscatalogus:

- **Zaakintake**: dienstverleningsverzoek (verzoek_type_code, aanvrager_type/BSN/KvK, status, procest_zaak_id voor handoff)
- **Klacht**: klacht (klacht_type, klager_anoniem/BSN, betreft_organisatie, awb_termijn_dagen, status, afdoening_brief_id)
- **Subsidie**: subsidie_aanvraag (regeling_code, aanvrager_rechtsvorm/KvK, aangevraagd_bedrag, verklaring_de_minimis/anbi/groepsregeling, status)
- **MOR**: mor_melding (categorie_code, subcategorie, locatie_geo/BAG, melder_anoniem, prio, status)
- **KTO**: kto_uitnodiging + kto_response (gerelateerd_zaak, scores 1-10, nps, pseudonimisatie_token)

Alle PII-velden (BSN, e-mail, telefoonnummer) encrypted-at-rest (AES-256-GCM); export-API geeft `***1234` aan non-superusers.

### Decision 4 — Installatie-automatie: 60 seconden

Template-installatie uitgevoerd als atomaire backend-call:

1. Validate pre-flight (verplichte sources aanwezig?)
2. Create 5 schemas + 8 pages + 3 workflows + 4 RBAC-rollen in target app
3. Seed referentielijsten (zaaktypecatalogus, klachttypen, MOR-categorieën)
4. Create template_installation record
5. Run conformiteit-check → score 100 (vanilla template)

Timing: <60 sec target. Admin ziet progress-modal; bij success: "Test het formulier" direct-link.

### Decision 5 — Customisatie als overlay, niet overschrijving

Gemeente-maatwerk (extra velden, workflow-aanpassingen, jargon) opgeslagen als **diff** tov vanilla template, niet als volledige vervanging. Voordeel:

- Template-upgrade voegt vanilla-changes toe zonder customisatie te wissen
- Conflict-resolution UI toon wanneer upgrade een customization raakt
- Admin kan "accepteer upgrade + behoud customisatie" of "skip deze upgrade" kiezen

Implementatie: `customisaties` JSON op `template_installation` bevat [ { "path": "schemas/klacht/properties/klacht_type_code", "op": "add" | "remove" | "replace", "value": ... } ] (RFC 6902 JSON Patch format).

### Decision 6 — Conformiteit-validatie scheduled + dashboard

Elke 24u (default: 03:00) draait een scheduled job per `template_installation`:

1. Load template_pack + template_conformity_rules
2. Voor elke regel (check_type, check_config): valideer current state van app
3. Count greens + reds → conformiteit_score (percentage checks passing)
4. Bij score dip: admin-notificatie "Customisatie verbreekt 1 GEMMA-vereiste (error): BSN-veld ontbreekt"

Dashboard toont per template:
- Score 0-100 met breakdown per regel (groen/oranje/rood)
- Last check timestamp
- Remediation-links per failed rule

Reden: admins moeten zien of hun customisaties de GEMMA-conformiteit bedreigd hebben; Forum Standaardisatie kan gestandaardiseerde rapportage ophalen.

### Decision 7 — VNG-referentielijsten synchronisatie

Scheduled sync (daily 04:00) haalt bron-URLs op per `gemma_referentielijst`:

1. Fetch huidige versie van VNG Zaaktypecatalogus, CBS MOR-classificaties, etc.
2. Diff tegen opgeslagen entries
3. Op mutatie: admin-notificatie met modal "3 nieuwe MOR-categorieën: laadpalen, e-bikes, koolmonoxide. Toepassen?"
4. Admin klikt accept → entries bijgewerkt, todas reset op alle installations van deze template

Reden: referentielijsten evolueren (VNG publiceert jaarlijks updates); gemeenten moeten op-de-hoogte blijven zonder handmatig sync-werk.

### Decision 8 — DigiD/eHerkenning prefill

Bij zaakintake/subsidie-formulier:

- Burger klikt "Inloggen met DigiD" → doorloopt DigiD-flow (via openconnector DigiD-source)
- Post-login: BSN → BRP-API call → prefill naam_voornaam, naam_achternaam, geboortedatum, correspondentie_adres
- Velden gemarkeerd "geverifieerd door BRP", read-only (tenzij admin configureert anders)
- Fallback: DigiD niet geconfigureerd → notificatie "Authenticatie niet beschikbaar — vul handmatig in" (of blok indien `bsn_required`)

Voor organisaties (subsidie, KvK-lookup): eHerkenning-flow → KvK-API call → KvK_nummer + organisatienaam.

### Decision 9 — Procest handoff op formulier-submit

Bij submit zaakintake/subsidie/klacht/MOR → status "ingediend" + procest-zaak creatie:

1. Bepaal `vng_zaaktype_mapping` voor verzoek_type_code / regeling_code / klacht_type (configureerbaar per template_installation)
2. POST procest API: zaaktype + initiator + omschrijving + bijlagen
3. Opslaan `procest_zaak_id` op formulier-instance
4. Email indiener: zaaknummer + link naar zaak-portal

Bij procest-timeout: retry-queue via n8n (3x exponential backoff, max 24u); indiener krijgt bevestiging dat "verwerking loopt", admin ziet retry-status.

### Decision 10 — KTO pseudonimisatie + k-anoniem dashboard

KTO-response-flow:

1. Burger ontvangt email "Kunt u ons helpen met feedback?" met unieke response-token
2. Vult scores (bereikbaarheid, deskundigheid, etc.) + NPS + toelichting
3. Response opgeslagen **zonder** BSN/e-mail, alleen hash-token (`pseudonimisatie_token = sha256(e_mail + secret_pepper)`)
4. `kto_uitnodiging.response_ontvangen = true`

Dashboard (mydash):
- Aggregatie per zaaktype + periode
- K-anonimiteit: alleen tonen als n≥5 (anders "overig")
- Scores gemiddeld, NPS, sentimentanalyse
- Geen individuele respondent traceerbaar

### Decision 11 — Audit-trail + AVG-retention

Elke template-instance voert append-only audit:

- submission (wat ingediend, door wie, wanneer)
- PII-access (wie las BSN/e-mail, wanneer, via welke API)
- export (wie exporteerde, wat, wanneer)

Bewaartermijnen configureerbaar per template (defaults):
- Zaakintake / Klacht / Subsidie: 7 jaar (Awb zaak-retention)
- KTO: 1 jaar (anoniem dashboard, geen persoonsgegevens na pseudonimisatie)
- Scheduled retention-job: bij einde termijn → pseudonimiseer (PII-velden gewist, stats bewaard), log "retention_pseudonymized"

### Decision 12 — Multi-taal NL/EN + gemeente-jargon

Basis-labels op elke template (page titles, form labels, validatie-messages) in NL + EN:

- `zaakintake.pages[0].title.nl = "Dienstverleningsverzoek"`
- `zaakintake.pages[0].title.en = "Service Request"`

Gemeente-overlay (per `template_installation`):
- `overlays.i18n.nl = { "label_voornaam": "Hoe heet je?" }`
- Render: overlay-waarde als aanwezig, anders basis-label

EN-fallback: als NL-overlay ingesteld maar EN ontbreekt → valt terug op basis-EN, console-warning (debug mode).

### Decision 13 — Pre-geladen pages, workflows, RBAC

Bij template-installatie zijn alle artifacts al configured (niet leeg):

- **Pages**: zaakintake 3 pages (form, confirmation, track), klacht 2 (form, list-open), subsidie 2 (form, decisions), MOR 1 (form + map), KTO 1 (survey)
- **Workflows**: klacht (Awb-termijn tracking, reminder-notificatie), subsidie (decision path → docudesk-generatie), MOR (assign-to-team per categorie), KTO (event-triggered post-zaak)
- **RBAC**: burger, intaker, behandelaar, clusterhoofd, subsidiebeoordelaar, klachtcoördinator (admin kan rollen granuleren)

Admin hoeft niet "Voeg een page toe" — forms zijn direct testbaar, claimable.

## Seed Data

### Meta-schema seed

Drie entries in `template_pack` register:

```json
{
  "@self": { "register": "openbuilt", "schema": "template_pack", "slug": "zaakintake-formulier" },
  "slug": "zaakintake-formulier",
  "naam": "Zaakintake-formulier",
  "omschrijving": "Generiek dienstverleningsverzoek met BSN/KvK-validatie, DigiD/eHerkenning prefill, procest-handoff",
  "versie": "1.0.0",
  "gemma_versie": "GEMMA 2.5.3",
  "forum_standaarden": ["BAG", "BRP", "KvK", "DigiD", "NL_API", "NL_GOV"],
  "bevat_schemas": ["dienstverleningsverzoek"],
  "bevat_pages": ["zaakintake_form", "zaakintake_confirmation", "zaakintake_track"],
  "bevat_workflows": ["zaakintake_procest_handoff"],
  "bevat_rollen": ["burger", "intaker", "behandelaar", "clusterhoofd"],
  "verplichte_sources": ["bag-api", "brp-api", "kvk-api", "digid"],
  "optionele_sources": [],
  "taal_set": ["nl", "en"],
  "documentatie_url": "https://docs.openbuilt.nl/templates/zaakintake"
}
```

Analoog voor: klachtformulier, subsidie-aanvraag, melding-or, kto.

### Template-conformity-rules seed

Entries in `template_conformity_rule` register, e.g.:

```json
{
  "@self": { "register": "openbuilt", "schema": "template_conformity_rule", "slug": "GEMMA-ZIK-002" },
  "regel_code": "GEMMA-ZIK-002",
  "omschrijving": "BSN MUST present op dienstverleningsverzoek voor natuurlijk persoon",
  "severity": "error",
  "check_type": "schema_field_required",
  "check_config": { "schema": "dienstverleningsverzoek", "field": "bsn", "required_for": "aanvrager_type === 'natuurlijk_persoon'" },
  "bron_norm": "GEMMA 2.5.3 Gegevenscatalogus Zaakintake, sectie 4.1.1"
}
```

Per template: 5–10 regels covering veld-aanwezigheid, type-validatie, source-configuratie, RBAC-rolle-aanwezigheid.

### Referentielijst seed

Entries in `gemma_referentielijst` register:

```json
{
  "@self": { "register": "openbuilt", "schema": "gemma_referentielijst", "slug": "VNG-ZAAKTYPECATALOGUS" },
  "lijst_code": "VNG-ZAAKTYPECATALOGUS",
  "versie": "2.5.3",
  "bron_url": "https://www.vng.nl/api/zaaktypecatalogus/v1/zaaktypen",
  "entries": [
    { "code": "B0337", "label": "Informatieverzoek", "categorie": "Dienstverleningszaken" },
    { "code": "B0338", "label": "Klacht behandeling", "categorie": "Klachtzaken" },
    ...
  ],
  "laatst_gesynchroniseerd_op": "2026-05-22T04:00:00Z"
}
```

Per referentielijst: VNG-ZAAKTYPECATALOGUS, VNG-KLACHTTYPEN, VNG-MOR-CATEGORIEËN, CBS-WONINGEN-AARD, etc.

### Template-schema seed (per template)

Bijvoorbeeld zaakintake: één dienstverleningsverzoek-schema record met volledige JSON Schema definition:

```json
{
  "@self": { "register": "openbuilt", "schema": "...", "slug": "dienstverleningsverzoek" },
  "title": "Dienstverleningsverzoek",
  "type": "object",
  "properties": {
    "verzoek_nummer": { "type": "string", "description": "Auto-generated DV-{year}-{seq}" },
    "verzoek_type_code": { "type": "string", "description": "FK vng_dienstverleningstypen" },
    "aanvrager_type": { "enum": ["natuurlijk_persoon", "niet_natuurlijk_persoon"] },
    "bsn": { "type": "string", "description": "Encrypted, only for natuurlijk_persoon" },
    "kvk_nummer": { "type": "string", "description": "For niet_natuurlijk_persoon" },
    ...
  },
  "required": ["verzoek_type_code", "aanvrager_type", "omschrijving_verzoek"]
}
```

Analoog voor klacht, subsidie_aanvraag, mor_melding, kto_uitnodiging, kto_response.

## Reuse Analysis

**Leveraging existing OpenBuilt/OpenRegister services:**

- `ObjectService` — CRUD per template-installatie, template_pack, template_conformity_rule
- `SchemaService` — schema-load/validate voor conformiteits-checks
- `RegisterService` — register-creation per-template (openbuilt-{template_slug})
- `ConfigurationService` — import seed-data (template_pack, conformity_rules, referentielijsten)
- `WorkflowEngineRegistry` — workflow-render per template (Awb-termijn, subsidie-decision, MOR-triage)
- `FileService` — attachment upload/download (bijlagen op zaakintake/klacht/subsidie)
- `AuditTrailService` — logging per template-instance
- `AuthorizationService` — RBAC per template-rollen
- `NotificationService` — admin-notificaties (conformiteit-dip, sync-diff, procest-retry)
- `ImportService` / `ExportService` — admin bulk-export van gemeentelijke responses
- CnDataTable, CnDetailPage, CnFormDialog — UI components voor list + detail + create per template-instance
- CnDashboardPage — KTO-dashboard widget

**No custom reimplementation required:** templates define *what* gets configured (schemas, pages, workflows, RBAC), not *how* — all CRUD, validation, rendering, audit already provided by OpenBuilt/OpenRegister.

## Deduplication Check

**No overlap found with existing specs or services.**

Similar openbuilt-chain specs define the *mechanics* (schema-editor, page-designer, workflow-designer, RBAC); this spec defines the *canonical content* (five product templates + conformity rules + referentielijsten). Cross-app integration (procest, decidesk, docudesk, openconnector) is via existing integration points, not new code.

**Examined:**

- `openbuilt-versioning` / `-schema-editor` / `-page-designer` / `-workflow-designer` / `-rbac` — define tools, not content. ✓ No overlap.
- `openbuilt-template-catalogue` — provides marketplace UX, not templates themselves. This spec populates the catalogue. ✓ No overlap.
- `procest` / `decidesk` / `docudesk` / `openconnector` — integration targets, not template logic. ✓ No overlap.
- Template-specific validation (conformity-rules) — not provided by any existing service. ✓ New capability.
- Referentielijsten sync (VNG sources) — not provided by OpenRegister. ✓ New capability.

**Conclusion:** this spec is pure *domain content* layered atop existing platform capabilities. No refactor needed.
