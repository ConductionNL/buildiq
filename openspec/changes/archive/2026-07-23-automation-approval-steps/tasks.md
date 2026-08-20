## 1. Compiler: approval action kind

- [x] 1.1 Extend the v1 matrix: `object-created|object-updated|object-deleted|lifecycle-transition` + `approval` is supported; `schedule`/`manual` + `approval` stay fail-closed.
- [x] 1.2 `AutomationCompilerService` approval-backend branch: upsert an OR `ApprovalChain` (`aut-<slug>`, one step, `role` = assignee group) via `approval#create`/`approval#update`.
  - acceptance: recompiling an unchanged automation produces a byte-identical `ApprovalChain` (idempotent)
- [x] 1.3 Trigger-fire listener calls `ApprovalService::initializeChain()` for the fired object's uuid against the compiled chain.
- [x] 1.4 Record `provenance.approvalChainName`; enable/disable/delete removes exactly the provenance-listed chain.

## 2. Follow-up dispatch

- [x] 2.1 `ApprovalOutcomeListener` on `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent` resolves the originating automation by `aut-<slug>` chain name and dispatches its on-approve/on-reject actions.
- [x] 2.2 No-match events (chain not owned by any automation) are a no-op single lookup, not a scan.

## 3. Editor UI

- [x] 3.1 `AutomationEditDialog` gains the `approval` action type: NC-group assignee picker (`:input-label`, degrades to free-text on load failure) + on-approve/on-reject nested action-list editors reusing the existing typed-action components.
- [x] 3.2 Fail-closed validation blocks `approval` on `schedule`/`manual` triggers with an explicit message; allows it on event/lifecycle-transition triggers.

## 4. My Approvals widget

- [x] 4.1 `MyApprovalsWidget.vue` — registrable page-widget type; reads viewer groups via `IInitialState`; calls `GET /api/approval-steps` and filters client-side by group membership.
- [x] 4.2 Approve/reject buttons call OR's `/api/approval-steps/{id}/approve|reject` directly — no OpenBuild controller in between.

## 5. Status and dry-run surfacing

- [x] 5.1 `AutomationsController::status()` reports `approvalState: none|pending|approved|rejected` for the automation's most recent chain instantiation.
- [x] 5.2 Dry-run marks the `approval` action as would-be executed (dry-run/skipped) and creates no real `ApprovalStep`.

## 6. Tests

- [x] 6.1 PHPUnit: compiler approval-backend branch (chain upsert, idempotency, provenance), `ApprovalOutcomeListener` dispatch on approve vs reject.
- [x] 6.2 Newman: status/dry-run approval-state fields; My Approvals endpoints called directly (no OpenBuild pass-through route exists). NOTE: collection written (`tests/integration/openbuild-automation-approval-steps.postman_collection.json`), NOT executed against a live instance in this session (no deploy to the shared dev instance per project policy) — same caveat as the Playwright suite below.
- [x] 6.3 Playwright: compose an approval automation end to end, approve via My Approvals as a group member, confirm on-approve follow-up fires. NOTE: written in `tests/e2e/automations.spec.ts`, CI-run only — not executed in this session, mirroring the pre-existing automation-designer suite's own documented convention.

## 7. Verify

- [x] 7.1 `composer check:strict` and hydra mechanical gates (redundant-controller, no-admin-idor, spec-coverage) green on the diff. Verified: `composer lint` (php -l, clean), `phpcs --standard=phpcs.xml` (0 errors/warnings on touched files), `phpmd` (only pre-existing baseline debt + accepted class-level coupling/complexity metrics on the two new listener classes — no new method-level violations), `psalm --no-cache` (no errors), `phpstan analyse` (no errors), `composer test:unit` (672/672 PHPUnit tests pass), `npm run lint` (eslint, 0 errors), `npx stylelint` (clean), `npx vitest run` (full suite: 126 files / 1224 tests pass). All 39 `run-hydra-gates.sh --scope-to-diff origin/development` gates green (1-32, 46-52).
- [x] 7.2 `openspec validate "automation-approval-steps"` passes and `openspec status` shows all artifacts complete before archiving.
