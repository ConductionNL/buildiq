# Spec: automation-designer

## Purpose

Adds to the existing `automation-designer` capability and replaces REQ-AUTD-004: an
automation whose steps change records, call another system, or decide with a rule
set compiles to an OpenRegister flow that runs, instead of to records nothing
executes.

## REMOVED Requirements

### Requirement: Automations compile deterministically to existing declarative primitives (REQ-AUTD-004)

**Reason**: Two of its compile targets never ran. Lifecycle transition plus
`object-op` or `webhook` compiled to `related-object-upsert` and
`webhook-dispatch` records keyed `type`, which OpenRegister's lifecycle executor
cannot run, and a manual automation compiled to a rule set nothing evaluates.

**Migration**: REQ-BQAR-001 below carries every other clause and scenario of this
requirement unchanged, and replaces those two targets with one OpenRegister flow.
Code annotations that cite REQ-AUTD-004 move to REQ-BQAR-001. The repair step of
REQ-BQAR-007 moves the automations already compiled to the old targets.

## ADDED Requirements

### Requirement: Automations compile deterministically to declarative primitives or one OpenRegister flow (REQ-BQAR-001)

The system SHALL compile a saved automation (`AutomationCompilerService`)
exclusively to existing declarative primitives or a listener-backed external
integration per the matrix: event/transition + `send-notification` → an
`x-openregister-notifications` entry on the target schema keyed
`aut-<slug>-<n>`; an automation with a `manual` trigger, or holding a flow
action (`object-op` or `webhook` outside an approval, or `rule-decision`) →
one OpenRegister flow named `aut-<slug>`, saved through OpenRegister's
`FlowService::save()`, bound in `Application.flows`, and named in
`provenance.flowUuid`; lifecycle transition + a flow action → refused with the
combination named, until OpenRegister can start a flow on a transition;
schedule + `run-synchronization` → a `manifest.schedules[]` entry
with id `aut-<slug>-<n>` and action `openconnector:synchronization`, valid
against the existing schedules validator; event/lifecycle-transition + `approval` → an OpenRegister
`ApprovalChain` named `aut-<slug>` (one step, `role` = the assignee group),
upserted via OR's approval-chains API and instantiated against the trigger
object's uuid via `ApprovalService::initializeChain()` at trigger-fire time;
on-approve/on-reject follow-up actions dispatch through a typed listener on
OR's `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent`, never a new
approval engine; event/lifecycle-transition + `generateDocument` → a
validated action config (no compile-time Docudesk-side artifact: Docudesk's
`correspondence/generate` call is stateless) dispatched at trigger-fire time
by `DocumentGenerationListener` through `DocumentGenerationService`, which
calls Docudesk's existing `POST /apps/docudesk/api/correspondence/generate`
route impersonating the Application owner's session, never a Docudesk PHP
class import (see `automation-document-action` and the modified
`docudesk-document-templates` REQ-DDT-006). Compilation SHALL be
deterministic (identical automation → identical artifacts) and idempotent
(recompiling an unchanged automation changes nothing). No new imperative
execution engine is introduced in buildiq.

@e2e exclude backend compilation contract: artifact shapes are asserted by
PHPUnit against `AutomationCompilerService` (unit) and the OR round-trip
(integration); the user-visible halves are covered by REQ-AUTD-001/002/005
Playwright scenarios, and the schedules artifact is additionally visible in
the existing SchedulesSection e2e surface

**ID:** REQ-BQAR-001 (replaces REQ-AUTD-004)

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

#### Scenario: Manual automation compiles to an OpenRegister flow

- **WHEN** a manual automation with a FEEL condition and an `object-op`
  action is compiled
- **THEN** an OpenRegister flow named `aut-<slug>` exists with an
  `openregister.trigger-manual` node, an `openregister.filter` node holding
  the condition as JSONLogic and an `openregister.object-write` node, it is
  bound in the app's `Application.flows`, and the automation's
  `provenance.flowUuid` names it

#### Scenario: A record step on a lifecycle transition is refused

- **WHEN** an automation with trigger "lifecycle transition" and an
  `object-op` action is compiled
- **THEN** `AutomationCompilerService` throws
  `UnsupportedAutomationCombinationException` naming the combination, and no
  lifecycle action record is written

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

### Requirement: A record step runs outside an approval (REQ-BQAR-002)

An automation with an `object-op` action on an object or manual trigger SHALL
compile the action to `openregister.object-write` in its flow. The target schema
SHALL be one of the schemas of the automation's app version, and a target outside
it SHALL be refused at compile.

#### Scenario: A new permit creates an inspection record

