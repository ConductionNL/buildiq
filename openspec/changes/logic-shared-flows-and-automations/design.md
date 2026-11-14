# Design: logic-shared-flows-and-automations

Read at buildiq development `d21e42f`, openregister development `ae898b0`.

## Where it sits

- Binding: `Application.flows` in `lib/Settings/register.d/22-flows-and-agents.json`
  (line 7), items `{flow, label, sourceUuid}` with `additionalProperties: false`.
- Picker: `src/modals/AppSettingsModal.vue` renders the flow picker (lines
  99-130), fed by `ApplicationDetailActions.vue` `onSettingsOpen()` (line 871),
  which reads `GET /apps/openregister/api/flows` (line 880), and saved by
  `setFlows()` (line 907).
- Display: `src/components/applicationDetail/widgets/FlowsWidget.vue` lists the
  bound flows and deep-links into OpenRegister.
- Export: `lib/Service/FlowAndAgentExportBundler.php`,
  `lib/Service/FlowChannelProvisioner.php` (`existingSourceUuids()` line 302,
  `rebindApplication()` line 332).
- Automations: `lib/Settings/register.d/40-automations.json` and the flow backend
  of `logic-automation-actions-that-run`, which compiles an automation to a flow
  `aut-<slug>` and binds it in `Application.flows`.
- Sub-flow: openregister `lib/Service/Flow/Nodes/SubFlowNode.php`, id
  `openregister.sub-flow`, config `flow`, `flowId`, `wait`, `fanOut`, with a
  stack and depth guard against cycles.

## D1. Where a bound flow runs

A flow binding gains `runOn[]`, each `{schema, event}` with `event` one of
`object.created`, `object.updated`, `object.deleted`, or `{mode: "from-record",
schema}`. It is declared in a new fragment
`lib/Settings/register.d/82-flow-binding-run-on.json`, which adds the property to
the binding items. `AppSettingsModal.vue` shows a "Run on" editor under each
picked flow, listing only the app version's schemas.

## D2. A binding compiles to a wrapper flow

For each `runOn` entry buildiq saves a wrapper flow `aut-attach-<app>-<n>`
through `FlowService::save()`: `openregister.trigger-object` with the app's
register and the chosen schema and event, then `openregister.sub-flow` with
`flowId` the bound flow and `wait: false`. A `from-record` entry gets
`openregister.trigger-manual` instead, and shows in the runtime action of
`logic-automation-actions-that-run` D8. The wrappers are bound in
`Application.flows` with `sourceUuid` pointing at the binding they serve, so
export can tell a wrapper from a flow the maker bound, and removing a binding
removes its wrappers. The bound flow is never modified.

## D3. Sharing an automation

`Automation` gains `shared` (boolean, default false). Only an `owners` member of
the automation's app may set it. A shared automation must have a manual trigger
or no trigger of its own that names the source app's schemas, so it reads its
input from the item it is given; the compiler refuses to share one whose steps
write a schema of the source app, and names the step. The flow picker in other
apps lists shared automations as "Shared automations", with the source app's
name.

## D4. Calling another automation

A new action kind `run-automation` names a shared automation (or one of the same
app). It compiles through the flow backend to `openregister.sub-flow` with
`wait: true`, so the caller's later steps see its output items. OpenRegister's
stack guard refuses a cycle at run time; buildiq also refuses a direct self
reference at compile.

## D5. Seeing where it runs

`FlowsWidget.vue` shows, per bound flow, its `runOn` entries and the status and
time of its last run, read from OpenRegister's flow runs
(`GET /apps/openregister/api/flow-runs?flowId=<uuid>&limit=1`, openregister
`lib/Controller/FlowRunController.php:155-157`).

## Risks

- A bound flow written for other data can fail on this app's records. The
  failure lands in that flow's run log, and the widget shows the last status.
- Sharing makes one change reach several apps. The source app's owners decide,
  and the picker names the source app.

## What it does not do

- It does not edit a flow it did not create.
- It does not share across instances.
- It does not change how rule sets are shared.
