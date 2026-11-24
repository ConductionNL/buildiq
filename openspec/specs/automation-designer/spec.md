# automation-designer Specification

**OpenSpec changes**: [automation-approval-steps](../../changes/archive/2026-07-23-automation-approval-steps/) _(archived 2026-07-23)_, [automation-document-action](../../changes/archive/2026-07-24-automation-document-action/) _(archived 2026-07-24)_

**Status**: done

## Purpose
TBD - created by archiving change automation-designer. Update Purpose after archive.

## Requirements

### Requirement: Automations page lists automations per application version (REQ-AUTD-001)

The system SHALL render an **Automations** page
(`src/views/AutomationsPage.vue`, registered in `src/registry.js` and routed
via a `src/manifest.d/40-automations.json` fragment, mirroring the
business-rules page) that lists every `automation` object belonging to the
currently selected Application and ApplicationVersion. Each row SHALL show
name, trigger summary, action summary, enabled state, and a drift badge when
the compiled artifacts no longer match the automation definition. The page
SHALL offer a version selector consistent with the ApplicationVersion chain
so an editor can inspect a non-production version's automations.

**ID:** REQ-AUTD-001

#### Scenario: Existing automations render for the selected version

- **WHEN** the Automations page opens for an application version that has
  automations
- **THEN** each automation renders as a row with its name, trigger summary,
  action summary and enabled state

#### Scenario: Empty state renders without error

- **WHEN** the Automations page opens for a version with no automations
- **THEN** an empty state with a "New automation" affordance renders and no
  error is shown

#### Scenario: Switching versions switches the list

- **WHEN** the editor selects a different ApplicationVersion in the version
  selector
- **THEN** the list shows only automations whose `versionUuid` matches the
  selected version

### Requirement: Automation editor composes trigger, condition and actions (REQ-AUTD-002)

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
vocabulary; `generateDocument` — a Docudesk `templateId` picked from the same
template list the Documents section already renders, plus an `output` mode
of `attach`, `download-link`, or `notify`). Schema, transition,
synchronization, NC-group and Docudesk-template pickers SHALL be populated
from OpenRegister/Nextcloud/Docudesk REST and degrade to free-text ids when
a list cannot load. All selects SHALL carry `:input-label`. The
`generateDocument` action SHALL render disabled with the missing-app hint
when `useAppStatus('docudesk')` reports Docudesk absent, mirroring
`docudesk-document-templates` REQ-DDT-005.

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

#### Scenario: Compose a document-generation action

- **WHEN** the editor creates an automation with trigger "lifecycle
  transition" on schema `permit-application` transition `approve`, a
  `generateDocument` action with a picked template and `output: "attach"`,
  and saves
- **THEN** the persisted automation carries the typed `generateDocument`
  action with `templateId` and `output: "attach"`

#### Scenario: Document-generation action is disabled without Docudesk

@e2e exclude covered by tests/dialogs/AutomationEditDialog.spec.js ("generateDocument action is disabled without Docudesk") — a component-level render assertion; no distinct live-browser affordance beyond the disabled-state styling REQ-DDT-005's own e2e coverage already exercises for the sibling Documents-section surface.

- **GIVEN** Docudesk is not installed
- **WHEN** the editor opens the action-type picker
- **THEN** `generateDocument` renders disabled with the missing-app hint

### Requirement: Unsupported trigger and action combinations are blocked fail-closed (REQ-AUTD-003)

The system SHALL enforce the v1 compilation matrix (design.md Decision 2 of
`automation-designer`, extended by design.md Decision 1 of
`automation-approval-steps` and design.md Decision 2 of
`automation-document-action`) in both the editor and the compiler: a
trigger/action combination or a condition placement that no existing
declarative primitive can express (e.g. object created + `object-op`, a
condition on a plain object-event or schedule trigger, an `approval` action
on a `schedule` or `manual` trigger, a `generateDocument` action on a
`schedule` or `manual` trigger) SHALL be blocked with an explicit message
naming the unsupported combination. The system SHALL NOT silently drop, stub,
or partially compile an unsupported automation. `approval` and
`generateDocument` on an event/lifecycle-transition trigger ARE supported
(see the compilation requirement below) and SHALL NOT be blocked.

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

