---
kind: feature
depends_on: [openbuilt-schema-designer, openbuilt-page-designer, openbuilt-workflow-designer, openbuilt-rbac]
---

## Why

Nederlandse overheidsorganisaties (gemeenten, provincies, waterschap, uitvoeringsorganisaties) bouwen jaarlijks honderden formulieren, registers en zaakprocessen voor burgerdiensten (klantcontact, vergunningen, subsidies, klachten, meldingen, participatie). De GEMMA (Gemeentelijke Model Architectuur) referentie-architectuur van VNG Realisatie definieert al jaren de gegevenscatalogus, bedrijfsfuncties, standaardprocessen en referentiecomponenten, plus de Forum Standaardisatie pas-toe-of-leg-uit lijst met verplichte open standaarden (NL API Strategie, NL GOV, BAG, BRK, KvK, BSN, DigiD, eIDAS). In de praktijk zijn deze standaarden onbereikbaar voor citizen developers en policy-medewerkers — te complex, te abstract, geen kant-en-klare bouwblokken.

Deze spec levert het **GEMMA Starter Pack**: een curated bibliotheek van vijf direct-installeerbare OpenBuilt-app-templates die de meest voorkomende overheids-use-cases dekken. Elke template is volledig geconfigureerd conform GEMMA en Forum Standaardisatie:

1. **Zaakintake-formulier** — generiek dienstverleningsverzoek met BSN/KvK-validatie
2. **Klachtformulier** — Wob/Awb-conforme klachtenregeling (42-daagse termijn)
3. **Subsidie-aanvraag** — Awb-conforme aanvraag met bijlagen + procest-zaaktype handoff
4. **Melding Openbare Ruimte (MOR)** — kapotte stoep, zwerfafval, etc. met BAG-locatie en GIS-kaart
5. **Klant-tevredenheidsonderzoek (KTO)** — post-dienstverlening met geanonimiseerd dashboard

Elke template bevat de volledige configuratie: schemas, page-designer pagina's, workflow-designer flows, RBAC-rollen, integratie-configuraties (BAG, KvK, BRP, DigiD, openconnector sources), seed-data referentielijsten (productcatalogus, klachttypen, KTO-dimensies), en minimale documentatie (gebruikershandleiding NL/EN, configuratiegids). One-click install vanuit openbuilt-template-catalogue, daarna direct customizeerbaar per gemeente.

## What Changes

### 1. Meta-schemas voor template-pack management

**NEW** vier meta-register-schemas in `openbuilt/gemma-starter-pack/`:

- **template_pack** — beschrijft een installeerbare template met UUID, slug (zaakintake|klacht|subsidie|melding-or|kto), naam, omschrijving, versie (semver), GEMMA-versie, verplichte Forum Standaarden, bevat_schemas/pages/workflows/rollen, verplichte en optionele openconnector sources, gepubliceerd_op, documentatie_url, taal_set (NL/EN).

- **template_installation** — registreert een installatie op een OpenBuilt-app: UUID, FK naar template_pack_id + application_id, geïnstalleerd_op/door, geïnstalleerde_versie, customisaties (JSON diff), conformiteit_score (0-100), conformiteit_laatste_check.

- **template_conformity_rule** — definieert per template_pack validatieregels: UUID, FK naar template_pack, regel_code (GEMMA-ZIK-001, etc.), omschrijving, severity (info|warn|error), check_type (schema_field_required|schema_field_type|page_component_present|workflow_node_present|source_configured|rbac_role_present), check_config (JSON met type-specifieke params), bron_norm (GEMMA/Awb/Woo referentie).

- **gemma_referentielijst** — centraal opgeslagen VNG/CBS referentielijsten: UUID, lijst_code (VNG-ZAAKTYPECATALOGUS, CBS-WONINGEN-AARD, BAG-OBJECTTYPE), versie, entries (JSON array), bron_url, laatst_gesynchroniseerd_op.

### 2. Per-template canonieke datamodellen

**Vijf template-schemas** via OpenBuilt's schema-editor en openregister:

#### Template 1 — Zaakintake-formulier
- **dienstverleningsverzoek**: UUID, verzoek_nummer (auto: DV-{jjjj}-{seq}), verzoek_type_code (FK vng_dienstverleningstypen), aanvrager_type (natuurlijk_persoon|niet_natuurlijk_persoon), BSN (encrypted), KvK_nummer, naam_voornaam, naam_achternaam, e_mail, telefoonnummer, correspondentie_adres (BAG-link), omschrijving_verzoek, bijlagen, ingediend_op, status (concept|ingediend|in_behandeling|gehonoreerd|geweigerd|ingetrokken), procest_zaak_id (FK, gevuld na handoff), digid_session_id (audit).

#### Template 2 — Klachtformulier
- **klacht**: UUID, klacht_nummer (auto: KL-{jjjj}-{seq}), klacht_type_code (FK vng_klachttypen: bejegening|tijdigheid|inhoudelijk|procedure|overig), klager_anoniem, klager_bsn (encrypted, nullable), klager_naam, klager_contact_e_mail, klager_contact_telefoon, betreft_onderwerp, betreft_organisatie_onderdeel_id (FK), omschrijving_klacht (≥50 char), gewenste_oplossing, bijlagen, ingediend_op, awb_termijn_dagen (default 42, max 84), status (ingediend|in_behandeling|gegrond|ongegrond|gegrond_zonder_gevolg|ingetrokken), afdoening_brief_id (FK docudesk).

