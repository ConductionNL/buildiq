# Design: logic-automation-actions-that-run

Read at buildiq development `d21e42f`, openregister development `ae898b0`.

## Where it sits

- Compiler: `lib/Service/AutomationCompilerService.php`. `MATRIX` (lines
  149-156) allows `object-op` and `webhook` only on `lifecycle-transition` and
  `manual`. `compileDialectBackend()` (line 753) turns them into lifecycle
  records keyed `type` (lines 815-839), which OpenRegister's executor cannot run:
  it reads the handler from `action` and throws when that is empty
  (openregister `lib/Service/Lifecycle/LifecycleActionExecutor.php:83-88`).
  `compileRulesBackend()` (line 914) turns a manual automation into a RuleSet
  that nothing runs. `buildConditionAndActions()` (lines 979-995) compiles a
  rule-set condition to a `call-rule-set` action dispatched ahead of the other
  actions, which "always run unconditionally in this shape", so that condition
  gates nothing.
- The path that works: follow-ups inside an approval, dispatched by
  `ApprovalOutcomeListener` through `RuleActionDispatcher` (`__invoke()` lines
  124-141, `dispatchObjectOp()` line 198, `dispatchWebhook()` line 250).
- Composer: `src/dialogs/AutomationEditDialog.vue`. Condition kinds `feel` and
  `rule-set` (lines 120-135); record step form (lines 205-231); webhook form
  with a free "Webhook URL" (lines 233-246); triggers (lines 484-495); action
  kinds (lines 568-584).
- Flow provisioning: `lib/Service/FlowChannelProvisioner.php` saves flows through
  OpenRegister's `FlowService::save()` with `app` set to buildiq (lines
  241-260) and rebinds `Application.flows` (line 332). `Application.flows` is
  declared in `lib/Settings/register.d/22-flows-and-agents.json`.
- OpenRegister nodes used: `openregister.trigger-object` (events at
  `lib/Service/Flow/Nodes/TriggerObjectNode.php:80-84`, config keys `event`,
  `register`, `schema`), `openregister.trigger-manual`,
  `openregister.trigger-schedule`, `openregister.filter` (JSONLogic, per
  `lib/Service/Flow/FlowExpression.php`), `openregister.decision-table` (config
  `table`, `inputMapping`, `outputMapping`, `defaultOutputs`, `resultKey`,
  `lib/Service/Flow/Nodes/DecisionTableNode.php:146-148`),
  `openregister.object-write`, `openregister.send-notification`. Integriq node:
  `openconnector.source-call` (config `source`, `endpoint`, `method`, `query`,
  `headers`, `body`, `output`, integriq `lib/Flow/SourceCallNode.php:286`).
- Rules: `lib/Service/DecisionTableEvaluator.php` translates buildiq's table to
  the shared shape in `toSharedTable()` (line 183) and `sharedCell()` (line 284).
  The rules page is `src/views/RuleSetsPage.vue`, declared with `menu: []` in
  `src/manifest.d/20-business-rules.json`.
- Way in: `src/components/ApplicationDetailActions.vue` "Automations" action
  (lines 303-307, `openAutomations()` lines 842-855).

## D1. When an automation becomes a flow

An automation compiles to one OpenRegister flow when its trigger is `manual`, or
when any action is a flow action: `object-op` or `webhook` outside an approval,
the new `rule-decision`, and the kinds later changes add (`ai-step`,
`script-step`). `send-notification` in such an automation compiles into the flow
as `openregister.send-notification`. `approval` and `generateDocument` keep their
backends and cannot share an automation with a flow action in this version; the
compiler refuses the mix and names it. Notification-only automations on object
events keep the dialect backend, which runs today.

## D2. The flow's shape

A linear flow named `aut-<slug>`: the trigger node, an optional
`openregister.filter` for the condition, then one node per action in order.
It is saved through `FlowService::save()` like `FlowChannelProvisioner` does,
with `app` buildiq, and bound in `Application.flows` as `{label, flow}`. The
automation's `provenance.flowUuid` names it. Enable and disable set the flow's
enabled state. `remove()` deletes it through `FlowService::delete()` and unbinds
it. Recompiling an unchanged automation writes an identical node list, so
`provenance.compiledHash` stays stable (REQ-BQAR-001).

