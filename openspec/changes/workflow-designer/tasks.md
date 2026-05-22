## 1. Data Model Schemas (Declarative — ADR-031)

- [ ] 1.1 **Declare `workflow_definition` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-WFD-001, REQ-WFD-007, REQ-WFD-010
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Schema declares `uuid`, `application_id` (FK), `naam`, `slug` (unique within app), `omschrijving`, `versie` (semver), `status` (enum: concept|gepubliceerd|gedeprecateerd), `canvas_json` (JSON), `gepubliceerd_op` (date-time), `gepubliceerd_door_id` (FK users), `tags` (array). Validates against OpenAPI 3.0.0. No PHP workflow state machine class created; all lifecycle declarative in schema.

- [ ] 1.2 **Declare `workflow_node` schema**
  - spec_ref: REQ-WFD-001
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_definition_id` (FK), `node_id` (string, stable within workflow), `node_type` (enum: start_manual|start_scheduled|start_event|start_api|task_user|task_service|gateway_exclusive|gateway_parallel_split|gateway_parallel_join|intermediate_timer|end_normal|end_error), `naam`, `config` (JSON, type-specific), `position_x` (int), `position_y` (int).

- [ ] 1.3 **Declare `workflow_edge` schema**
  - spec_ref: REQ-WFD-001, REQ-WFD-005
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_definition_id` (FK), `from_node_id`, `to_node_id`, `label` (text), `expressie` (text for conditions), `is_default` (bool for exclusive-gateway fallback).

- [ ] 1.4 **Declare `workflow_variable_spec` schema**
  - spec_ref: REQ-WFD-003, REQ-WFD-004
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_definition_id` (FK), `naam` (snake_case), `data_type` (enum: string|number|boolean|date|datetime|object|array|file_reference|object_reference), `referenced_schema_slug` (nullable, for object_reference), `default_value` (JSON), `omschrijving`, `is_input` (bool), `is_output` (bool).

- [ ] 1.5 **Declare `workflow_instance` schema with state-machine lifecycle**
  - spec_ref: REQ-WFD-002, REQ-WFD-006, REQ-WFD-007
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_definition_id` (FK), `workflow_versie_snapshot` (semver), `gestart_op` (date-time), `gestart_door_id` (FK users, nullable), `gestart_via` (enum: manual|scheduled|event|api), `huidige_node_ids` (array), `status` (enum: lopend|voltooid|geannuleerd|fout|wachtend_op_timer), `variables` (JSON), `gerelateerd_object_type` (string), `gerelateerd_object_id` (UUID), `beëindigd_op`, `eindstatus_node_id`, `foutmelding`. **x-openregister-lifecycle** declares states and transitions: `lopend → voltooid|geannuleerd|fout|wachtend_op_timer`, no re-entry to lopend.

- [ ] 1.6 **Declare `task_instance` schema with claim-workflow**
  - spec_ref: REQ-WFD-003
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_instance_id` (FK), `node_id`, `naam` (denormalized), `assignee_user_id` (FK users, nullable), `assignee_role` (string, nullable), `assignee_group_id` (FK groups, nullable), `aangemaakt_op` (date-time), `claimed_op` (date-time, nullable), `due_datum` (date, nullable), `status` (enum: open|geclaimd|voltooid|gedelegeerd|geëscaleerd|geannuleerd), `voltooid_op`, `voltooid_door_id`, `form_data` (JSON), `escalation_count` (int). Validation: exactly-one-of assignee_user_id / assignee_role / assignee_group_id (or all null for unclaimed).

- [ ] 1.7 **Declare `workflow_timer` schema**
  - spec_ref: REQ-WFD-006
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_instance_id` (FK), `node_id`, `geplande_trigger_op` (date-time), `getriggerd_op` (date-time, nullable), `status` (enum: pending|fired|cancelled), `payload` (JSON).

