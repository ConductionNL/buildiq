---
status: draft
---
# Workflow Designer (Visual BPMN-light)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** sub-tool / Designers > Workflow Designer

**Rationale:** one of four designers  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

OpenBuilt is een low-code app builder waarmee citizen developers data-applicaties bouwen door schemas te definiëren, pagina's te ontwerpen en logica te configureren, zonder code te schrijven. De huidige scope dekt **gegevens** (schema-designer), **interface** (page-designer), **navigatie en runtime** en **delivery** (versioning, exporter, RBAC). Wat ontbreekt is de **procesdimensie**: hoe stroomt data en werk door de applicatie heen — wie krijgt wat te doen, in welke volgorde, onder welke voorwaarden, en met welke escalatie als iets blijft liggen. In de praktijk strandt elke OpenBuilt-applicatie van enige omvang op het ontbreken hiervan; gebruikers bouwen workarounds met handmatige taakverdeling buiten de app, of installeren een externe BPM-engine (Camunda, Flowable) die niet integreert met hun OpenBuilt-data en RBAC-model.

Deze spec voegt een eersteklas **Workflow Designer** toe aan OpenBuilt: een visuele drag-and-drop omgeving waarmee citizen developers procesflows configureren tussen schema-objecten, formulieren en integraties. Het is bewust **BPMN-light** — een vereenvoudigd subset van BPMN 2.0 dat ~90% van de echte use cases dekt zonder de leercurve van volledige BPMN. Onderdelen: start-events (manueel, scheduled, op-data-event, via-API), task-nodes (user-task met form, service-task met integratie-call, automatische data-update), gateways (exclusief op conditie, parallel split/join), boundary timers/escalaties op tasks, end-events. Variabelen propageren door de flow en zijn bind-baar aan form-fields en condities. Elke gedeployde workflow heeft een runtime die instances opstart, status bijhoudt, taken aan personen toewijst (user/role/group via openbuilt-rbac), deadlines bewaakt en escaleert bij overschrijding.

De spec dekt: (a) de visuele designer-canvas met node-palette, drag-and-drop, connector-tekening, validation-overlay; (b) het volledige data-model voor workflow-definities (versioned), workflow-instances (lopend), task-instances en variable-store; (c) de runtime-engine die instances opstart, taken toewijst, condities evalueert, timers schedulet; (d) **user-tasks** met form-rendering en assignment naar user/role/group/dynamische expressie; (e) **service-tasks** die via openconnector externe API's aanroepen of via cross-app links Conduction-apps invoken; (f) **versioning** van workflows met running instances die op hun oorspronkelijke versie blijven (no forced migration); (g) **debugging** met instance-trace voor builders; (h) **export** als executable spec voor cross-environment delivery via openbuilt-exporter. Out of scope: complete BPMN-coverage (geen sub-processes/event sub-processes/compensation/transactions/message-correlation), CMMN (case management), DMN (decisions als aparte taal — wel zeer eenvoudige conditional gateways).

## Data Model

Acht schemas in `openbuilt/workflow-designer/`, gehost in de per-app register (`openbuilt-{slug}`) zoals het ADR-016-hybride model voorschrijft:

**workflow_definition**: `uuid`, `application_id` (FK openbuilt application), `naam`, `slug` (uniek binnen application), `omschrijving`, `versie` (semver, e.g. "1.4.0"), `status` (concept|gepubliceerd|gedeprecateerd), `canvas_json` (JSON — de visuele layout: nodes, edges, posities), `gepubliceerd_op` (datetime), `gepubliceerd_door_id`, `tags`. Versioned: wijzigingen maken nieuwe versie aan.

**workflow_node**: `uuid`, `workflow_definition_id` (FK), `node_id` (string — stable id binnen workflow, e.g. "task_review_aanvraag"), `node_type` (start_manual|start_scheduled|start_event|start_api|task_user|task_service|task_script|gateway_exclusive|gateway_parallel_split|gateway_parallel_join|end_normal|end_error|intermediate_timer|intermediate_signal), `naam`, `config` (JSON — type-specifieke configuratie), `position_x` (int), `position_y` (int).

