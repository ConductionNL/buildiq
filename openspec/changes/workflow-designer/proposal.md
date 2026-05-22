---
kind: code
depends_on: ["bootstrap-openbuilt", "openbuilt-page-designer", "openbuilt-rbac"]
chain:
  - bootstrap-openbuilt
  - openbuilt-page-designer
  - openbuilt-rbac
  - workflow-designer  # THIS spec — adds process dimension
---

## Why

OpenBuilt is een low-code app builder voor citizen developers die gegevens-applicaties bouwen door schemas, pagina's en logica te definiëren. De huidige scope dekt drie dimensies: **gegevens** (schema-designer), **interface** (page-designer), en **navigatie + runtime + delivery** (versioning, exporter, RBAC). 

Wat ontbreekt is de vierde essentiële dimensie: **processen**. Hoe stroomt data en werk door de applicatie heen — wie krijgt wat te doen, in welke volgorde, onder welke voorwaarden, met welke escalaties? In de praktijk stranden OpenBuilt-applicaties van enige omvang op dit gemis; gebruikers bouwen workarounds met handmatige taakverdeling buiten de app, of installeren externe BPM-engines (Camunda, Flowable) die niet integreren met hun OpenBuilt-data en RBAC-model.

Deze spec voegt een **Workflow Designer** toe: een visuele drag-and-drop omgeving waarmee citizen developers procesflows configureren tussen schema-objecten, formulieren en integraties. Het is bewust **BPMN-light** — een vereenvoudigd subset van BPMN 2.0 dat ~90% van echte use cases dekt zonder de leercurve van volledig BPMN.

Met workflows wordt OpenBuilt compleet: nu kun je niet alleen _gegevens_ en _interfaces_ definiëren, maar ook hoe taken automatisch toegewezen en voltooid worden, hoe escalaties werken, hoe externe systemen betrokken worden, en hoe alles auditabel is.

## What Changes

- **NEW** 8 schemas onder `openbuilt/workflow-designer/`: `workflow_definition`, `workflow_node`, `workflow_edge`, `workflow_variable_spec`, `workflow_instance`, `task_instance`, `workflow_timer`, `workflow_event_log` — volledige data-model voor workflow-definities, lopende instances, taken en audit-logs.

- **NEW** Visuele canvas-editor `src/components/WorkflowDesigner.vue` en gerelateerde UI-componenten: node-palette, property-panel, zoom/pan, undo/redo, validation-overlay.

- **NEW** Runtime-engine in de OpenBuilt-runtime die workflow-instances opstart, taken toewijst naar users/rollen/groepen, condities evalueert op gateways, timers schedult, escalaties afhandelt.

- **NEW** Vier start-event types: handmatig, scheduled (cron), op data-event (object-created/-updated), via HTTP-API.

- **NEW** Vier task-node types: user-tasks met form-rendering en dynamische assignment, service-tasks voor externe API's en cross-app integraties (procest, decidesk, docudesk), inclusief retry-logica.

- **NEW** Gateways: exclusief (branching op condities), parallel (split/join). Condities zijn safe-subset JavaScript tegen workflow-variables.

- **NEW** Timers en escalatie: intermediate-timers (wacht N), boundary-timers op tasks (escaleer bij overdue), configureerbare escalatie-acties.

- **NEW** Versioning: workflows versioneren net als Applications; running instances draaien op hun originele versie, geen forced migration.

- **NEW** Debugger: instance-trace met chronologische event-timeline, variable-state snapshot per event, visualisatie van het pad op canvas.

- **NEW** RBAC-integratie: rollen `workflow_designer`, `workflow_operator`, `task_assignee` via openbuilt-rbac; start_api endpoints hebben scoped service-account-tokens.

- **NEW** Export-integratie met openbuilt-exporter: workflows exporteerbaar als executable spec voor cross-environment delivery.

### Capabilities

#### New Capabilities

- `workflow-designer`: The visual BPMN-light editor, canvas with node-palette, drag-and-drop, properties panel, validation-overlay. Owns the 8 data-model schemas (workflow_definition, workflow_node, etc.), the canvas UI components, and editor state management.

- `workflow-runtime`: The process engine that executes workflow instances. Handles instance lifecycle (start → node-entry/exit → completion), task assignment (fixed user / role / group / dynamic expression), gateway evaluation (exclusive on condition / parallel split-join), timer firing, escalation, and event-log persistence.

- `workflow-start-events`: Four trigger types (manual, scheduled, on-data-event, api) that spawn workflow instances.

- `workflow-task-nodes`: User-tasks bound to page-designer forms, and service-tasks that call external APIs (via openconnector) or cross-app actions (procest, decidesk, docudesk).

- `workflow-versioning`: Semver versioning of workflow definitions with snapshot persistence; running instances respect their snapshot version.

- `workflow-debugging`: Instance trace with event timeline, variable-state snapshots, canvas path visualization for debugging hung or completed instances.

#### Modified Capabilities

- `openbuilt-rbac`: No schema changes; adds three new role definitions to the standard RBAC setup.
- `openbuilt-runtime`: Consumes workflow-start-events; task-inbox component renders task_instance entries; start-button actions trigger manual workflow starts.

## Impact

- **New code**:
  8 schemas in `lib/Settings/openbuilt_register.json`;
  canvas UI components under `src/components/workflow-editor/`;
  runtime engine service classes under `lib/Service/Workflow/`;
  background jobs for scheduled events and timer firing;
  controller endpoints for designer + runtime APIs;
  Pinia store for editor state;
  custom Vue composables for canvas + state management.

- **External dependencies**: None beyond existing stack. Canvas rendering uses Nextcloud Vue component library; node layout via existing graph libraries if needed.

- **OpenRegister**: Uses OR's standard REST + validation; 8 new schemas with lifecycle declarations for workflow-instance state machine.

- **Backward compatibility**: Purely additive. Existing virtual apps continue to run unaffected. New workflows only activate when explicitly created and published.

- **Foundational ADRs honoured**:
  - ADR-002 (versioning): workflows follow the same versioning model as Applications.
  - ADR-022 (register namespaces): all 8 schemas live in the `openbuilt` namespace.
  - ADR-031 (declarative + imperative split): workflow-instance state machine declarative in OR schema; runtime execution is code exception.
  - Cross-integration (ADR-016, ADR-019): workflows integrate with page-designer (forms), rbac (roles), runtime (instance startup), exporter (export spec).