- **GIVEN** a maker's automation on `vergunning` with trigger "Object created" and a record step that creates an `inspectie` with `vergunning` set to the new record's id
- **WHEN** an app user creates a `vergunning`
- **THEN** an `inspectie` record exists that points at it, and the flow's run log shows the write

#### Scenario: A target schema from another app is refused

- **GIVEN** a record step whose target schema slug belongs to a different app
- **WHEN** the maker saves the automation
- **THEN** the save fails and names the schema as outside this app

### Requirement: A webhook step calls a configured source (REQ-BQAR-003)

The webhook step SHALL name an integriq source and a path instead of a URL, and
SHALL compile to `openconnector.source-call` with method POST and the payload
template as body. A webhook step without a source SHALL be refused on save.
Without integriq the webhook kind SHALL be disabled with the reason.

#### Scenario: A maker posts new requests to the planning system

- **GIVEN** an integriq source `planning` the maker may use
- **WHEN** they add a webhook step with source `planning`, path `/intake` and a payload template, and save
- **THEN** the compiled flow holds an `openconnector.source-call` node with source `planning` and endpoint `/intake`

### Requirement: A condition gates the actions (REQ-BQAR-004)

A FEEL condition SHALL compile to an `openregister.filter` node holding the
JSONLogic translation of the expression, and an expression the translator cannot
express SHALL be refused at compile with the part named. A rule-set condition
SHALL compile to an `openregister.decision-table` node and a filter on its
result, so the actions run only when the rule set says so.

#### Scenario: Only large claims start the step

- **GIVEN** a manual automation with condition `bedrag > 1000` and a record step
- **WHEN** an app user runs it on a claim of 500
- **THEN** the run stops at the filter and no record is written

### Requirement: A step decides with a rule set (REQ-BQAR-005)

The composer SHALL offer "Decide with a rule set": a decision table rule set, an
input mapping from record fields to its columns, and an output field. It SHALL
compile to `openregister.decision-table` with the table translated as the rules
engine translates it, and `openregister.object-write` of the result onto the
record. A condition-action rule set SHALL be refused. Activating a new version
of a referenced rule set SHALL recompile the automations that copy it.

#### Scenario: The fee category is decided on creation

- **GIVEN** an active decision table `leges-categorie` with inputs `oppervlakte` and `type`
- **WHEN** a maker adds "Decide with a rule set" on `aanvraag` created, mapping both fields and writing to `categorie`, and an app user creates an `aanvraag`
- **THEN** the record's `categorie` holds the table's decision

#### Scenario: A new rule version reaches the flow

- **GIVEN** an automation that copies `leges-categorie` version 1.0.0
- **WHEN** an administrator activates version 1.0.1
- **THEN** the automation recompiles and its provenance names version 1.0.1

### Requirement: App users run manual automations from a record (REQ-BQAR-006)

A built app SHALL offer a runtime action on detail pages that lists the app's
enabled manual automations for the record's schema. `POST
/api/automations/{uuid}/run` SHALL refuse a caller without a use role on the
app, a caller who cannot read the record, and an automation that is not manual
and enabled.

#### Scenario: A clerk runs an automation on one record

- **GIVEN** an app user with the use role on app `permits` viewing `vergunning` 42
- **WHEN** they open the actions menu and choose "Send to inspection"
- **THEN** the automation's flow starts for record 42 and the menu confirms it started

#### Scenario: A user of another app cannot run it

- **GIVEN** a signed-in colleague without a role on app `permits`
- **WHEN** they call `POST /api/automations/{uuid}/run` for that app's automation
- **THEN** the route answers 403 and no flow run starts

### Requirement: Stuck automations are moved or disabled with a reason (REQ-BQAR-007)

A repair step SHALL remove the `related-object-upsert` and `webhook-dispatch`
lifecycle records and the manual-trigger rule sets that earlier compiles wrote,
recompile each affected automation to a flow, and disable any it cannot compile
with the reason recorded and shown on the automations page.

#### Scenario: A transition webhook is disabled and says why

- **GIVEN** an automation compiled before this change with a lifecycle transition trigger and a webhook step
- **WHEN** the app upgrades
- **THEN** its lifecycle record is gone, the automation is disabled, and the automations page says "Record and webhook steps cannot run on a lifecycle transition yet."

### Requirement: The app leads to its business rules (REQ-BQAR-008)

The actions menu of the app detail page SHALL offer "Business rules" next to
"Automations", opening the business rules page with the app preselected, and
that page SHALL list the rule sets owned by the app and the global ones.

#### Scenario: A maker finds the app's rule sets

- **GIVEN** a maker on the detail page of app `permits`
- **WHEN** they choose "Business rules" in the actions menu
- **THEN** the business rules page lists the rule sets owned by `permits` and the global rule sets