#### Template 3 — Subsidie-aanvraag
- **subsidie_aanvraag**: UUID, aanvraag_nummer, regeling_code (FK gemeente_subsidieregelingen), aanvrager_rechtsvorm (vereniging|stichting|coöperatie|natuurlijk_persoon|overig), KvK_nummer, bestuurssamenstelling (JSON array), aangevraagd_bedrag, subsidiabel_doel, start_activiteit_datum, eind_activiteit_datum, begroting_bijlage_id, co_financiering (JSON), verklaring_de_minimis, verklaring_anbi, verklaring_groepsregeling, bijlagen, ingediend_op, status (concept|ingediend|in_behandeling|verleend|geweigerd|vastgesteld|teruggevorderd), verleningsbeschikking_id, vaststellingsbeschikking_id.

#### Template 4 — Melding Openbare Ruimte (MOR)
- **mor_melding**: UUID, melding_nummer (auto: MOR-{jjjj}-{seq}), categorie_code (FK vng_mor_categorieën: afval|groen|wegen|water|verlichting|overig), subcategorie_code, locatie_geo (geojson Point/LineString), locatie_bag_adres_id (nullable), locatie_omschrijving, foto_bijlagen, omschrijving, melder_anoniem, melder_naam, melder_contact_e_mail, melder_contact_telefoon, melding_kanaal (web|app|telefoon|balie|email), prio (laag|midden|hoog|spoed), gemeld_op, status (ontvangen|in_behandeling|opgelost|niet_op_te_lossen|doorgestuurd), opgelost_op, terugmelding_aan_melder.

#### Template 5 — Klant-tevredenheidsonderzoek (KTO)
- **kto_uitnodiging**: UUID, gerelateerd_zaak_id (FK procest), zaaktype_code, verstuurd_aan_e_mail, verstuurd_op, unieke_response_token, response_ontvangen.
- **kto_response**: UUID, uitnodiging_id (FK), gemeente_orgaan_id, zaaktype_code, ingevuld_op, score_bereikbaarheid/deskundigheid/snelheid/resultaat (1-10), score_totaal (auto-berekend 1-10), nps_score (-100..+100), toelichting, pseudonimisatie_token (sha256).

### 3. Template-installatie mechaniek

**NEW** template-installatieflow:
- Admin klikt "Installeer" op template in openbuilt-template-catalogue
- Pre-flight check valideert verplichte openconnector sources aanwezig (BAG, DigiD, etc.)
- Progress-modal toont stappen: schemas creëren → pages → workflows → RBAC-rollen → seed-data
- Binnen 60 seconden: alle 5 schemas + 8 pages + 3 workflows + 4 RBAC-rollen + referentielijsten geïnstalleerd
- Success-bevestiging met direct-link naar "Test het formulier"

### 4. GEMMA-conformiteit Validatie

**NEW** scheduled conformity-check job:
- Draait na template-installatie en na customisatie
- Valideert against `template_conformity_rule` records per template
- Geeft conformiteit-score 0-100 met breakdown per regel
- Bij customisatie die verbreekt: warschuwing aan admin met impact (bijv. "BSN-veld ontbreekt — BRP-koppeling werkt niet")

### 5. Seed-data Synchronisatie

**NEW** scheduled sync job:
- Draait dagelijks (default: 03:00 UTC)
- Haalt VNG-referentielijsten bron-URL op (zaaktypecatalogus, klachttypen, MOR-categorieën, etc.)
- Vergelijkt entries, detecteert mutaties, slaat op
- Noticeert admins met diff-modal: "3 nieuwe MOR-categorieën toegevoegd, 0 verwijderd"

### 6. Customisatie zonder update-pad breken

**NEW** overlay-based customisatie storage:
- Customisaties opgeslagen als diff tov vanilla template
- Template-upgrade past vanilla-changes toe zonder customisaties te overschrijven
- Conflict-resolution UI bij overlap (schema-veld dat upgrade vereist maar gemeente heeft verwijderd)

### 7. Multi-taal + gemeente-jargon

**NEW** i18n-overlay system:
- Basis: Nederlands + Engels labels op elke template
- Gemeente-overlay: "burger" → "inwoner Amsterdam" zonder basis-vertaling te raken
- EN-fallback: valt terug op basis-EN label als gemeente NL customisatie heeft zonder EN

### 8. DigiD/eHerkenning integratie

**NEW** authenticatie-prefill:
- Aanvrager klikt "Inloggen met DigiD" → doorloopt DigiD-flow → terug op formulier
- BSN + naam_voornaam/achternaam + geboortedatum + correspondentie_adres voorgevuld uit BRP-call via openconnector
- Gemarkeerd als "geverifieerd door BRP"
- Fallback bij DigiD niet geconfigureerd: optioneel handmatig invullen of blok

