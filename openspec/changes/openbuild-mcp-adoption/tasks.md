# Tasks: buildiq-mcp-adoption

## Implementation Tasks

### Task 1: Keep the alias and the eight tools, and check roles on every write
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-for-a-caller-the-apps-roles-allow`
- **files**: `lib/Mcp/BuildiqToolProvider.php`, `lib/Mcp/Handler/*.php`
- **acceptance_criteria**:
  - GIVEN an MCP client WHEN it lists the `buildiq` tools THEN the eight builder tools are there
  - GIVEN a caller with only a viewers role WHEN their agent calls any write tool THEN it is refused
- [ ] Implement
- [ ] Test

### Task 2: An approved plan before every MCP write
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved`
- **files**: `lib/Service/CopilotService.php`, `lib/Mcp/BuildiqToolProvider.php`, `lib/Mcp/Handler/AbstractToolHandler.php`, the Copilot panel in `src/`
- **acceptance_criteria**:
  - GIVEN an agent WHEN it submits a plan THEN nothing is written and a person with a write role can approve or reject it in buildiq
  - GIVEN a write call without an approved plan, or one that differs from the approved step WHEN it runs THEN it is refused
  - GIVEN `buildiq.promoteVersion` WHEN called THEN it follows the same rule
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

### Task 5: Assert the catalog shape
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-outside-agents-shall-build-apps-through-the-eight-builder-tools`
- **files**: `tests/Unit/Mcp/`
- **acceptance_criteria**:
  - GIVEN the `buildiq` catalog WHEN enumerated THEN it holds the eight builder tools and ten derived read tools, and no derived id ends in `.create`, `.update` or `.delete`
- [ ] Implement
- [ ] Test

### Task 6: Tests for the approval rule
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer`
- **files**: `tests/Unit/Mcp/BuildiqToolProviderTest.php`, `tests/Unit/Mcp/Handler/WriteHandlerValidationTest.php`, `tests/Unit/Service/CopilotServiceTest.php`
- **acceptance_criteria**:
  - GIVEN each write tool WHEN called without an approved plan THEN a test shows it is refused
  - GIVEN the Copilot flow WHEN the suite runs THEN its existing tests pass unchanged in substance
- [ ] Implement
- [ ] Test

### Task 7: Update the CHANGELOG and the docs
- **spec_ref**: `openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved`
- **files**: `CHANGELOG.md`, `docs/`
- **acceptance_criteria**:
  - GIVEN the docs WHEN this change lands THEN they say outside agents build through the eight tools, under the app's roles and an approved plan
- [ ] Implement
- [ ] Test

## Verification

- `openspec validate buildiq-mcp-adoption --type change --strict` passes.
- The `buildiq` MCP catalog holds the eight builder tools and ten derived read tools.
- Every MCP write is refused without a matching approved plan.
- The Copilot's plan → preview → approve → execute → rollback flow is unchanged end-to-end.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- PHP verified the CI way in a container, against a baseline measured first — zero new failures
- Scoped PHPCS clean on every touched `lib/` file; `python3 -m json.tool` after every JSON edit
- `@spec` tags point at `openspec/specs/...`, never an archived change path (gate-46)
- No new user-facing strings — i18n N/A
- `openspec validate` passes