**workflow_edge**: `uuid`, `workflow_definition_id` (FK), `from_node_id`, `to_node_id`, `label` (text — leeg voor unconditionele, expressie voor gateway), `expressie` (text — JS-achtige condition, e.g. "amount > 5000"), `is_default` (bool — bij exclusieve gateway de fallback).

**workflow_variable_spec**: `uuid`, `workflow_definition_id` (FK), `naam` (snake_case), `data_type` (string|number|boolean|date|datetime|object|array|file_reference|object_reference), `referenced_schema_slug` (nullable — voor object_reference), `default_value` (JSON), `omschrijving`, `is_input` (bool — gevraagd bij start), `is_output` (bool — exposed na completion).

**workflow_instance**: `uuid`, `workflow_definition_id` (FK), `workflow_versie_snapshot` (semver — bewaart welke versie deze instance draait), `gestart_op` (datetime), `gestart_door_id` (nullable bij start_scheduled/start_event), `gestart_via` (manual|scheduled|event|api), `huidige_node_ids` (array — meerdere bij parallel), `status` (lopend|voltooid|geannuleerd|fout|wachtend_op_timer), `variables` (JSON — runtime variable values), `gerelateerd_object_type` (string), `gerelateerd_object_id` (UUID — voor "this workflow handles this object"), `beëindigd_op`, `eindstatus_node_id`, `foutmelding`.

**task_instance**: `uuid`, `workflow_instance_id` (FK), `node_id` (welke task-node), `naam` (denormaliseerd cache), `assignee_user_id` (FK users, nullable), `assignee_role` (string, nullable), `assignee_group_id` (FK groups, nullable), `aangemaakt_op` (datetime), `claimed_op` (datetime, nullable — wanneer een user uit een groep de taak op zich nam), `due_datum` (date, nullable), `status` (open|geclaimd|voltooid|gedelegeerd|geëscaleerd|geannuleerd), `voltooid_op`, `voltooid_door_id`, `form_data` (JSON — antwoorden op form-fields), `escalation_count` (int).

**workflow_timer**: `uuid`, `workflow_instance_id` (FK), `node_id`, `geplande_trigger_op` (datetime), `getriggerd_op` (nullable), `status` (pending|fired|cancelled), `payload` (JSON). Gebruikt voor intermediate timers en boundary-escalation timers.

**workflow_event_log**: `uuid`, `workflow_instance_id` (FK), `event_type` (instance_started|node_entered|node_exited|task_assigned|task_completed|timer_fired|gateway_evaluated|variable_set|instance_completed|error_raised), `node_id` (nullable), `timestamp`, `actor_id` (nullable), `payload` (JSON), `level` (info|warn|error). Append-only audit log + debugger source.

Validaties:
- `workflow_definition` MUST hebben ≥1 `start_*` node en ≥1 `end_*` node; canvas zonder eindknoop = validation error bij publish.
- `workflow_edge.expressie` MUST parseable zijn als safe-subset-JS (geen access naar globals, alleen variables + math + string ops); validator weigert anders.
- Geen circulaire flows zonder gateway met `is_default` op een uitgaande edge (anders kan instance vastlopen).
- Parallel split MUST corresponderen met parallel join verderop in dezelfde flow.
- `task_instance` heeft precies één van `assignee_user_id`, `assignee_role`, `assignee_group_id` gevuld (of nul tijdens "wacht op claim").
- Bij `workflow_definition` publish wordt een immutable snapshot gemaakt; running instances blijven op hun snapshot, nieuwe instances starten op de nieuwe versie.

## Requirements

### REQ-001: Visuele Designer-canvas

The system SHALL een drag-and-drop canvas leveren met node-palette (links), canvas (midden), property-panel (rechts), zoom/pan, snap-to-grid, undo/redo (≥30 steps), en validation-overlay (rode badges op nodes met config-issues).

#### Scenario 1: nieuwe workflow opstellen
- **GIVEN** lege workflow `subsidie-aanvraag-flow` in concept
- **WHEN** de builder drag-and-drops start_manual → task_user → gateway_exclusive → 2x task_user → end_normal en tekent connectors
- **THEN** wordt elke node + edge als `workflow_node`/`workflow_edge` opgeslagen, het canvas-JSON ge-update bij elke wijziging (debounced 1s), en property-panel toont contextuele configuratie per geselecteerde node