### 9. Procest handoff

**NEW** cross-app zaak-creatie:
- Bij submit dienstverleningsverzoek/subsidie/klacht/MOR: status flipt naar "ingediend"
- System creëert procest-zaak according vng_zaaktype_mapping
- procest_zaak_id terug opgeslagen in formulier-instance
- Indiener krijgt e-mail met zaaknummer + portal-link
- Retry-queue via n8n bij procest-API timeout (3x exp-backoff)

### 10. KTO Pseudonimisatie

**NEW** KTO geanonimiseerde data handling:
- kto_response opgeslagen ZONDER BSN/e-mail, alleen hash-token (sha256)
- kto_uitnodiging.response_ontvangen = true bij submit
- Mydash KTO-dashboard met aggregaties per zaaktype/periode zonder respondent traceerbaar (k-anonimiteit: scores alleen als n≥5)

### 11. Audit-trail + AVG-conformiteit

**NEW** append-only audit per formulier:
- Logged: submission, every PII access, every export
- Per-template bewaartermijn-configuratie (default: 7j zaak-gerelateerd, 1j KTO, 5j klacht per Awb)
- Scheduled retention-job: pseudonymiseer na termijn (PII gewist, non-PII metadata bewaard voor statistiek)

### 12. Page-designer vooraf geladen templates

**MODIFIED** openbuilt-page-designer:
- Bij template-installatie zijn alle 8 pages al geconfigureerd (zaakintake 3 pages, klacht 2, subsidie 2, MOR 1)
- Admin kan direct customiseren of publish

### 13. Workflow-designer vooraf geladen flows

**MODIFIED** openbuilt-workflow-designer:
- Bij template-installatie zijn alle workflows reeds ingesteld:
  - Klacht: Awb-termijn-bewaking (42 dagen, extensie tot 84, reminder-notificatie)
  - Subsidie: verleningsbesluit-flow (verleend/geweigerd) met decidesk-integratie, vaststelling
  - MOR: toewijzing aan team per categorie
  - KTO: event-triggered uitnodiging na zaak-completion

### 14. RBAC rollen per template

**MODIFIED** openbuilt-rbac:
- Per template standaard rollen geïnstalleerd: burger, intaker, behandelaar, clusterhoofd, subsidiebeoordelaar, klachtcoördinator
- Admin kan rollen granuleren per gemeente-organisatiestructuur

## Capabilities

### New Capabilities

- `gemma-template-pack-management` — registratie + installatie + conformiteits-controle van vijf templates uit de openbuilt-template-catalogue
- `gemma-one-click-install` — installatie van template (schemas + pages + workflows + RBAC + seed-data) in 60 sec
- `gemma-conformity-validation` — scheduled check + admin-dashboard breakdown per template
- `gemma-referentielijst-sync` — daily sync van VNG-referentielijsten met diff-modals
- `gemma-customisatie-overlay` — storage van gemeente-maatwerk zonder update-pad te breken
- `gemma-i18n-overlay` — gemeente-jargon labels bovenop basis NL/EN
- `gemma-digid-integration` — DigiD/eHerkenning pre-fill voor BSN/KvK + naam
- `gemma-procest-handoff` — automatische zaak-creatie + retry-queue
- `gemma-kto-pseudonimisatie` — geanonimiseerde KTO-responses + k-anoniem dashboard
- `gemma-audit-trail` — append-only logging + AVG-conformiteits-retention

### Modified Capabilities

- `openbuilt-template-catalogue` — registreert 5 templates voor één-klik installatie
- `openbuilt-page-designer` — templates pre-loaded met alle pages
- `openbuilt-workflow-designer` — templates pre-loaded met alle flows
- `openbuilt-rbac` — templates pre-loaded met standaard-rollen
- `openbuilt-schema-editor` — templates pre-loaded met alle schemas

## Cross-app Integration

- **openbuilt-template-catalogue** — 5 templates geregistreerd + install-endpoint
- **openbuilt-schema-designer, -page-designer, -workflow-designer, -rbac** — templates voorzien van pre-geladen artifacts
- **procest** — zaak-creatie handoff doel
- **openconnector** — BAG, BRP, KvK, DigiD, eHerkenning sources
- **decidesk** — subsidie-verleningsbesluit integratie
- **docudesk** — afdoeningsbrief + KTO-uitnodiging + verleningsbeschikking generatie
- **mydash** — KTO-dashboard widget
- **n8n** — retry-queue + email-routing
- **opencatalogi** — GEMMA-gegevenscatalogus URIs (beleidsdoel, taakgebied, referenties)

## Out of scope

- Vervolg-templates voor specifieke domeinen (gemma-vergunningen-pack, gemma-omgevingsvergunning-pack, etc.) — separate changes
- Enterprise-koppelingen aan legacy zaaksystemen — via openconnector + procest
- Volledige BRK/BRP/KvK-API gateway — via openconnector sources
- Community-customisatie federatie — toekomstige uitbreiding (REQ-009)
- DPIA + privacyimpactanalyses per template — buiten scope spec, wel in implementatie-ondersteuning
