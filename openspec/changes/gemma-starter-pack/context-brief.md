---
status: draft
---
# GEMMA Starter Pack (Overheid-templates voor OpenBuilt)

## Purpose

Nederlandse gemeenten, provincies en uitvoeringsorganisaties bouwen jaarlijks honderden formulieren, registers en zaakprocessen — voor klantcontact, vergunningen, subsidies, klachten, meldingen en participatie. Vrijwel elke organisatie bouwt deze opnieuw, soms in dure low-code platforms (Mendix, OutSystems), soms in Microsoft Forms/Excel-koppelingen, vaak in PDF-formulieren met handmatige verwerking. De **GEMMA (Gemeentelijke Model Architectuur)** referentie-architectuur van VNG Realisatie definieert al jaren de **gegevenscatalogus**, **bedrijfsfuncties**, **standaardprocessen** en **referentiecomponenten** die hieronder zouden moeten liggen, plus de **Forum Standaardisatie pas-toe-of-leg-uit lijst** met verplichte open standaarden (NL API Strategie, NL GOV, BAG, BRK, KvK, BSN, DigiD, eIDAS). In de praktijk zijn deze standaarden voor een citizen developer of policy-medewerker onbereikbaar — te complex, te abstract, geen kant-en-klare bouwblokken.

Deze spec levert het **GEMMA Starter Pack**: een curated bibliotheek van vijf direct-installeerbare OpenBuilt-app-templates die de meest voorkomende overheids-use-cases dekken, elk volledig geconfigureerd conform GEMMA en Forum Standaardisatie. De vijf templates: **zaakintake-formulier** (generiek dienstverleningsverzoek met BSN/KvK-validatie), **klachtformulier** (Wob/Awb-conforme klachtenregeling), **subsidie-aanvraag** (Awb-conforme subsidieaanvraag met bijlagen + procest-zaaktype-handoff), **melding openbare ruimte** (MOR — kapotte stoep, zwerfafval, etc., met BAG-locatie en GIS-kaart), **klant-tevredenheidsonderzoek** (KTO post-dienstverlening met geanonimiseerd dashboard). Elke template bevat de schemas, page-designer pagina's, workflow-designer flows, RBAC-rollen, integratie-configuraties (BAG, KvK, BRP, DigiD, openconnector sources), seed-data referentielijsten (productcatalogus, klachttypen, klanttevredenheidsdimensies), én een minimale documentatie-set (gebruikershandleiding NL/EN, configuratiegids). One-click install vanuit openbuilt-template-catalogue, daarna customiseerbaar per gemeente.