#### Scenario 2: validation-overlay
- **GIVEN** canvas met een task_user zonder assignee-config en een gateway zonder default edge
- **WHEN** de builder klikt "Valideren"
- **THEN** verschijnen rode badges op de twee problematische nodes met tooltip ("ontbrekende assignee", "ontbrekende default edge") en publish-knop blijft disabled

### REQ-002: Start-events (Manueel, Scheduled, Op-data-event, API)

The system SHALL vier start-event types ondersteunen: handmatige trigger door user, scheduled cron, on-object-created/-updated event van een schema, en HTTP-API trigger via uniek token.

#### Scenario 1: scheduled start
- **GIVEN** workflow met `start_scheduled` config `{"cron":"0 9 * * MON"}` (elke maandagochtend 09:00)
- **WHEN** de scheduler tick passeert
- **THEN** wordt een nieuwe `workflow_instance` gemaakt met `gestart_via='scheduled'`, en de eerste downstream node wordt geactiveerd

#### Scenario 2: on-object-event start
- **GIVEN** workflow met `start_event` op schema `subsidie_aanvraag` event `object.created`
- **WHEN** een nieuw subsidie_aanvraag object wordt aangemaakt in de applicatie
- **THEN** start een instance met `variables.aanvraag_id = {nieuwe object id}`, `gerelateerd_object_type='subsidie_aanvraag'`, `gerelateerd_object_id=<id>`

### REQ-003: User-tasks met Form-rendering en Assignment

The system SHALL user-tasks ondersteunen met (a) form gekoppeld aan een page-designer form-component (b) assignment via fixed user, fixed role, fixed group, of dynamische expressie tegen variables, (c) due-date relatief (e.g. "+3 dagen na aanmaak") of absoluut.

#### Scenario 1: assignment via dynamische expressie
- **GIVEN** task_user "Inhoudelijke beoordeling" met assignee expressie `aanvraag.toegewezen_behandelaar` en form-koppeling `form_beoordeling_v2`
- **WHEN** een instance dit task-node binnenkomt met `variables.aanvraag.toegewezen_behandelaar = "jan.devries"`
- **THEN** wordt een task_instance gemaakt met `assignee_user_id` van jan.devries, taakt verschijnt in zijn taken-lijst, en hij krijgt een Nextcloud-notificatie

#### Scenario 2: group-assignment met claim
- **GIVEN** task_user met `assignee_group_id = "fin-team"` (6 leden)
- **WHEN** task verschijnt
- **THEN** hebben alle 6 leden de task in "Beschikbaar voor mijn groep"-lijst; zodra Maria klikt "Claim" wordt `claimed_op` gezet, `assignee_user_id = maria.jansen`, en de taak verdwijnt uit de inboxes van de andere 5

### REQ-004: Service-tasks (Externe + Cross-app)

The system SHALL service-tasks ondersteunen die (a) een openconnector source aanroepen met variable-mapping voor request body, en response naar variables mappen; (b) een cross-app actie aanroepen via Conduction-integratie-registry (e.g. "create procest zaak", "stuur decidesk besluit ter ondertekening").

#### Scenario 1: openconnector call
- **GIVEN** service-task config `{"source_id":"bag-api","operation":"get_adres","input_mapping":{"postcode":"variables.aanvrager.postcode","huisnummer":"variables.aanvrager.huisnummer"},"output_mapping":{"variables.bag_adres":"$.adres"}}`
- **WHEN** instance bereikt deze node
- **THEN** wordt de bag-api source aangeroepen, response gemapt naar `variables.bag_adres`, en de flow vervolgt; bij HTTP-error wordt `node_type=end_error` getriggerd of de retry-config gebruikt

#### Scenario 2: cross-app create-zaak
- **GIVEN** service-task config `{"action":"procest.create_zaak","input_mapping":{"zaaktype":"\"subsidie-evaluatie\"","initiator":"variables.aanvrager.id"}}`
- **WHEN** node wordt bereikt
- **THEN** wordt via cross-app integration-registry een procest zaak aangemaakt, de zaak-UUID terug in `variables.gemaakte_zaak_id`, en een audit-log entry gemaakt

