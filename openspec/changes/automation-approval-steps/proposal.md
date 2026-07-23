---
kind: code
---

## Why

`AutomationCompilerService`'s v1 matrix explicitly, deliberately blocks "any approval-step action" fail-closed (REQ-AUTD-003) — human-in-the-loop approval was scoped out of the first automation-designer cut. Every competitor either paywalls this (ToolJet, Retool, Power Platform) or has it roadmap-only (Budibase Q2-2026); nobody ships it free today. OpenRegister already ships a working, tested approval-chain primitive (`ApprovalService::initializeChain/approveStep/rejectStep`, the `/api/approval-chains` + `/api/approval-steps` REST surface) — this is a pure consume-not-rebuild opportunity (ADR-022) to close the market's biggest open wedge.

## What Changes

- **New automation action kind `approval`**: assignee = an NC group id (OR's `ApprovalStep.role` is verified via `IGroupManager::isInGroup` — no direct-user assignee exists at the OR layer, so v1 scopes the action to group assignees only; see Open Question). Compiles to an OR `ApprovalChain` (one step, `role: <group>`) that `AutomationCompilerService` initialises against the trigger object's uuid via `ApprovalService::initializeChain()`.
- **On-approve / on-reject follow-up branches**: the automation editor lets an `approval` action carry nested follow-up actions for each outcome (e.g. `send-notification` on reject, `object-op` status update on approve). These compile to `x-openregister-notifications` entries keyed off the existing `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent` (OR already dispatches these — OpenBuild adds typed listeners that translate the event into the follow-up action, never a new approval engine).
- **"My approvals" runtime widget**: a page widget (built-app runtime) listing pending `ApprovalStep`s whose `role` matches one of the current viewer's NC groups (group set via `IInitialState`, mirroring the `runtime-group-scoped-access` precedent — no new group-membership API), with approve/reject actions calling OR's existing `/api/approval-steps/{id}/approve|reject` directly (no OpenBuild pass-through controller — ADR-022 redundant-controller gate).
- **Automation editor + dryRun/status surface approval state**: `AutomationEditDialog` gains the `approval` action type (assignee group picker, optional on-approve/on-reject action lists); `AutomationsController::status()` and the dry-run panel report `pending|approved|rejected` for the automation's most recent approval chain instantiation, sourced from OR's `ApprovalStep`/`ApprovalChain` read endpoints.
- **Removes the v1 fail-closed block on approval actions**: REQ-AUTD-003's "any approval-step action" exclusion is lifted for the new matrix cell (event/lifecycle-transition trigger + `approval` action); all other unsupported combinations remain blocked exactly as before.

## Capabilities

### New Capabilities
- `automation-approval-action`: the `approval` action kind, its compilation to an OR `ApprovalChain` instantiation bound to the trigger object, on-approve/on-reject follow-up compilation via typed listeners on OR's approval events, and the "My approvals" runtime widget.

### Modified Capabilities
- `automation-designer`: the editor's composable action vocabulary gains `approval` (REQ-AUTD-002); the fail-closed matrix (REQ-AUTD-003) no longer blocks approval actions on event/lifecycle-transition triggers; the compilation matrix (REQ-AUTD-004) gains the approval→chain mapping; dry-run/status (REQ-AUTD-007) surfaces approval state. (Delta spec at `specs/automation-designer/spec.md`.)

## Impact

- **Schema:** none in OpenBuild's own register — approval state lives entirely in OR's existing `oc_openregister_approval_chains`/`oc_openregister_approval_steps` tables, consumed via REST.
- **Backend:** `AutomationCompilerService` gains an approval-backend compile branch (creates/updates an `ApprovalChain` config via OR's `approval#create`/`approval#update`, and calls `initializeChain()` at trigger-fire time via a new typed listener); new `ApprovalOutcomeListener` translating `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent` into the automation's configured follow-up actions.
- **Frontend:** `AutomationEditDialog` gains the approval action editor; new `src/components/runtime/MyApprovalsWidget.vue` (or equivalent widget entry) calling OR's `/api/approval-steps` and `/api/approval-steps/{id}/approve|reject` directly from the built-app runtime.
- **RBAC:** approve/reject authorization is enforced entirely by OR (`ApprovalService::verifyRole`/`verifySeparationOfDuties`) — OpenBuild adds no parallel check, per ADR-022.
- **Non-goal:** escalation-timeout enforcement (auto-reassign/escalate a stale pending step) is out of scope for v1 — OR's `ApprovalStep` has no timeout/escalation field to consume; see design.md Open Questions.
