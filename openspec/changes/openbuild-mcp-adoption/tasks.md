# Tasks: buildiq-mcp-adoption

## Implementation Tasks

### Task 1: Sever the MCP alias — the security fix, landed first and alone
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/buildiq-mcp-surface/spec.md#requirement-buildiq-exposes-no-hand-written-mcp-tool`
- **files**: `lib/AppInfo/Application.php`, `lib/Mcp/BuildiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN `Application.php` registers `IMcpToolProvider::buildiq` WHEN this task lands THEN that registration MUST be removed and `BuildiqToolProvider` MUST drop `implements IMcpToolProvider`
  - GIVEN an MCP client lists tools WHEN the catalog for `buildiq` is built THEN no 2-segment `buildiq.*` tool MUST appear
  - GIVEN the Copilot WHEN a plan is executed THEN it MUST still work — `CopilotService` calls the class directly, not through OR
- [ ] Implement
- [ ] Test

### Task 2: Rename to `CopilotToolExecutor` and re-home the handlers
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer`
- **files**: `lib/Service/Copilot/CopilotToolExecutor.php`, `lib/Service/Copilot/Tools/*.php`, `lib/Service/CopilotService.php`, `lib/Service/Copilot/CopilotPlanValidator.php`, `lib/Service/Copilot/CopilotPromptBuilder.php`
- **acceptance_criteria**:
  - GIVEN the move WHEN it completes THEN `getToolDescriptors()`, `invokeTool()` and all nine handler bodies MUST be unchanged apart from namespace
  - GIVEN `lib/Mcp/` WHEN the move completes THEN the directory MUST no longer exist
  - GIVEN a plan step with a `javascript:` route WHEN executed THEN `UpsertPageHandler`'s route-injection guard MUST still reject it
- [ ] Implement
- [ ] Test

### Task 3: Confirm `exportJob.githubCredentialId` carries no secret, then declare the dialect on the four base schemas
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/buildiq-mcp-surface/spec.md#requirement-a-curated-read-only-dialect-on-five-schemas`
- **files**: `lib/Settings/openbuild_register.json`
- **acceptance_criteria**:
  - GIVEN `exportJob.githubCredentialId` WHEN inspected THEN it MUST be a credential-broker reference and never an inlined token; if it can inline one, `exportJob` drops to OFF
  - GIVEN the dialect on `Application`, `ApplicationVersion`, `ApplicationTemplate`, `exportJob` WHEN imported THEN `McpAnnotationValidator` MUST accept it, with `search` + `get` only and every filter a real property
  - GIVEN each verb WHEN emitted THEN it MUST carry `scope: 'read'`, `readOnlyHint: true` and useful agent-facing `description` prose
- [ ] Implement
- [ ] Test

### Task 4: Declare the dialect on `Automation` and verify the `register.d` union merge preserves it
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/buildiq-mcp-surface/spec.md#requirement-a-curated-read-only-dialect-on-five-schemas`
- **files**: `lib/Settings/register.d/40-automations.json`
- **acceptance_criteria**:
  - GIVEN `Automation` WHEN declared THEN `search` + `get` only, filters `applicationSlug`, `enabled`, `trigger`
  - GIVEN overlays `20-data-registers.json` and `31-export-job-broker-credential.json` re-declare `Application` and `exportJob` WHEN the register is imported THEN the `x-openregister-mcp` block MUST survive the union merge on both — assert against the live imported schema, do not assume
- [ ] Implement
- [ ] Test

### Task 5: Assert the catalog shape — ten reads, zero writes
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/buildiq-mcp-surface/spec.md#requirement-no-tool-may-mutate-an-application-a-manifest-or-a-version`
- **files**: `tests/Unit/Mcp/`, `tests/Unit/Service/Copilot/`
- **acceptance_criteria**:
  - GIVEN the derived catalog for `buildiq` WHEN enumerated THEN it MUST contain exactly ten tools, all `search`/`get`, all `readOnlyHint: true`
  - GIVEN the catalog WHEN enumerated THEN no id ending in `.create`, `.update` or `.delete` MUST appear, and no `buildiq.upsertPage` / `.addWidget` / `.upsertMenuItem` / `.upsertSchema` / `.createApp` / `.promoteVersion` MUST appear
- [ ] Implement
- [ ] Test

### Task 6: Retarget the existing tests at the renamed class
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer`
- **files**: `tests/Unit/Mcp/BuildiqToolProviderTest.php`, `tests/Unit/Mcp/Handler/WriteHandlerValidationTest.php`, `tests/Unit/Service/CopilotServiceTest.php`, `tests/Unit/Service/PrincipalMatcherTest.php`
- **acceptance_criteria**:
  - GIVEN four test files construct or mock `BuildiqToolProvider` WHEN it is renamed THEN each MUST be retargeted with its assertions preserved
  - GIVEN the suite runs the CI way WHEN measured against a baseline taken first THEN there MUST be zero new failures
- [ ] Implement
- [ ] Test

### Task 7: Update the CHANGELOG and the docs
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/buildiq-mcp-surface/spec.md#requirement-buildiq-exposes-no-hand-written-mcp-tool`
- **files**: `CHANGELOG.md`, `docs/`
- **acceptance_criteria**:
  - GIVEN the CHANGELOG WHEN this change lands THEN it MUST record that the eight hand-written MCP tools are gone and that manifest authoring is Copilot-only
  - GIVEN any doc claiming an agent can build an app over MCP WHEN reviewed THEN it MUST be corrected
- [ ] Implement
- [ ] Test

## Verification

- `openspec validate buildiq-mcp-adoption --type change --strict` passes.
- The `buildiq` MCP catalog contains ten read-only derived tools and nothing else.
- The Copilot's plan → preview → approve → execute → rollback flow is unchanged end-to-end.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- PHP verified the CI way in a container, against a baseline measured first — zero new failures
- Scoped PHPCS clean on every touched `lib/` file; `python3 -m json.tool` after every JSON edit
- `@spec` tags point at `openspec/specs/...`, never an archived change path (gate-46)
- No new user-facing strings — i18n N/A
- `openspec validate` passes
