# agent-workspace Specification

**OpenSpec changes**: [agent-workspace](../../changes/archive/2026-07-24-agent-workspace/) _(archived 2026-07-24)_

**Status**: done

## Purpose

Named, tool-scoped AI agents layered on top of the existing `ai-copilot`
plan/execute engine (ADR-022 consume-not-rebuild). An `Agent` is a persistent,
reusable configuration — instructions, an explicit subset of the eight
`OpenBuildToolProvider` tools it may invoke, and a per-run action cap — never
a wider capability surface than the bare copilot. Every agent run is
transparently logged (prompt, plan, every tool call's arguments and result,
outcome), addressing the market-wide "trust gap" evidence (Budibase Agents,
Retool Agents' tool-chip transparency, NocoBase AI Employees) with the same
plan/approve/execute/rollback architecture `ai-copilot` already ships.

## Requirements

### Requirement: Agent entity declares a named, tool-scoped configuration

The system SHALL declare an `Agent` schema in the `openbuild` register
namespace with properties `uuid`, `applicationSlug`, `name`, `instructions`
(free text), `modelTaskType`, `enabledTools` (array, each value one of the
eight `OpenBuildToolProvider` tool names), and `maxActionsPerRun` (positive
integer). `enabledTools` SHALL be validated against the eight-tool catalogue
at save time — an unknown tool name SHALL be rejected.

@e2e exclude declarative OR schema-level enum validation
(`lib/Settings/register.d/70-agent-workspace.json`) — save-time rejection is
OpenRegister's own JSON-Schema validator, not app UI logic; no Playwright
surface exercises it distinctly from any other schema-validated save.

#### Scenario: Agent with a valid tool subset saves

- **WHEN** an editor creates an `Agent` with
  `enabledTools: ["openbuild.upsertPage", "openbuild.addWidget"]`
- **THEN** the `Agent` record is created

#### Scenario: Unknown tool name is rejected

- **WHEN** an editor attempts to create an `Agent` with
  `enabledTools: ["openbuild.deleteApp"]` (not in the catalogue)
- **THEN** the save is rejected with a validation error naming the unknown
  tool

### Requirement: Agents page provides CRUD and a per-agent chat panel

The system SHALL provide `AgentsPage.vue` listing every `Agent` belonging to
the current Application, with create/edit via `AgentEditDialog.vue`
(`src/dialogs/`), and a chat panel per agent reusing `CopilotPanel.vue` with
that agent's `agentId`, `instructions`, and `enabledTools` passed as props.
Sending a message in an agent's chat panel SHALL call
`POST /api/copilot/plan` with that agent's `agentId`; approving a proposal
SHALL call `POST /api/copilot/execute` with the same `agentId`.

#### Scenario: Agent chat plans and executes scoped to that agent

- **WHEN** a developer opens an agent's chat panel and asks it to add a page
- **THEN** the plan request carries that agent's `agentId`
- **AND** approving the resulting proposal executes with the same `agentId`

### Requirement: Every agent run is transparently logged and reviewable

The system SHALL persist an `AgentRun` record for every plan+execute (or
plan+discard) interaction issued with an `agentId`: `agentId`, `prompt`, the
returned `plan`, an ordered `toolCalls[]` (each `{tool, arguments, result}`),
and `outcome` (`applied`|`rolled-back`|`discarded`|`plan-rejected`). The
Agents page SHALL provide a run-history list per agent rendering these
records, including each tool call's arguments and result (the Retool
tool-chip transparency pattern) — never a summarised or redacted view of
what actually ran. Run-history read access SHALL be restricted server-side
to owners/editors of the agent's parent Application, matching the existing
`openbuild-rbac` posture for anything execute-adjacent.

#### Scenario: A discarded proposal is still logged

@e2e exclude backend audit-log contract — verified by PHPUnit
(`CopilotServiceTest::testDiscardLogsDiscardedOutcome`,
`AgentRunLoggerTest`); the discard UI flow itself (no execute request sent)
is the same panel path REQ-OBAIC-007's "Discarding a proposal changes
nothing" e2e scenario already exercises.

- **WHEN** a developer discards a proposal in an agent's chat panel
- **THEN** an `AgentRun` record exists with `outcome: "discarded"` and an
  empty `toolCalls[]`

#### Scenario: Run history shows every tool call's arguments and result

- **WHEN** a developer opens the run-history list for an agent with a
  previously applied run
- **THEN** each tool call in that run renders its `tool` name, its
  `arguments`, and its `result`

#### Scenario: A non-owner/non-editor cannot read an agent's run history

@e2e exclude authorization matrix — role-denied 403 is verified by PHPUnit
(`AgentsControllerTest::testRunsReturns403ForViewerOnlyCaller`) with role
fixtures Playwright's single-admin global-setup cannot represent, matching
REQ-OBAIC-005's identical exclusion rationale for the bare copilot's
viewer-denial scenario.

- **WHEN** a caller who holds neither an owners nor an editors role on the
  agent's parent Application requests `GET /api/agents/{uuid}/runs`
- **THEN** the endpoint returns 403 and no run row is returned

### Requirement: An agent's tool scope can never exceed the base copilot catalogue

`enabledTools` SHALL always be validated, both at `Agent` save time and at
every plan/execute request time, as a subset of the eight-tool
`OpenBuildToolProvider` catalogue. No configuration path, prompt content, or
client request SHALL be able to grant an agent a tool outside that
catalogue.

@e2e exclude backend allow-list-narrowing contract — verified by PHPUnit
with a mocked TaskProcessing manager
(`CopilotServiceTest::testPlanRejectsStepOutsideAgentsNarrowerAllowList`,
`testExecuteRejectsStepOutsideAgentsAllowList`), matching REQ-OBAIC-002's
identical nondeterministic-LLM exclusion rationale.

#### Scenario: Agent-scoped requests never exceed the base allow-list

- **GIVEN** any `Agent` configuration
- **WHEN** that agent's plan/execute requests are validated server-side
- **THEN** the effective allow-list is always a subset of the eight-tool
  catalogue, regardless of the agent's `instructions` content

### Requirement: Agents run only from their own chat surface in v1

The system SHALL NOT provide any mechanism, in this change, for an
automation, schedule, webhook, or other non-chat trigger to invoke an
`Agent`'s plan/execute path. Automation-triggered agent invocation is
explicitly deferred to a follow-up change.

@e2e exclude negative/absence assertion — verified by static inspection of
the automation action-type enum (`AutomationCompilerService`/
`AutomationEditDialog.vue`'s fixed `send-notification|run-synchronization|
object-op|webhook|approval|generateDocument` list has no `invokeAgent` kind);
there is no UI state to drive in a browser to prove an absence.

#### Scenario: No automation action kind can invoke an agent

- **WHEN** the automation editor's action-type list is inspected
- **THEN** no action kind exists that invokes an `Agent`
