## Context

`AutomationCompilerService` compiles a declarative `Automation` object to one of three existing backends (dialect notifications/lifecycle-actions, schedules, rules) per a fail-closed v1 matrix (`design.md` Decision 2 of `automation-designer`); anything not in the matrix throws `UnsupportedAutomationCombinationException`, including — explicitly, by name — "any approval-step action" (REQ-AUTD-003). OpenRegister ships a complete, tested approval-chain engine: `ApprovalChain` (name, schemaId, statusField, `steps` JSON array of `{order, role, statusOnApprove}`), `ApprovalStep` (chainId, objectUuid, stepOrder, role, status, decidedBy, comment), `ApprovalService::initializeChain(chain, objectUuid, requesterId, stepsOverride)` to start a chain against an object, `approveStep`/`rejectStep` with group-membership (`IGroupManager::isInGroup`) and separation-of-duties checks, and four typed events (`ApprovalStepInitiatedEvent`, `ApprovalStepApprovedEvent`, `ApprovalStepRejectedEvent`, and a chain-completed signal). The REST surface (`/api/approval-chains`, `/api/approval-steps{,/{id}/approve,/{id}/reject}`) is `#[NoAdminRequired]` and already RBAC-checked server-side.

Constraint: `ApprovalStep.role` is verified purely by NC group membership (`verifyRole()` calls `isInGroup($userId, $role)`) — there is no per-user assignee primitive in OR today. The proposal's "assignee = NC group or user" is therefore reduced to group-only for v1 (see Open Questions).

## Goals / Non-Goals

**Goals:**
- Add `approval` as a fourth automation action kind, compiling to an OR `ApprovalChain` + `initializeChain()` call — no new approval logic in OpenBuild.
- On-approve/on-reject are automation-level follow-up actions triggered by OR's own events, not a new state machine.
- "My approvals" reads and writes OR's approval REST endpoints directly from the runtime — zero pass-through controller in OpenBuild (ADR-022).
- Automation status/dry-run surfaces the current chain's aggregate state (`pending`/`approved`/`rejected`) by reading OR's `ApprovalStep`s for the automation's most recent trigger firing.

