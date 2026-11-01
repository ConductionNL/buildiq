# Design: ai-agents-knowledge-and-run-trace

Read at buildiq development `d21e42f`.

## Where it sits

- Schemas: `lib/Settings/register.d/70-agent-workspace.json`. `Agent` (slug
  `buildAgent`, line 5, version `1.0.0`) holds `applicationSlug`, `name`,
  `instructions`, `modelTaskType`, `enabledTools` (line 60) and
  `maxActionsPerRun` (line 79). `AgentRun` (slug `agent-run`, line 89) holds
  `prompt`, `plan`, `toolCalls` (line 142) and `outcome` (line 167).
- Writing a run: `lib/Service/AgentRunLogger.php`, `log()` at line 87, saves
  `{agentId, applicationSlug, prompt, plan, toolCalls, outcome, createdAt}`
  (lines 90-98) through `ObjectService::saveObject()`. `CopilotService` calls it
  on a rejected plan (line 263), a discard (line 372) and on execute (lines 899,
  958, 966).
- The model calls: `CopilotService::requestPlanFromLlm()` makes the first
  attempt and at most one repair (lines 1296-1323) through `runPlanAttempt()`.
  The prompt comes from `CopilotPromptBuilder::build()`, which already takes the
  agent's `instructionsPrefix` (lines 73-84).
- Reading runs: `GET /api/agents/{uuid}/runs` (`appinfo/routes.php:330`),
  `AgentsController::runs()` (`lib/Controller/AgentsController.php:131`),
  owners and editors only. `src/components/agents/AgentRunHistory.vue` renders
  the prompt, outcome and every tool call (lines 49-73).
- The page: `src/views/AgentsPage.vue` picks an app with a select and lists that
  app's agents (`fetchAgents()`, line 221). It reads no route query. The page is
  declared in `src/manifest.d/70-agent-workspace.json` with `menu: []`.
- The editor: `src/dialogs/AgentEditDialog.vue`, fields at lines 27-75.
- The way in, by precedent: `src/components/ApplicationDetailActions.vue` has an
  "Automations" action (lines 303-307) that pushes the `Automations` route with
  `?app=` and `?version=` (`openAutomations()`, lines 842-855).
  `src/views/AutomationsPage.vue` reads that query (lines 258-272).
- Files on an object: `src/dialogs/IconUploadSection.vue:310` already posts to
  OpenRegister's `/api/objects/{register}/{schema}/{uuid}/files`.

## D1. Knowledge is files on the agent

A knowledge file is a file attached to the `Agent` object through OpenRegister's
object files. It inherits the object's access, so every owner or editor who may
run the agent may read its knowledge, and nobody else. `Agent` gains
`knowledge[]` (`{fileName, addedBy, addedAt}`) as the ordered list the prompt
uses, and a version bump to `1.1.0`. `AgentEditDialog.vue` gains a "Knowledge"
section with Add and Remove.

## D2. Whole files under a budget, until retrieval exists

At plan time for an agent, `CopilotService` reads each knowledge file's text and
adds a "Knowledge" section to the prompt after the instructions. Plain text and
Markdown are read directly; PDF and Word wait for OpenRegister's text read. The
budget is 40,000 characters across all files. Adding a file that would pass the
budget is refused in the dialog with the remaining room named. Ranked retrieval
over a larger set needs OpenRegister's vector facade (see proposal). When it
lands, the budget becomes the retrieval size and the refusal goes away.

## D3. The model steps are collected per turn

The text task seam (`CopilotTextTask`, from `ai-copilot-documents-and-code-help`)
returns a step record for every call: `{attempt, kind (plan or repair),
startedAt, durationMs, status, taskType, promptChars, output, refusal}`.
`output` is the raw answer, capped at 20,000 characters. `refusal` is the
parse or validator message that led to the repair. The plan step writes nothing
today, so the steps wait in a distributed cache (`ICacheFactory`, as
`lib/Service/RuleSetCacheManager.php:69` does) under a `traceId` that the plan
response returns, for one hour.

## D4. The run keeps the steps

`AgentRun` gains `modelSteps[]`, `knowledgeUsed[]` (file names) and
`durationMs`, version `1.1.0`. `AgentRunLogger::log()` takes the `traceId`,
reads the steps from the cache and stores them with the run. The cache key
includes the agent id and the user id, so one user's trace never lands on
another user's run. A missing or expired trace stores
`modelSteps: []` with `traceMissing: true`, never a guess. A rejected plan
(line 263) has its steps in hand and stores them directly.

## D5. The run history shows the steps first

`AgentRunHistory.vue` lists the model steps above the tool calls: attempt,
duration, status, the refusal that caused a repair, and the raw answer behind a
disclosure. It lists the knowledge files used. Run history stays owners and
editors only, through the existing route.

## D6. A way in from the app

`ApplicationDetailActions.vue` gains an "Agents" action next to "Automations",
for the same roles, pushing the `Agents` route with `?app=`. `AgentsPage.vue`
reads `?app=` on load and preselects that app, the way `AutomationsPage.vue`
reads it. The page stays off the main menu, as automations are: an agent belongs
to an app.

## Risks

- A raw answer can hold text from a knowledge file. It is stored on the run,
  which only the app's owners and editors can read, the same people who can read
  the knowledge file.
- The cache can be flushed between plan and execute. D4 records that honestly
  instead of dropping the run.

## What it does not do

- It does not count tokens.
- It does not move buildiq agents into Hermiq's engine.
- It adds no knowledge source other than attached files.