- [ ] 1.8 **Declare `workflow_event_log` schema (append-only)**
  - spec_ref: REQ-WFD-008
  - files: `lib/Settings/openbuilt_register.json`
  - acceptance_criteria: Declares `uuid`, `workflow_instance_id` (FK), `event_type` (enum: instance_started|node_entered|node_exited|task_assigned|task_completed|timer_fired|gateway_evaluated|variable_set|instance_completed|error_raised), `node_id` (nullable), `timestamp`, `actor_id` (FK users, nullable), `payload` (JSON), `level` (enum: info|warn|error). No update/delete operations permitted; read-only append-only.

## 2. Canvas Editor Components

- [ ] 2.1 **Build `src/components/workflow-editor/WorkflowCanvas.vue`**
  - spec_ref: REQ-WFD-001
  - files: `src/components/workflow-editor/WorkflowCanvas.vue`
  - acceptance_criteria: Canvas component with zoom (100% to 500%), pan (mouse drag), snap-to-grid (16px), drag-and-drop node placement. Emits `@node-added`, `@node-moved`, `@node-removed`, `@edge-added` events. Renders `<svg>` for connections, `<div>` overlays for nodes. Debounced (1000ms) save to parent.

- [ ] 2.2 **Build `src/components/workflow-editor/NodePalette.vue`**
  - spec_ref: REQ-WFD-001
  - files: `src/components/workflow-editor/NodePalette.vue`
  - acceptance_criteria: Left sidebar listing node-type categories (Start Events, Tasks, Gateways, End Events) with draggable items. Each item shows icon and label. Drag-to-canvas spawns new node.

- [ ] 2.3 **Build `src/components/workflow-editor/PropertyPanel.vue`**
  - spec_ref: REQ-WFD-001
  - files: `src/components/workflow-editor/PropertyPanel.vue`
  - acceptance_criteria: Right sidebar rendering contextuele properties for selected node. Tabs: General (name, description), Config (type-specific JSON editor), Advanced (position, z-index). V-model binds to parent's `selectedNode`.

- [ ] 2.4 **Build `src/components/workflow-editor/ValidationOverlay.vue`**
  - spec_ref: REQ-WFD-001
  - files: `src/components/workflow-editor/ValidationOverlay.vue`
  - acceptance_criteria: Render red error-badges on invalid nodes. Badge shows count of errors; tooltip lists them (e.g., "Missing assignee", "Gateway requires default edge"). Updates on every canvas change.

- [ ] 2.5 **Build `src/composables/useCanvasUndoRedo.js`**
  - spec_ref: REQ-WFD-001
  - files: `src/composables/useCanvasUndoRedo.js`
  - acceptance_criteria: Composable managing undo/redo stack (≥30 steps). Exposes `undo()`, `redo()`, `canUndo`, `canRedo`, `push(state)`. Each canvas change pushes state; undo/redo navigate stack. Test: 35 edits, undo 10, redo 10 restores.

## 3. Start Events (Implementation)

- [ ] 3.1 **Implement scheduled-start background job**
  - spec_ref: REQ-WFD-002
  - files: `lib/BackgroundJob/TriggerScheduledWorkflows.php` (IJob, runs every 5 min)
  - acceptance_criteria: Query all `workflow_definition` nodes with `node_type=start_scheduled`, parse cron expression, check if current time matches. For each match, create a new `workflow_instance` with `gestart_via='scheduled'`, `gestart_door_id=null`. Enqueue runtime job to advance the instance.

- [ ] 3.2 **Implement on-data-event trigger**
  - spec_ref: REQ-WFD-002
  - files: `lib/Service/Workflow/EventSubscriber.php` (hooks into OpenRegister object-lifecycle)
  - acceptance_criteria: Register subscriber for `object.created` and `object.updated` events on any schema. On event, query `workflow_definition` nodes with `node_type=start_event` filtering by matching schema. Create `workflow_instance` with `gerelateerd_object_type`, `gerelateerd_object_id`, and first `variables` bound to event-payload.