#### Scenario: generateDocument action on a schedule trigger is blocked

- **WHEN** the editor selects trigger "schedule" and attempts to add a
  `generateDocument` action
- **THEN** the editor blocks the combination with a message stating
  document-generation actions are only supported on object-event and
  lifecycle-transition triggers in v1

### Requirement: An automation is managed as one unit with provenance (REQ-AUTD-005)

The system SHALL record in each automation's `provenance` block every
artifact its last compile produced (`notificationKeys`, `lifecycleActions`,
`scheduleIds`, `ruleSetSlug`, `openconnectorObjects`, `compiledHash`).
Editing an automation SHALL recompile and upsert by the namespaced ids,
removing provenance-listed artifacts no longer produced. Deleting an
automation SHALL remove exactly the provenance-listed artifacts and then the
automation object, leaving hand-authored (non-`aut-` prefixed) entries
untouched. When a compiled artifact was hand-edited (recomputed hash
mismatch), the list SHALL show a drift warning and offer a
"Recompile (overwrite)" action in which the automation definition wins.

**ID:** REQ-AUTD-005

#### Scenario: Delete removes exactly the compiled artifacts

- **WHEN** the editor deletes an automation whose provenance lists a
  notification key and a schedules entry
- **THEN** that notification entry and that schedules entry are removed
- **AND** a hand-authored notification entry on the same schema (key without
  the `aut-` prefix) is untouched

#### Scenario: Drift is surfaced and recompile restores the definition

- **WHEN** a compiled schedules entry is hand-edited in the page designer and
  the Automations page is opened
- **THEN** the automation's row shows a drift warning
- **AND** activating "Recompile (overwrite)" restores the artifact to the
  automation definition's compiled shape

### Requirement: Enable and disable toggle the compiled artifacts as a unit (REQ-AUTD-006)

The system SHALL let an authorised user enable or disable an automation from
the list. Disabling SHALL recompile with every artifact inert (notification
entry `enabled: false`, schedules entry `enabled: false`, ConditionActionRule
`actief: false`, compiled lifecycle actions removed while provenance retains
them); enabling SHALL restore them. Artifacts SHALL remain in place while
disabled so re-enabling never loses configuration.

**ID:** REQ-AUTD-006

#### Scenario: Disable makes every compiled artifact inert

- **WHEN** the editor disables an enabled scheduled automation
- **THEN** its `manifest.schedules[]` entry has `enabled: false`
- **AND** the automation row shows the disabled state

#### Scenario: Re-enable restores the compiled state

- **WHEN** the editor re-enables that automation
- **THEN** the schedules entry has `enabled: true` again with its other
  fields unchanged

### Requirement: Dry-run test panel evaluates an automation without side effects (REQ-AUTD-007)

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

### Requirement: RBAC — editors author and test, owners enable on production (REQ-AUTD-008)

The system SHALL authorise the effectual automation routes
(`compile`, `enable`, `disable`, `dry-run`) in `AutomationsController` via
`PermissionResolver::matchesCaller()`: authoring, dry-running and enabling on
a non-production version require the caller to match
`['owners','editors']` on the Application; enabling (or editing while
enabled) an automation on the version currently set as the Application's
production version requires `['owners']` with `allowAdminBypass: false`.
Unauthorised calls SHALL receive `403` with a JSON error body before any
compile side effect. UI affordance-hiding SHALL NOT be relied on as the
security boundary.

**ID:** REQ-AUTD-008

#### Scenario: Editor authors and enables on a draft version

- **WHEN** a user in an `editors` group creates and enables an automation on
  a non-production version
- **THEN** the automation is saved, compiled and enabled

#### Scenario: Editor cannot enable on the production version

- **WHEN** the same editor attempts to enable an automation on the
  Application's production version
- **THEN** the enable call returns `403` and the automation stays disabled
- **AND** an owner performing the same enable succeeds

### Requirement: Automations are version-scoped and cloned on version branch (REQ-AUTD-009)

Each automation SHALL carry `applicationSlug` and `versionUuid` and SHALL
compile only into that version's register schemas and manifest. When the
version-branch flow in `ApplicationVersionService` creates a new
ApplicationVersion from a source version, the system SHALL clone the source
version's automations to the new version (new object uuids, new `aut-<uuid8>`
rule-set slugs) and recompile them there, so `promotesTo` chain members never
share a mutable compiled artifact.

