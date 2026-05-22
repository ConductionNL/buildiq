## ADDED Requirements

### Requirement: REQ-WFD-001 Visuele Designer-canvas met drag-and-drop

The system SHALL provide a visual workflow-designer canvas with the following components: (a) left node-palette listing available node types (start events, tasks, gateways, end events), (b) center canvas for drag-and-drop composition, (c) right property-panel for configuring selected node, (d) zoom/pan controls, (e) snap-to-grid positioning, (f) undo/redo stack (≥30 steps), (g) validation-overlay with red error badges on invalid nodes, (h) live canvas-JSON persistence (debounced 1 second).

#### Scenario: Nieuwe workflow opstellen met validatie-badges

- **GIVEN** een lege workflow `subsidie-aanvraag-flow` in concept-status
- **WHEN** de builder drag-and-drops start_manual → task_user → gateway_exclusive → 2x task_user → end_normal, tekent connectors, en klikt "Valideren"
- **THEN** worden alle nodes en edges als `workflow_node` / `workflow_edge` opgeslagen, het canvas-JSON debounced geupdate per wijziging, en de property-panel toont contextuele configuratie per node
- **AND** een task_user zonder assignee-config krijgt een rode badge met tooltip "ontbrekende assignee"
- **AND** een gateway zonder default edge krijgt een rode badge met tooltip "ontbrekende default edge"
- **AND** publish-knop blijft disabled zolang badges zichtbaar zijn

#### Scenario: Undo/redo werkt correct over 30+ acties

- **GIVEN** builder voert 35 edits uit (nodes toevoegen, verplaatsen, connectors tekenen)
- **WHEN** hij 10x klikt "Undo"
- **THEN** keert de canvas 10 stappen terug naar de toestand 25 edits geleden
- **AND** "Redo" brengt de 10 stappen terug

---

### Requirement: REQ-WFD-002 Start-events: Manueel, Scheduled, Op-data-event, API

The system SHALL support four start-event node types with the following triggers: (a) `start_manual` — user initiates via UI button, (b) `start_scheduled` — recurring cron-based trigger (e.g., "every Monday 09:00"), (c) `start_event` — spawned on schema data-event (object-created, object-updated), (d) `start_api` — HTTP POST with unique token-based authentication.

#### Scenario: Scheduled start op basis van cron

- **GIVEN** workflow met `start_scheduled` node config `{"cron":"0 9 * * MON"}` (elke maandagochtend 09:00 CET)
- **WHEN** de scheduler-job at 2026-05-26 09:00 CET passeert
- **THEN** wordt automatisch een nieuwe `workflow_instance` gemaakt met `gestart_via='scheduled'`, `gestart_op=2026-05-26T09:00:00Z`
- **AND** de eerste downstream node (volgende na het start_scheduled event) wordt onmiddellijk geactiveerd
- **AND** een event-log entry wordt aangemaakt: `{event_type: 'instance_started', actor_id: null, timestamp: <now>}`

#### Scenario: Op-object-event start met variable binding

- **GIVEN** workflow met `start_event` node config `{"schema":"subsidie_aanvraag","event":"object.created"}`
- **WHEN** een nieuw subsidie_aanvraag object wordt aangemaakt in de applicatie (via page-designer form of API)
- **THEN** wordt een `workflow_instance` spawn met:
  - `variables.aanvraag_id = {nieuwe object UUID}`
  - `gerelateerd_object_type='subsidie_aanvraag'`
  - `gerelateerd_object_id=<UUID>`
  - `gestart_via='event'`
- **AND** eerste downstream node wordt geactiveerd
- **AND** task-assignees ontvangen notificaties

#### Scenario: API-trigger met scoped service-token

