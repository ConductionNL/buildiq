## MODIFIED Requirements

### Requirement: A natural-language brief produces a validated builder-operation plan

`POST /api/copilot/plan` SHALL accept `{ brief, appSlug?, agentId? }` (brief
1–2000 chars; `appSlug` optional kebab-case slug of an existing target app;
`agentId` optional reference to an `Agent`), call the LLM through
`OCP\TaskProcessing` (`TextToText`) with a constrained system prompt
embedding the tool catalogue (prefixed with the resolved agent's
`instructions` when `agentId` is present), and return a plan
`{ summary, steps[] }` where every step is `{ tool, arguments }` with `tool`
restricted to the effective allow-list and `arguments` valid against that
tool's `inputSchema` from `OpenBuildToolProvider::getToolDescriptors()`. The
effective allow-list SHALL be the eight allow-listed operations
(`openbuild.createApp`, `openbuild.upsertSchema`, `openbuild.upsertPage`,
`openbuild.addWidget`, `openbuild.upsertMenuItem`, `openbuild.promoteVersion`,
`openbuild.listApps`, `openbuild.getAppManifest`) when no `agentId` is given,
or the server-side intersection of that catalogue with the resolved agent's
`enabledTools` when `agentId` is given — an agent SHALL NEVER expand the
allow-list beyond the eight-tool catalogue. Unparsable LLM output SHALL
trigger exactly one repair round-trip; a second failure SHALL return **422**
`plan_invalid` with a user-safe message. Planning SHALL perform **zero
writes**. When `agentId` is present and the plan's step count would exceed
the agent's `maxActionsPerRun`, the endpoint SHALL return **422** naming the
violated `max_actions_per_run` cap.

**ID:** REQ-OBAIC-002

@e2e exclude nondeterministic-LLM backend contract — plan parsing, the
single repair retry, allow-list enforcement and the zero-writes guarantee
are verified by PHPUnit with a mocked TaskProcessing manager
(`tests/Unit/Service/CopilotServiceTest.php`,
`tests/Unit/Service/CopilotPlanValidatorTest.php`); the user-visible
plan-render path is covered by the wizard and panel Playwright specs under
REQ-OBAIC-006/007.

#### Scenario: A brief yields an allow-listed, schema-valid plan

- **WHEN** a user posts `{ "brief": "A tool library: members borrow tools" }`
  and the mocked LLM returns a plan containing `createApp`, two
  `upsertSchema`, two `upsertPage` and one `upsertMenuItem` step
- **THEN** the response is 200 with that `summary` and `steps[]`, every step
  passing the corresponding tool `inputSchema`
- **AND** no Application, ApplicationVersion, schema or manifest was written

#### Scenario: A step outside the allow-list is rejected

- **WHEN** the LLM output contains a step `{ "tool": "openbuild.deleteApp" }`
  (not in the catalogue) or `upsertPage` arguments missing the required
  `route`
- **THEN** the endpoint returns 422 `plan_invalid` naming the offending step
  index and nothing is applied

#### Scenario: Unparsable output gets exactly one repair retry

- **WHEN** the LLM returns non-JSON prose twice in a row
- **THEN** the service issues exactly one repair re-prompt, then returns 422
  `plan_invalid` — never a third LLM call

#### Scenario: An agent-scoped plan step outside that agent's narrower list is rejected

- **GIVEN** an `Agent` with `enabledTools: ["openbuild.upsertPage"]`
- **WHEN** a plan request carries that agent's `agentId` and the LLM output
  contains an `openbuild.upsertSchema` step
- **THEN** the endpoint returns 422 `plan_invalid`, even though
  `upsertSchema` is in the bare eight-tool catalogue

#### Scenario: An agent-scoped plan exceeding maxActionsPerRun is rejected

- **GIVEN** an `Agent` with `maxActionsPerRun: 3`
- **WHEN** a plan request carrying that agent's `agentId` yields a 4-step
  plan
- **THEN** the endpoint returns 422 naming the violated `max_actions_per_run`
  cap

### Requirement: An approved plan executes atomically through the MCP handler layer

`POST /api/copilot/execute` SHALL accept the reviewed plan verbatim plus the
optional `agentId` it was planned with, re-validate it server-side
(effective allow-list per the resolved agent when `agentId` is present,
per-tool `inputSchema`, predicted caps — the server never trusts the
client's review), snapshot the manifest of every ApplicationVersion the plan
touches, and dispatch each step in order through
`OpenBuildToolProvider::invokeTool()` — the same handler classes, RBAC
checks, OR object locks and caps as the MCP surface, with no duplicated
builder logic. On any step failure the service SHALL restore all snapshotted
manifests, delete an application created by this plan (via
`ApplicationDeletionService`), and return **422** with the failed step index
and the handler's error envelope — a failed plan leaves no plan-created
state behind. On success it SHALL return the ordered per-step results. When
`agentId` is present, the service SHALL persist an `AgentRun` record (see
`agent-workspace`) capturing the prompt, plan, each tool call's arguments and
result, and the final outcome, regardless of success, rollback, or
plan-rejection.

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

#### Scenario: An agent-scoped execute persists a transparent run record

- **GIVEN** an approved plan executed with `agentId` set
- **WHEN** execution completes, whether successfully or via rollback
- **THEN** an `AgentRun` record exists capturing the prompt, plan, every
  tool call's arguments and result, and the final outcome