@e2e exclude backend version-branch contract — asserted by PHPUnit on the
`ApplicationVersionService` clone hook and compiler re-keying; the
version-branch UI flow itself is covered by existing versionRouting /
version-rollback Playwright suites, and per-version listing is covered by the
REQ-AUTD-001 version-selector scenario

**ID:** REQ-AUTD-009

#### Scenario: Branching a version clones and re-keys its automations

- **WHEN** a new ApplicationVersion is branched from a version that has a
  manual automation compiled to rule set `aut-<uuid8>`
- **THEN** the new version has its own automation object with a new uuid and
  a distinct `aut-` rule-set slug, compiled into the new version's resources
- **AND** disabling the clone does not change the source version's artifacts

### Requirement: The rules engine dispatches side-effect actions through a wired dispatcher (REQ-AUTD-010)

The system SHALL wire a `RuleActionDispatcher` into
`RuleEngineService::evaluate()` (fixing the verified defect at
`lib/Service/RuleEngineService.php:142` where no dispatcher is passed and
side-effect actions silently no-op in wet runs) and SHALL extend
`ConditionActionExecutor`'s typed action vocabulary with `object-op`
(dispatched via OpenRegister `ObjectService::saveObject`) and `webhook`
(HTTP POST via Nextcloud's client service against the compiled target
config). Dry-run evaluation SHALL continue to suppress all dispatch. This
completes the documented ADR-031 §Exceptions code path; no new engine is
added.

@e2e exclude backend engine contract — dispatch wiring, new action types and
dry-run suppression are asserted by PHPUnit on RuleEngineService /
ConditionActionExecutor / RuleActionDispatcher; the user-visible surface is
covered by the REQ-AUTD-007 dry-run Playwright scenarios

**ID:** REQ-AUTD-010

#### Scenario: Wet evaluation dispatches a send-notification action

- **WHEN** a rule set whose triggered rule carries a `send-notification`
  action is evaluated with `dryRun: false`
- **THEN** the dispatcher is invoked for that action and a Nextcloud
  notification is produced

#### Scenario: Dry-run still suppresses dispatch

- **WHEN** the same rule set is evaluated with `dryRun: true`
- **THEN** the dispatcher is not invoked and the action is reported as
  dry-run/skipped

#### Scenario: object-op action writes through ObjectService

- **WHEN** a triggered rule carries an `object-op` create action for a schema
  and evaluation runs wet
- **THEN** exactly one object is created in that schema via
  `ObjectService::saveObject` with the mapped fields

### Requirement: Automations compile deterministically to declarative primitives or one OpenRegister flow (REQ-BQAR-001)

The system SHALL compile a saved automation (`AutomationCompilerService`)
only to existing declarative primitives, a listener-backed integration or one
OpenRegister flow (REQ-BQAR-001a to 001d), deterministically and idempotently.
No new imperative execution engine is introduced in buildiq.

@e2e exclude backend compilation contract, asserted by PHPUnit; the user-visible
halves are REQ-AUTD-001/002/005's Playwright scenarios

**ID:** REQ-BQAR-001 (replaces REQ-AUTD-004)

#### Scenario: Recompilation is idempotent

- **WHEN** an unchanged automation is compiled twice
- **THEN** the second compile produces byte-identical artifacts and the
  `provenance.compiledHash` is unchanged

### Requirement: Notifications and scheduled syncs compile to their dialects (REQ-BQAR-001a)

The system SHALL compile event/transition + `send-notification` to an
`x-openregister-notifications` entry on the target schema keyed
`aut-<slug>-<n>`, and schedule + `run-synchronization` to a
`manifest.schedules[]` entry with id `aut-<slug>-<n>` and action
`openconnector:synchronization`, valid against the existing schedules validator.

#### Scenario: Event notification compiles to the notifications dialect

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an enabled automation with trigger "object created" on `permit`
  and one `send-notification` action is compiled
- **THEN** the `permit` schema in the version's register carries an
  `x-openregister-notifications` entry keyed `aut-<slug>-1` with
  `trigger.type: "created"`, the mapped channels/recipients/subject and
  `enabled: true`

