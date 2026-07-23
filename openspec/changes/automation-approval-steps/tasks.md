## 1. Compiler: approval action kind

- [ ] 1.1 Extend the v1 matrix: `object-created|object-updated|object-deleted|lifecycle-transition` + `approval` is supported; `schedule`/`manual` + `approval` stay fail-closed.
- [ ] 1.2 `AutomationCompilerService` approval-backend branch: upsert an OR `ApprovalChain` (`aut-<slug>`, one step, `role` = assignee group) via `approval#create`/`approval#update`.
  - acceptance: recompiling an unchanged automation produces a byte-identical `ApprovalChain` (idempotent)
- [ ] 1.3 Trigger-fire listener calls `ApprovalService::initializeChain()` for the fired object's uuid against the compiled chain.
- [ ] 1.4 Record `provenance.approvalChainName`; enable/disable/delete removes exactly the provenance-listed chain.

## 2. Follow-up dispatch

- [ ] 2.1 `ApprovalOutcomeListener` on `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent` resolves the originating automation by `aut-<slug>` chain name and dispatches its on-approve/on-reject actions.
- [ ] 2.2 No-match events (chain not owned by any automation) are a no-op single lookup, not a scan.

## 3. Editor UI

- [ ] 3.1 `AutomationEditDialog` gains the `approval` action type: NC-group assignee picker (`:input-label`, degrades to free-text on load failure) + on-approve/on-reject nested action-list editors reusing the existing typed-action components.
- [ ] 3.2 Fail-closed validation blocks `approval` on `schedule`/`manual` triggers with an explicit message; allows it on event/lifecycle-transition triggers.

## 4. My Approvals widget

- [ ] 4.1 `MyApprovalsWidget.vue` — registrable page-widget type; reads viewer groups via `IInitialState`; calls `GET /api/approval-steps` and filters client-side by group membership.
- [ ] 4.2 Approve/reject buttons call OR's `/api/approval-steps/{id}/approve|reject` directly — no OpenBuild controller in between.

## 5. Status and dry-run surfacing

- [ ] 5.1 `AutomationsController::status()` reports `approvalState: none|pending|approved|rejected` for the automation's most recent chain instantiation.
- [ ] 5.2 Dry-run marks the `approval` action as would-be executed (dry-run/skipped) and creates no real `ApprovalStep`.

## 6. Tests

- [ ] 6.1 PHPUnit: compiler approval-backend branch (chain upsert, idempotency, provenance), `ApprovalOutcomeListener` dispatch on approve vs reject.
- [ ] 6.2 Newman: status/dry-run approval-state fields; My Approvals endpoints called directly (no OpenBuild pass-through route exists).
- [ ] 6.3 Playwright: compose an approval automation end to end, approve via My Approvals as a group member, confirm on-approve follow-up fires.

## 7. Verify

- [ ] 7.1 `composer check:strict` and hydra mechanical gates (redundant-controller, no-admin-idor, spec-coverage) green on the diff.
- [ ] 7.2 `openspec validate "automation-approval-steps"` passes and `openspec status` shows all artifacts complete before archiving.
