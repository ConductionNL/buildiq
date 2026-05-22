status: draft

# Business Rules Engine

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** sub-tool / Designers > Business Rules Designer

**Rationale:** rules authoring is a designer  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

OpenBuilt stelt citizen-developers in staat om binnen het Nextcloud-ecosysteem custom apps te ontwerpen via de page-designer en runtime. Wat momenteel ontbreekt is een gestructureerde manier om bedrijfsregels (business rules) los van UI-code te modelleren: validaties, workflow-routering, automatische berekeningen, conditionele zichtbaarheid van velden, en escalatie-logica. Nu eindigen deze als verspreide IF-conditions in templates, hardcoded backend-checks, of (erger) niet-gevalideerde aannames in formulieren.

Voor de doelgroep (procesanalisten, business-experts zonder programmeer-achtergrond) is een visuele rule-engine essentieel. Twee dominante paradigmas dekken samen de overgrote meerderheid van use-cases: decision tables (gebaseerd op DMN, OMG-standaard) voor multi-condition mapping zoals tariefberekeningen, kortings-staffels, eligibility-checks, en condition-action chains voor sequentiele workflow-besluiten zoals routering van een aanvraag op basis van bedrag, regio en aanvragerstype.

Deze spec levert een rule-engine die: (a) deze twee paradigmas via visual editors aanbiedt, (b) regels per tenant deployable maakt zonder app-redeploy, (c) een test-sandbox biedt waarin business-experts hun regels kunnen valideren met sample-payloads voordat ze live gaan, (d) versioning en audit-trail per rule biedt, (e) hot-reload zonder downtime ondersteunt, en (f) een runtime-API biedt waar andere openbuilt-apps tegen kunnen evalueren (synchrone calls voor validatie, async events voor routering).

## Data Model

**RuleSet** (nieuw schema, register `openbuilt-rules`):
- `naam` (string, slug-formaat)
- `beschrijving` (string)
- `versie` (semver-string)
- `status` (enum: draft / test / actief / gearchiveerd)
- `eigenaarApp` (string, openbuilt-app-slug die deze rule-set bezit)
- `geactiveerdOp` (datetime, nullable)
- `gedeactiveerdOp` (datetime, nullable)
- `ingangsdatum`, `einddatum` (date, nullable - voor tijdelijke regels)

**DecisionTable** (nieuw schema):
- `ruleSetId` (relatie)
- `hitPolicy` (enum: unique / first / priority / any / collect / rule-order)
- `inputColumns` (array: naam, type, expressie-pad in input-payload)
- `outputColumns` (array: naam, type, defaultwaarde)
- `regels` (array van rij-objecten: condities per input-kolom, waardes per output-kolom, optionele label)

**ConditionActionRule** (nieuw schema):
- `ruleSetId` (relatie)
- `naam`, `prioriteit` (integer)
- `salience` (integer, voor evaluatie-volgorde bij gelijke prioriteit)
- `conditie` (string, expressie in FEEL-subset)
- `acties` (array: type [set-veld / start-workflow / send-notification / call-rule-set], parameters)
- `actief` (boolean)

**RuleExecutionLog** (nieuw schema):
- `ruleSetId`, `ruleSetVersie`
- `tijdstip` (datetime)
- `triggerContext` (string, bv. "object-create:patient")
- `inputPayload` (json, optioneel gemaskeerd voor PII)
- `outputResultaat` (json)
- `geraaktRegels` (array van rule-ids)
- `executieDuurMs` (integer)
- `fouten` (array van foutmeldingen)

**TestCase** (nieuw schema):
- `ruleSetId` (relatie)
- `naam`, `beschrijving`
- `inputPayload` (json)
- `verwachtResultaat` (json)
- `laatsteTestResultaat` (enum: niet-uitgevoerd / geslaagd / gefaald)
- `laatsteTestOutput` (json)

## Requirements

### REQ-001: Decision table visueel ontwerpen

GIVEN een gebruiker met rol rule-designer in openbuilt
WHEN deze "Nieuwe decision table" kiest voor een RuleSet
THEN opent een grid-editor waar input-kolommen + output-kolommen + rijen kunnen worden gedefinieerd, met inline validatie van celexpressies (FEEL-subset: ranges, lijsten, vergelijkingen), live preview van hit-policy-effect, en visuele hint bij overlappende/onvolledige regels.

### REQ-002: Condition-action chain met salience

GIVEN een RuleSet bedoeld voor workflow-routering
WHEN de ontwerper meerdere ConditionActionRule's toevoegt
THEN evalueert het systeem deze in volgorde van `prioriteit` desc dan `salience` desc, voert de acties van de eerst-matchende regel uit, en stopt - tenzij de regel-actie `continue` bevat dan worden volgende regels ook geevalueerd.

### REQ-003: Test-sandbox met TestCase-suite