### REQ-005: Gateways (Exclusief en Parallel)

The system SHALL exclusieve gateways ondersteunen die exact één uitgaande edge volgen op basis van condities (eerste matchende, of default), en parallel split/join gateways die de flow opdelen en synchroniseren.

#### Scenario 1: exclusieve gateway op bedrag
- **GIVEN** gateway met 3 uitgaande edges: edge A `expressie="bedrag <= 1000"`, edge B `"bedrag <= 5000"`, edge C `is_default=true`
- **WHEN** instance bereikt gateway met `variables.bedrag = 3200`
- **THEN** wordt edge B gevolgd (eerste matchende na A faalt), event-log noteert evaluatie + gekozen branch

#### Scenario 2: parallel split + join
- **GIVEN** parallel-split → task_X + task_Y → parallel-join → vervolg
- **WHEN** instance arriveert bij split
- **THEN** worden beide task_instances tegelijk aangemaakt, instance.huidige_node_ids = [X, Y]; pas wanneer beide voltooid zijn passeert de join en flow continues — bij asynchrone completion blijft join wachten

### REQ-006: Timers en Escalatie

The system SHALL intermediate-timers (wait N tijd) en boundary-timers op user-tasks (escalate-if-not-done-in-N) ondersteunen, met escalatie-actie configureerbaar: reassign / notify-manager / move-to-other-task.

#### Scenario 1: intermediate timer
- **GIVEN** workflow met intermediate_timer node config `{"duur":"P14D"}` (14 dagen)
- **WHEN** instance bereikt node op 2026-04-01 14:00
- **THEN** wordt een `workflow_timer` record gemaakt met `geplande_trigger_op = 2026-04-15 14:00`, instance status → `wachtend_op_timer`; bij 2026-04-15 fires de timer, status → `lopend`, downstream node geactiveerd

#### Scenario 2: boundary escalation
- **GIVEN** task_user met `due_datum_offset = "P3D"` en boundary-config `{"on_overdue":"reassign_to_manager_role"}`
- **WHEN** taak na 3 dagen niet voltooid
- **THEN** wordt `task.status = geëscaleerd`, een nieuwe task_instance aangemaakt voor de manager-role, originele user krijgt notificatie "uw taak is geëscaleerd", `escalation_count += 1`

### REQ-007: Versioning met Backward-compatibele Running Instances

The system SHALL bij publish van een nieuwe versie de oude versie als immutable snapshot bewaren; alle running instances blijven op hun snapshot draaien tot completion, nieuwe instances gebruiken de nieuwste versie.

#### Scenario 1: nieuwe versie publiceren
- **GIVEN** workflow `subsidie-flow` v1.2.0 met 47 running instances
- **WHEN** de builder publiceert v1.3.0 (extra task toegevoegd)
- **THEN** wordt v1.3.0 marked `gepubliceerd`, v1.2.0 marked `gedeprecateerd`, alle 47 instances behouden `workflow_versie_snapshot="1.2.0"` en lopen door op de oude flow; nieuwe instances starten op 1.3.0

### REQ-008: Workflow Debugger en Instance Trace

The system SHALL elke instance een trace-view bieden met chronologische timeline van events (node entered/exited, task assigned/completed, gateway-evaluatie met expressie en uitkomst, variable-mutaties) en visualisatie van het huidige pad op de canvas.

#### Scenario 1: debug-view openen
- **GIVEN** instance `WI-2026-001847` doorloopt sinds 3 dagen
- **WHEN** builder opent `/workflows/{def_id}/instances/{instance_id}/trace`
- **THEN** verschijnt timeline met alle events, canvas-overlay markeert doorlopen path groen en huidige node oranje, klikbare events tonen variable-state op dat moment

### REQ-009: Permissies op Workflow-niveau

The system SHALL RBAC integreren met openbuilt-rbac: rol `workflow_designer` mag definities maken/edit/publishen, `workflow_operator` mag instances starten + zien, `task_assignee` ziet alleen taken die naar hem/zijn rol/zijn group geassigned zijn; service-account-tokens voor start_api endpoints scope-baar per workflow.

