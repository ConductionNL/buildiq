## 1. Schema

- [ ] 1.1 Add the `Agent` schema to `lib/Settings/openbuild_register.json` (name, instructions, modelTaskType, enabledTools, maxActionsPerRun, applicationSlug); validate `enabledTools` against the eight-tool catalogue at save time.
- [ ] 1.2 Add the `AgentRun` schema (agentId, prompt, plan, toolCalls[], outcome, timestamps).

## 2. CopilotService agent-scoping

- [ ] 2.1 `plan()`/`execute()` accept optional `agentId`; resolve server-side to `Agent`; compute effective allow-list as `array_intersect(EIGHT_TOOL_CATALOGUE, enabledTools)`; prefix `instructions` onto the system prompt.
  - acceptance: an agent-scoped step outside that agent's list is rejected even when it's in the base catalogue
- [ ] 2.2 Enforce `maxActionsPerRun` at plan-acceptance time, returning 422 naming `max_actions_per_run` when exceeded.
- [ ] 2.3 `CopilotController` accepts and passes through `agentId` on both endpoints.

## 3. Run logging

- [ ] 3.1 `AgentRunLogger` persists one `AgentRun` per plan+execute/discard interaction issued with an `agentId` — prompt, plan, per-step tool call (arguments + result), outcome (applied/rolled-back/discarded/plan-rejected).
- [ ] 3.2 Bare (non-agent) copilot calls are verified unaffected — no `AgentRun` written, no behavioural change.

## 4. Frontend

- [ ] 4.1 `src/views/AgentsPage.vue` — CRUD list of Agents for the current Application.
- [ ] 4.2 `src/dialogs/AgentEditDialog.vue` — name/instructions/modelTaskType/enabledTools (NcSelect multi with `inputLabel`)/maxActionsPerRun.
- [ ] 4.3 `CopilotPanel.vue` gains optional `agentId`/`instructions`/`enabledTools` props (backwards-compatible, omitted ⇒ bare copilot behaviour unchanged); "acting as: `<agent name>`" header when present.
- [ ] 4.4 Run-history list view per agent, rendering each tool call's arguments and result.

## 5. Tests

- [ ] 5.1 PHPUnit: allow-list intersection (agent can't exceed base catalogue), maxActionsPerRun rejection, AgentRunLogger captures applied/rolled-back/discarded/plan-rejected outcomes, bare-copilot-path regression (no agentId ⇒ identical to pre-change behaviour).
- [ ] 5.2 Playwright: create an agent scoped to two tools, chat with it, approve a proposal, confirm the run appears in run-history with tool-call detail; confirm a disallowed tool request is rejected.

## 6. Verify

- [ ] 6.1 `composer check:strict` and hydra mechanical gates (spec-coverage, route-auth, semantic-auth) green on the diff.
- [ ] 6.2 `openspec validate "agent-workspace"` passes and `openspec status` shows all artifacts complete before archiving.
