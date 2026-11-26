# automation-designer

## ADDED Requirements

### Requirement: Every run of an automation that buildiq dispatches is recorded (REQ-BQRL-001)

The system SHALL write one `automation-run` record per run of one automation on
one subject at every point where buildiq dispatches an automation: the `manual`
trigger evaluated by `RuleEngineService`, `AutomationApprovalTriggerListener`,
`ApprovalOutcomeListener` and `DocumentGenerationListener`. The record MUST
carry the automation uuid, the version uuid, the compiled hash, the trigger,
the subject, the start time, the duration, the outcome (`succeeded`, `failed`,
`skipped`, `waiting`), a reason for `failed` and `skipped`, and one step per
action with its own outcome and message. The record MUST NOT carry the input
payload. A failure to write the record SHALL be logged at error level and MUST
NOT abort the automation.

@e2e tests/e2e/automation-runs.spec.ts

#### Scenario: A manual run with a failing action is recorded with the failure

- **GIVEN** an automation "Nieuw ticket melden" with the `manual` trigger and two actions
- **WHEN** a maker runs it on ticket T-1 and the second action fails with "Webhook answered 500"
- **THEN** one `automation-run` record exists for that automation and subject T-1
- **AND** its outcome is `failed`
- **AND** its first step has outcome `succeeded` and its second step has outcome `failed` with message "Webhook answered 500"

#### Scenario: A false condition is a skipped run with its reason

- **GIVEN** an automation with the condition `prioriteit = "hoog"`
- **WHEN** it is triggered on a ticket whose `prioriteit` is "laag"
- **THEN** the run is recorded with outcome `skipped`
- **AND** its reason names the condition that was false

#### Scenario: An approval run waits and is completed by the decision

- **GIVEN** an automation "Klacht ter goedkeuring" with an approval action and a document action on approve
- **WHEN** complaint C-2026-0412 takes the transition `indienen`
- **THEN** a run is recorded with outcome `waiting` and the step "approval requested"
- **WHEN** a team lead approves the task
- **THEN** the same run is updated with the decision step and the document step, and its outcome becomes `succeeded`

#### Scenario: A recorder failure does not stop the automation

- **GIVEN** the `automation-run` schema cannot be written
- **WHEN** an automation runs
- **THEN** its actions still run
- **AND** an error is logged naming the automation

### Requirement: A maker reads the runs of an automation they may edit (REQ-BQRL-002)

The system SHALL expose `GET /api/automations/{uuid}/runs` with the filters
`outcome`, `since`, `until`, `limit` (default 25) and `offset`, newest first,
and a `summary` with `lastRunAt`, `lastOutcome` and `lastError`. The endpoint
MUST apply the same authorization as `GET /api/automations/{uuid}/status`: a
caller who may not edit the automation's application receives the same refusal
the status endpoint gives. The `automation-run` schema MUST NOT be readable by
non-admins through OpenRegister's object API.

@e2e tests/e2e/automation-runs.spec.ts

#### Scenario: An editor reads the runs

- **GIVEN** a user who is an editor of the application Pipelinq
- **WHEN** they request the runs of an automation in that application
- **THEN** they receive its runs, newest first, with the summary

#### Scenario: A user who may not edit the application reads nothing

- **GIVEN** a signed-in user with no role on the application Pipelinq
- **WHEN** they request the runs of one of its automations
- **THEN** the response is the refusal `status` gives for that user
- **AND** no run is returned

#### Scenario: Filtering on failures

- **WHEN** an editor requests the runs with `outcome=failed`
- **THEN** only runs with outcome `failed` are returned

### Requirement: The Automations page shows the last run and opens the run log (REQ-BQRL-003)