- [ ] 3.3 **Implement start_api HTTP endpoint**
  - spec_ref: REQ-WFD-002
  - files: `lib/Controller/WorkflowController.php` method `startViaApi()`
  - acceptance_criteria: `POST /api/workflows/{workflow_id}/start-via-api` with `Authorization: Bearer sk_wf_...` header. Validate token scoped to workflow. Parse request body as variable-map. Create `workflow_instance` with `gestart_via='api'`. Return 201 with `{instance_uuid, status}`. Rate-limit: 100 req/hour per token.

## 4. User-Task Assignment & Forms

- [ ] 4.1 **Implement dynamic-assignment expression evaluator**
  - spec_ref: REQ-WFD-003
  - files: `lib/Service/Workflow/AssignmentEvaluator.php`
  - acceptance_criteria: `evaluateAssignment(task_config, variables, rbacContext): uuid|role|group` method. Parse expression (safe-subset JS: no globals, only variables + . notation + math + string ops). Return assignee user-UUID / role-name / group-UUID. Raise exception on parse error or unsafe code.

- [ ] 4.2 **Implement form-data binding in task-instance**
  - spec_ref: REQ-WFD-003
  - files: `lib/Service/Workflow/FormDataMapper.php`, `src/components/workflow-runtime/TaskFormRenderer.vue`
  - acceptance_criteria: When task_instance loads form (from page-designer by page_id + form_id), map form-fields to `task_instance.form_data` on submit. Handle type coercion (date strings, numbers, file references). Validate against form schema before persist.

- [ ] 4.3 **Build task-inbox UI component**
  - spec_ref: REQ-WFD-003
  - files: `src/components/workflow-runtime/TaskInbox.vue`
  - acceptance_criteria: Render user's open + group-claimed tasks. Sections: "Mijn taken" (open, user-assigned), "Beschikbare taken" (group-assigned, unclaimed). Claim button transitions task to claimed. Form link opens form-renderer in sidebar/modal.

## 5. Service-Tasks & Integration

- [ ] 5.1 **Implement openconnector request/response mapper**
  - spec_ref: REQ-WFD-004
  - files: `lib/Service/Workflow/OpenconnectorInvoker.php`
  - acceptance_criteria: `invoke(service_task_config, variables): response` method. Map `input_mapping` from variables to request-body. Call openconnector source via its SDK/REST. Map `output_mapping` from response (JSONPath) back to variables. Handle errors: retry logic (exponential backoff, max 3), fallback-action (continue or end_error).

- [ ] 5.2 **Implement cross-app action registry calls**
  - spec_ref: REQ-WFD-004
  - files: `lib/Service/Workflow/CrossAppActionInvoker.php`
  - acceptance_criteria: `invoke(action: "procest.create_zaak"|"decidesk.send_for_signature"|"docudesk.generate_document", payload, variables): response` method. Look up action in ADR-019 integration-registry. Call via registered endpoint. Handle response, update variables. Audit-log the action.

- [ ] 5.3 **Background job for service-task retry logic**
  - spec_ref: REQ-WFD-004
  - files: `lib/BackgroundJob/RetryServiceTask.php`
  - acceptance_criteria: IJob tracking failed service-task invocations. Implements exponential backoff: 1min, 2min, 4min for retries. Max 3 retries. On final failure, transition instance to end_error node (or continue, per config).

## 6. Gateways & Control Flow

- [ ] 6.1 **Implement exclusive-gateway condition evaluator**
  - spec_ref: REQ-WFD-005
  - files: `lib/Service/Workflow/ConditionEvaluator.php`
  - acceptance_criteria: `evaluateCondition(expression: string, variables: array): bool` method. Safe-subset JS parser (no globals, only variables, math, string ops). Evaluate each outgoing edge's expression in order. Return the first-matched edge, or default edge if none match. Raise exception on unsafe/invalid expression.

- [ ] 6.2 **Implement parallel split/join logic**
  - spec_ref: REQ-WFD-005
  - files: `lib/Service/Workflow/ParallelGatewayManager.php`
  - acceptance_criteria: `splitParallel(split_node_id, downstream_nodes): task_instances[]` method. Create a task_instance for each downstream branch. Track join-node-id. `joinParallel(join_node_id, workflow_instance): bool` method returns true when all branch-tasks complete. Reject unmatched split/join at publish-time validation.