#### Scenario: Scheduled sync compiles to a schedules entry

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an enabled automation with a Daily schedule trigger and a
  `run-synchronization` action is compiled
- **THEN** the version's `manifest.schedules[]` contains an entry
  `{id: "aut-<slug>-1", enabled: true, interval: 86400, action:
  "openconnector:synchronization", arguments: {synchronizationId}}` that
  passes `validateSchedules`

### Requirement: Flow actions compile to one OpenRegister flow (REQ-BQAR-001b)

The system SHALL compile an automation with a `manual` trigger, or holding a flow
action (`object-op` or `webhook` outside an approval, or `rule-decision`), to one
OpenRegister flow named `aut-<slug>`, saved through OpenRegister's
`FlowService::save()`, bound in `Application.flows`, and named in
`provenance.flowUuid`. A lifecycle transition + a flow action SHALL be refused
with the combination named, until OpenRegister can start a flow on a transition.

#### Scenario: Manual automation compiles to an OpenRegister flow

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** a manual automation with a FEEL condition and an `object-op`
  action is compiled
- **THEN** an OpenRegister flow named `aut-<slug>` exists with an
  `openregister.trigger-manual` node, an `openregister.filter` node holding
  the condition as JSONLogic and an `openregister.object-write` node, it is
  bound in the app's `Application.flows`, and the automation's
  `provenance.flowUuid` names it

#### Scenario: A record step on a lifecycle transition is refused

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an automation with trigger "lifecycle transition" and an
  `object-op` action is compiled
- **THEN** `AutomationCompilerService` throws
  `UnsupportedAutomationCombinationException` naming the combination, and no
  lifecycle action record is written

### Requirement: Approval actions compile to an OpenRegister approval chain (REQ-BQAR-001c)

The system SHALL compile event/lifecycle-transition + `approval` to an
OpenRegister `ApprovalChain` named `aut-<slug>` (one step, `role` = the assignee
group), upserted via OR's approval-chains API and instantiated against the
trigger object's uuid via `ApprovalService::initializeChain()` at trigger-fire
time. On-approve/on-reject follow-up actions SHALL dispatch through a typed
listener on OR's `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent`, never
a new approval engine.

#### Scenario: Approval action compiles to an OR approval chain

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an enabled automation with trigger "object created" on
  `permit-application` and one `approval` action assigned to group
  `permit-reviewers` is compiled
- **THEN** an OR `ApprovalChain` named `aut-<slug>` exists with one step
  `{order: 1, role: "permit-reviewers"}`
- **AND** the automation's `provenance.approvalChainName` names it

#### Scenario: Trigger firing initialises an approval step

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an object is created that matches an enabled automation's
  `approval` action trigger
- **THEN** `ApprovalService::initializeChain()` is called for that object's
  uuid against the compiled chain, creating a `pending` `ApprovalStep`

#### Scenario: Approval outcome dispatches the configured follow-up

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an `ApprovalStep` compiled from an automation's `approval` action
  is approved
- **THEN** the automation's on-approve follow-up actions are dispatched
- **AND** no on-reject follow-up action is dispatched

### Requirement: Document generation runs through Docudesk's route (REQ-BQAR-001d)

The system SHALL compile event/lifecycle-transition + `generateDocument` to a
validated action config, dispatched at trigger-fire time by
`DocumentGenerationListener` through `DocumentGenerationService`, which calls
Docudesk's `POST /apps/docudesk/api/correspondence/generate` route impersonating
the Application owner's session, never a Docudesk PHP class import (see
`automation-document-action` and `docudesk-document-templates` REQ-DDT-006).

#### Scenario: Document-generation action fires via the pinned Docudesk route

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

- **WHEN** an object matching an enabled automation's `generateDocument`
  trigger is created/transitioned
- **THEN** `DocumentGenerationService` calls
  `POST /apps/docudesk/api/correspondence/generate` with `dataRefs` naming
  that object, impersonating the Application owner
- **AND** no Docudesk PHP class is imported anywhere in the call path

#### Scenario: Missing Docudesk fails the compile, not the runtime

@e2e exclude backend compilation contract, asserted by PHPUnit (see REQ-BQAR-001)

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
