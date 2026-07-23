---
kind: code
---

## Why

"AI agents" became table stakes across every competitor in one research cycle (Budibase Agents Beta, Retool Agents with tool-chip run transparency, NocoBase "AI Employees", Airtable Omni) while OpenBuild still only offers one-shot prompt-to-app (`ai-copilot`) with no persistent, named, scoped agent concept. The trust gap the market evidence surfaces — "fragile, intent-opaque" prompt-to-app loses trust at production — is exactly what `ai-copilot`'s plan/approve/execute/rollback architecture already solves for one-shot use; this change persists that same trustworthy pattern as a **named, tool-scoped, transparently-logged** agent, reusing `CopilotService`'s plan/execute contract rather than building a second execution engine.

## What Changes

- **`Agent` entity** (per Application, OR-backed config register object): `name`, `instructions` (free text, prefixed onto the copilot system prompt), `modelTaskType` (which `OCP\TaskProcessing` task type it targets — model-agnostic, matching `ai-copilot`'s existing provider-agnostic design), `enabledTools` (a subset of the 8 existing `OpenBuildToolProvider` tool descriptors — never a superset; an agent can only ever be a **narrower** version of what the copilot can already do), `maxActionsPerRun` (integer cap on steps per execute call).
- **Agents page** (CRUD list + create/edit dialog) plus a **chat panel per agent**, reusing `CopilotPanel.vue`/`useCopilot.js` plumbing — the same message→proposal→approve/discard UX, scoped to one agent's instructions and tool whitelist instead of the bare copilot.
- **Server-side tool-whitelist enforcement**: `CopilotService::plan()`/`execute()` gain an optional agent-scoped allow-list narrowing — when a request carries an `agentId`, the effective allow-list is `enabledTools ∩ the existing 8-tool catalogue` (an agent can never unlock a tool the bare copilot doesn't already expose), enforced server-side exactly like the existing allow-list check (REQ-OBAIC-002/004) — never a UI-only restriction a crafted request could bypass.
- **Transparent run log**: every agent run persists an `AgentRun` record (prompt, the returned plan, each tool call with its arguments and result, final outcome) viewable in a run-history list per agent — the Retool tool-chip transparency pattern, addressing the trust-gap evidence directly.
- **`maxActionsPerRun` enforced alongside the existing manifest caps**: a plan whose step count exceeds the agent's cap is rejected the same way an over-cap manifest is rejected today (REQ-OBAIC-003's pattern) — named, explicit, server-side.
- **No autonomous triggers in v1 (explicit non-goal)**: an agent runs only when a human sends it a message in its chat surface. Automation-triggered agent runs (an automation action kind that invokes an agent) are explicitly deferred to a follow-up change — not attempted here.

## Capabilities

### New Capabilities
- `agent-workspace`: the `Agent` entity, the Agents page (CRUD + chat panel reusing `CopilotPanel`), the `AgentRun` transparent run log + run-history list, and the `maxActionsPerRun` cap.

### Modified Capabilities
- `ai-copilot`: `POST /api/copilot/plan` and `POST /api/copilot/execute` gain an optional `agentId` that narrows the effective tool allow-list to that agent's `enabledTools` (intersected with, never exceeding, the existing 8-tool catalogue) and prefixes the agent's `instructions` onto the system prompt. (Delta spec at `specs/ai-copilot/spec.md`.)

## Impact

- **Schema:** `lib/Settings/openbuild_register.json` — new `Agent` schema (name, instructions, modelTaskType, enabledTools, maxActionsPerRun, applicationSlug) and new `AgentRun` schema (agentId, prompt, plan, toolCalls[], outcome, timestamps), both in the `openbuild` register namespace.
- **Backend:** `CopilotService::plan()`/`execute()` gain the optional agent-scoped narrowing parameter; a new `AgentRunLogger` persists the `AgentRun` record around every plan/execute pair issued with an `agentId`; no new execution engine — every write still flows through `OpenBuildToolProvider::invokeTool()`.
- **Frontend:** new `src/views/AgentsPage.vue` (CRUD list), new `src/dialogs/AgentEditDialog.vue`, `CopilotPanel.vue` gains an optional `agentId`/`instructions`/`enabledTools` prop set (backwards-compatible — omitted, it behaves exactly as the bare copilot does today), new run-history list view per agent.
- **RBAC:** unchanged from `ai-copilot` — agent plan/execute requests are RBAC-guarded exactly like bare copilot requests (REQ-OBAIC-005), scoped to the Application the agent belongs to.
- **Non-goal:** autonomous/automation-triggered agent runs — deferred; v1 agents run only from their own chat surface.