- [ ] 6.3 **Add publish-time flow validation**
  - spec_ref: REQ-WFD-005
  - files: `lib/Service/Workflow/FlowValidator.php`
  - acceptance_criteria: Before publishing a workflow_definition, validate: (a) ≥1 start-node and ≥1 end-node present, (b) no orphaned nodes, (c) all exclusive-gateways have a default edge, (d) all parallel-splits have matching joins, (e) all edges have valid expressions (condition-evaluator can parse).

## 7. Timers & Escalation

- [ ] 7.1 **Implement intermediate-timer node handling**
  - spec_ref: REQ-WFD-006
  - files: `lib/Service/Workflow/TimerManager.php`, `lib/BackgroundJob/FireTimers.php`
  - acceptance_criteria: When instance enters intermediate_timer node, parse ISO 8601 duration (e.g., "P3D"), compute fire timestamp, create `workflow_timer` record. Background job checks every 5 min; when `geplande_trigger_op <= now`, set `status='fired'`, advance instance to next node.

- [ ] 7.2 **Implement boundary-timer escalation on task**
  - spec_ref: REQ-WFD-006
  - files: `lib/Service/Workflow/TaskEscalationManager.php`, `lib/BackgroundJob/EscalateTasks.php`
  - acceptance_criteria: When task_instance created with `due_datum`, create associated boundary-timer. Check every 5 min; on due-date exceeded, apply escalation-action (reassign_to_manager_role, notify_manager, move_to_other_task). Create audit log entries.

- [ ] 7.3 **Implement escalation-action handlers**
  - spec_ref: REQ-WFD-006
  - files: `lib/Service/Workflow/EscalationActionExecutor.php`
  - acceptance_criteria: Three action handlers: (a) `reassignToManagerRole()` — find manager role, create new task; mark old task as geëscaleerd, (b) `notifyManager()` — send notification to user's manager, (c) `moveToOtherTask()` — assign to fallback task-node.

## 8. Versioning

- [ ] 8.1 **Implement workflow publish with snapshot and version-bump**
  - spec_ref: REQ-WFD-007
  - files: `lib/Service/Workflow/PublishService.php`
  - acceptance_criteria: `publish(workflow_definition, version_string)` method. Create immutable snapshot (copy canvas_json, nodes, edges, variables to version-specific storage). Mark previous version as deprecated. All new instances use latest version. Running instances keep their snapshot-version.

- [ ] 8.2 **Implement instance-versioning on start**
  - spec_ref: REQ-WFD-007
  - files: `lib/Service/Workflow/InstanceFactory.php`
  - acceptance_criteria: When creating a new `workflow_instance`, query the latest published version of the workflow_definition, set `workflow_versie_snapshot` to its semver. Load definition from that snapshot version for execution.

## 9. Debugger & Tracing

- [ ] 9.1 **Build instance-trace timeline UI**
  - spec_ref: REQ-WFD-008
  - files: `src/views/WorkflowInstanceDebug.vue`
  - acceptance_criteria: Vertical timeline rendering `workflow_event_log` entries chronologically (instance_started, node_entered, task_assigned, task_completed, gateway_evaluated, timer_fired, error_raised). Each entry shows timestamp, actor, event-type. Clickable to show variable-state and error details at that moment.

- [ ] 9.2 **Build canvas-overlay path visualization**
  - spec_ref: REQ-WFD-008
  - files: `src/components/workflow-editor/InstancePathOverlay.vue`
  - acceptance_criteria: Render the workflow canvas with the executed path highlighted (green line from start to last node), current-node in orange. Update when timeline-item clicked (show state at that moment). Supports zoom/pan inherited from canvas.

- [ ] 9.3 **Implement variable-state snapshot in event-log**
  - spec_ref: REQ-WFD-008
  - files: `lib/Service/Workflow/EventLogger.php`
  - acceptance_criteria: On each significant event (node-enter, task-assign, gateway-evaluate), snapshot the current `workflow_instance.variables` and store in `workflow_event_log.payload`. Event-log query can reconstruct variable-state at any point in timeline.

