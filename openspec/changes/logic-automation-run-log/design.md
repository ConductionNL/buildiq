# Design: logic-automation-run-log

## Board

No board on the canvas (`5NkFW28vZUUij43xzxHg5a`) draws a run log. The two
nearest boards set the shape, and this design follows them:

- **BqAutomatiseringen** draws the automation rows with three buttons on the
  right: "Aan", "Testen", "Bewerken". The "Runs" button ("Uitvoeringen") joins
  them between "Testen" and "Bewerken", and the last-run line sits under the
  action summary in the same small grey type the board uses for the trigger
  detail ("schema klacht, overgang indienen").
- **BqAutomatiseringTesten** draws the dry-run result as a two-column table
  "Actie | Uitkomst" with a duration line under it ("Duur 184 ms"). The per-run
  detail reuses exactly that table, so a maker reads a real run the way they
  already read a test run.

## D1. A buildiq schema, because the automation is buildiq's

ADR-022 says consume what OpenRegister owns. The automation object, its
compiler and four of its dispatch points are buildiq's, so the record of a run
of it is buildiq's too. It lives in the `buildiq` register as the schema
`automation-run` in `lib/Settings/register.d/40-automations.json`, next to
`automation`. It is stored through `ObjectService::saveObject`, the same path
`RuleEngineService` uses for `rule-execution-log`. No new table.

Properties:

| property | type | note |
| --- | --- | --- |
| `automation` | string (uuid) | the automation that ran |
| `versionUuid` | string (uuid) | the application version, so a run stays with its version |
| `compiledHash` | string | `provenance.compiledHash` at run time, so a run of an older definition is visible as such |
| `trigger` | string | `object-created`, `object-updated`, `object-deleted`, `lifecycle-transition`, `schedule`, `manual` |
| `subject` | object | `{schema, uuid}` of the object the run acted on; null for a schedule |
| `startedAt` | date-time | |
| `durationMs` | integer | |
| `outcome` | string enum | `succeeded`, `failed`, `skipped`, `waiting` |
| `reason` | string | for `skipped` and `failed`: one sentence, for example "The condition was false: prioriteit is laag." |
| `steps` | array | `{action, outcome, message}` per action, `outcome` one of `succeeded`, `failed`, `skipped`, `waiting`, `in-openregister` |
| `actor` | string | uid that caused the trigger, or `system` |

No input payload is stored. A run record is read by more people than an
object's own audit trail, and the subject uuid is enough to open the object.

`authorization` on the schema: read `admin`, create `admin`, update `admin`,
delete `admin`. Makers do not read it through OpenRegister; they read it
through the buildiq endpoint in D3, which applies the automation's own rules.
This matches why automation CRUD lives in `AutomationsController` and not on
OpenRegister REST (`appinfo/routes.php:200-216`).

## D2. Where a run is written

One service, `AutomationRunRecorder` (`lib/Service/AutomationRunRecorder.php`),
with `start(automation, trigger, subject)`, `step(action, outcome, message)`
and `finish(outcome, reason)`. It is called from the four places buildiq
dispatches:

| dispatch point | what it records |
| --- | --- |
| `RuleEngineService` evaluation for an `aut-` RuleSet (`manual` trigger) | condition result, each side-effect action through `RuleActionDispatcher` |
| `AutomationApprovalTriggerListener` | the run with outcome `waiting` and step "approval requested" |
| `ApprovalOutcomeListener` | completes the `waiting` run: the decision, then each `onApprove` or `onReject` action |
| `DocumentGenerationListener` | the document step with the Docudesk (filinq) result or error |

A recorder failure is logged at error level and never aborts the automation,
the same rule `RuleEngineService` applies to its own audit write.

## D3. Read endpoint

`GET /api/automations/{uuid}/runs?outcome=&since=&until=&limit=&offset=` on
`AutomationsController::runs`, `#[NoAdminRequired]`, guarded by the same
`withAutomation(roles: self::WRITE_ROLES)` pipeline `status()` uses (an editor
or owner of the automation's application). Results newest first, default limit 25. The response carries
`summary: {lastRunAt, lastOutcome, lastError}` so the list page needs one call
per row at most. The list page fetches summaries for the visible rows in one
call: `GET /api/automations/runs/summary?uuids=a,b,c`, which runs every uuid
through the same pipeline and leaves out any uuid the caller may not read.

## D4. Steps that OpenRegister executes

Notifications compile to `x-openregister-notifications`, lifecycle actions to
`x-openregister-lifecycle`, schedules to `manifest.schedules[]`. OpenRegister
(or integriq for a synchronization) executes them without calling buildiq.
OpenRegister records notification dispatches in `NotificationDispatchLog`
(`notificationSlug`, `dispatchedAt`) but exposes no read endpoint, and records
no outcome for a typed lifecycle action.

So in v1 such a step shows in the runs dialog as one line per action with
outcome `in-openregister` and the text "Runs in OpenRegister. No per-run record
yet." The run itself is not invented: an automation whose every action runs in
OpenRegister shows the note "This automation runs entirely in OpenRegister" and
no run rows, never an empty table that reads as "never ran".

Cross-repo: OpenRegister needs a read endpoint over its notification dispatch
log filtered by notification key (the `aut-` prefix), and an outcome record for
typed lifecycle actions. When those land, the recorder reads them into the same
run shape. Schedules link to the synchronization log in integriq, whose app id
`fleetAppId.js` resolves.

## D5. Retention

A background job `AutomationRunPruneJob` (daily) deletes `automation-run`
records older than 90 days. The summary in D3 is computed from the newest
record, so pruning never loses the last run of an automation that still runs.
An automation that has not run for 90 days shows "No run in the last 90 days",
which is itself the useful fact.

## D6. Version copy and delete

`automation-run` records are not copied when a version is branched
(`ApplicationVersionService` clones automations, not their history). Deleting
an automation keeps its runs until the prune job removes them, so a maker can
still see why something happened after removing the automation.
