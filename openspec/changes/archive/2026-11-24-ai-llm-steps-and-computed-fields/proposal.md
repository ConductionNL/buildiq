---
kind: code
depends_on: [logic-automation-actions-that-run]
---

# Proposal: ai-llm-steps-and-computed-fields

## Why

**buildiq matrix, row `ai-llm-action`**, "Call a language model as a step in an
automation, for example to classify or summarise.", rated `no`, `built.state`
`none`. Two competitors rate it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-ai/src/client-v2/workflow/nodes/llm/index.tsx:18
  "LLM" workflow node and ... nodes/employee/index.tsx:23 "AI employee" node ...
  Reached on: Workflow canvas, add node, LLM" (source read at v2.2.18).
- Budibase: "packages/shared-core/src/automations/steps/ai/promptLLM.ts:9 'LLM
  prompt', classify.ts:11 'Classify text', summarise.ts:15, translate.ts:9,
  extract.ts:13, generate.ts:12 and agent.ts:12 automation steps ... Reached on:
  automation editor > add step > AI" (source read at v3.46.0).

Today, from `built.evidence`: "Searched: automation action types
(src/dialogs/AutomationEditDialog.vue:570-584) and RuleActionDispatcher.php:127-131
have no LLM step ... openregister origin/development lib/Service/Flow/Nodes has
no AI or LLM node ... buildiq's only LLM use is the copilot (CopilotService.php
TextToText)."

One correction, read at the same shas. OpenRegister's own node directory has no
language model node, but Hermiq contributes one to OpenRegister's engine:
`hermiq.agent-step`, "Run an agent turn and put its answer on the item"
(hermiq `lib/Flow/HermiqAgentNode.php:102-126`, development). Buildiq's
Automations page opens the shared flow canvas through "Edit flows..."
(`src/views/AutomationsPage.vue:18-23`, `CnFlowEditModal` from
`@conduction/nextcloud-vue` 2.57.1), and that canvas lists every node in
OpenRegister's catalogue (`useFlowStore.js` `loadNodeCatalog()`,
`GET /apps/openregister/api/flow/node-catalog`). So with Hermiq installed a
maker who knows the canvas can already wire an agent step. What is missing is
the step in the automation composer, where a maker picks "classify" or
"summarise" without building a flow graph, and the write of the answer back to
the record.

**buildiq matrix, row `ai-computed-column`**, "Add AI-computed fields that
translate, categorise, clean or score the sentiment of a record's text.", rated
`no`, `built.state` `none`. Two competitors rate it `yes`:

- Budibase: "packages/types/src/sdk/ai.ts:5-13 AI column operations
  SUMMARISE_TEXT, CLEAN_DATA, TRANSLATE, CATEGORISE_TEXT, SENTIMENT_ANALYSIS,
  PROMPT, SEARCH_WEB, computed per row by
  packages/server/src/utilities/rowProcessor/utils.ts:171-215" (source read at
  v3.46.0).
- Microsoft Power Apps: "a prompt column is an AI-powered data type with a
  natural language prompt over other columns; the generated result is stored
  persistently in the row"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/prompt-column).

Today, from `built.evidence`: "No AI field type
(src/components/schema-editor/FieldEditor.vue:319-327) and no LLM automation
step (ai-llm-action, src/dialogs/AutomationEditDialog.vue:570-584)".

## What changes

- The automation composer gets an "Ask AI" step with five presets: classify,
  summarise, extract, translate and a free prompt. The answer lands in a field
  of the record.
- The schema designer gets AI fields: a field whose value the AI fills from
  other fields of the same record, with the same five operations plus
  sentiment.
- Both compile, through the flow backend of `logic-automation-actions-that-run`,
  to an OpenRegister flow: an object trigger, Hermiq's `hermiq.agent-step`, and
  `openregister.object-write` back onto the record.
- The step and the field only show when OpenRegister's node catalogue lists
  `hermiq.agent-step`.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ai-llm-action | Call a language model as a step in an automation, for example to classify or summarise. | no | an AI step in the automation composer that writes its answer to the record |
| buildiq | ai-computed-column | Add AI-computed fields that translate, categorise, clean or score the sentiment of a record's text. | no | a field type the AI fills from the record's other fields |

## Existing work it builds on

- Spec `openspec/specs/automation-designer/spec.md` (archived
  `2026-07-11-automation-designer`, `2026-07-23-automation-approval-steps`,
  `2026-07-24-automation-document-action`): the composer, the compile matrix and
  provenance. This change adds one action kind.
- Change `logic-automation-actions-that-run` (this pass): the flow backend that
  turns a composer automation into an OpenRegister flow bound to the app. This
  change needs it.
- Spec `openspec/specs/schema-designer-ui/spec.md`: the field editor the AI
  field extends.
- Change `openbuild-app-binds-flows-and-agents` (open): `Application.flows`
  binds a flow by UUID; compiled AI flows are bound there.

## Sibling halves

- hermiq: runs the model. `hermiq.agent-step` exists on hermiq development
  (`lib/Flow/HermiqAgentNode.php`); it renders `{{field}}` placeholders from the
  item, can demand JSON, and stores the answer under a configured key. A failed
  turn raises into the step's `onError` policy (lines 276-305). The step names a
  Hermiq agent, so the maker needs one; provider and residency per feature are
  Hermiq's open change `a-provider-and-a-place-per-ai-feature`. Hermiq owes
  nothing new for this change.
- openregister: a changed-fields condition on `openregister.trigger-object`.
  Its config keys are `event`, `register` and `schema` only
  (`lib/Service/Flow/Nodes/TriggerObjectNode.php:169-171`), so a flow on
  `object.updated` that writes the same record starts itself again. OpenRegister
  owes a way to start only when named fields changed. Until it lands, AI steps
  and AI fields run on `object-created` and `manual` only.

## Out of scope

- Web search as an AI operation (Budibase `SEARCH_WEB`).
- Images or files as AI input.
- Running an AI step inside the browser. Every call runs server side through
  Hermiq.
- A buildiq flow node. The node is Hermiq's, the engine is OpenRegister's
  (ADR-065).
