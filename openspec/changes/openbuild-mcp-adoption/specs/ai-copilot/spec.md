# ai-copilot Specification (delta)

**Status**: in-progress
**Scope**: buildiq
**OpenSpec changes**:
- `buildiq-mcp-adoption`: the Copilot and outside MCP agents share the eight builder operations, and both write only under an approved plan (kind: code)

## Purpose

The Copilot is unchanged in behaviour. Its eight builder operations stay in `BuildiqToolProvider`,
which also serves outside MCP agents (decision 392). Both doors keep a person between the plan and
the write.

## MODIFIED Requirements

### Requirement: An approved plan executes atomically through the MCP handler layer

`POST /api/copilot/execute` SHALL accept the reviewed plan verbatim,
re-validate it server-side (allow-list, per-tool `inputSchema`, predicted
caps — the server never trusts the client's review), snapshot the manifest
of every ApplicationVersion the plan touches, and dispatch each step in
order through `BuildiqToolProvider::invokeTool()`, the same handler
classes, RBAC checks, OR object locks and caps as before, with no
duplicated builder logic. On any step failure the service SHALL restore all
snapshotted manifests, delete an application created by this plan (via
`ApplicationDeletionService`), and return **422** with the failed step index
and the handler's error envelope — a failed plan leaves no plan-created
state behind. On success it SHALL return the ordered per-step results.

Outside MCP agents reach the same handlers through `BuildiqToolProvider`, and SHALL write only
under a plan a person approved, as `buildiq-mcp-surface` says. The Copilot and an outside agent
therefore share one approval rule: `plan()` performs zero writes and returns a predicted manifest
for review, and only an explicitly approved plan may be executed.

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

#### Scenario: An outside agent's write needs the same approval

- **WHEN** an MCP client calls `buildiq.upsertPage` without an approved plan
- **THEN** the call is refused
- **AND** the same call inside an approved plan runs `UpsertPageHandler` as the Copilot does

#### Scenario: An agent-scoped execute persists a transparent run record

- **GIVEN** an approved plan executed with `agentId` set
- **WHEN** execution completes, whether successfully or via rollback
- **THEN** an `AgentRun` record exists capturing the prompt, plan, every
  tool call's arguments and result, and the final outcome

## Acceptance Criteria

- [ ] The Copilot's plan, preview, execute and rollback behave as before
- [ ] Every existing `ai-copilot` PHPUnit and Playwright test passes unmodified in substance
- [ ] An outside agent's write is refused without an approved plan
