---
kind: code
depends_on: [logic-automation-actions-that-run]
---

# Proposal: logic-shared-flows-and-automations

## Why

**buildiq matrix, row `logic-workflow-attach`**, "Attach an existing workflow to
an app so it runs on the app's records.", rated `partial`, `built.state`
`built`. Four competitors rate it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-workflow/src/client-v2/triggers/collection/index.tsx:21
  a Collection event workflow binds to a chosen collection and runs on its
  records; ... CustomActionTrigger.ts:100 lets a page button run a workflow on
  the current record" (source read at v2.2.18).
- Budibase: "automations live in the workspace and run on its tables, so any app
  in it uses them: row triggers fire for any app's writes ... and screens call an
  existing automation with the 'Trigger Automation' button action" (source read
  at v3.46.0).
- Mendix: "workflows are fully integrated with the microflow and page editors of
  the app" (https://docs.mendix.com/refguide/workflows/).
- Microsoft Power Apps: "apps built with Power Apps provide rich business logic
  and workflow capabilities"
  (https://learn.microsoft.com/en-us/power-apps/powerapps-overview).

Today, from `built.evidence`: "src/modals/AppSettingsModal.vue:115 flow picker
... ApplicationDetailActions.vue:900-910 setFlows -> obPatchApp({flows}) onto
Application.flows (lib/Settings/register.d/22-flows-and-agents.json). Consumers
of Application.flows in buildiq: export and GitHub bundling ... and the app
detail FlowsWidget list; nothing in buildiq or OpenRegister ... uses the binding
to trigger or scope a flow." The missing half: attaching a flow makes it run on
the app's records.

**buildiq matrix, row `reuse-shared-logic`**, "Share a rule set or automation
between apps.", rated `partial`, `built.state` `built`. Two competitors rate it
`yes`:

- Budibase: "automations belong to the workspace and serve all its apps ... an
  automation can call another with the 'Trigger an automation' step" (source
  read at v3.46.0).
- Microsoft Power Apps: "business rules on a table apply to every canvas and
  model-driven app using that table"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/data-platform-create-business-rule).

Today, from `built.evidence`: "RuleSet lives in the system-wide buildiq register
with ownerApp and isGlobal ... any app's automation can reference one by slug
... Rule sets are edited on src/views/RuleSetsPage.vue". Rule sets are shared;
automations are not. The missing half: one app's automation used by another.

## What changes

- An attached flow gets a "Run on" setting: one of the app's schemas with an
  event (created, updated, deleted), or "from a record" for app users.
- Buildiq compiles each setting to a small flow of its own: the app's trigger,
  then `openregister.sub-flow` calling the attached flow. The attached flow
  itself is never edited.
- An automation's owner can mark it shared. A shared automation's flow shows up
  in other apps' flow picker under "Shared automations", and attaches like any
  flow.
- A new step "Run another automation" calls a shared automation from inside an
  automation, through `openregister.sub-flow`.
- The app detail page shows, per attached flow, where it runs and its last run.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | logic-workflow-attach | Attach an existing workflow to an app so it runs on the app's records. | partial | an attached flow that runs on the app's records or from a record |
| buildiq | reuse-shared-logic | Share a rule set or automation between apps. | partial | sharing an automation with other apps and calling it from theirs |

## Existing work it builds on

- Change `openbuild-app-binds-flows-and-agents` (open) and spec
  `app-channel-application`: `Application.flows` bindings by UUID, and export.
- Change `logic-automation-actions-that-run` (this pass): the flow backend and
  the runtime action that runs a manual automation from a record; attached flows
  set to "from a record" use the same runtime action.
- Archived `2026-06-14-procest-workflow-attachments` and spec
  `procest-workflow-attachments`: attaching a dossiq workflow to a schema, a
  different binding that this change leaves alone.
- Open change `buildiq-consumes-shared-dmn`: rule sets stay shared as they are.

## Sibling halves

- openregister: nothing new for the attach itself. `openregister.sub-flow` runs
  another flow as a step, waiting or fire-and-forget, with a recursion guard
  (`lib/Service/Flow/Nodes/SubFlowNode.php:103-104`, config keys at lines
  159-161). A published manual flow started by an app user needs the same
  OpenRegister entrypoint `logic-automation-actions-that-run` names.
- None other.

## Out of scope

- Editing another app's flow or another app's automation.
- Sharing across instances. Export and the GitHub shop already carry flows with
  an app.
- Sharing rule sets, which already works.
