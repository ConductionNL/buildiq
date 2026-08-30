# buildiq-mcp-surface Specification

**Status**: planned
**Scope**: buildiq
**OpenSpec changes**:
- `buildiq-mcp-adoption` — declares the ADR-063 read-only dialect and removes every app-mutating tool from the MCP surface (kind: code)

## Purpose

Defines what an MCP agent may and may not do with Buildiq. Buildiq authors applications, so its
write tools do not edit records — they edit **the UI of a running app**. This capability makes the
agent-facing surface read-only by construction, and states normatively that the manifest-mutating
tools are reachable only through the AI Copilot's plan → preview → human-approval → atomic-execute
flow, never through a bare tool call. See ADR-063 and the `ai-copilot` spec.

## ADDED Requirements

### Requirement: Buildiq exposes no hand-written MCP tool
The system MUST NOT register any `IMcpToolProvider` implementation for app id `buildiq`, and MUST NOT annotate any Buildiq service method with `#[McpTool]`.

The `OCA\OpenRegister\Mcp\IMcpToolProvider::buildiq` container alias MUST be removed from
`lib/AppInfo/Application.php`, and the class formerly registered under it MUST NOT implement
`IMcpToolProvider`. Buildiq's entire agent-facing tool surface MUST therefore be derived from
schema declarations.

#### Scenario: The buildiq MCP catalog contains only derived tools
- GIVEN OpenRegister builds the tool catalog for app id `buildiq`
- WHEN an MCP client lists available tools
- THEN every returned tool id MUST match the 3-segment derived form `buildiq.{schema}.{verb}`
- AND no 2-segment hand-written id such as `buildiq.upsertPage` MUST appear

### Requirement: No tool may mutate an application, a manifest or a version
The system MUST NOT expose any MCP tool that creates, updates or deletes an `Application`, an `ApplicationVersion`, a manifest sub-document (page, widget, menu item), a per-version schema, or an `exportJob`.

Concretely, none of `buildiq.createApp`, `buildiq.promoteVersion`, `buildiq.upsertSchema`,
`buildiq.upsertPage`, `buildiq.addWidget` or `buildiq.upsertMenuItem` MUST be reachable through
MCP, and no `x-openregister-mcp` block in Buildiq's register MUST declare a `create`, `update` or
`delete` verb. A manifest write changes what every user of that app sees; it is a destructive write
regardless of how routine it looks, and it MUST carry a human approval, which the MCP surface cannot
provide. The tools retain their home in the Copilot executor — this requirement removes their
reachability, not their existence.

#### Scenario: An agent cannot rewrite a running app's UI
- GIVEN an agent holds every grant OpenRegister can issue for app id `buildiq`
- WHEN it attempts to add a widget to a page of a live application
- THEN no such tool MUST exist in the catalog
- AND the attempt MUST fail as an unknown tool, not merely be denied at invoke time

#### Scenario: No write verb is derivable
- GIVEN every `x-openregister-mcp` block in `openbuild_register.json` and `register.d/`
- WHEN the derived catalog is built
- THEN it MUST contain no tool id ending in `.create`, `.update` or `.delete`

### Requirement: A curated read-only dialect on five schemas
The system MUST declare `x-openregister-mcp` with the `search` and `get` verbs on exactly `Application`, `ApplicationVersion`, `ApplicationTemplate`, `exportJob` and `Automation`, each with `scope: 'read'` and `readOnlyHint: true`.

Each verb MUST carry agent-facing `description` prose, and every `search.filters` entry MUST name a
real declared property of that schema — OpenRegister's `McpAnnotationValidator` rejects the schema at
import otherwise. The dialect MUST NOT be declared on `RuleSet`, `DecisionTable`,
`ConditionActionRule`, `TestCase`, `RuleExecutionLog`, `BuiltAppRoute` or `HelloMessage`.

#### Scenario: The derived catalog answers the questions humans ask
- GIVEN the dialect is declared on the five schemas
- WHEN the derived catalog for `buildiq` is built
- THEN it MUST emit ten tools — `search` and `get` for each of `Application`, `ApplicationVersion`, `ApplicationTemplate`, `exportJob` and `Automation`
- AND a user MUST be able to ask which apps exist and whether an export job failed, and get an answer

#### Scenario: Declared filters survive the register.d union merge
- GIVEN `register.d/20-data-registers.json` and `register.d/31-export-job-broker-credential.json` re-declare `Application` and `exportJob` to add properties
- WHEN the register is imported and the overlays are union-merged
- THEN the `x-openregister-mcp` block MUST still be present on both schemas
- AND `McpAnnotationValidator` MUST accept every declared filter as a real property

## Non-Functional Requirements

- **Performance:** The `buildiq` catalog MUST stay at or below roughly a dozen tools, so tool-selection accuracy and prompt token cost do not degrade (ADR-063 rule 3).
- **Internationalization:** No new user-facing strings; tool `description` prose is agent-facing English. N/A for `nl_NL`/`en_US`.

## Acceptance Criteria

- [ ] No `IMcpToolProvider::buildiq` alias is registered and no class in `buildiq` implements it
- [ ] The `buildiq` catalog contains exactly ten derived read tools and nothing else
- [ ] No `create`/`update`/`delete` tool id exists for `buildiq`
- [ ] The AI Copilot's plan/preview/execute/rollback behaviour is unchanged

## Notes

- ADR-063 (hydra#102). Verified at OpenRegister `origin/development`:
  `Mcp/BuiltIn/SchemaDerivedToolProvider.php` (derived id shape, `suppressedIds` self-suppression by
  exact id) and `Service/Mcp/McpAnnotationValidator.php` (`VERBS`, `SCOPES`, filter cross-check).
- Open: confirm `exportJob.githubCredentialId` is a credential-broker reference and never inlines a
  token. If it can carry a secret, `exportJob` drops to OFF — the dialect has no field projection.
