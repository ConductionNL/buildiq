## Context

OpenBuilt's early specs built out a **data** layer (schema-designer), an **interface** layer (page-designer), and **runtime + delivery** (versioning, RBAC, exporter). This spec adds the **process** layer: workflows that orchestrate tasks, assignments, and integrations without writing code.

The process layer must integrate tightly with three existing capabilities:

- **page-designer**: user-tasks display forms defined in the page-designer; bidirectional preview and live link.
- **rbac**: assignment config references roles and groups from the RBAC system; dynamic expressions can access user attributes.
- **runtime**: the workflow-engine runs inside the OpenBuilt runtime; instances are spawned on data-events; task-inbox and start-button actions are wired into the runtime UI.

The Conduction stack provides reference implementations:

- **Camunda Platform / Camunda BPMN Modeler** — the de-facto BPMN reference; canvas UX, node-type conventions, execution semantics.
- **Activiti / Flowable** — BPMN engines; lessons learned about runtime design, timer scheduling, variable handling.
- **n8n** — workflow automation for system integration; clear decision-guide in docs for when a BPM-light workflow should hand off to a dedicated integration engine.
- **ISO/IEC 19510:2013 (BPMN 2.0)** — formal specification for node types, edge semantics, execution.

The spec deliberately **not** covers full BPMN (sub-processes, event sub-processes, compensation, transactions, message-correlation), CMMN (case management), or DMN (decision tables as a separate language). Exclusions are documented in design decisions.

## Goals / Non-Goals

**Goals:**

- Deliver a visual BPMN-light editor enabling citizen developers to define process flows without code.
- Support start-events (manual, scheduled, data-event, API), user-tasks with form-binding and dynamic assignment, service-tasks with external integration, exclusive and parallel gateways, timers and escalation.
- Maintain backward-compatible versioning: running instances stay on their original definition version; new instances use the latest.
- Provide debugging: instance trace with event timeline and variable-state snapshots for troubleshooting hung or errored processes.
- Integrate with openbuilt-page-designer (forms), openbuilt-rbac (roles), and openbuilt-runtime (task inbox, start actions).
- Support export: workflows exportable as executable spec via openbuilt-exporter for cross-environment promotion.
- Enforce deterministic execution: no floating-point time drift, no clock-dependent branching, reproducible event logs.

**Non-Goals:**

- **Full BPMN 2.0 coverage**: Sub-processes, compensation, message-correlation, event sub-processes. Rationale: 90% of real use cases fit BPMN-light; the remaining 10% should use dedicated case-management or integration tools.
- **DMN (Decision Model and Notation)**: Complex decision tables; `gateway_exclusive` edges use inline JavaScript expressions. Future: if expressions become unmanageable, upgrade to DMN tables.
- **CMMN (Case Management)**: Fully event-driven, unstructured work. OpenBuilt workflows are designed for structured processes; CMMN is a separate domain.
- **User-facing workflow simulation**: No "dry run" mode. A published workflow is final; preview is via the test-environment export + promote workflow.
- **Graphical condition-builder UI**: Conditions are inline JavaScript expressions. Rationale: a visual condition builder is complex and constrains expressiveness; plain text with inline syntax-highlight + validation is clearer.
- **Manual task reassignment within a running instance**: Tasks are assigned at creation; reassign via escalation or admin tooling (future).
- **Workflow sub-instances (calling workflows from workflows)**: Future; deferred because it adds significant complexity and is rare in citizen-developer use cases.

## Decisions

### Decision 1 — BPMN-light subset, not full BPMN

The workflow-designer SHALL implement exactly these node types: `start_manual`, `start_scheduled`, `start_event` (on data-event), `start_api`, `task_user`, `task_service`, `gateway_exclusive`, `gateway_parallel_split`, `gateway_parallel_join`, `intermediate_timer`, `end_normal`, `end_error`. It SHALL NOT implement sub-processes, compensation, message-events, or event sub-processes.

**Rationale**: 90% of government / MKB workflows fit this subset. Full BPMN is a 2-week learning curve; BPMN-light is learned in an hour. The remaining 10% of complex case-work should explicitly use a dedicated case-management tool (CMMN) or hand off to n8n for system integration.

