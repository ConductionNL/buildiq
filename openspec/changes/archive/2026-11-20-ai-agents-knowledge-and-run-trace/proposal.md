---
kind: code
---

# Proposal: ai-agents-knowledge-and-run-trace

## Why

Three rows of the buildiq matrix are about the same Agents page.

**buildiq matrix, row `ai-agent-knowledge-base`**, "Give an AI agent a
knowledge base of documents to answer from.", rated `no`, `built.state` `none`.
A changelog demand row points at Budibase
(https://github.com/Budibase/budibase/releases/tag/v3.42.0). Two competitors
rate it `yes`:

- Budibase: "knowledge/index.svelte:372 KnowledgeAddControls (file upload and
  SharePoint sites, knowledge/new/SharePointSiteStepModal.svelte) and :422
  KnowledgeTable; retrieval uses Gemini File Search and needs GEMINI_API_KEY ...
  Reached on: agent > Knowledge" (source read at v3.46.0).
- Mendix: "Mendix Cloud GenAI Resource Packs include knowledge bases that store
  your documents for a text generation resource to answer from"
  (https://docs.mendix.com/agents/mx-cloud-genai/).

Today, from `built.evidence`: "src/dialogs/AgentEditDialog.vue:6-71 holds name,
instructions, modelTaskType and enabledTools only; grep -iE
'knowledge|rag|embedding' in AgentEditDialog.vue: no document knowledge base".

**buildiq matrix, row `ai-named-agents`**, "Set up named AI agents with a fixed
set of tools and a purpose.", rated `partial`, `built.state` `built`. Two
competitors rate it `yes`:

- NocoBase: "EmployeesPage.tsx:696 AI employee editor with role setting (line
  497) and selectable skills and tools; mounted at ... plugin.tsx:80 as
  Settings, AI, Employees" (source read at v2.2.18).
- Budibase: "config.svelte:111-139 pick the agent's model and :149
  OperationsSection; each operation gets instructions and tools ... agents
  created via AgentModal (home/index.svelte:6)" (source read at v3.46.0).

Today, from `built.evidence`: "Page registered at /agents
(src/manifest.d/70-agent-workspace.json) with menu: [], and grep over src finds
no router push, link or menu entry to it." `reachedOn`: "/agents, only by typing
the URL (no menu entry or in-app link)". The missing half: a way in from the
app.

**buildiq matrix, row `ai-agent-run-log`**, "See what an AI agent did in each
run.", rated `partial`, `built.state` `built`. Two competitors rate it `yes`:

- Budibase: "logs.svelte:260 LogsSessionList and :273 LogsSessionDetail with
  per-step LogsSessionStep.svelte; free licence keeps 1 day" (source read at
  v3.46.0).
- Mendix: "GenAI Commons stores chat completion traces in the app database for
  traceability, including tool usage, readable with the TraceMonitoring role,
  plus token usage data"
  (https://docs.mendix.com/agents/agents-kit-2/reference-guide/commons/).

Today, from `built.evidence`: "lib/Service/AgentRunLogger.php persists an
AgentRun on agent-scoped execute/discard ... GET /api/agents/{uuid}/runs
(routes.php:330) -> lib/Controller/AgentsController.php runs(), owners/editors
only; src/components/agents/AgentRunHistory.vue:139 renders it inside
AgentsPage." Tool calls are already kept and shown:
`AgentRunLogger::log()` stores `toolCalls` (`lib/Service/AgentRunLogger.php:87-96`)
and `AgentRunHistory.vue:49-73` renders each call's tool, arguments and result.
What a run does not keep is the model's part: each call to the language model,
the repair round-trip, what the validator refused, how long it took, and which
knowledge went into the prompt. That is the missing half this change builds.

## What changes

- An agent gets a knowledge list: files attached to the agent, read into the
  plan prompt under a size budget.
- The app detail page gets an "Agents" action, and the Agents page opens on that
  app.
- A run keeps its model steps: each text task with its attempt number,
  duration, status, raw answer and the validator messages that caused a repair,
  plus the knowledge files used.
- The run history shows those steps above the tool calls.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | ai-agent-knowledge-base | Give an AI agent a knowledge base of documents to answer from. | no | documents an agent answers from |
| buildiq | ai-named-agents | Set up named AI agents with a fixed set of tools and a purpose. | partial | a way to reach the Agents page from the app |
| buildiq | ai-agent-run-log | See what an AI agent did in each run. | partial | the model steps of a run: calls, repair, refusals, duration and knowledge used |

## Existing work it builds on

- Spec `openspec/specs/agent-workspace/spec.md` (archived
  `2026-07-24-agent-workspace`): the `Agent` and `AgentRun` schemas, the Agents
  page, the run-history list with tool calls, and owner or editor access to run
  history. This change adds requirements and rewrites none.
- Spec `openspec/specs/ai-copilot/spec.md`: the plan prompt, the one repair
  round-trip and the text task the model steps come from.
- Change `ai-copilot-documents-and-code-help` (this pass): the file text read
  and the `CopilotTextTask` seam this change records steps from.
- Archived `2026-08-19-bundle-agents-slug-not-numeric-id` and spec
  `agent-export-hermiq-register`: agents are exported by slug; knowledge files
  travel with the agent object.

## Sibling halves

- openregister: ranked retrieval over large knowledge. Hermiq's open change
  `vector-rag` names the public vector search facade as OpenRegister's to build
  ("the public vector-search facade Hermiq needs OR to publish"); OpenRegister
  development has no such facade (`lib/Service/Mcp/ToolRegistryFacade.php` is the
  only facade). Until it lands, buildiq includes whole files under a budget and
  refuses knowledge over it. OpenRegister also owes the file text read named in
  `ai-copilot-documents-and-code-help`, for PDF and Word knowledge files.
- hermiq: the model call runs as a `core:text2text` task that Hermiq provides
  (`lib/TaskProcessing/Text2TextProvider.php`, hermiq development). Hermiq's own
  agents keep their own context model (`ContextAssembler`) and run traces
  (`RunTraceCollector`); buildiq's builder agents do not move there in this
  change. Hermiq owes nothing new here.

## Out of scope

- Token counts. TaskProcessing reports none, so a run cannot record them.
- SharePoint or other outside knowledge sources.
- A run log across all agents; history stays per agent.
- Letting an automation run an agent. The existing requirement "Agents run only
  from their own chat surface in v1" stands.
