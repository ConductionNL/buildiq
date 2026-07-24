## MODIFIED Requirements

### Requirement: Automation editor composes trigger, condition and actions

The system SHALL provide a standalone `AutomationEditDialog`
(`src/dialogs/`, `NcModal`-based per the modal-isolation gate) in which an
editor composes exactly one TRIGGER (object created / object updated / object
deleted / lifecycle transition on a chosen schema and transition action /
cron schedule reusing the schedules-editor cadence presets / manual), an
optional CONDITION (a FEEL-subset expression or a reference to an existing
rule set), and one or more typed ACTIONS (`send-notification`,
`run-synchronization`, `object-op` create/update on a schema, `webhook`
POST, `approval` — assignee is an NC group, with optional on-approve and
on-reject follow-up action lists composed from the same typed-action
vocabulary). Schema, transition, synchronization and NC-group pickers SHALL
be populated from OpenRegister/Nextcloud REST and degrade to free-text ids
when a list cannot load. All selects SHALL carry `:input-label`.

**ID:** REQ-AUTD-002

#### Scenario: Compose an event-triggered notification

- **WHEN** the editor creates an automation with trigger "object created" on
  schema `permit` and a `send-notification` action with a subject and
  recipients, and saves
- **THEN** an `automation` object is persisted with
  `trigger: {type: "object-created", schema: "permit"}` and the typed
  notification action

#### Scenario: Compose a scheduled synchronization run

- **WHEN** the editor creates an automation with trigger "schedule", cadence
  preset Daily, and a `run-synchronization` action with a picked
  synchronization, and saves
- **THEN** the persisted automation carries
  `trigger: {type: "schedule", interval: 86400}` and the
  `run-synchronization` action with the chosen `synchronizationId`

#### Scenario: Compose a manual automation with a condition

- **WHEN** the editor creates an automation with trigger "manual", condition
  `payload.amount > 1000` (FEEL subset), and an `object-op` create action on a
  schema with a field mapping, and saves
- **THEN** the persisted automation carries the FEEL condition and the typed
  `object-op` action

#### Scenario: Compose an approval action with on-approve and on-reject follow-ups

- **WHEN** the editor creates an automation with trigger "object created" on
  schema `permit-application`, an `approval` action assigned to NC group
  `permit-reviewers`, an on-approve `object-op` status update, and an
  on-reject `send-notification`, and saves
- **THEN** the persisted automation carries the typed `approval` action with
  `assigneeGroup: "permit-reviewers"` and both follow-up action lists

### Requirement: Unsupported trigger and action combinations are blocked fail-closed

The system SHALL enforce the v1 compilation matrix (design.md Decision 2 of
`automation-designer`, extended by design.md Decision 1 of
`automation-approval-steps`) in both the editor and the compiler: a
trigger/action combination or a condition placement that no existing
declarative primitive can express (e.g. object created + `object-op`, a
condition on a plain object-event or schedule trigger, an `approval` action on
a `schedule` or `manual` trigger) SHALL be blocked with an explicit message
naming the unsupported combination. The system SHALL NOT silently drop, stub,
or partially compile an unsupported automation. `approval` on an
event/lifecycle-transition trigger IS supported (see the compilation
requirement below) and SHALL NOT be blocked.

**ID:** REQ-AUTD-003

#### Scenario: Unsupported action for an event trigger is blocked in the editor

- **WHEN** the editor selects trigger "object created" and then attempts to
  add a `webhook` action
- **THEN** the editor blocks the combination with a message stating it is not
  yet expressible declaratively and the automation cannot be saved in that
  shape

#### Scenario: Condition on a schedule trigger is blocked

- **WHEN** the editor selects trigger "schedule" and enters a FEEL condition
- **THEN** validation reports that conditions are only supported on manual
  triggers in v1 and the automation cannot be saved with the condition

#### Scenario: Approval action on a schedule trigger is blocked

- **WHEN** the editor selects trigger "schedule" and attempts to add an
  `approval` action
- **THEN** the editor blocks the combination with a message stating approval
  actions are only supported on object-event and lifecycle-transition
  triggers in v1

#### Scenario: Approval action on an event trigger is accepted

- **WHEN** the editor selects trigger "object created" and adds an `approval`
  action with an assignee group
- **THEN** the editor accepts the combination and the automation can be saved

### Requirement: Automations compile deterministically to existing declarative primitives

The system SHALL compile a saved automation (`AutomationCompilerService`)
exclusively to existing declarative primitives per the matrix:
event/transition + `send-notification` → an `x-openregister-notifications`
entry on the target schema keyed `aut-<slug>-<n>`; lifecycle transition +
`object-op`/`webhook` → typed `related-object-upsert`/`webhook-dispatch`
records appended to that transition's `x-openregister-lifecycle` actions,
tagged with an `aut-<slug>` marker; schedule + `run-synchronization` → a
`manifest.schedules[]` entry with id `aut-<slug>-<n>` and action
`openconnector:synchronization`, valid against the existing schedules
validator; manual → a namespaced RuleSet (`aut-<uuid8>`) plus
ConditionActionRule evaluated by the existing rules engine;
event/lifecycle-transition + `approval` → an OpenRegister `ApprovalChain`
named `aut-<slug>` (one step, `role` = the assignee group), upserted via OR's
approval-chains API and instantiated against the trigger object's uuid via
`ApprovalService::initializeChain()` at trigger-fire time; on-approve/
on-reject follow-up actions dispatch through a typed listener on OR's
`ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent`, never a new
approval engine. Compilation SHALL be deterministic (identical automation →
identical artifacts) and idempotent (recompiling an unchanged automation
changes nothing). No new imperative execution engine is introduced in
openbuild.

