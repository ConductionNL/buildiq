---
kind: code
---

# Proposal: logic-automation-actions-that-run

## Why

Three rows of the buildiq matrix share one defect: the automation composer saves
steps that compile to something nothing runs.

**buildiq matrix, row `logic-action-update-record`**, "Create or update another
record as an automation step.", rated `partial`, `built.state` `built`. Three
competitors rate it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-workflow/src/client-v2/nodes/update.tsx:19
  Update record and ... nodes/create.tsx:25 Create record nodes on any
  collection ... Reached on: Workflow canvas, add node, Create or Update record"
  (source read at v2.2.18).
- Budibase: "packages/shared-core/src/automations/steps/createRow.ts:11 'Create
  row' and ... updateRow.ts:11 'Update row' steps on any table ... Reached on:
  automation editor > add step > Create row or Update row" (source read at
  v3.46.0).
- Mendix: "microflows (unlike rules) can create, delete, change and rollback
  objects" (https://docs.mendix.com/refguide/rules/).

Today, from `built.evidence`: "Transition path compiles to {type:
'related-object-upsert'} lifecycle actions OpenRegister cannot run
(AutomationCompilerService.php:815-827; no handler in openregister
LifecycleActionRegistry.php:70-71). Manual path compiles to a RuleSet nothing
runs. Working path: object-op inside an approval's on-approve/on-reject list".

**buildiq matrix, row `logic-action-webhook`**, "Call an external webhook as an
automation step.", rated `partial`, `built.state` `built`. Four competitors rate
it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-workflow-request/src/client-v2/RequestInstruction.tsx:18
  "HTTP request" node calls any URL with method, headers and body" (source read
  at v2.2.18).
- Budibase: "packages/shared-core/src/automations/steps/apiRequest.ts:11 'API
  request' step ... plus Zapier, n8n, Make, Slack and Discord webhook steps"
  (source read at v3.46.0).
- Mendix: "Call REST Service microflow action"
  (https://docs.mendix.com/refguide/consumed-rest-services/).
- Microsoft Power Apps: "standard or custom connectors call external services as
  actions"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/connections-list).

Today, from `built.evidence`: "lifecycle-transition compiles to {type:
'webhook-dispatch', url, payloadTemplate, marker} ... OpenRegister's
LifecycleActionExecutor reads the handler name from the 'action' key and throws
when it is empty (openregister lib/Service/Lifecycle/LifecycleActionExecutor.php:83-88),
and LifecycleActionRegistry only maps set-fields/set-field
(LifecycleActionRegistry.php:70-71) ... manual compiles to a RuleSet
(compileRulesBackend :910-933) that nothing runs".

**buildiq matrix, row `logic-rules-engine`**, "Define business rules that decide
an outcome from a record's data.", rated `partial`, `built.state` `built`. Two
competitors rate it `yes`:

- Mendix: "a rule is a special microflow returning a Boolean or enumeration,
  used in decisions and reusable" (https://docs.mendix.com/refguide/rules/).
- Microsoft Power Apps: "business rules apply logic and validations without
  writing code"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/data-platform-create-business-rule).

Today, from `built.evidence`: "POST /api/rules/{slug}/evaluate (routes.php:191)
and GET /schema (routes.php:192) appear in no frontend path ... and no sibling
... calls them." `reachedOn`: "/business-rules ... which has menu: [] and no
in-app link or router push anywhere in src/: only reachable by typing the URL".

The missing half for all three: a composer automation that holds a record step,
a webhook step or a rule decision must compile to something that runs.
OpenRegister's flow engine is that thing (ADR-065), and buildiq already
provisions flows through `FlowService::save()`
(`lib/Service/FlowChannelProvisioner.php:241-260`).

## What changes

- A flow backend in the automation compiler: an automation with a record step, a
  webhook step, a rule decision or a manual trigger compiles to one OpenRegister
  flow, bound to the app in `Application.flows`.
- A record step compiles to `openregister.object-write`, limited to the app's own
  schemas.
- A webhook step names an integriq source and a path, and compiles to
  `openconnector.source-call`. The free URL field goes.
- A new "Decide with a rule set" step puts a decision table into the flow as
  `openregister.decision-table` and writes the outcome to a field.
- A FEEL condition compiles to `openregister.filter` through a FEEL to JSONLogic
  translator.
- Manual automations can be run by app users from a record, through a runtime
  action.
- Record and webhook steps on a lifecycle transition are refused until
  OpenRegister can start a flow on a transition, instead of compiling records
  nothing runs. A repair step moves or disables the automations already stuck.
- The app detail page links to the business rules page.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | logic-action-update-record | Create or update another record as an automation step. | partial | a record step that runs outside an approval follow-up |
| buildiq | logic-action-webhook | Call an external webhook as an automation step. | partial | a webhook step that runs outside an approval follow-up |
| buildiq | logic-rules-engine | Define business rules that decide an outcome from a record's data. | partial | rule sets that decide on records inside automations, and a way to reach them |

## Existing work it builds on

- Spec `openspec/specs/automation-designer/spec.md` (archived
  `2026-07-11-automation-designer`, `2026-07-23-automation-approval-steps`,
  `2026-07-24-automation-document-action`). REQ-AUTD-004 names the compile
  targets this change replaces, so it is removed and replaced here by
  REQ-BQAR-001, which keeps its other clauses and scenarios.
- Spec `openspec/specs/business-rules-engine/spec.md` (archived
  `2026-06-14-business-rules-engine`) and open change
  `buildiq-consumes-shared-dmn`, whose table adapter
  (`DecisionTableEvaluator::toSharedTable()`) is reused to put a table into a
  flow.
- Open change `openbuild-app-binds-flows-and-agents` and spec
  `app-channel-application`: `Application.flows` and the `FlowService::save()`
  provisioning path.
- Open change `harden-rules-authz-and-audit-parity`: rule access and audit rules
  stay as they are.

## Sibling halves

- openregister: three things. (1) A way to start a flow on a lifecycle
  transition: `openregister.trigger-object` only knows `object.created`,
  `object.updated` and `object.deleted`
  (`lib/Service/Flow/Nodes/TriggerObjectNode.php:80-84`), and the lifecycle
  registry has no action that starts a flow
  (`lib/Service/Lifecycle/LifecycleActionRegistry.php:69-72`). (2) A way for a
  signed-in user of the owning app to start a published manual flow on a record:
  `POST /api/flows/{id}/run` is the editor's draft test run, gated on `flow.run`
  (`lib/Controller/FlowController.php:960-999`), and `FlowService::find()`
  refuses a flow that is not the caller's. (3) Nothing else: the nodes this
  change compiles to exist on development.
- integriq: nothing new. `openconnector.source-call` is on integriq development
  (`lib/Flow/SourceCallNode.php`), and it takes a configured source and a
  contained path by design ("WHY A SOURCE AND NOT A URL").

## Out of scope

- Record and webhook follow-ups inside an approval. They run today through
  `RuleActionDispatcher` and stay.
- Condition-action rule sets inside a flow. Their FEEL actions have no flow
  node; this change uses decision tables only.
- A visual flow canvas in the composer. The shared canvas stays behind "Edit
  flows...".
