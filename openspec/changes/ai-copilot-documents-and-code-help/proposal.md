---
kind: code
---

# Proposal: ai-copilot-documents-and-code-help

## Why

Two rows in the buildiq matrix ask for the same copilot to do more than it does
today.

**buildiq matrix, row `ai-code-assist`**, "Get AI help writing expressions,
queries or code in the builder.", rated `no`, `built.state` `none`. Five
competitors rate it `yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-ai/src/client-v2/ai-employees/ai-coding/AICodingButton.tsx:39
  AI coding button tied to the Nathan employee, injected into every code editor
  as an extra ... Reached on: any JS editor, AI coding button" (source read at
  v2.2.18).
- Budibase: "packages/builder/src/components/common/CodeEditor/AIGen.svelte:42
  prompt calls API.generateJs ... with Accept or Reject at :98-102, mounted in
  every code editor (CodeEditor.svelte:72); cron expression helper at ai.ts:31"
  (source read at v3.46.0).
- Appsmith: "app/client/src/components/editorComponents/CodeEditor/index.tsx:1746
  AskAIButton on code editors and ... GlobalAISidePanel with Explain, Fix
  Errors, Refactor" (source read at v2.4.2).
- Mendix: "Maia gives guidance, recommendations and assistance for development
  tasks" (https://docs.mendix.com/refguide/mendix-ai-assistance/).
- Microsoft Power Apps: "Get formula suggestions (preview) for Power Fx
  formulas" (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/formula-columns).

What buildiq does today, from `built.evidence`: "The copilot proposes manifest
changes (pages, schemas, menus, widgets) only (BuildiqToolProvider.php:84-258);
no AI help in the FEEL/condition editors (src/dialogs/DecisionTableEditor.vue,
ConditionActionRuleEditor.vue, src/utils/feelCell.js), the automation
field-mapping or payload JSON boxes (AutomationEditDialog.vue:205-246), or the
raw manifest editor".

**buildiq matrix, row `ai-spec-to-app`**, "Upload a requirements document and
get user stories, a data model and pages generated from it.", rated `partial`,
`built.state` `built`. A roadmap demand row points at Mendix
(https://www.mendix.com/whats-new/). Two competitors rate it `yes`:

- Mendix: "Maia Plan takes project context with attached documents, sketches and
  images and generates epics and stories; Maia Make generates app artifacts from
  user stories and Start with Maia builds the domain model and pages"
  (https://docs.mendix.com/developerportal/maia-plan/create-app/).
- Microsoft Power Apps: "describe the business use case and add images such as
  process flows; Plans generates user roles and stories, a data model, Dataverse
  tables, canvas and model-driven apps and flows"
  (https://learn.microsoft.com/en-us/power-apps/maker/plan-designer/plan-designer).

What buildiq does today, from `built.evidence`: "'Generate with AI' takes a
typed description only: src/dialogs/CopilotGenerateDialog.vue:30 NcTextArea (no
file upload in the dialog) -> POST /apps/buildiq/api/copilot/plan
(appinfo/routes.php:320) -> lib/Controller/CopilotController.php:122; it
proposes schemas and pages, not user stories, and a document's text can only be
pasted". The typed brief is also capped at 2000 characters
(`lib/Service/CopilotService.php:1023-1032`), so a real requirements document
does not fit even when pasted. The missing half this change builds: read a
document the maker picks from their Files, and propose user stories that the
schemas and pages trace back to.

## What changes

- A small "Ask AI" button beside four expression inputs: the condition of a
  condition-action rule, a row of a decision table, the field mapping of a
  record step and the payload template of a webhook step.
- The button writes a proposal from a plain request, or explains the current
  value. The maker accepts or rejects it. Nothing saves until the editor saves.
- A new `POST /api/copilot/assist` endpoint validates every proposal before it
  returns: FEEL through `FeelParser`, a decision cell through the cell grammar,
  a mapping against the target schema's properties.
- "Generate with AI" gains "Add a document": the maker picks a file from their
  Files, and the plan reads it next to the typed brief.
- The plan gains user stories. Each story names the schemas and pages that serve
  it, the review shows the stories first, and the created app keeps them.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ai-code-assist | Get AI help writing expressions, queries or code in the builder. | no | AI help inside the FEEL condition, decision table, field mapping and payload template editors |
| buildiq | ai-spec-to-app | Upload a requirements document and get user stories, a data model and pages generated from it. | partial | reading a document from Files, and user stories that the generated schemas and pages trace back to |

## Existing work it builds on

- Spec `openspec/specs/ai-copilot/spec.md` (from the archived
  `2026-07-11-ai-copilot-prompt-to-app`): availability probe and graceful
  degrade, a validated plan with one repair round-trip, approve-before-apply,
  RBAC like the wizard, and the wizard's prompt-to-app path. This change adds
  requirements to it and changes none.
- Archived `2026-07-24-agent-workspace`: agent-scoped copilot runs. The assist
  endpoint is not agent-scoped and writes no `AgentRun`.
- Spec `openspec/specs/business-rules-engine/spec.md` and
  `openspec/specs/automation-designer/spec.md`: the editors the button sits in.
- Open change `buildiq-consumes-shared-dmn`: decision tables now evaluate
  through OpenRegister's shared evaluator; the cell grammar the assist checks
  against is the one `DecisionTableEvaluator::sharedCell()` translates.

## Sibling halves

- hermiq: the language model call. Buildiq sends a `core:text2text` task through
  Nextcloud TaskProcessing, the way the copilot already does
  (`lib/Service/CopilotService.php:1398`). Hermiq answers it through its
  provider `lib/TaskProcessing/Text2TextProvider.php` (hermiq development). Hermiq
  owes nothing new for the one-shot call. Pinning the assist to one provider per
  feature is Hermiq's open change `a-provider-and-a-place-per-ai-feature`; until
  that lands, the assist uses the instance's text2text provider.
- openregister: reading the text of a PDF, Word or OpenDocument file the caller
  may read. The extractors exist (`lib/Service/TextExtraction/WordExtractor.php:61`,
  `lib/Service/TextExtraction/PdfExtractor.php:56`), but the read route
  `GET /api/files/{fileId}/text` is a deprecated stub that always answers 404
  (`lib/Controller/FileTextController.php:147-160`). OpenRegister owes a
  published read that returns a file's text as the requesting user. Until it
  exists, buildiq accepts plain text and Markdown files only.

## Out of scope

- AI help in the raw manifest JSON editor. A manifest edit is the copilot panel's
  job, which already exists.
- Images, sketches and process diagrams as input. Mendix and Power Apps accept
  them; this change reads text only.
- Running or testing a proposed expression against records. The rule set test
  sandbox already does that.
- Generating flows. Flows are OpenRegister's (ADR-065).
