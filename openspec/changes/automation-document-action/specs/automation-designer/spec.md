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
POST, `generateDocument` — a Docudesk `templateId` picked from the same
template list the Documents section already renders, plus an `output` mode
of `attach`, `download-link`, or `notify`). Schema, transition,
synchronization and Docudesk-template pickers SHALL be populated from
OpenRegister/Docudesk REST and degrade to free-text ids when a list cannot
load. All selects SHALL carry `:input-label`. The `generateDocument` action
SHALL render disabled with the missing-app hint when `useAppStatus('docudesk')`
reports Docudesk absent, mirroring `docudesk-document-templates` REQ-DDT-005.

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

#### Scenario: Compose a document-generation action

- **WHEN** the editor creates an automation with trigger "lifecycle
  transition" on schema `permit-application` transition `approve`, a
  `generateDocument` action with a picked template and `output: "attach"`,
  and saves
- **THEN** the persisted automation carries the typed `generateDocument`
  action with `templateId` and `output: "attach"`

#### Scenario: Document-generation action is disabled without Docudesk

- **GIVEN** Docudesk is not installed
- **WHEN** the editor opens the action-type picker
- **THEN** `generateDocument` renders disabled with the missing-app hint

### Requirement: Unsupported trigger and action combinations are blocked fail-closed

The system SHALL enforce the v1 compilation matrix (design.md Decision 2 of
`automation-designer`, extended by design.md Decision 2 of
`automation-document-action`) in both the editor and the compiler: a
trigger/action combination or a condition placement that no existing
declarative primitive can express (e.g. object created + `object-op`, a
condition on a plain object-event or schedule trigger, a `generateDocument`
action on a `schedule` or `manual` trigger) SHALL be blocked with an explicit
message naming the unsupported combination. The system SHALL NOT silently
drop, stub, or partially compile an unsupported automation.
`generateDocument` on an event/lifecycle-transition trigger IS supported (see
the compilation requirement below) and SHALL NOT be blocked.

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

#### Scenario: generateDocument action on a schedule trigger is blocked

- **WHEN** the editor selects trigger "schedule" and attempts to add a
  `generateDocument` action
- **THEN** the editor blocks the combination with a message stating
  document-generation actions are only supported on object-event and
  lifecycle-transition triggers in v1

### Requirement: Automations compile deterministically to existing declarative primitives

The system SHALL compile a saved automation (`AutomationCompilerService`)
exclusively to existing declarative primitives or a listener-backed external
integration per the matrix: event/transition + `send-notification` → an
`x-openregister-notifications` entry on the target schema keyed
`aut-<slug>-<n>`; lifecycle transition + `object-op`/`webhook` → typed
`related-object-upsert`/`webhook-dispatch` records appended to that
transition's `x-openregister-lifecycle` actions, tagged with an `aut-<slug>`
marker; schedule + `run-synchronization` → a `manifest.schedules[]` entry
with id `aut-<slug>-<n>` and action `openconnector:synchronization`, valid
against the existing schedules validator; manual → a namespaced RuleSet
(`aut-<uuid8>`) plus ConditionActionRule evaluated by the existing rules
engine; event/lifecycle-transition + `generateDocument` → a validated action
config (no compile-time Docudesk-side artifact — Docudesk's
`correspondence/generate` call is stateless) dispatched at trigger-fire time
by `DocumentGenerationListener` through `DocumentGenerationService`, which
calls Docudesk's existing `POST /apps/docudesk/api/correspondence/generate`
route impersonating the Application owner's session — never a Docudesk PHP
class import (see `automation-document-action` and the modified
`docudesk-document-templates` REQ-DDT-006). Compilation SHALL be
deterministic (identical automation → identical artifacts) and idempotent
(recompiling an unchanged automation changes nothing). No new imperative
execution engine is introduced in openbuild.

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

#### Scenario: Document-generation action fires via the pinned Docudesk route

- **WHEN** an object matching an enabled automation's `generateDocument`
  trigger is created/transitioned
- **THEN** `DocumentGenerationService` calls
  `POST /apps/docudesk/api/correspondence/generate` with `dataRefs` naming
  that object, impersonating the Application owner
- **AND** no Docudesk PHP class is imported anywhere in the call path

#### Scenario: Missing Docudesk fails the compile, not the runtime

- **WHEN** an automation carrying a `generateDocument` action is compiled on
  an instance where Docudesk is absent
- **THEN** `AutomationCompilerService` throws
  `UnsupportedAutomationCombinationException` naming the missing `docudesk`
  dependency