#### Scenario 1: ongeautoriseerde publish
- **GIVEN** user met alleen `workflow_operator` rol
- **WHEN** hij `POST /api/workflows/{id}/publish`
- **THEN** retourneert API 403 `{"error":"requires_workflow_designer_role"}`

### REQ-010: Export naar Executable Spec voor Multi-environment

The system SHALL workflows exporteren als deterministische JSON spec via openbuilt-exporter, met alle nodes, edges, variable-specs, en assignment-config (rollen-namen, niet user-ID's) zodat een dev → test → prod promotie schoon werkt.

#### Scenario 1: export + import
- **GIVEN** workflow `subsidie-flow` v1.3.0 in dev-environment
- **WHEN** export gegenereerd en in test-environment geïmporteerd
- **THEN** wordt v1.3.0 daar opgevoerd, rollen worden gemapt op test-omgeving's rol-definities (waarschuwing bij ontbrekende rol), geen user-ID's hard-coded

## Standards & Sources

- **BPMN 2.0 (ISO/IEC 19510:2013)** — referentie voor node-types, edge semantics, execution semantics; deze spec implementeert een subset.
- **DMN 1.4** — decision-table standaard; toekomstige uitbreiding voor gateway-condities die te complex worden voor inline expressies.
- **CMMN 1.1** — case management; expliciet niet geïmplementeerd, advies in docs naar wanneer een dedicated case-management app meer past.
- **Camunda Platform / Camunda BPMN Modeler** — de-facto reference implementatie; canvas-UX inspiratie + node-icoonconventies.
- **Activiti / Flowable** — alternatieve BPMN engines; lessons learned uit hun documentatie over runtime-engine design.
- **BPM-CMM (Business Process Maturity Model)** — voor docs/communicatie over wat citizen-developer-flows wel/niet aankunnen.
- **OWASP API Security Top 10** — voor start_api endpoint hardening (rate-limit, token scope, idempotency).
- **GEMMA 2 procesarchitectuur referentie-processen** — bron voor `gemma-starter-pack` workflow templates.
- **VNG ZTC (Zaaktypecatalogus)** — voor cross-app handoffs naar procest met juiste zaaktype-codes.
- **W3C JSON Schema draft 2020-12** — voor `workflow_variable_spec.data_type` validatie.
- **RFC 5545 (iCal)** — voor toekomstige iCal-export van workflow-deadlines naar agenda's.

## Cross-app Integration

- **openbuilt-page-designer**: user-tasks koppelen aan form-componenten uit de page-designer; designer kan vanuit task-config direct in de form-editor springen; form-data wordt in workflow-variables gemapt; bidirectional preview tussen task-config en gebruikte form.
- **openbuilt-rbac**: assignment naar role/group leest rollendefinities uit de rbac-spec; permissies op designer + operator + runtime via rbac-rolset; dynamische expressie-assignment leest user-attributen uit rbac (departement, manager-keten).
- **openbuilt-runtime**: workflow-engine draait binnen de runtime; instances opgestart bij object-events die de runtime ontvangt; runtime levert het task-inbox-component en de start-button-actie.
- **openbuilt-schema-designer**: variables van type object_reference verwijzen naar schemas; trigger-events koppelen aan schema-CRUD events; schema-mutaties die fields verwijderen die in actieve workflows refereerd worden krijgen waarschuwing.
- **openbuilt-exporter**: export-format voor cross-environment promotie; ook gebruikt door template-catalogue; exports volgen versie-pinning van schemas/pages waar workflow naar verwijst.
- **openbuilt-template-catalogue**: starter-workflows (incl. gemma-starter-pack) als templates installeerbaar; community-templates voor common patterns (approve-loop, escalation-chain).
- **openbuilt-version-snapshots / version-promotion**: workflow-definities versioneren mee in de app-versie-snapshot; running instances respecteren version-pinning bij app-rollback.
- **openconnector** (service-tasks): bron-API-calls via openconnector sources; consumer voor 3rd-party HTTP; rate-limit en circuit-breaker afkomstig uit openconnector-config.
- **opencatalogi**: workflow-definities publiceerbaar als opencatalogi-publicatie type "procesmodel" voor open-data publicatie van overheid-processen.
- **procest** (cross-app actie): service-task action `procest.create_zaak` voor handoff naar zaakafhandeling; bidirectional via `procest.zaak.status_changed` event als workflow-trigger.
- **decidesk** (cross-app actie): service-task action `decidesk.create_besluit_concept` of `decidesk.send_for_signature` voor governance-flows; consumer van `decidesk.besluit.vastgesteld` als workflow-trigger.
- **docudesk** (cross-app actie): genereer documenten als output van een workflow-stap (bijv. brief, beschikking, contract); document-ID terug in variables.
- **openregister** (eventsource): alle CRUD-events op schemas in registers zijn beschikbaar als start_event-trigger via standard event-bus.
- **n8n** (alternatief / aanvullend): voor extreem complexe integratie-flows die buiten BPMN-light passen, kan service-task een n8n-workflow triggeren; n8n is voor systeem-integratie, workflow-designer voor proces-logica; duidelijke decision-guide in docs.
- **integration-registry (hydra ADR-019)**: registreert workflow-instances als integrations-targets zodat andere apps via "view in app X" naar instance-trace kunnen klikken.
- **mydash**: workflow-metrics (instances-per-dag, gemiddelde cyclustijd, escalation-rate) als widget op management-dashboards.
- **AI-chat-companion (ADR-034)**: mcp-tool `workflow.start_instance` zodat een chat-conversatie direct een workflow kan starten; en `workflow.query_instance_status` voor "hoe staat het met mijn aanvraag" prompts.
- **planix** (toekomstige uitbreiding): task-instances bij user-tasks kunnen optioneel mee gerendered in planix dashboard-my-work voor gebruikers die OpenBuilt-task naast planix-task hebben.

## Target Users

- **Citizen developers / business-analysten**: primaire gebruikers, ontwerpen workflows in visuele designer, koppelen aan eigen schemas/forms; verwachten geen BPMN-expertise nodig te hebben — visuele metafoor + sensible defaults.
- **Application owners**: configureren start-triggers (scheduled, event), beheren publish-lifecycle, bewaken instance-metrics, beslissen over deprecation van oude versies, communiceren wijzigingen naar gebruikers.
- **Business-administrators**: dagelijkse operatie — starten instances handmatig, debuggen vastgelopen instances, herstarten na fix, beheren task-queues, bewaken SLA-overschrijdingen.
- **End-users (medewerkers)**: ontvangen tasks in hun task-inbox, vullen forms in, claimen taken uit groep-queues; meestal niet bewust van de onderliggende workflow — UX moet "gewoon werken" voelen.
- **Managers / teamleads**: krijgen escalaties bij overdue taken, kunnen taken delegeren of reassignen, bekijken team-task-load, sturen op throughput en cyclustijd.
- **Integratie-developers / engineers**: configureren service-tasks die complexe API-koppelingen vragen, soms in samenwerking met citizen developers; schrijven custom-script-tasks (later, niet in MVP) voor edge-cases.
- **Auditors / compliance**: gebruiken instance-trace + event-log voor audit van proces-uitvoering; "wie heeft besluit X genomen en wanneer en op basis waarvan"; reconstructie voor klachten- of bezwaar-procedures.
- **Privacy officers (DPO/FG)**: bewaken dat workflow-instances PII niet langer bewaren dan nodig (retention-config op variables); valideren dat service-tasks geen PII naar non-verwerkers sturen.
- **Hydra / OpenBuilt platform-team**: bouwen starter-templates voor sectoren (GEMMA voor overheid, zorg-pad voor zorginstellingen, factuur-flow voor MKB), valideren nieuwe node-types op backward-compatibility.
- **External integrators / consultants**: implementeren OpenBuilt-apps bij klanten, hergebruiken design-patterns over implementaties heen, bouwen domein-specifieke template-packs voor verkoop in catalogue.
- **End-customer eindgebruikers (bij MKB-implementaties)**: ontvangen automatische triggers (factuur-flow, klant-onboarding), zien voortgang in self-service portaal.
