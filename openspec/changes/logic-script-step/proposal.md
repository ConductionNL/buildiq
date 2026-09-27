---
kind: code
depends_on: [logic-automation-actions-that-run]
---

# Proposal: logic-script-step

## Why

**buildiq matrix, row `logic-custom-code-step`**, "Run a custom script as a step
in an automation.", rated `no`, `built.state` `none`. Three competitors rate it
`yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-workflow-javascript/src/client-v2/nodes/script.tsx:36
  JavaScript node; builtIn packages/presets/nocobase/package.json:179 ...
  Reached on: Workflow canvas, add node, JavaScript" (source read at v2.2.18).
- Budibase: "packages/shared-core/src/automations/steps/executeScriptV2.ts:11
  'JavaScript' step ... and packages/shared-core/src/automations/steps/bash.ts:10
  'Bash scripting' ... Reached on: automation editor > add step > JavaScript"
  (source read at v3.46.0).
- Mendix: "microflow logic and custom Java code (Java actions)"
  (https://docs.mendix.com/refguide/testing-microflows-with-unit-testing-module/).

Today, from `built.evidence`: "composer action types
(AutomationEditDialog.vue:570-584) have no script step;
RuleActionDispatcher.php:127-131 supports
send-notification/object-op/webhook/start-workflow/call-rule-set, and
start-workflow is a logged no-op (RuleActionDispatcher.php:135-141);
FeelParser.php:17 forbids function calls; openregister origin/development
lib/Service/Flow/Nodes has no script or code node".

Where maker code may run is already decided, and not by buildiq. OpenRegister's
expression evaluator says: "A JavaScript expression engine ... means running
user-authored code inside the Nextcloud process ... That is not a trade worth
making, so JSONLogic is the ceiling here by decision, not by omission. The route
to the things it genuinely cannot express (loops with state, parsing, crypto) is
an optional sandboxed sidecar (#2066), never a relaxation of this boundary"
(openregister `lib/Service/Flow/FlowExpression.php`, header). OpenRegister issue
#2066 describes that sidecar: a `flow-code-runner` ExApp, "node:22 runtime, no
PHP, no NC, no DB, no filesystem, per-call timeout + memory cap, declared egress
(default: none)", items in and items out.

Buildiq's half is authoring: the step in the composer, the code editor, who may
write it, and the compile to the runner's step.

## What changes

- A "Run a script" step in the automation composer, with a code editor for a
  function that takes the flow's items and returns items.
- It compiles, through the flow backend, to OpenRegister's `code` step, which
  runs in the sandboxed runner.
- Only app owners may add or change a script step, and only when an
  administrator has allowed script steps for the instance.
- The step is offered only when OpenRegister's node catalogue lists the code
  step.
- A test button runs the script against sample items in the runner, without
  writing anything.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | logic-custom-code-step | Run a custom script as a step in an automation. | no | authoring a script step that compiles to the sandboxed code step |

## Existing work it builds on

- Change `logic-automation-actions-that-run` (this pass): the flow backend.
- Spec `openspec/specs/automation-designer/spec.md`: REQ-AUTD-008 (editors
  author, owners enable on production) and REQ-AUTD-007 (dry run without side
  effects).
- Spec `openspec/specs/settings-and-observability/spec.md`: buildiq's admin
  settings, where the instance switch lives.

## Sibling halves

- openregister: the whole runtime. The `code` step type and the
  `flow-code-runner` ExApp are OpenRegister issue #2066 (open). No change for it
  exists under openregister `openspec/changes/` at `ae898b0`, and no code or
  script node exists in `lib/Service/Flow/Nodes/`. OpenRegister owes the node,
  its id and config keys, the runner, its limits, the egress declaration, and the
  run trace that records the code and the items. Until it lands this change's
  step stays hidden.
- nextcloud-vue: nothing required. `CnJsonViewer` at 2.57.1 is an editable
  CodeMirror editor with `json`, `xml`, `html` and plain text modes; buildiq uses
  plain text. JavaScript highlighting would be a small addition there, not a
  precondition.

## Out of scope

- Running maker code in the Nextcloud process or in the browser for this step.
- Languages other than JavaScript.
- Network access from a script. The runner's default is none, and buildiq does
  not ask for more.
- Scripts on pages. That is `pages-custom-code`.