- **GIVEN** workflow met `start_api` node config `{"enabled":true}`, systeem genereert unieke token `sk_wf_abc123xyz...` 
- **WHEN** externe systeem POSTs naar `POST /api/workflows/{workflow_id}/start-via-api` met header `Authorization: Bearer sk_wf_abc123xyz...` en body `{"bedrag": 5000, "aanvrager_id": "user-456"}`
- **THEN** wordt `workflow_instance` gecreëerd met `variables.bedrag=5000, variables.aanvrager_id='user-456'`, `gestart_via='api'`
- **AND** API retourneert 201 met `{instance_uuid: "wi-...", status: "lopend"}`
- **AND** token is rate-limited (100 req/hour) en scoped alleen tot deze workflow

---

### Requirement: REQ-WFD-003 User-tasks met formulier-rendering en dynamische assignment

The system SHALL support `task_user` nodes with: (a) form-binding naar een page-designer form-component (pagina-ID + form-ID), (b) assignment via fixed user UUID, fixed role name, fixed group UUID, or dynamic JavaScript expression tegen workflow-variables, (c) optional due-date (relatief offset e.g. "+3D" of absoluut datum).

#### Scenario: Dynamische assignment via variabel-expressie

- **GIVEN** task_user "Inhoudelijke beoordeling" config:
  ```json
  {
    "form_page_id": "form_beoordeling_v2",
    "assignee_mode": "dynamic",
    "assignee_expression": "variables.aanvraag.toegewezen_behandelaar",
    "due_offset": "P3D"
  }
  ```
- **WHEN** workflow_instance bereikt deze task-node met `variables.aanvraag.toegewezen_behandelaar = "jan.devries"`
- **THEN** wordt een `task_instance` aangemaakt met:
  - `assignee_user_id = <UUID van jan.devries>`
  - `status='open'`
  - `due_datum = <vandaag + 3 dagen>`
- **AND** jan.devries ontvangt een Nextcloud-notificatie "Je hebt een taak: Inhoudelijke beoordeling"
- **AND** taak verschijnt in zijn task-inbox
- **AND** form-component van page_beoordeling_v2 wordt geladen, data-bindings naar form-fields werken

#### Scenario: Groep-assignment met claim-workflow

- **GIVEN** task_user met config `{"assignee_mode":"group", "assignee_group_id": "fin-team"}` (groep met 6 leden)
- **WHEN** task wordt aangemaakt
- **THEN** hebben alle 6 leden de task in hun "Beschikbare taken (mijn groep)"-sectie van de inbox
- **AND** `task_instance.assignee_user_id = null` (nog niet geclaimd)
- **AND** wanneer Maria op "Claim deze taak" klikt:
  - `claimed_op` wordt gezet op huidige timestamp
  - `assignee_user_id` wordt gezet op Maria's UUID
  - Taak verdwijnt uit de inboxes van de andere 5 teamleden
  - Maria ontvangt bevestigingsnotificatie "Taak geclaimd"

---

### Requirement: REQ-WFD-004 Service-tasks: Openconnector + Cross-app integraties

The system SHALL support `task_service` nodes that invoke: (a) an openconnector source with request/response variable-mapping, or (b) a cross-app action via Conduction integration-registry (e.g., `procest.create_zaak`, `decidesk.send_for_signature`). Retry-logic (e.g., exponential backoff, max 3 retries) and error-handling (fallback to end_error node or continue) are configurable.

#### Scenario: Openconnector API-call met request/response mapping

- **GIVEN** service-task config:
  ```json
  {
    "type": "openconnector",
    "source_id": "bag-api",
    "operation": "get_adres",
    "input_mapping": {
      "postcode": "variables.aanvrager.postcode",
      "huisnummer": "variables.aanvrager.huisnummer"
    },
    "output_mapping": {
      "variables.bag_adres": "$.adres"
    },
    "on_error": "end_error"
  }
  ```
- **WHEN** workflow_instance bereikt deze node
- **THEN** wordt openconnector source `bag-api` aangeroepen met request-body `{postcode: "2512NP", huisnummer: "100"}`
- **AND** response `{adres: "Binnenhof 100, Den Haag"}` wordt gemapt naar `variables.bag_adres`
- **AND** flow vervolgt naar volgende node
- **AND** event-log entry: `{event_type: 'task_completed', node_id: 'task_bag_lookup', payload: {source: 'bag-api', status: 200}}`