## D3. Triggers

- `object-created`, `object-updated`, `object-deleted` map to
  `openregister.trigger-object` with the version's register and the target
  schema.
- `schedule` maps to `openregister.trigger-schedule`.
- `manual` maps to `openregister.trigger-manual`.
- `lifecycle-transition` has no flow trigger in OpenRegister. Flow actions on it
  are refused with "Record and webhook steps cannot run on a lifecycle
  transition yet." until OpenRegister ships one (see proposal).

## D4. Record step

`object-op` compiles to `openregister.object-write` with `operation` create or
update, the register of the app's version, and a target schema that must be one
of that version's schemas. A slug outside the app is refused at compile. The
field mapping becomes the node's templated values, so `{{field}}` reads the
triggering record.

## D5. Webhook step

The webhook form trades "Webhook URL" for a source picker (integriq sources the
maker can use) and a path. It compiles to `openconnector.source-call` with
`method` POST and the payload template as `body`. A draft that still carries a
bare URL is refused on save with "Pick a source for this webhook.". Without
integriq the webhook kind is disabled with the reason, as `generateDocument` is
without the document app.

## D6. Conditions

A new `lib/Service/FeelToJsonLogic.php` walks the AST that `FeelParser::parse()`
returns and emits JSONLogic for `openregister.filter`: comparisons, ranges,
lists, `and`, `or`, `not`, null checks, arithmetic, literals, and field paths as
`{"var": "json.<path>"}`. An expression it cannot translate is refused at
compile with the part it could not translate. A `rule-set` condition compiles to
`openregister.decision-table` followed by a filter on the table's result, so the
reference finally gates the actions.

## D7. Decide with a rule set

A new action kind `rule-decision`: a rule set slug (decision table type only),
an input mapping from record fields to table columns, and the output field. It
compiles to `openregister.decision-table` with the table built by the same
translation as `toSharedTable()` and `sharedCell()`, then
`openregister.object-write` of the result onto the triggering record. A
condition-action rule set is refused. The automation's provenance records each
rule set it copied and its version. Activating a new rule set version recompiles
the automations that reference it, through the path `RuleSetVersioningService::promoteToActive()`
already runs.

## D8. Running a manual automation from a record

A new runtime action `src/components/runtime/RunAutomationAction.vue`,
registered in `src/runtimeRegistry.js` next to `TrackLinkAction`, lists the
enabled manual automations of the app for the record's schema and runs the
chosen one. It calls a new buildiq route `POST /api/automations/{uuid}/run`
(`#[NoAdminRequired]`), which checks that the automation is manual and enabled,
that the caller holds a use role on the app, and that the caller can read the
record, and then asks OpenRegister to start the flow for that record. That last
call waits on OpenRegister (see proposal); until then the action is hidden.

## D9. Automations already stuck

A repair step `lib/Repair/MoveStuckAutomationsToFlows.php` finds automations
whose provenance lists `related-object-upsert` or `webhook-dispatch` lifecycle
records or an `aut-` RuleSet from a manual trigger. It removes those artefacts,
recompiles the automation through D1, and disables it with a recorded reason
when it cannot compile (a transition trigger, or a webhook with a bare URL). The
automations page shows that reason.

## D10. The rules page has a way in

`ApplicationDetailActions.vue` gains "Business rules" next to "Automations".
`RuleSetsPage.vue` reads `?app=` and lists the rule sets whose `ownerApp` is that
app, plus global ones.

## Risks

- Moving manual automations to flows changes where they run. The repair step
  records each move, and a disabled automation says why.
- A copied decision table can drift from its rule set. D7 recompiles on
  activation, and provenance names the version each flow holds.
- A FEEL construct with no JSONLogic form is refused, not guessed.

## What it does not do

- It adds no flow node, trigger or lifecycle handler; those are OpenRegister's.
- It does not change approval follow-ups.
- It keeps notification-only and document automations on their current backends.