Each automation row on the Automations page SHALL show a last-run line under
its action summary ("Last run 7 Oct 14:02, succeeded", "Last run failed: Webhook
answered 500", or "No run in the last 90 days") and SHALL offer a "Runs" button
between "Test" and "Edit". The button SHALL open a runs dialog in `src/modals/`
with a table of runs (time, trigger, object, outcome, duration), an outcome
filter, and per run the table "Action | Outcome" that the test panel uses. The
object cell SHALL link to the object.

@e2e tests/e2e/automation-runs.spec.ts

#### Scenario: A failed last run is visible on the list

- **GIVEN** the last run of "Nieuw ticket melden" failed with "Webhook answered 500"
- **WHEN** a maker opens the Automations page
- **THEN** that row shows "Last run failed: Webhook answered 500"

#### Scenario: The run detail reads like a test result

- **WHEN** a maker opens "Runs" on an automation and expands a run
- **THEN** a table with the columns "Action" and "Outcome" lists each action of that run
- **AND** the run's duration shows under the table

#### Scenario: An automation that never ran says so

- **GIVEN** an automation with no run record
- **WHEN** a maker opens its runs dialog
- **THEN** the dialog says "No run in the last 90 days"
- **AND** shows no empty table

### Requirement: A step that OpenRegister executes is labelled, never invented (REQ-BQRL-004)

For an action that compiles to an artifact OpenRegister or integriq executes
without buildiq (a notification, a typed lifecycle action, a schedule), the
runs dialog MUST NOT show a success or failure that OpenRegister did not
record. The compiler SHALL give each compiled notification the key
`aut-<automation uuid>-<position>` and each typed lifecycle action the key
`aut-<automation uuid>`, and the runs endpoint SHALL read OpenRegister's
outcome records under that key (`GET /api/automation-outcomes`, under the
caller's own access) and answer each as a run of its own with
`source: openregister`, one step, the outcome (`sent` and `done` as succeeded,
`partial` and `failed` as failed, `skipped` as skipped), the reason, and for a
notification the number of people it reached. An outcome whose key belongs to
another automation MUST NOT be shown. An action OpenRegister keeps no record of
SHALL be listed with the outcome "Runs in OpenRegister. No per-run record
yet." When every action of an automation runs outside buildiq and none leaves
a record, the dialog SHALL say "This automation runs entirely in OpenRegister"
and show no run rows. A schedule action SHALL link to the synchronization log
in integriq.

@e2e tests/e2e/automation-runs.spec.ts

#### Scenario: A notification's runs come from OpenRegister's record

- **GIVEN** an automation on object creation whose only action is "Send notification"
- **AND** OpenRegister recorded that notification as sent to 2 people for object `T-7`
- **WHEN** a maker opens its runs dialog
- **THEN** a run row for `T-7` shows the outcome "succeeded" and no duration
- **AND** its detail lists the step "send-notification" as "succeeded: Sent to 2 people"
- **AND** the last-run line on the Automations page counts that run

#### Scenario: An automation with no record anywhere does not fake a log

- **GIVEN** an automation on object creation whose only action is a webhook
- **WHEN** a maker opens its runs dialog
- **THEN** the dialog says "This automation runs entirely in OpenRegister"
- **AND** no run row is shown

#### Scenario: Another automation's outcomes are not shown

- **GIVEN** two automations whose uuids start with the same characters
- **WHEN** a maker opens the runs of one of them
- **THEN** no outcome recorded under the other automation's key is shown

#### Scenario: A mixed automation labels the OpenRegister step

- **GIVEN** an approval automation that also calls a webhook OpenRegister executes
- **WHEN** a maker expands a run
- **THEN** the webhook step shows "Runs in OpenRegister. No per-run record yet."

### Requirement: Old runs are pruned and the last run stays (REQ-BQRL-005)

A daily background job SHALL delete `automation-run` records older than 90
days. The summary of REQ-BQRL-002 MUST be computed from the newest remaining
record. Run records MUST NOT be copied when an application version is branched,
and MUST stay when their automation is deleted, until the prune job removes them.

@e2e exclude a 90-day retention cannot be driven in a browser run; covered by PHPUnit on AutomationRunPruneJob with a fixed clock

#### Scenario: A run older than 90 days is removed

- **GIVEN** a run record started 91 days ago and one started yesterday
- **WHEN** the prune job runs
- **THEN** the old record is deleted and the one from yesterday stays

#### Scenario: Deleting an automation keeps its runs

- **WHEN** a maker deletes an automation that ran yesterday
- **THEN** its run record from yesterday still exists