GIVEN een RuleSet met minimaal een DecisionTable of ConditionActionRule
WHEN de ontwerper TestCases definieert met sample-payloads en verwachte outputs
THEN biedt het systeem een "Run alle tests"-knop die elke testcase door de actuele RuleSet-versie laat lopen, en toont per testcase: geslaagd/gefaald, daadwerkelijke output, diff met verwachting - en blokkeert promotie naar status `actief` zolang tests falen of niet zijn uitgevoerd.

### REQ-004: Versionering bij activatie

GIVEN een RuleSet met status `test` en alle tests geslaagd
WHEN de ontwerper "Activeer versie"-actie kiest
THEN incrementeert het systeem automatisch de semver (patch bij regel-toevoeging, minor bij kolom-toevoeging, major bij breaking change), zet de huidige actieve versie op `gearchiveerd`, en activeert de nieuwe versie met `geactiveerdOp = now()` - oude versie blijft beschikbaar voor RuleExecutionLog-traceerbaarheid.

### REQ-005: Hot-reload zonder app-restart

GIVEN een actieve RuleSet die wordt geconsumeerd door een openbuilt-app
WHEN een nieuwe versie wordt geactiveerd
THEN herlaadt de rule-engine-runtime de regel-definitie binnen 30 seconden zonder restart van de consument-app, en vanaf dat moment evalueren nieuwe rule-aanroepen tegen de nieuwe versie - lopende async-flows behouden hun gebruikte versie.

### REQ-006: Runtime-API voor consument-apps

GIVEN een actieve RuleSet
WHEN een openbuilt-app via `POST /api/rules/{ruleSetSlug}/evaluate` een input-payload stuurt
THEN evalueert het systeem de regels synchroon (default timeout 500ms), retourneert het resultaat als JSON met geraaktRegels-metadata, en logt de uitvoering in RuleExecutionLog - inclusief expliciete `dry-run`-modus die geen acties uitvoert maar wel het uitkomst-payload genereert.

### REQ-007: Per-tenant deployment

GIVEN een multi-tenant openbuilt-installatie
WHEN een tenant-beheerder een RuleSet wijzigt
THEN is de wijziging alleen actief binnen die tenant (RuleSet's zijn tenant-scoped via standaard openregister-multitenancy), tenzij expliciet als `globaal` gemarkeerd door een platform-beheerder - waarbij globale rule-sets read-only zijn voor tenants en alleen overridable via een tenant-specifieke override-rule.

### REQ-008: Audit-trail + impact-analyse

GIVEN een wijziging op een actieve RuleSet (versie-promotie, deactivatie)
WHEN de wijziging wordt opgeslagen
THEN registreert het systeem: tijdstip, gebruiker, oude/nieuwe versie, wijzigings-diff, getroffen apps (op basis van runtime-API-consumers in de afgelopen 30 dagen), en stuurt een notification naar de eigenaren van consument-apps - zodat business-impact van regel-wijzigingen traceerbaar is.

## Standards

- **DMN 1.4** (Decision Model and Notation, OMG-standaard) - basis voor decision tables + FEEL-expressies
- **BPMN 2.0** (raakvlakken voor workflow-routering vanuit rule-acties)
- **OpenAPI 3.1** (runtime-API contract)
- **SemVer 2.0** (versionering)
- **NORA / EIRA** (architectuur-principes voor scheiden bedrijfsregels van app-logica)
- **ISO 27001 / BIO** (audit-trail-eisen voor regels die financien of rechten beinvloeden)
- **AVG art. 22** (geautomatiseerde besluitvorming - rule-engine moet uitlegbaar zijn)

## Cross-app

- **openbuilt page-designer**: form-builder verwijst naar RuleSets voor veldvalidatie + conditionele zichtbaarheid
- **openbuilt runtime**: consumeert runtime-API voor live evaluatie
- **openregister**: opslag van RuleSet/DecisionTable/etc als register-objecten
- **n8n-nextcloud**: rule-actie `start-workflow` triggert n8n-flow
- **mydash**: rule-uitvoeringsmetrics (calls/sec, faalpercentage)
- **decidesk**: besluiten over rule-wijziging-impact bij major-versie-bumps
- **docudesk**: opslag van rule-documentatie + impact-rapporten

## Target users

- **Business-expert / procesanalist** (rule-designer): primaire gebruiker visuele editors
- **Citizen developer** (rule-implementer): koppelt rules aan formulieren in page-designer
- **Tenant-beheerder**: activeert/archiveert rule-sets per tenant
- **Platform-beheerder**: beheert globale rule-sets, monitort runtime
- **App-eigenaar**: ontvangt impact-notificaties bij rule-wijzigingen
- **Auditor**: consulteert audit-trail bij geschillen over geautomatiseerde besluiten
- **End-user** (indirect): ondervindt rule-uitvoering via formulier-gedrag en workflow-routering