**Alternatives considered**:
- *Full BPMN 2.0* — rejected: learning curve, feature bloat.
- *DMN for decisions* — deferred: inline JS expressions handle 95% of cases; if expressions become unwieldy (5-arg nested ternary), upgrade to DMN.

### Decision 2 — Conditions are JavaScript expressions, no visual builder

Exclusif-gateway and edge conditions are specified as JavaScript-like expressions (safe subset: no global access, only variables + math + string ops, no function calls). The UI displays the raw expression with syntax-highlight and validation feedback, not a visual condition builder.

**Rationale**: A graphical condition builder is itself a small language; it constrains expressiveness (e.g., can't express "both A and B, but not C"). Plain text with inline validation is clearer. The safe-subset evaluator prevents code injection and runaway loops.

**Alternatives considered**:
- *Visual if-then-else builder* — rejected: constrained expressiveness, complex UI.
- *DMN decision tables* — deferred: for the small percentage of workflows with unmanageable expressions.

### Decision 3 — Service-tasks via OpenConnector + cross-app action registry

Service-tasks support two modes: (a) call an openconnector source with request-body mapping and response-mapping, and (b) invoke a cross-app action via the Conduction integration-registry (e.g., `procest.create_zaak`, `decidesk.send_for_signature`). The system SHALL NOT support custom script-tasks (Node.js, Python, etc.) in v1.

**Rationale**: OpenConnector and integration-registry are production-proven; custom scripts introduce runtime-safety (sandbox, timeouts, resource limits) and DevOps overhead (versioning, logging, secrets).

**Alternatives considered**:
- *Custom script-tasks* — deferred: requires sandboxing, version control, secret injection — save for v2 after the core engine is stable.
- *Hand-crafted webhook + manual orchestration* — rejected: users would implement the workflow outside the system.

### Decision 4 — Versioning: immutable snapshots, no forced migration

When a workflow definition is published, it receives an immutable semver (e.g., 1.2.0). All running instances of an older version continue to completion on that version. New instances start on the latest published version. There is no forced upgrade of running instances.

**Rationale**: In government / finance, changing a running process mid-execution breaks SLAs and audit trails. Immutable snapshots ensure determinism and compliance.

**Alternatives considered**:
- *Forced migration to new version* — rejected: violates audit trail, breaks running processes.
- *Always-latest (no versioning)* — rejected: breaks compliance and SLAs.

### Decision 5 — Task assignment: fixed user, fixed role, fixed group, or dynamic expression

User-task nodes support four assignment modes: (a) fixed user UUID, (b) fixed role name, (c) fixed group UUID, (d) dynamic expression (e.g., `variables.aanvraag.toegewezen_behandelaar`, `users.find(u => u.departement === variables.departement)[0].id`). The UI enforces exactly-one-of these at publish time. Dynamic expressions are validated as safe-subset JavaScript.

**Rationale**: Covers 95% of real workflows. Role-based is most common; user is for escalation; group is for queue; dynamic expression is for complex assignment logic.

**Alternatives considered**:
- *Only role-based* — rejected: misses user-specific and group-based workflows.
- *Unlimited dynamic expressions* — rejected: no validation, risk of injection or runaway logic.

### Decision 6 — Parallel split/join must be balanced

A `gateway_parallel_split` must eventually sync with a `gateway_parallel_join`. The editor SHALL reject a flow where a split is unmatched. At runtime, the join waits for all branches to complete before advancing.

**Rationale**: Unmatched split/join leads to deadlock. Balancing is enforced at publish time to catch errors early.

**Alternatives considered**:
- *Implicit join (no explicit gateway)* — rejected: unclear when to sync; causes silent deadlocks.

### Decision 7 — Timers use ISO 8601 durations (not cron)

Intermediate-timer and boundary-timer nodes specify durations in ISO 8601 format (e.g., `P3D` for 3 days, `PT2H30M` for 2.5 hours). Scheduled-start nodes use cron expressions (e.g., `0 9 * * MON`). This split is intentional: durations are relative (for instance-specific timers); cron is absolute (for scheduled starts).

**Rationale**: ISO 8601 is standard for relative durations. Cron is standard for scheduled jobs.

**Alternatives considered**:
- *Cron for all* — rejected: can't express "3 days from when this task was created".
- *Custom duration language* — rejected: ISO 8601 is standard and widely understood.

### Decision 8 — Event-log is append-only, never pruned during instance lifetime

Workflow-instances maintain an append-only `workflow_event_log` recording every node entry/exit, task assignment, gateway evaluation, timer firing, variable mutation, and error. The log is never pruned while the instance is running. After completion, retention is configurable (default: 90 days for audit, then purge).

**Rationale**: Audit trail for compliance; source-of-truth for debugging; immutable for legal disputes.

**Alternatives considered**:
- *Rotating log (bounded size)* — rejected: breaks audit trails, legal disputes need full history.
- *Real-time streaming to external log system* — deferred: added complexity; append-only local log sufficient for v1.

### Decision 9 — No manual runtime modification of variables or task state

The debugger is read-only for v1. Variables and task state cannot be mutated from the UI (e.g., no "force complete this task" button). Remediation is: fix the workflow definition, export a new version, and let running instances finish on the old version.

**Rationale**: Manual overrides break determinism and audit trails. If a process is stuck, it's a design error; fix the design.

**Alternatives considered**:
- *Admin UI to force-complete tasks* — deferred: requires careful audit logging and permission checks; saves for v2 after core is stable.

### Decision 10 — Export includes workflow + schema snapshot, not seed data

When a workflow is exported via openbuilt-exporter, the spec includes the workflow definition (all 8 schemas at current version). Schema seed data is NOT included in the workflow export (it's exported separately as an Application export). This keeps workflow exports lightweight and composable.

**Rationale**: Workflows are process templates; schemas are data structures. Exporting them together creates redundancy. Templates often reuse a base schema from another app (e.g., a subsidies-request schema used by multiple workflows).

**Alternatives considered**:
- *Bundle seed data with workflow export* — rejected: redundancy, tight coupling.

## Data Model Overview

Eight schemas under `openbuilt/workflow-designer/`:

| Schema | Role |
|--------|------|
| `workflow_definition` | Versioned workflow blueprint: nodes, edges, variables, metadata |
| `workflow_node` | Single node (task, gateway, event, etc.) with config and position |
| `workflow_edge` | Connection between nodes, with optional condition expression |
| `workflow_variable_spec` | Workflow-scoped variable declarations (name, type, default, input/output) |
| `workflow_instance` | Runtime instance of a workflow: status, current node, variables, related object |
| `task_instance` | Assigned task (user-task node instance) with form data and status |
| `workflow_timer` | Scheduled timer (intermediate or boundary) with fire timestamp |
| `workflow_event_log` | Append-only audit trail of instance events (node entry, task assigned, timer fired, etc.) |

Each is declared in `lib/Settings/openbuilt_register.json` with proper OpenAPI schema, validation, and (for `workflow_instance` and `task_instance`) lifecycle declarations per ADR-031.

## Seed Data

Example workflow and related instances for testing and documentation:

```
workflow_definition:
  - uuid: "wf-001", slug: "subsidie-aanvraag-flow", version: "1.0.0", status: "published"
    nodes: [start_manual, task_user "Inhoudelijke beoordeling", gateway_exclusive on bedrag, ...]
    edges: [...]
    variables: [{name: "aanvraag_id", type: "object_reference"}, {name: "bedrag", type: "number"}, ...]

workflow_instance:
  - uuid: "wi-001", workflow_definition_id: "wf-001", workflow_versie_snapshot: "1.0.0"
    status: "lopend", huidige_node_ids: ["task-review"], variables: {aanvraag_id: "aanvraag-123", bedrag: 3200}
    gestart_op: "2026-05-20T10:30Z", gestart_door_id: "user-alice", gestart_via: "manual"

task_instance:
  - uuid: "ti-001", workflow_instance_id: "wi-001", node_id: "task-review"
    assignee_user_id: "user-jan", status: "open", due_datum: "2026-05-23"
    form_data: {opmerking: "Goedgekeurd"}
```

All Dutch values (field names, sample text) follow existing conventions in openbuilt-page-designer.
