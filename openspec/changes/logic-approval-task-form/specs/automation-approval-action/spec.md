# automation-approval-action

## ADDED Requirements

### Requirement: A maker names the fields an approver fills in (REQ-BQTF-001)

The "Require approval" action in the automation editor SHALL offer an optional
ordered list "Fields the approver fills in", chosen from the properties of the
trigger's schema, each with a "Required" switch. The list SHALL be stored on
the action as `form.fields[]` with `field` and `required`. Saving MUST be
refused, naming the field, when a chosen field is not in the schema, is
`readOnly`, is hidden, or is a relation or array field. An empty list SHALL
leave the approval exactly as it is without a form.

@e2e tests/e2e/automation-approval-form.spec.ts

#### Scenario: A maker asks the approver for two fields

- **GIVEN** an automation "Klacht ter goedkeuring" on schema `klacht` with a "Require approval" action
- **WHEN** the maker adds `bedrag` (required) and `motivering` (not required) to "Fields the approver fills in" and saves
- **THEN** the action stores `form.fields` as `bedrag` required and `motivering` not required, in that order

#### Scenario: A read-only field is refused at save

- **GIVEN** the property `zaaknummer` of `klacht` is `readOnly`
- **WHEN** the maker adds `zaaknummer` to the list and saves
- **THEN** the save is refused with a message naming `zaaknummer`

### Requirement: The form is compiled into the approval chain, or refused (REQ-BQTF-002)

The compiler SHALL write a non-empty field list as
`form: {kind: "fields", fields: [...]}` on the approval chain position it
compiles, and the compiled hash MUST include it. While OpenRegister does not
carry a chain position's form into the tasks it opens, the compiler MUST refuse
a non-empty field list with the message "Approval forms need OpenRegister to
carry a form into the task" and MUST NOT compile the approval without it.

@e2e exclude depends on an OpenRegister capability that is not on development yet; covered by PHPUnit on AutomationCompilerService for both the refusal and the compiled chain

#### Scenario: The chain carries the form

- **GIVEN** OpenRegister accepts a form on a chain position
- **WHEN** the automation with `bedrag` and `motivering` compiles
- **THEN** the chain position carries `form.kind` "fields" and both fields in order

#### Scenario: Without OpenRegister support the form is refused, not dropped

- **GIVEN** OpenRegister does not accept a form on a chain position
- **WHEN** the maker saves an approval with one field in the list
- **THEN** the save is refused with "Approval forms need OpenRegister to carry a form into the task"

#### Scenario: Changing the fields shows as drift

- **GIVEN** a compiled automation with a form
- **WHEN** someone removes `motivering` from the chain position outside buildiq
- **THEN** the Automations page shows the drift badge for that automation

### Requirement: An approver fills in the form before approving (REQ-BQTF-003)

In the "My approvals" widget, a task whose form state is `ready` SHALL show
"{n} fields to fill in" on its row, and "Approve" SHALL open the nextcloud-vue
task form component with that task's form. Confirming SHALL complete the task
with `outcome` "approved" and the typed values as `data`. A task whose form is
`broken` or `unresolvable` SHALL show OpenRegister's reason on its row and
MUST disable "Approve". "Reject" MUST NOT send `data`. A task without a form
SHALL complete on "Approve" as it does today. buildiq MUST NOT validate the
values itself.

@e2e tests/e2e/automation-approval-form.spec.ts

#### Scenario: Approving with a form writes the values

- **GIVEN** an open approval task for complaint C-2026-0412 asking for `bedrag` (required) and `motivering`
- **WHEN** a team lead clicks "Approve", fills in `bedrag` 450 and confirms
- **THEN** the task completes with outcome `approved`
- **AND** complaint C-2026-0412 has `bedrag` 450

#### Scenario: A missing required field keeps the dialog open

- **WHEN** the team lead confirms without filling in `bedrag`
- **THEN** the dialog stays open with `motivering` still filled in
- **AND** `bedrag` is marked as required
- **AND** the task is still open

#### Scenario: A broken form cannot be approved

- **GIVEN** the schema `klacht` dropped `bedrag` after the task was opened
- **WHEN** the team lead opens "My approvals"
- **THEN** the row shows the reason that `bedrag` no longer exists
- **AND** "Approve" is disabled

#### Scenario: An approval without a form is unchanged

- **GIVEN** an approval task with no form
- **WHEN** the team lead clicks "Approve"
- **THEN** the task completes at once with outcome `approved`