## 10. RBAC Integration

- [ ] 10.1 **Add workflow_designer, workflow_operator, task_assignee roles to RBAC**
  - spec_ref: REQ-WFD-009
  - files: `lib/Repair/AddWorkflowRoles.php` (migration), `appinfo/info.xml` (repair registration)
  - acceptance_criteria: Three roles registered: `workflow_designer` (create/edit/publish), `workflow_operator` (start instances, view), `task_assignee` (view own tasks). Documentation in `docs/rbac-roles.md`.

- [ ] 10.2 **Implement authorization checks on designer endpoints**
  - spec_ref: REQ-WFD-009
  - files: `lib/Controller/WorkflowDesignerController.php`
  - acceptance_criteria: All POST/PUT/DELETE endpoints check user has `workflow_designer` role. GET endpoints check `workflow_operator` or `workflow_designer`. Return 403 if unauthorized. Audit-log all design changes.

- [ ] 10.3 **Implement task-visibility scoping in inbox query**
  - spec_ref: REQ-WFD-009
  - files: `lib/Service/TaskService.php` method `getTasksForUser()`
  - acceptance_criteria: Query `task_instance` filtered by: (a) assignee_user_id = current_user, OR (b) assignee_group_id IN current_user.groups, OR (c) assignee_role IN current_user.roles. Return only visible tasks. Exclude completed/cancelled.

## 11. Export Integration

- [ ] 11.1 **Wire workflow export to openbuilt-exporter**
  - spec_ref: REQ-WFD-010
  - files: Coordinate with openbuilt-exporter change to add workflow-export capability
  - acceptance_criteria: Exporter collects all 8 workflow schemas at the specified version. Generates `workflow.json` with nodes/edges/variables. Role references use role-names (not UUIDs). No user-IDs hardcoded. Validates that roles/schemas exist in target-environment on import.

## 12. Testing & Documentation

- [ ] 12.1 **Write integration tests for canvas save/load**
  - spec_ref: REQ-WFD-001
  - files: `tests/integration/WorkflowCanvasSaveTest.php`
  - acceptance_criteria: Test: create 10 nodes, edit properties, undo 5, redo 3. Assert canvas-json matches expected state.

- [ ] 12.2 **Write integration tests for instance execution**
  - spec_ref: REQ-WFD-002, REQ-WFD-003, REQ-WFD-005, REQ-WFD-006
  - files: `tests/integration/WorkflowExecutionTest.php`
  - acceptance_criteria: Test scenarios: manual start, scheduled start, on-data-event start, user-task assignment (fixed, dynamic, group), exclusive-gateway branching, parallel split/join, timers, escalation. Assert event-log, variable-state, task-creation.

- [ ] 12.3 **Write unit tests for condition and assignment evaluators**
  - spec_ref: REQ-WFD-003, REQ-WFD-005
  - files: `tests/unit/Workflow/ConditionEvaluatorTest.php`, `tests/unit/Workflow/AssignmentEvaluatorTest.php`
  - acceptance_criteria: Test safe-subset JS parsing, expression evaluation with variables, unsafe-code rejection, error messages.

- [ ] 12.4 **Write user documentation**
  - spec_ref: REQ-WFD-001 through REQ-WFD-010
  - files: `docs/workflow-designer.md` (overview), `docs/workflow-designer-guide.md` (user guide), `docs/workflow-api.md` (REST endpoints)
  - acceptance_criteria: Cover canvas UI, node-type reference, assignment modes, conditions, timers, versioning, debugging, export, RBAC roles. Include Dutch UI screenshots. Provide BPMN-light vs full-BPMN comparison.

- [ ] 12.5 **Write ADR for workflow-versioning decision**
  - spec_ref: REQ-WFD-007
  - files: `.claude/openspec/architecture/adr-0XX-workflow-versioning.md` (in .claude/ repo folder)
  - acceptance_criteria: Document decision: immutable snapshots, backward-compatible running instances, no forced migration. Rationale, alternatives, implications.