De spec dekt: (a) de template-installatie-mechaniek (hoe één-klik 5 schemas + 8 pages + 3 workflows + RBAC + seed-data + GEMMA-koppelingen oplevert binnen 60 seconden); (b) per template de canonieke datamodellen (geänkerd in GEMMA-gegevenscatalogus URI's), de page-flows, en de workflow-flows; (c) de **conformiteits-validatie** die controleert of een template bij installatie + na customisatie nog GEMMA-conform is (verplichte velden aanwezig, verplichte standaarden bereikbaar); (d) de **localisatie-strategie** (NL primair, EN secundair, makkelijk uit te breiden per gemeente-jargon); (e) **customisatie-best-practices** zodat een gemeente velden kan toevoegen zonder de update-pad te breken; (f) **upgrade-pad** wanneer VNG een nieuwe GEMMA-versie publiceert of een referentielijst muteert.

Out of scope: vervolg-templates voor specifieke domeinen (vergunningen-APV, BIBOB, leerlingenvervoer — komen in separate `gemma-vergunningen-pack` etc.), enterprise-koppelingen aan legacy zaaksystemen (gaat via openconnector + procest), volledige BRK/BRP/KvK-API gateway (komt via openconnector sources, wordt door templates gebruikt).

## Data Model

Deze spec definieert geen volledig eigen registers (de templates zelf bevatten 5×N schemas), maar wel een **meta-laag** voor template-management, plus de canonieke schema-shapes per template. Vier meta-schemas in `openbuilt/gemma-starter-pack/`:

**template_pack**: `uuid`, `slug` (`zaakintake|klacht|subsidie|melding-or|kto`), `naam`, `omschrijving`, `versie` (semver), `gemma_versie` (e.g. "GEMMA 2.5.3"), `forum_standaarden` (array van standaarden-codes, e.g. ["BAG","KvK","DigiD"]), `bevat_schemas` (JSON array van schema-slugs), `bevat_pages` (array), `bevat_workflows` (array), `bevat_rollen` (array van rbac-rol-slugs), `verplichte_sources` (array — openconnector sources die geconfigureerd moeten zijn), `optionele_sources`, `gepubliceerd_op`, `documentatie_url`, `taal_set` (default ["nl","en"]).

**template_installation**: `uuid`, `template_pack_id` (FK), `application_id` (FK openbuilt app), `geïnstalleerd_op` (datetime), `geïnstalleerd_door_id`, `geïnstalleerde_versie` (semver), `customisaties` (JSON — diff van wijzigingen tov vanilla template), `laatste_upgrade_op`, `upgrade_beschikbaar` (bool, gevuld door scheduled check), `conformiteit_score` (decimal 0-100, percentage GEMMA-vereisten dat bewaard is na customisatie), `conformiteit_laatste_check` (datetime).

**template_conformity_rule**: `uuid`, `template_pack_id` (FK), `regel_code` (e.g. "GEMMA-ZIK-001"), `omschrijving` (text — wat de regel checkt), `severity` (info|warn|error), `check_type` (schema_field_required|schema_field_type|page_component_present|workflow_node_present|source_configured|rbac_role_present), `check_config` (JSON — type-specifieke params), `bron_norm` (text — e.g. "GEMMA 2.5.3 Gegevenscatalogus, sectie 4.2.1 Zaaktype").

**gemma_referentielijst**: `uuid`, `lijst_code` (e.g. "VNG-ZAAKTYPECATALOGUS", "CBS-WONINGEN-AARD", "BAG-OBJECTTYPE"), `versie`, `entries` (JSON array), `bron_url`, `laatst_gesynchroniseerd_op`. Centraal opgeslagen, alle templates verwijzen ernaar.

Per template een canonieke schema-shape (samenvatting; volledige JSON Schema's in `template-catalogue/zaakintake/schemas/`):

**Template 1 — Zaakintake-formulier**
- Schema `dienstverleningsverzoek`: `uuid`, `verzoek_nummer` (auto: `DV-{jjjj}-{seq}`), `verzoek_type_code` (lookup `vng_dienstverleningstypen`), `aanvrager_type` (natuurlijk_persoon|niet_natuurlijk_persoon), `bsn` (encrypted, alleen voor natuurlijk_persoon), `kvk_nummer` (alleen niet_natuurlijk_persoon), `naam_voornaam`, `naam_achternaam`, `e_mail`, `telefoonnummer`, `correspondentie_adres` (BAG-link), `omschrijving_verzoek` (text), `bijlagen` (array file_reference), `ingediend_op`, `status` (concept|ingediend|in_behandeling|gehonoreerd|geweigerd|ingetrokken), `procest_zaak_id` (nullable, gevuld na handoff), `digid_session_id` (audit).

**Template 2 — Klachtformulier**
- Schema `klacht`: `uuid`, `klacht_nummer` (`KL-{jjjj}-{seq}`), `klacht_type_code` (lookup `vng_klachttypen`: bejegening|tijdigheid|inhoudelijk|procedure|overig), `klager_anoniem` (bool), `klager_bsn` (encrypted, nullable), `klager_naam`, `klager_contact_e_mail`, `klager_contact_telefoon`, `betreft_onderwerp` (text), `betreft_organisatie_onderdeel_id` (FK organisations), `omschrijving_klacht` (text, ≥50 char), `gewenste_oplossing` (text), `bijlagen`, `ingediend_op`, `awb_termijn_dagen` (default 42 — Awb art. 9:11), `status` (ingediend|in_behandeling|gegrond|ongegrond|gegrond_zonder_gevolg|ingetrokken), `afdoening_brief_id` (FK docudesk).

**Template 3 — Subsidie-aanvraag**
- Schema `subsidie_aanvraag`: `uuid`, `aanvraag_nummer`, `regeling_code` (lookup `gemeente_subsidieregelingen`), `aanvrager_rechtsvorm` (vereniging|stichting|coöperatie|natuurlijk_persoon|overig), `kvk_nummer`, `bestuurssamenstelling` (JSON array), `aangevraagd_bedrag` (decimal), `subsidiabel_doel` (text), `start_activiteit_datum`, `eind_activiteit_datum`, `begroting_bijlage_id` (file_reference), `co_financiering` (JSON — bron + bedrag), `verklaring_de_minimis` (bool), `verklaring_anbi` (bool), `verklaring_groepsregeling` (bool), `bijlagen`, `ingediend_op`, `status` (concept|ingediend|in_behandeling|verleend|geweigerd|vastgesteld|teruggevorderd), `verleningsbeschikking_id`, `vaststellingsbeschikking_id`.

**Template 4 — Melding Openbare Ruimte (MOR)**
- Schema `mor_melding`: `uuid`, `melding_nummer` (`MOR-{jjjj}-{seq}`), `categorie_code` (lookup `vng_mor_categorieën`: afval|groen|wegen|water|verlichting|overig), `subcategorie_code`, `locatie_geo` (geojson Point of LineString), `locatie_bag_adres_id` (nullable), `locatie_omschrijving` (text), `foto_bijlagen` (array file_reference), `omschrijving` (text), `melder_anoniem` (bool), `melder_naam`, `melder_contact_e_mail`, `melder_contact_telefoon`, `melding_kanaal` (web|app|telefoon|balie|email), `prio` (laag|midden|hoog|spoed), `gemeld_op`, `status` (ontvangen|in_behandeling|opgelost|niet_op_te_lossen|doorgestuurd), `opgelost_op`, `terugmelding_aan_melder` (bool).

**Template 5 — Klant-tevredenheidsonderzoek (KTO)**
- Schema `kto_uitnodiging`: `uuid`, `gerelateerd_zaak_id` (FK procest), `zaaktype_code`, `verstuurd_aan_e_mail`, `verstuurd_op`, `unieke_response_token`, `response_ontvangen` (bool).
- Schema `kto_response`: `uuid`, `uitnodiging_id` (FK, nullable bij anoniem), `gemeente_orgaan_id`, `zaaktype_code`, `ingevuld_op`, `score_bereikbaarheid` (1-10), `score_deskundigheid` (1-10), `score_snelheid` (1-10), `score_resultaat` (1-10), `score_totaal` (1-10, auto-berekend), `nps_score` (-100..+100), `toelichting` (text), `pseudonimisatie_token` (sha256 — koppelt antwoorden zonder identificeren).

Validaties (template-overschrijdend):
- BSN-velden MUST encrypted-at-rest (AES-256-GCM); export-API blurt ze tot `***1234` voor non-superusers.
- `awb_termijn_dagen` op klacht-template default 42 dagen, mag wel verlengd worden tot 84 (Awb art. 9:11 lid 2) maar niet korter.
- Subsidie-aanvraag MUST `verklaring_de_minimis` aanwezig (waar/onwaar) voor subsidies onder 200K EUR.
- MOR `locatie_geo` MUST binnen gemeentegrens (geofence check op gemeente-orgaan polygoon).
- KTO `pseudonimisatie_token` MUST 1-richting hashed; ruwe BSN/e-mail nooit opgeslagen op response.

## Requirements

### REQ-001: Eén-klik Template-installatie

The system SHALL elke template installeerbaar maken in een leeg of bestaand OpenBuilt-app via één klik, met automatische creatie van schemas, pages, workflows, RBAC-rollen, en seed-data binnen 60 seconden.

#### Scenario 1: installatie zaakintake
- **GIVEN** lege OpenBuilt-app `dienstverlening-mijn-gemeente` zonder schemas
- **WHEN** de admin opent template-catalogue, selecteert "Zaakintake-formulier (GEMMA)" en klikt "Installeer"
- **THEN** verschijnt progress-modal, en binnen 60s zijn schemas (dienstverleningsverzoek + 2 hulp), 3 pages, 1 workflow, 4 RBAC-rollen geïnstalleerd, en de admin krijgt success-bevestiging met direct-link naar "Test het formulier"

#### Scenario 2: pre-flight check ontbrekende source
- **GIVEN** app waar openconnector source `bag-api` niet geconfigureerd is
- **WHEN** admin probeert zaakintake-template te installeren
- **THEN** verschijnt pre-flight check met waarschuwing "Verplichte source ontbreekt: BAG (adresvalidatie). Configureer via openconnector eerst." met directe link

### REQ-002: GEMMA-conformiteit Validatie

The system SHALL elke template-installatie + customisatie valideren tegen `template_conformity_rule` records en een conformiteit-score 0-100 leveren met breakdown per regel.

#### Scenario 1: conformiteit-check na installatie
- **GIVEN** template zaakintake net geïnstalleerd zonder customisaties
- **WHEN** de scheduled conformity-check draait
- **THEN** wordt `conformiteit_score = 100`, alle regels groen, en admin ziet "Volledig GEMMA 2.5.3-conform"

#### Scenario 2: customisatie verbreekt regel
- **GIVEN** admin heeft veld `bsn` uit `dienstverleningsverzoek` verwijderd (overtreedt regel "GEMMA-ZIK-002: BSN MUST present voor natuurlijk persoon")
- **WHEN** save + conformity-check
- **THEN** wordt score 92, regel "GEMMA-ZIK-002" rood, admin krijgt waarschuwing "Customisatie verbreekt 1 GEMMA-vereiste (error): BSN-veld ontbreekt — dienstverleningsverzoeken kunnen niet meer aan natuurlijk persoon gekoppeld worden via BRP"

### REQ-003: Seed-data Referentielijsten Synchronisatie

The system SHALL VNG-referentielijsten (zaaktypecatalogus, klachttypen, dienstverleningstypen, MOR-categorieën) automatisch synchroniseren via een scheduled job tegen de bron-URL van VNG/CBS, en bij wijziging admins notificeren met diff-overzicht.

#### Scenario 1: scheduled sync
- **GIVEN** datum 2026-05-01 03:00, last sync was 30 dagen geleden
- **WHEN** scheduled sync draait
- **THEN** wordt elke `gemma_referentielijst` bron-URL opgehaald, entries vergeleken, mutaties opgeslagen, en alle template_installations die deze lijst gebruiken krijgen waarschuwing op admin-dashboard

#### Scenario 2: diff-modal
- **GIVEN** VNG heeft 3 nieuwe MOR-categorieën toegevoegd
- **WHEN** admin opent waarschuwing
- **THEN** verschijnt modal "VNG-MOR-categorieën gewijzigd — 3 toegevoegd: laadpalen, e-bikes, koolmonoxide; 0 verwijderd. Toepassen op MOR-template?" met accept/reject knoppen

### REQ-004: Multi-tenant Customisatie Zonder Update-pad Breken

The system SHALL customisaties opslaan als overlay/diff bovenop de vanilla template, zodat een template-upgrade vanilla-changes kan toepassen zonder customisaties te overschrijven, met conflict-resolution UI bij overlap.

#### Scenario 1: extra veld toegevoegd, daarna template-upgrade
- **GIVEN** template subsidie v1.2.0 geïnstalleerd, gemeente heeft veld `internetadres_organisatie` toegevoegd aan `subsidie_aanvraag`
- **WHEN** template-upgrade naar v1.3.0 beschikbaar (voegt veld `verklaring_anbi` toe, raakt `internetadres_organisatie` niet)
- **THEN** geen conflict, upgrade past `verklaring_anbi` toe, `internetadres_organisatie` blijft als customisatie, conformity score blijft 100

#### Scenario 2: conflict
- **GIVEN** gemeente heeft veld `bsn` verwijderd; v1.3.0 maakt `bsn` required
- **WHEN** upgrade
- **THEN** verschijnt conflict-modal "v1.3.0 vereist `bsn` veld (required), maar dit is uitgezet in jullie customisatie. Kies: (a) accepteer upgrade en herstel bsn, (b) skip deze upgrade, (c) zie diff-detail"

### REQ-005: Localisatie NL/EN met Gemeente-jargon Overlay

The system SHALL elke template default Nederlands + Engels leveren, en per gemeente een jargon-overlay (e.g. "burger" → "inwoner van Amsterdam") toestaan zonder de basis-vertaling te overschrijven.

#### Scenario 1: overlay toepassen
- **GIVEN** template zaakintake met basis-labels NL "Voornaam van aanvrager", EN "First name of applicant"
- **WHEN** gemeente Amsterdam overlay-key `vraag_voornaam = "Hoe heet je?"`
- **THEN** rendert het formulier voor Amsterdam-app de overlay-tekst, voor andere apps de basis-tekst

#### Scenario 2: EN-fallback bij ontbrekende vertaling
- **GIVEN** custom label NL aanwezig, EN ontbreekt
- **WHEN** user kiest taal EN
- **THEN** valt UI terug op basis-EN-label van template, met dev-warning in console (alleen bij debug mode)

### REQ-006: DigiD/eHerkenning Integratie voor Authenticatie

The system SHALL aanvrager-authenticatie via DigiD (natuurlijk persoon, BSN) of eHerkenning (organisaties, KvK) ondersteunen via een geconfigureerde openconnector source, met automatische voor-vulling van BSN/KvK + naam uit BRP/KvK na succesvolle login.

#### Scenario 1: DigiD-login
- **GIVEN** burger opent publieksversie van subsidie-aanvraag formulier
- **WHEN** klikt "Inloggen met DigiD", doorloopt DigiD-flow, terug op formulier
- **THEN** worden `bsn`, `naam_voornaam`, `naam_achternaam`, `geboortedatum`, `correspondentie_adres` voorgevuld uit BRP-call, gemarkeerd als "geverifieerd door BRP"

#### Scenario 2: niet-DigiD-fallback
- **GIVEN** template-installatie zonder DigiD-source geconfigureerd
- **WHEN** burger opent formulier
- **THEN** verschijnt notificatie "Authenticatie niet beschikbaar — vul gegevens handmatig in" en formulier laat handmatige BSN-invoer toe (mits configureerbaar) of blokkeert (default: blokkeert voor templates met `bsn_required`)

### REQ-007: Procest Cross-app Handoff bij Submissie

The system SHALL bij submit van een formulier (status flipt naar "ingediend") automatisch een procest-zaak aanmaken volgens de vng-zaaktype-mapping, en de procest-zaak-ID terug in de formulier-instance opslaan.

#### Scenario 1: zaakintake → procest
- **GIVEN** dienstverleningsverzoek geconfigureerd met `verzoek_type_code = "informatieverzoek"` mapt naar procest-zaaktype `B0337`
- **WHEN** burger klikt "Verzenden"
- **THEN** wordt status `ingediend`, een procest-zaak aangemaakt met zaaktype B0337, initiator=burger, omschrijving=verzoek-tekst, bijlagen mee-gekopieerd, `procest_zaak_id` opgeslagen in dienstverleningsverzoek, burger krijgt e-mail met zaaknummer + portal-link

#### Scenario 2: procest niet bereikbaar
- **GIVEN** procest-API tijdelijk down (timeout)
- **WHEN** submit
- **THEN** wordt formulier-status `ingediend` opgeslagen, retry-queue ingeschakeld via n8n (3 retries, exp-backoff), admin-notificatie bij final failure; geen verlies van gegevens

### REQ-008: KTO Pseudonimisatie en Geanonimiseerd Dashboard

The system SHALL KTO-responses pseudonimiseren (geen BSN/e-mail op response, alleen hash-token), en een mydash-dashboard leveren met aggregaties per zaaktype, periode, en gemeente-orgaan zonder individuele respondent traceerbaar te maken.

#### Scenario 1: pseudonimisatie
- **GIVEN** KTO-uitnodiging verzonden aan `jan@example.nl` met response-token
- **WHEN** jan vult formulier in
- **THEN** wordt `kto_response` opgeslagen met `pseudonimisatie_token = sha256(e-mail + secret_pepper)`, geen direct identificeerbare velden; `kto_uitnodiging.response_ontvangen = true`

#### Scenario 2: dashboard k-anonimiteit
- **GIVEN** dashboard-view "KTO per zaaktype Q1 2026"
- **WHEN** zaaktype met <5 responses
- **THEN** wordt het zaaktype gegroepeerd in "overig" om k-anonimiteit te bewaren; scores alleen getoond wanneer n≥5

### REQ-009: Versie-promotie tussen Gemeentes / Federatie-deling

The system SHALL gemeente-customisaties aan een template optioneel kunnen exporteren naar een federatieve template-store waar andere gemeenten deze customisaties kunnen importeren (na review door pack-curator).

#### Scenario 1: customisatie indienen
- **GIVEN** gemeente Zwolle heeft 3 velden toegevoegd aan MOR-template die voor klimaatadaptatie nuttig zijn
- **WHEN** admin klikt "Deel met federatie"
- **THEN** wordt customisatie-payload (anoniem, zonder gemeente-data) naar federatie-store gestuurd, status `pending_review`; bij approval beschikbaar als "Community-customisatie: klimaatadaptatie-velden voor MOR"

### REQ-010: Audit-trail en AVG-conformiteit per Formulier

The system SHALL elke submission, every PII access, en every export loggen in een append-only audit-log, met AVG-conforme bewaartermijn-configuratie per template (default: 7 jaar voor zaak-gerelateerd, 1 jaar voor KTO, 5 jaar voor klacht conform Awb).

#### Scenario 1: bewaartermijn-aflopen
- **GIVEN** dienstverleningsverzoek uit 2019-01-15 met bewaartermijn 7 jaar
- **WHEN** scheduled retention-job draait op 2026-01-16
- **THEN** wordt het record gepseudonimiseerd (PII-velden gewist, niet-PII metadata bewaard voor statistiek), audit-log entry "retention_pseudonymized", admin krijgt maandelijks samenvattings-rapport

## Standards & Sources

- **GEMMA 2.5+ Gemeentelijke Model Architectuur** (VNG Realisatie) — basis voor gegevenscatalogus, bedrijfsfuncties, processen, referentiecomponenten.
- **VNG Zaaktypecatalogus (ZTC)** — standaard zaaktypen-set voor procest-handoff.
- **Algemene wet bestuursrecht (Awb)** — vooral H9 (klachtbehandeling, 42-dagen termijn) + H4 (subsidies, beschikkingen).
- **Wet open overheid (Woo)** — verplichte actieve openbaarmaking categorieën.
- **Algemene Verordening Gegevensbescherming (AVG/GDPR)** — bewaartermijnen, pseudonimisatie, dataminimalisatie, betrokkenenrechten.
- **NL API Strategie** (Forum Standaardisatie) — REST-design, pas-toe-of-leg-uit standaard.
- **NL GOV Assurance Profile for OAuth 2.0** — voor DigiD/eHerkenning integratie.
- **Forum Standaardisatie pas-toe-of-leg-uit lijst** — verplichte open standaarden (BAG, BRK, KvK, DigiD, eIDAS, BSN, NEN-3610, etc.).
- **eIDAS 2.0** — voor cross-border identificatie.
- **NL-AFNL Stelselcatalogus** — voor basisregistraties-referenties (BRP, BAG, BRK, KvK).
- **CBS-classificaties** — voor MOR-categorieën, demografische velden.
- **ISO 9001 / NEN-ISO 18091** (kwaliteitsmanagement lokaal bestuur) — voor KTO-dimensies.
- **Common European Customer Satisfaction Index (CSI)** — voor KTO-scoring-methode.
- **Net Promoter Score (NPS)** — voor klant-loyaliteit-indicator.
- **W3C SHACL** — voor schema-conformity-rule definitie (toekomstige verfijning).
- **Logius koppelvlak-standaard StUF-ZKN** — voor cross-app handoff naar legacy zaaksystemen via openconnector.

## Cross-app Integration

- **openbuilt-template-catalogue**: deze pack registreert 5 templates die uit de catalogue installeerbaar zijn.
- **openbuilt-schema-designer**: templates leveren kant-en-klare schemas die in de designer customizeerbaar zijn.
- **openbuilt-page-designer**: form-componenten + lay-outs voor elk template, geconfigureerd conform NL Design System.
- **openbuilt-workflow-designer**: workflows voor klacht-behandeling (Awb-termijn-bewaking), subsidie-aanvraag-traject (verleningsbesluit → vaststelling), MOR-toewijzing (categorie → team), KTO-uitnodiging (event-triggered na zaak-completion).
- **openbuilt-rbac**: rolset per template (`burger`, `intaker`, `behandelaar`, `clusterhoofd`, `subsidiebeoordelaar`, `klachtcoördinator`, etc.).
- **openbuilt-runtime**: hostet de geïnstalleerde apps, regelt API-endpoints.
- **opencatalogi (GEMMA gegevenscatalogus consumer)**: leest `beleidsdoel`, `taakgebied`, `gegevenscatalogus-URIs` uit opencatalogi GEMMA-publicaties zodat templates correcte referenties leggen.
- **procest** (cross-app handoff target): elke submission van zaakintake/subsidie/klacht/MOR start een procest-zaak.
- **decidesk** (subsidie-verleningsbesluit): subsidie-template integreert met decidesk voor formele besluitvorming en ondertekening van verleningsbeschikking.
- **docudesk** (afdoeningsbrief, KTO-uitnodiging, verleningsbeschikking): templates roepen docudesk aan voor document-generatie uit data + template.
- **openconnector** (BAG/BRP/KvK/DigiD/eHerkenning): templates eisen geconfigureerde sources voor BAG-adresvalidatie, BRP-voorvulling, KvK-validatie, DigiD/eHerkenning-login.
- **n8n** (retry-queue + e-mailrouting): retry-mechanisme voor procest-handoff failures; e-mailbevestigingen aan indieners.
- **mydash** (KTO-dashboard, MOR-heatmap): widgets voor management-inzicht.
- **softwarecatalog (Softwarecatalogus VNG)**: deze pack is registreerbaar als "GEMMA-conform softwareproduct" in de Softwarecatalogus, met geautomatiseerde conformity-rapportage.
- **integration-registry (hydra ADR-019)**: registreert template-installaties zodat cross-app navigatie ("zie deze klacht in OpenBuilt-klacht-app") werkt.

## Target Users

- **Gemeente / provincie / waterschap / uitvoeringsorganisatie applicatie-beheerders**: primaire installateurs en customiseerders van templates per organisatie; beslissen welke templates uitgerold worden, configureren openconnector-sources voor BAG/BRP/KvK, beheren upgrade-cyclus.
- **Citizen developers / business-analysten in publieke sector**: customisaties doorvoeren binnen GEMMA-conformiteit, simpele workflow-aanpassingen, lokale jargon-overlay, extra velden voor lokale beleids-context.
- **Beleidsmedewerkers / juridisch adviseurs**: bewaken inhoudelijke correctheid van klachten-, subsidie-, dienstverleningstemplates tegen Awb/Woo/AVG; valideren dat verleningsbesluit-tekst conform Awb art. 4:46 is.
- **Privacy officers (FG/DPO)**: gebruiken audit-trail + retention-config voor AVG-bewaking, valideren pseudonimisatie KTO, onderhouden Verwerkingsregister-entries per template, beoordelen DPIA's voor maatwerk-customisaties.
- **Informatieveiligheidsfunctionarissen (CISO)**: bewaken BIO-conformiteit, DigiD-aansluiting-audits, log-retention voor security-events.
- **Burgers / ondernemers / bezoekers**: eindgebruikers van de geïnstalleerde formulieren (publieksversie), gebruiken DigiD/eHerkenning voor authenticatie, ontvangen ontvangstbevestiging + zaaknummer + portal-link.
- **Behandelend ambtenaren / intaker-medewerkers / klantcontactcentrum**: gebruiken backend-views van templates (lijsten, detail-pagina's) voor afhandeling, in samenwerking met procest; behandelen klachten binnen Awb-termijn, beoordelen subsidie-aanvragen, dispatchen MOR-meldingen naar uitvoerende teams.
- **Buitendienstmedewerkers (groenteam, wegen, handhaving)**: ontvangen MOR-tickets op mobiel, status-update vanuit veld, foto's van opgeloste situatie.
- **Wethouders / management / KTO-analisten**: gebruiken KTO-dashboard voor sturing op klanttevredenheid, signaleren clusters van klachten over zelfde organisatie-onderdeel, gebruiken benchmark tegen waarstaatjegemeente.
- **VNG Realisatie / Gebruikersvereniging Common Ground**: kunnen federatieve community-customisaties cureren, GEMMA-updates publiceren die als template-pack-upgrades verspreid worden, valideren conformiteits-regels.
- **Forum Standaardisatie**: monitort pas-toe-of-leg-uit-naleving via de conformiteits-rapporten die deze pack genereert.
- **CBS / waarstaatjegemeente.nl**: ontvangen gestandaardiseerde KPI-uitsplitsing per gemeente via auto-export voor benchmarking.
- **Conduction-team**: onderhoudt de packs zelf, brengt nieuwe templates uit, ondersteunt gemeenten bij installatie/customisatie/upgrade, schrijft conformity-rules voor nieuwe GEMMA-versies.
- **Andere overheid-platformen (DSO, ROVA, Common Ground initiatieven, Logius)**: kunnen de packs (EUPL-1.2) hergebruiken in eigen distributies.
- **Onderwijsinstellingen / opleidingen bestuurskunde**: gebruiken de packs als didactisch materiaal voor "moderne gemeentelijke dienstverlening" en "GEMMA in de praktijk" colleges.
