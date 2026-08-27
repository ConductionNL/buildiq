# automation-approval-action Specification

**OpenSpec changes**: [automation-approval-steps](../../changes/archive/2026-07-23-automation-approval-steps/) _(archived 2026-07-23)_

**Status**: done

## Purpose

The `approval` automation action kind: assignee is an NC group (group-only in
v1 — OR's `ApprovalStep.role` has no per-user primitive), compiled to an OR
`ApprovalChain` instantiation bound to the trigger object, on-approve/
on-reject follow-up compilation via typed listeners on OR's approval events,
and the "My approvals" runtime widget that lists and decides the viewer's
pending steps by calling OpenRegister's approval-steps REST surface directly
(ADR-022 consume-not-rebuild — Buildiq never implements an approval
engine).

## Requirements

### Requirement: My Approvals runtime widget lists pending steps for the viewer's groups

The system SHALL provide a `MyApprovalsWidget` page-widget type, placeable on
any built-app page, that calls OpenRegister's `GET /api/approval-steps` and
filters the result client-side to steps whose `role` is present in the
current viewer's NC groups (supplied via `IInitialState`, never a DOM
attribute read). The widget SHALL offer approve/reject actions that call
OpenRegister's `POST /api/approval-steps/{id}/approve` and
`/api/approval-steps/{id}/reject` directly — Buildiq SHALL NOT expose an
intermediate pass-through controller for these calls.

#### Scenario: Widget shows only steps the viewer's groups can act on

- **WHEN** a viewer in NC group `permit-reviewers` opens a page carrying the
  `MyApprovalsWidget`
- **THEN** the widget lists pending `ApprovalStep`s whose `role` is
  `permit-reviewers`
- **AND** does not list pending steps assigned to other groups

#### Scenario: Approve action calls OpenRegister directly

- **WHEN** a viewer clicks Approve on a listed step
- **THEN** the frontend calls OpenRegister's
  `POST /api/approval-steps/{id}/approve` directly
- **AND** no Buildiq controller method mediates the call

#### Scenario: Empty state when no steps are assigned

- **WHEN** the viewer's groups match no pending `ApprovalStep`
- **THEN** the widget renders an empty state and no approve/reject controls

### Requirement: Buildiq introduces no new approval authorization logic

All approval authorization (role/group membership, separation-of-duties) SHALL be enforced exclusively by OpenRegister's `ApprovalService`. Buildiq
SHALL NOT duplicate, pre-check, or bypass that authorization in the widget,
the compiler, or any listener — an approve/reject call that OR's own checks
would reject SHALL be rejected by OR, not filtered out silently beforehand in
a way that could mask a bug.

#### Scenario: Non-member approve attempt is rejected by OpenRegister, not masked

- **WHEN** a viewer who is not a member of a step's assigned group somehow
  triggers an approve call for that step
- **THEN** OpenRegister's `verifyRole` check rejects the call with `403`
- **AND** Buildiq performs no separate authorization check that would
  produce a different outcome