- **IF** HTTP-error 500 retourneert:
  - Retry-logic: exponential backoff, max 3 retries over 10 minuten
  - Na 3e fout: flow springt naar end_error node (per config `"on_error": "end_error"`)
  - Instance krijgt `status='fout'`, `foutmelding='BAG-API timeouts after 3 retries'`

#### Scenario: Cross-app action zaak aanmaken in Procest

- **GIVEN** service-task config:
  ```json
  {
    "type": "cross_app",
    "action": "procest.create_zaak",
    "input_mapping": {
      "zaaktype": "\"subsidie-evaluatie\"",
      "initiator": "variables.aanvrager.id",
      "onderwerp": "variables.subsidie_titel"
    }
  }
  ```
- **WHEN** node wordt bereikt
- **THEN** wordt via integration-registry een Procest `create_zaak` action ingediend
- **AND** response zaak-UUID wordt in `variables.gemaakte_zaak_id` opgeslagen
- **AND** audit-log entry gemaakt: `{event_type: 'task_completed', node_id: '...', payload: {action: 'procest.create_zaak', zaak_id: 'zaak-...'}}`

---

### Requirement: REQ-WFD-005 Gateways: Exclusief en Parallel

The system SHALL support `gateway_exclusive` nodes that evaluate outgoing edges in order and follow the first matching condition (or the default if no match), and `gateway_parallel_split` / `gateway_parallel_join` pairs that split flow into concurrent branches and synchronize completion.

#### Scenario: Exclusieve gateway op voorwaarde (bedrag)

- **GIVEN** exclusive-gateway node met 3 uitgaande edges:
  - Edge A: `expressie="bedrag <= 1000"`, label "Klein bedrag"
  - Edge B: `expressie="bedrag <= 5000"`, label "Gemiddeld bedrag"
  - Edge C: `is_default=true`, label "Groot bedrag"
- **WHEN** workflow_instance bereikt gateway met `variables.bedrag = 3200`
- **THEN** evaluator test Edge A: `3200 <= 1000` → false
- **AND** evaluator test Edge B: `3200 <= 5000` → true, MATCH
- **AND** flow volgt Edge B naar volgende node (bijv. task voor gemiddelde aanvragen)
- **AND** event-log entry: `{event_type: 'gateway_evaluated', node_id: 'gw_bedrag', payload: {expression: 'bedrag <= 5000', result: true, taken_edge: 'B'}}`

#### Scenario: Parallel split + join met asynchrone completion

- **GIVEN** flow: parallel-split → [task_X, task_Y] → parallel-join → taak_Z
- **WHEN** instance bereikt parallel-split op T=10:00
- **THEN** beide task_instances worden tegelijk aangemaakt:
  - `task_instance{node_id: 'task_X', status: 'open', assignee_user_id: 'user-alice'}`
  - `task_instance{node_id: 'task_Y', status: 'open', assignee_user_id: 'user-bob'}`
- **AND** `workflow_instance.huidige_node_ids = ['task_X', 'task_Y']` (beide actief)
- **AND** event-log: `{event_type: 'node_exited', node_id: 'split', ...}`, dan `{event_type: 'node_entered', node_id: 'task_X', ...}`, dan `{..., node_id: 'task_Y', ...}`

- **WHEN** user-alice completes task_X om T=10:15:
  - Event-log: `{event_type: 'task_completed', node_id: 'task_X', ..., voltooid_op: '10:15'}`
  - `workflow_instance.huidige_node_ids = ['task_Y']` (nog wachtend op Y)
  - Join-node staat om T=10:15 NOT af (wacht op task_Y)

- **WHEN** user-bob completes task_Y om T=10:30:
  - `workflow_instance.huidige_node_ids = ['task_Y']` → `[]` → join afgevuurd
  - `workflow_instance.huidige_node_ids = ['taak_Z']` (flow continues)