@e2e exclude backend compilation contract — artifact shapes are asserted by
PHPUnit against `AutomationCompilerService` (unit) and the OR round-trip
(integration); the user-visible halves are covered by REQ-AUTD-001/002/005
Playwright scenarios, and the schedules artifact is additionally visible in
the existing SchedulesSection e2e surface

**ID:** REQ-AUTD-004

#### Scenario: Event notification compiles to the notifications dialect

- **WHEN** an enabled automation with trigger "object created" on `permit`
  and one `send-notification` action is compiled
- **THEN** the `permit` schema in the version's register carries an
  `x-openregister-notifications` entry keyed `aut-<slug>-1` with
  `trigger.type: "created"`, the mapped channels/recipients/subject and
  `enabled: true`

#### Scenario: Scheduled sync compiles to a schedules entry

- **WHEN** an enabled automation with a Daily schedule trigger and a
  `run-synchronization` action is compiled
- **THEN** the version's `manifest.schedules[]` contains an entry
  `{id: "aut-<slug>-1", enabled: true, interval: 86400, action:
  "openconnector:synchronization", arguments: {synchronizationId}}` that
  passes `validateSchedules`

#### Scenario: Manual automation compiles to a namespaced rule set

- **WHEN** a manual automation with a FEEL condition and an `object-op`
  action is compiled
- **THEN** a RuleSet with slug `aut-<uuid8>` and one ConditionActionRule
  (condition in `conditie`, the typed action in `acties`) exist in the
  shared register, and the automation's `provenance.ruleSetSlug` names it

#### Scenario: Recompilation is idempotent

- **WHEN** an unchanged automation is compiled twice
- **THEN** the second compile produces byte-identical artifacts and the
  `provenance.compiledHash` is unchanged

#### Scenario: Approval action compiles to an OR approval chain

- **WHEN** an enabled automation with trigger "object created" on
  `permit-application` and one `approval` action assigned to group
  `permit-reviewers` is compiled
- **THEN** an OR `ApprovalChain` named `aut-<slug>` exists with one step
  `{order: 1, role: "permit-reviewers"}`
- **AND** the automation's `provenance.approvalChainName` names it

#### Scenario: Trigger firing initialises an approval step

- **WHEN** an object is created that matches an enabled automation's
  `approval` action trigger
- **THEN** `ApprovalService::initializeChain()` is called for that object's
  uuid against the compiled chain, creating a `pending` `ApprovalStep`

#### Scenario: Approval outcome dispatches the configured follow-up

- **WHEN** an `ApprovalStep` compiled from an automation's `approval` action
  is approved
- **THEN** the automation's on-approve follow-up actions are dispatched
- **AND** no on-reject follow-up action is dispatched

### Requirement: Dry-run test panel evaluates an automation without side effects

The system SHALL provide a test panel (`AutomationTestPanelModal`,
`src/modals/`, mirroring `RuleSetTestSandboxModal`) that accepts a sample
JSON payload and calls `POST /api/automations/{uuid}/dry-run`. The endpoint
SHALL compile the automation in-memory to its rules-backend representation
and evaluate it through the existing rule engine with `dryRun: true`,
returning whether the condition matched, the would-be actions (marked
dry-run/skipped), errors, and duration. For an automation carrying an
`approval` action, the dry-run response and `AutomationsController::status()`
SHALL additionally report the aggregate state (`none|pending|approved
|rejected`) of the automation's most recently initialised approval chain
instantiation, read from OR's `ApprovalStep`s for the compiled chain. A dry
run SHALL NOT dispatch any side effect, SHALL NOT initialise a real approval
step, and SHALL NOT modify any compiled artifact.

**ID:** REQ-AUTD-007

#### Scenario: Dry-run shows would-be actions without executing them

- **WHEN** the editor opens the test panel of a manual automation whose
  condition matches the entered sample payload and runs the test
- **THEN** the panel lists each action as would-be executed (dry-run,
  skipped) with no errors
- **AND** no notification, object write, webhook or synchronization run is
  dispatched

#### Scenario: Non-matching condition reports no actions

- **WHEN** the editor runs the test with a payload that does not satisfy the
  automation's condition
- **THEN** the panel reports the condition did not match and lists no
  would-be actions

#### Scenario: Dry-run of an approval automation reports would-be approval, not a real step

- **WHEN** the editor runs a dry-run test of an automation with an `approval`
  action against a matching sample payload
- **THEN** the panel lists the `approval` action as would-be executed
  (dry-run, skipped)
- **AND** no `ApprovalStep` is created in OpenRegister

#### Scenario: Status reports the live approval state

- **WHEN** an automation's compiled `approval` action has an in-flight
  `pending` `ApprovalStep` for the most recently triggered object
- **THEN** `AutomationsController::status()` reports `approvalState:
  "pending"` for that automation
