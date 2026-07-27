## Context

`ai-copilot` already ships the entire trust-preserving execution architecture agents need: a constrained plan step (`POST /api/copilot/plan`, zero writes, allow-listed tools, predicted-manifest validation), an explicit human approval gate (`CopilotPanel`'s Approve/Discard), and an atomic execute with snapshot/rollback (`POST /api/copilot/execute`, reusing `OpenBuildToolProvider::invokeTool()` — the identical MCP handler classes, RBAC, and manifest caps). What it lacks is *persistence and identity*: every copilot interaction today is anonymous and stateless — no named agent, no narrower-than-the-full-catalogue tool scope, no run history a citizen developer or an auditor can review later. This change adds exactly that layer on top, without touching the execution engine itself.

Constraint: ADR-022 — no new execution engine; every agent action must still flow through `invokeTool()`. Constraint: the market evidence explicitly frames the wedge as a *trust gap* ("fragile, intent-opaque" loses trust at production) — the differentiator is transparency (Retool's tool-chip pattern), not raw autonomy, which is why run-history logging is a first-class requirement, not an afterthought.

## Goals / Non-Goals

**Goals:**
- An `Agent` is a named, reusable, tool-scoped configuration — not a one-off prompt.
- An agent's tool scope can only ever be a subset of what the bare copilot already exposes (8 tools) — never a path to a wider capability surface.
- Every agent run is fully inspectable after the fact: the prompt, the plan, every tool call's arguments and result, and the outcome.
- Reuse `CopilotPanel`/`useCopilot` for the chat UX rather than building a second chat component.
- `maxActionsPerRun` gives an app owner a blast-radius cap independent of the global manifest caps.

**Non-Goals:**
- Autonomous / scheduled / automation-triggered agent runs — v1 agents act only inside a human-initiated chat turn. (A follow-up change may add an `invokeAgent` automation action kind once this foundation is proven.)
- Multi-agent orchestration (agents calling agents) — out of scope.
- A new LLM provider integration — agents ride the exact same `OCP\TaskProcessing` `TextToText` path `ai-copilot` already uses; `modelTaskType` selects among task types NC already exposes, not a new provider abstraction.
- Per-tool argument-level restrictions (e.g. "this agent may only `upsertPage` on schema X") — v1 scoping is tool-kind-level only (matches `enabledTools` being a set of tool names, not a set of constrained tool+argument predicates).

## Decisions

### D1 — `CopilotService::plan()`/`execute()` gain an optional agent-scoped allow-list narrowing, not a parallel agent execution path
**Choice:** Both methods accept an optional `?Agent $agent` (resolved server-side from `agentId`, never trusted from the client beyond the id). When present, the effective tool allow-list used in the existing REQ-OBAIC-002/004 validation is `array_intersect(EIGHT_TOOL_CATALOGUE, $agent->enabledTools)` — computed server-side, re-checked at both plan and execute time exactly like the existing allow-list check, never derived from anything the client sends. `$agent->instructions` is prefixed onto the system prompt `CopilotPromptBuilder` already constructs.
**Why:** This is the only design that guarantees "an agent can never do more than the bare copilot" by construction — the intersection can only shrink the existing catalogue, never add to it, and the same allow-list-rejection code path REQ-OBAIC-002's "step outside the allow-list is rejected" scenario already tests now also rejects an agent-scoped step outside *that agent's* narrower list.
**Alternative considered:** A separate `AgentExecutionService` reimplementing plan/execute for agents. Rejected — ADR-022/031 direction is consume-not-rebuild; duplicating the snapshot/rollback/RBAC logic that already exists in `CopilotService` would be exactly the "second execution engine" this change's Non-Goals explicitly rule out, and would double the surface that must stay behaviourally identical.

### D2 — `AgentRun` is written by a logger wrapping the existing plan/execute calls, not embedded inside `CopilotService`'s core logic
**Choice:** A new `AgentRunLogger` decorator/listener persists one `AgentRun` record per plan+execute pair issued with an `agentId`: the original prompt, the returned plan (`summary`+`steps[]`), each step's tool call with its arguments and the handler result (success payload or error envelope), and the final outcome (`applied`|`rolled-back`|`discarded`|`plan-rejected`). A discarded proposal (REQ-OBAIC-007's discard scenario) still logs a run with outcome `discarded` and an empty tool-call list — discarding is itself part of the transparent history.
**Why:** Keeping the logger a thin wrapper (not woven into `CopilotService`'s internals) means the bare (non-agent) copilot path is untouched — zero risk of regressing REQ-OBAIC-001–007's existing behaviour for the wizard/panel entry points that never pass an `agentId`.
**Alternative considered:** Log runs client-side (the Vue panel posts a summary after the fact). Rejected — a client-side log is not trustworthy as an audit trail (a network failure or a bug in the client silently loses the record) and cannot capture the server-computed narrowed allow-list or the handler's raw result envelopes.

### D3 — `CopilotPanel.vue` gains optional agent-scoping props, remaining fully backwards-compatible
**Choice:** `CopilotPanel` gains optional props `agentId`, `instructions` (rendered as a small "acting as: `<agent name>`" header when present), and the effective `enabledTools` (used only for a client-side *hint* — e.g. greying out an unavailable action — never as the security boundary, which is D1's server-side check). Omitted entirely, the panel behaves exactly as `ai-copilot`'s existing bare copilot does today.
**Why:** Reusing one component for both surfaces (bare copilot in the page designer, agent chat in the Agents page) means UX fixes/improvements land once; the props are additive and optional so REQ-OBAIC-007's existing scenarios need no behavioural change.
**Alternative considered:** A separate `AgentChatPanel.vue` forked from `CopilotPanel`. Rejected — guarantees UX drift between the two surfaces over time; the proposal explicitly asks to reuse "CopilotPanel plumbing/useCopilot patterns."

### D4 — `maxActionsPerRun` is enforced server-side at plan-acceptance time, alongside the existing manifest caps
**Choice:** When an agent-scoped plan's `steps[]` length exceeds `agent->maxActionsPerRun`, `POST /api/copilot/plan` returns the same 422-with-named-violated-cap shape REQ-OBAIC-003's manifest-cap rejection already uses, with the cap named `max_actions_per_run`.
**Why:** Matches an existing, tested rejection shape rather than inventing a new error contract; gives an app owner an agent-specific blast-radius control independent of (and typically tighter than) the global 100-page/50-widget manifest caps.
**Alternative considered:** Truncate the plan to the cap and execute the first N steps. Rejected — silently executing a different plan than what was reviewed violates the explicit-approval guarantee (REQ-OBAIC-007's "no silent mutations") the whole architecture is built on; reject-and-let-the-user-narrow-the-ask is consistent with how an over-cap manifest plan is already handled.

### Declarative-vs-imperative decision (ADR-031)
The `Agent` and `AgentRun` schemas are declarative OR properties. The allow-list intersection, prompt-instruction prefixing, and run logging are imperative — justified under ADR-031's external-integration exception, the same justification `ai-copilot`'s `CopilotService` itself already relies on for its LLM-call/plan/execute orchestration (this change extends that existing precedent, it does not establish a new one).

## Risks / Trade-offs

- **An agent's `enabledTools` could be misconfigured to include a destructive-sounding tool the owner didn't intend** → the set is always a strict subset of the existing 8-tool catalogue, which itself contains no delete-app/delete-object tool (verified against `OpenBuildToolProvider::getToolDescriptors()`); the worst case is an agent that can create/upsert more than intended, always reviewable and discardable before execute.
- **Run-history growth (one `AgentRun` per turn, indefinitely)** → matches the existing pattern of OR objects accumulating over time (e.g. `ApplicationVersion` snapshots); a retention/archival policy is a follow-up if volume becomes a real operational concern, not a v1 blocker.
- **`instructions` prefixed onto the system prompt could be used to try to jailbreak past the tool allow-list** → irrelevant to safety by construction (D1): the allow-list check is a server-side structural validation of the *returned plan's* tool names against the intersection, not a trust boundary the prompt text can talk its way around — an LLM output naming a disallowed tool is rejected exactly like today's REQ-OBAIC-002 "step outside the allow-list" scenario, regardless of what the instructions said.
- **Two chat surfaces (bare copilot panel, agent chat) diverging in UX over time** → mitigated by D3's single shared component.

## Migration Plan

1. Add the `Agent` and `AgentRun` schemas to `lib/Settings/openbuild_register.json` (additive).
2. Extend `CopilotService::plan()`/`execute()` with the optional `agentId`-resolved allow-list narrowing + instruction prefixing (D1); extend `CopilotController` to accept and pass through `agentId`.
3. Implement `AgentRunLogger` (D2), wired around the existing plan/execute calls when `agentId` is present.
4. Extend `CopilotPanel.vue` with the optional agent-scoping props (D3).
5. Ship `AgentsPage.vue` (CRUD) + `AgentEditDialog.vue` + the run-history list view.
6. Enforce `maxActionsPerRun` in the plan-acceptance path (D4).
7. No migration for existing copilot usage — every change is additive/optional; the wizard and bare builder panel entry points are unaffected (no `agentId` sent).

**Rollback:** Remove the Agents page and the agent-scoping props from `CopilotPanel`; stop resolving `agentId` server-side (requests without it behave exactly as before). Existing `Agent`/`AgentRun` OR objects become inert. `ai-copilot`'s bare path is untouched throughout, so rollback carries zero regression risk to the existing copilot feature.

## Open Questions

- Should `AgentRun` history be visible only to the Application's owners/editors, or also to viewers (read-only)? Lean: owners/editors only, matching the existing `openbuild-rbac` posture for anything execute-adjacent.
- Does `modelTaskType` need to be agent-configurable in v1, or should it default to whatever `ai-copilot`'s bare path already uses? Lean: default-inherit in v1 (no new provider-selection UI), with the schema field present for a v1.1 follow-up once multiple task types are commonly configured on real instances.

## Seed Data

Example `Agent` object:

```json
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "applicationSlug": "vergunning-app",
  "name": "Page builder assistant",
  "instructions": "You help the vergunning-app team add and adjust form pages. Never touch existing schemas without being asked explicitly.",
  "modelTaskType": "TextToText",
  "enabledTools": ["openbuild.upsertPage", "openbuild.addWidget", "openbuild.getAppManifest"],
  "maxActionsPerRun": 5
}
```

Example `AgentRun` record after one chat turn:

```json
{
  "uuid": "00000000-0000-0000-0000-000000000000",
  "agentId": "00000000-0000-0000-0000-000000000000",
  "prompt": "Add a contact-details step to the intake form",
  "plan": {
    "summary": "Add a contact-details form step with three fields",
    "steps": [
      { "tool": "openbuild.upsertPage", "arguments": { "route": "/intake/contact" } },
      { "tool": "openbuild.addWidget", "arguments": { "widgetType": "form-field", "field": "email" } }
    ]
  },
  "toolCalls": [
    { "tool": "openbuild.upsertPage", "arguments": { "route": "/intake/contact" }, "result": { "isError": false } },
    { "tool": "openbuild.addWidget", "arguments": { "widgetType": "form-field", "field": "email" }, "result": { "isError": false } }
  ],
  "outcome": "applied",
  "createdBy": "YOUR_TOKEN_HERE"
}
```