---

### Requirement: REQ-WFD-006 Timers en Escalatie

The system SHALL support `intermediate_timer` nodes (suspend flow for ISO 8601 duration) and `task_user` boundary-timers (escalate if task not completed within duration). Escalation-actions are configurable: reassign to manager-role, notify manager, move-to-other-task.

#### Scenario: Intermediate timer met 14-daagse wacht

- **GIVEN** workflow met intermediate_timer node config `{"duur":"P14D"}`
- **WHEN** workflow_instance bereikt node op 2026-05-01 14:00 UTC
- **THEN** wordt `workflow_timer` record aangemaakt:
  - `geplande_trigger_op = 2026-05-15 14:00 UTC`
  - `status = 'pending'`
- **AND** `workflow_instance.status = 'wachtend_op_timer'`
- **AND** timer-job checkt elke 5 minuten; op 2026-05-15 14:00 fires de timer:
  - `workflow_timer.status = 'fired'`, `getriggerd_op = <actual fire time>`
  - `workflow_instance.status = 'lopend'`, `huidige_node_ids = [volgende node]`
  - Event-log: `{event_type: 'timer_fired', node_id: 'timer_14d', ...}`

#### Scenario: Boundary escalation op taak na 3 dagen niet voltooid

- **GIVEN** task_user config:
  ```json
  {
    "due_offset": "P3D",
    "escalation": {
      "enabled": true,
      "on_overdue": "reassign_to_manager_role",
      "manager_role": "fin-manager"
    }
  }
  ```
- **WHEN** task_instance wordt aangemaakt op 2026-05-20 10:00, `due_datum = 2026-05-23`, assigned aan user-anita
- **AND** om 2026-05-23 16:00 passeert de deadline (3 dagen)
- **THEN** escalation-job detecteert overdue:
  - Zoekt alle users met rol `fin-manager`
  - Creëert nieuwe `task_instance` met `assignee_role='fin-manager'`, status='open'
  - Update originele task: `status='geëscaleerd'`, `escalation_count=1`
  - Anita krijgt notificatie: "Uw taak 'Goedkeuring aanvraag' is geëscaleerd naar uw manager"
  - Event-log: `{event_type: 'task_escalated', node_id: '...', escalation_count: 1}`

---

### Requirement: REQ-WFD-007 Workflow-versioning met backward-compatible running instances

The system SHALL implement semantic versioning for workflow-definitions (major.minor.patch). When a new version is published, the previous version is marked `deprecated` but retained. All running instances continue on their snapshot-version until completion; new instances start on the latest published version. Forced upgrades of running instances are NOT supported.

#### Scenario: Nieuwe versie publiceren, bestaande instances blijven op oud snapshot

- **GIVEN** workflow `subsidie-flow` v1.2.0 met `status='published'`, 47 running instances
- **AND** builder wijzigt de workflow, voegt extra task toe (wijzigingen nog in `draft` state)
- **WHEN** hij klikt "Publiceer versie 1.3.0"
- **THEN** systeem:
  - Creëert immutable snapshot van alle nodes, edges, variables
  - Slaagt versie op als v1.3.0, `status='published'`, `gepubliceerd_op=<now>`
  - Markeert v1.2.0 als `status='deprecated'`
  - Alle 47 running instances behouden `workflow_versie_snapshot='1.2.0'`
  - Nieuwe instances starten met `workflow_versie_snapshot='1.3.0'`
- **AND** Event-log kan per instance-version gefilterd worden

---

### Requirement: REQ-WFD-008 Workflow Debugger en Instance Trace

The system SHALL provide a debugger view for each `workflow_instance` with: (a) chronological timeline of events from `workflow_event_log`, (b) variable-state snapshot per event (inspect variables op elk moment), (c) canvas-overlay visualizing the executed path (groen) en huidente node (oranje), (d) error details en stack-trace bij failure.

#### Scenario: Debug-trace openen en events inspecteren