**Non-Goals:**
- Rebuilding any part of the approval engine (routing tiers, separation-of-duties, multi-step chains beyond what OR's `steps` array already expresses) — v1 compiles exactly one step per `approval` action; a multi-step chain is expressible today only by adding more OR-side step definitions directly, not through the automation editor.
- Per-user assignee (OR has no primitive for it) — deferred.
- Escalation-timeout auto-reassignment — OR's `ApprovalStep` carries no timeout field; deferred.
- A generic "workflow builder" — this is one action kind bolted onto the existing trigger→action automation model, not a new visual workflow surface.

## Decisions

### D1 — `approval` compiles only on event/lifecycle-transition triggers, not schedule or manual
**Choice:** The matrix gains exactly one new cell: `object-created|object-updated|object-deleted|lifecycle-transition` + `approval` → the dialect backend's action list gains a typed `approval-init` entry (parallel to the existing `related-object-upsert`/`webhook-dispatch` entries appended to a lifecycle transition's `x-openregister-lifecycle` actions, or a same-shape entry compiled from a plain object-event trigger into the schema's `x-openregister-notifications`-adjacent action list). `schedule` + `approval` and `manual` + `approval` remain blocked fail-closed in v1.
**Why:** An approval step makes sense bound to a concrete object instance created/changed/transitioned — that is exactly the shape event and lifecycle triggers already carry (an object uuid). A schedule trigger has no single object to bind a chain to; `manual` triggers already route through the rules engine, which has no side-effect action registered for "start an approval chain" (`ConditionActionExecutor::SIDE_EFFECT_ACTIONS` would need a new entry — deferred to avoid growing the rules engine's action vocabulary in this change).
**Alternative considered:** Support `approval` on `manual` too, via a new rules-engine side-effect action. Rejected for v1 — smaller, safer surface; the dominant demand pattern (Budibase/Retool trust-gap evidence) is event/status-change-triggered approval ("when a permit application is submitted, route for approval"), not manually-invoked approval.

### D2 — Compilation creates/reuses one `ApprovalChain` per automation, keyed by the `aut-` provenance convention
**Choice:** On compile, `AutomationCompilerService` upserts one `ApprovalChain` via OR's `approval#create`/`approval#update` with `name: "aut-<slug>"`, `schemaId` = the trigger's schema, `steps: [{order: 1, role: "<assignee-group>"}]`. At trigger-fire time (the same dialect-listener path that today dispatches `x-openregister-notifications`), a new `AutomationApprovalTrigger` listener calls `ApprovalService::initializeChain($chain, $objectUuid, $requesterId)`.
**Why:** Reuses the exact `aut-<slug>` provenance-prefix convention the compiler already uses for notifications/rule-sets, so enable/disable/delete cleanly removes exactly the provenance-listed `ApprovalChain` (matches REQ-AUTD-005/006's "managed as one unit" guarantee) without inventing a second bookkeeping scheme.
**Alternative considered:** Create a fresh `ApprovalChain` per trigger firing. Rejected — churns OR's chain table for no benefit; the chain is a reusable *definition*, only `initializeChain()`'s per-object steps are per-firing.

### D3 — On-approve/on-reject follow-ups are typed listeners on OR's approval events, not polling
**Choice:** A new `ApprovalOutcomeListener` subscribes to `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent`, resolves the originating automation from the chain's `aut-<slug>` name, and — if the automation defines on-approve/on-reject follow-up actions — dispatches them through the *same* dialect/notification compilation the automation already uses (e.g. a follow-up `send-notification` becomes a direct notification send, not a new schema entry, since it fires once per event rather than being a standing declarative rule).
**Why:** Event-driven follow-up matches OR's existing event-dispatch architecture (four typed events already exist for exactly this purpose) and avoids polling `ApprovalStep` status.
**Alternative considered:** Poll approval status from an AppHost schedule. Rejected — OR already pushes typed events; polling would be strictly worse (latency, load) with no compensating benefit.

### D4 — "My approvals" widget calls OR's REST endpoints directly, filtering client-side by the viewer's groups
**Choice:** `MyApprovalsWidget.vue` calls `GET /apps/openregister/api/approval-steps?status=pending`, then filters client-side to steps whose `role` is in the viewer's NC groups (supplied via `IInitialState` on the runtime bootstrap, per the `runtime-group-scoped-access` precedent — never a DOM-attribute read, per ADR-004). Approve/reject buttons `POST` directly to OR's `/api/approval-steps/{id}/approve|reject`.
**Why:** OR's `steps()` endpoint has no "assigned to me" server-side filter (it filters by `status`/`role`/`chainId`/`objectUuid`, not by caller identity) — client-side group filtering is the only option without adding a new OR endpoint, and it matches the group-based authorization OR itself enforces (`verifyRole` checks the same group membership), so the client-side filter cannot show an action a server call would then reject.
**Why not an OpenBuild pass-through controller:** ADR-022's redundant-controller gate — a controller method whose body is `return $this->approvalClient->get(...)` ships dead indirection; the frontend already talks to OR REST directly elsewhere in OpenBuild's runtime (`useObjectStore`).
**Alternative considered:** Add a new OR endpoint `GET /api/approval-steps?assignedToMe=1`. Rejected — that is an OR-side change outside this change's boundary (ADR-022 direction is OpenBuild consumes, not requests upstream API additions as a blocking dependency); client-side filtering is sufficient and correct today.

### Declarative-vs-imperative decision (ADR-031)
The `approval` action's config (assignee group, on-approve/on-reject action lists) is declarative, stored on the `Automation` object exactly like the other three action kinds. The compile-time `ApprovalChain` upsert, the trigger-time `initializeChain()` call, and the event-driven follow-up dispatch are imperative — justified under ADR-031's cross-object/external-integration exception (identical justification already accepted for the existing dialect/schedules/rules compile branches: AutomationCompilerService itself is the precedent).

## Risks / Trade-offs

- **Group-only assignee is a scope reduction from the proposal's "group or user"** → documented Non-Goal; DEFERRED_QUESTION logged; OR has no per-user approval primitive to consume, and inventing one violates ADR-022.
- **No escalation/timeout** → documented Non-Goal; a stale pending step has no automatic remedy in v1 beyond an owner manually checking "My approvals" / the Automations status panel.
- **`ApprovalChain` upsert on every compile could drift from the automation if a chain is hand-edited via OR's own UI** → mitigated by the `aut-<slug>` naming convention signalling "compiler-owned, do not hand-edit," matching the existing convention for `aut-` rule sets and notification entries.
- **Follow-up action fan-out on every approve/reject across every automation in the instance** → `ApprovalOutcomeListener` resolves the automation by exact `aut-<slug>` chain-name match before doing any work, so the cost of a no-match event is one lookup, not a scan.

## Migration Plan

1. Add the `approval` action kind to the editor's action vocabulary and the compilation matrix (additive).
2. Implement the compile-time `ApprovalChain` upsert + trigger-time `initializeChain()` listener in `AutomationCompilerService`.
3. Implement `ApprovalOutcomeListener` for on-approve/on-reject follow-ups.
4. Ship `MyApprovalsWidget.vue` as a new runtime widget type, registrable on any page.
5. Extend `AutomationsController::status()`/dry-run to surface approval-chain state.
6. No migration for existing automations — `approval` is a new, opt-in action kind; every existing automation compiles exactly as before.

**Rollback:** Remove the `approval` matrix cell (compiler throws `UnsupportedAutomationCombinationException` for it again, matching pre-change behaviour); disable/delete any compiled `aut-<slug>` `ApprovalChain`s via the existing enable/disable-as-a-unit path; remove the widget registration. OR's approval engine is untouched either way.

## Open Questions

- **Group-only vs group-or-user assignee**: OR's `ApprovalStep.role` is group-only. Provisional decision: v1 ships group-only; a per-user assignee would require an OR-side primitive (e.g. a synthetic single-member group, or a new `assigneeUserId` field on `ApprovalStep`) that is out of this change's boundary. Flagged in DEFERRED_QUESTIONS.
- **Escalation timeout**: no OR primitive exists. Provisional decision: accept the field in the UI as a stored-but-inert placeholder for a v1.1 follow-up, or omit it entirely from v1's editor. Lean: omit entirely from v1 (do not ship UI for a field that does nothing) and track as a follow-up change once OR gains a timeout primitive. Flagged in DEFERRED_QUESTIONS.
- **Multi-step chains from the editor**: v1 compiles exactly one step. Lean: acceptable v1 scope reduction — OR's `steps` array supports more, but exposing that through the automation editor is materially more UI (ordered assignee list) deferred to a follow-up if demand appears.

## Seed Data

Example `Automation` object with an `approval` action (OR object in OpenBuild's own register, `automation` schema):

```json
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "applicationSlug": "vergunning-app",
  "versionUuid": "00000000-0000-0000-0000-000000000000",
  "name": "Route permit application for approval",
  "enabled": true,
  "trigger": { "type": "object-created", "schema": "permit-application" },
  "actions": [
    {
      "type": "approval",
      "assigneeGroup": "permit-reviewers",
      "onApprove": [
        { "type": "object-op", "op": "update", "field": "status", "value": "approved" }
      ],
      "onReject": [
        {
          "type": "send-notification",
          "subject": "Your permit application was rejected",
          "recipients": ["<angle-brackets: applicant email field>"]
        }
      ]
    }
  ]
}
```

Compiled OR `ApprovalChain` (created/updated by the compiler, name `aut-<slug>`):

```json
{
  "name": "aut-route-permit-application-for-approval",
  "schemaId": 0,
  "statusField": "status",
  "steps": [
    { "order": 1, "role": "permit-reviewers", "statusOnApprove": "approved" }
  ],
  "enabled": true
}
```

Resulting `ApprovalStep` after `initializeChain()` fires on a new `permit-application` object:

```json
{
  "chainId": 0,
  "objectUuid": "00000000-0000-0000-0000-000000000000",
  "stepOrder": 1,
  "role": "permit-reviewers",
  "status": "pending",
  "requesterId": "YOUR_TOKEN_HERE"
}
```
