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

### Requirement: Automations compile deterministically to existing declarative primitives (REQ-AUTD-004)

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
engine; event/lifecycle-transition + `approval` → an OpenRegister
`ApprovalChain` named `aut-<slug>` (one step, `role` = the assignee group),
upserted via OR's approval-chains API and instantiated against the trigger
object's uuid via `ApprovalService::initializeChain()` at trigger-fire time;
on-approve/on-reject follow-up actions dispatch through a typed listener on
OR's `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent`, never a new
approval engine; event/lifecycle-transition + `generateDocument` → a
validated action config (no compile-time Docudesk-side artifact — Docudesk's
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

