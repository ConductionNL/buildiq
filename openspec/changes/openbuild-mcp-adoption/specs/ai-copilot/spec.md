# ai-copilot Specification (delta)

**Status**: in-progress
**Scope**: openbuild
**OpenSpec changes**:
- `openbuild-mcp-adoption` — the Copilot's tool catalogue moves from an `IMcpToolProvider` to a Copilot-owned executor; the flow, the handlers and the rollback semantics are unchanged (kind: code)

## Purpose

The Copilot is unchanged in behaviour. Only the **ownership** of its tool catalogue changes: the eight
builder operations stop being MCP tools and become Copilot-internal operations, because ADR-063 and
`openbuild-mcp-surface` remove OpenBuild's hand-written MCP provider. The Copilot remains the *only*
path through which an OpenBuild manifest can be authored by an LLM — and it keeps its plan, preview,
human approval and atomic rollback.

## MODIFIED Requirements

### Requirement: An approved plan executes atomically through the MCP handler layer

`POST /api/copilot/execute` SHALL accept the reviewed plan verbatim,
re-validate it server-side (allow-list, per-tool `inputSchema`, predicted
caps — the server never trusts the client's review), snapshot the manifest
of every ApplicationVersion the plan touches, and dispatch each step in
order through `CopilotToolExecutor::invokeTool()` — the same handler
classes, RBAC checks, OR object locks and caps as before, with no
duplicated builder logic. On any step failure the service SHALL restore all
snapshotted manifests, delete an application created by this plan (via
`ApplicationDeletionService`), and return **422** with the failed step index
and the handler's error envelope — a failed plan leaves no plan-created
state behind. On success it SHALL return the ordered per-step results.

The executor SHALL NOT implement `OCA\OpenRegister\Mcp\IMcpToolProvider`, and its operations SHALL
NOT be reachable from any MCP client. The Copilot is the **sole** LLM-driven path to a manifest write,
and it SHALL keep a human between the plan and the write: `plan()` performs zero writes and returns a
predicted manifest for review, and only an explicitly approved plan may be executed.

*Previously: dispatch went through `OpenBuildToolProvider::invokeTool()`, and the same handlers were
simultaneously exposed as hand-written MCP tools ("the same handler classes … as the MCP surface").
That MCP exposure is removed by `openbuild-mcp-surface`; the handlers and the flow are otherwise
identical.*

**ID:** REQ-OBAIC-004

@e2e exclude backend atomicity contract — snapshot/rollback, created-app
compensation, step ordering and the invokeTool dispatch (asserting the same
handler instances run) are verified by PHPUnit with mocked
ObjectService/handlers (`tests/Unit/Service/CopilotServiceTest.php`); the
happy execute path is exercised end-to-end by the wizard and panel
Playwright specs under REQ-OBAIC-006/007, which create and mutate real apps.

#### Scenario: A successful plan applies every step and reports per-step results

- **WHEN** an approved 5-step plan executes and every handler succeeds
- **THEN** the response lists 5 ordered step results (each the handler's
  success payload) and the target app's manifest contains the new pages,
  menu items and schemas

#### Scenario: A mid-plan failure rolls everything back

- **WHEN** step 4 of a 5-step plan returns `isError` from its handler
- **THEN** the manifests of all touched versions are restored to their
  pre-plan snapshots, an app created in step 1 is deleted, and the response
  is 422 carrying the failed step index and the handler's error message

#### Scenario: Execution reuses the handlers, not a copy

- **WHEN** an `upsertPage` step executes with a `javascript:` route
- **THEN** it is rejected by `UpsertPageHandler`'s own route-injection guard
  (issue #167) — proving the copilot path runs the identical handler
  validation

#### Scenario: The builder operations are not reachable without a human

- **WHEN** an MCP client attempts to call `openbuild.upsertPage` directly
- **THEN** no such tool exists in the catalog, because the executor is not an `IMcpToolProvider`
- **AND** the only way to reach `UpsertPageHandler` is a plan the user has reviewed and approved

## Acceptance Criteria

- [ ] `CopilotService`, `CopilotPlanValidator` and `CopilotPromptBuilder` compile against `CopilotToolExecutor` with no behavioural change
- [ ] Every existing `ai-copilot` PHPUnit and Playwright test passes unmodified in substance
- [ ] The executor does not implement `IMcpToolProvider`