- **GIVEN** workflow_instance `WI-2026-001847` die sinds 3 dagen doorloopt, nu stuck op een task
- **WHEN** builder navigeert naar `/workflows/{definition_id}/instances/{instance_id}/trace`
- **THEN** verschijnt debug-view met:
  - Chronologische timeline: "instance_started" (2026-05-20 10:00), "node_entered task-review" (10:05), "task_assigned to jan.devries" (10:05), "node_exited task-review" (14:30), "node_entered gateway" (14:30), "gateway_evaluated" (14:31, took edge A), etc.
  - Klikbare events: op klik toont variable-state snapshot op dat moment
  - Canvas-overlay: groen path van start tot huidden node, huidden node in oranje
- **AND** zoek/filter op event-type of node-ID

#### Scenario: Error-details bij failure

- **GIVEN** instance met een service-task die nach 3 retries faalt
- **WHEN** builder inspecteur de trace
- **THEN** event-log toont: `{event_type: 'error_raised', node_id: 'task_api_call', level: 'error', timestamp: '...', payload: {error_message: 'BAG API timeout', retry_count: 3, final_error: true}}`
- **AND** instance.status = 'fout', instance.foutmelding = 'BAG API timeout after 3 retries'

---

### Requirement: REQ-WFD-009 RBAC-integratie: rollen workflow_designer, workflow_operator, task_assignee

The system SHALL integrate with openbuilt-rbac to enforce role-based access: (a) `workflow_designer` — mag definities create/edit/publish, (b) `workflow_operator` — mag instances starten (manueel), instances zien, (c) `task_assignee` — ziet alleen taken die naar hem/zijn rol/zijn groep geassigned. Service-account-tokens voor `start_api` endpoints zijn scoped per workflow.

#### Scenario: Ongeautoriseerde publish geweigerd

- **GIVEN** user `anita` met alleen rol `workflow_operator` (geen `workflow_designer`)
- **WHEN** zij POSTs naar `POST /api/workflows/{id}/publish` met payload `{version: "1.3.0"}`
- **THEN** API retourneert 403 Forbidden: `{error: 'Requires workflow_designer role'}`
- **AND** versie wordt NIET gepubliceerd

#### Scenario: Task-visibility scoped naar assignee

- **GIVEN** task_instance aangemaakt voor user `bob` op dag 1
- **WHEN** user `alice` (andere afdeling) navigeert naar `/inbox/all-tasks`
- **THEN** Alice ziet de taak NIET (is assigned aan bob)
- **WHEN** bob navigeert naar zijn inbox
- **THEN** Bob ziet de taak en kan hem claimen

---

### Requirement: REQ-WFD-010 Export naar executable spec via openbuilt-exporter

The system SHALL enable export of workflow-definitions as deterministic JSON specs via openbuilt-exporter. Exported spec SHALL contain all nodes, edges, variable-specs, and assignment-config (role-NAMES, niet user-IDs) zodat dev → test → prod promotie schoon werkt zonder user-ID hardcoding.

#### Scenario: Export + import workflow naar test-environment

- **GIVEN** workflow `subsidie-flow` v1.3.0 in dev-environment, fully published
- **WHEN** builder klikt "Export workflow" → selecteert target "Download ZIP"
- **THEN** geëxporteerde ZIP bevat:
  - `workflow.json`: volledige definition (nodes, edges, variables, config)
  - `variables-schema.json`: spec van alle workflow-variables
  - `roles-mapping.txt`: documentatie welke rollen expected in target-env
- **AND** no user-IDs hardcoded; assignment-config refereert rollen bij naam

- **WHEN** developer importeert ZIP in test-environment:
  - Systeem valideert dat rollen `fin-manager`, `fin-team` in test-env bestaan (warning als ontbrekend)
  - Creëert workflow v1.3.0 met `status='concept'` (niet auto-published)
  - Variabelen-defaults worden gemapt naar test-omgeving's schema-namespaces
  - Ready voor testing voor promotion naar prod
