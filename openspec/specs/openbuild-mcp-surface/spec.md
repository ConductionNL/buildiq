# openbuild-mcp-surface Specification

## Purpose

Defines what an MCP agent may do with Buildiq. Outside agents may build: the eight builder tools
stay on the MCP surface, and every write runs under the app's roles and a plan a person approved
(decision 392, 12 Nov 2026). Read questions are answered by a curated, derived read-only dialect.

## Requirements

### Requirement: Outside agents SHALL build apps through the eight builder tools
The system SHALL keep `BuildiqToolProvider` registered as `IMcpToolProvider::buildiq`, so an MCP client can list apps, read a manifest, create an app, upsert a schema, a page, a widget and a menu item, and promote a version: `buildiq.listApps`, `buildiq.getAppManifest`, `buildiq.createApp`, `buildiq.upsertSchema`, `buildiq.upsertPage`, `buildiq.addWidget`, `buildiq.upsertMenuItem` and `buildiq.promoteVersion`. The six write tools SHALL run the same handlers as the AI Copilot, with the same validation, object locks and caps.

Rows: `ai-external-agent-builds` (buildiq matrix).

#### Scenario: An outside agent lists the builder tools
- GIVEN an MCP client connected through OpenRegister's MCP server
- WHEN it lists the tools for app id `buildiq`
- THEN the eight builder tools are in the catalog next to the derived read tools

#### Scenario: An agent's write runs the Copilot's handler
- GIVEN an approved plan with an `upsertPage` step whose route is `javascript:alert(1)`
- WHEN the agent calls `buildiq.upsertPage` for that step
- THEN `UpsertPageHandler`'s route guard refuses it, as it does for the Copilot

### Requirement: A builder tool SHALL write only for a caller the app's roles allow
Every write tool SHALL require the calling user to hold an owners or editors role on the target Application, as `AbstractToolHandler::requireWriteRole` does for the Copilot. `buildiq.createApp` SHALL require the right to create apps. An admin bypass SHALL be logged.

#### Scenario: A viewer's agent cannot change the app
- GIVEN a user with only the viewers role on an Application
- WHEN their agent calls `buildiq.addWidget` on it with an approved plan
- THEN the call is refused for lack of a write role and the manifest is unchanged

### Requirement: A builder tool SHALL write only under a plan a person approved
An outside agent SHALL first submit its intended steps as a plan, which writes nothing and returns the predicted manifest. A person with a write role on the app SHALL approve or reject the plan in buildiq. Each write tool call SHALL name an approved plan and match one of its steps; a call without an approved plan, or one that differs from the approved step, SHALL be refused. `buildiq.promoteVersion` SHALL follow the same rule, so an agent cannot promote what it wrote without approval.

#### Scenario: A write without an approved plan is refused
- GIVEN an agent with every grant OpenRegister can issue for app id `buildiq`
- WHEN it calls `buildiq.upsertPage` without naming an approved plan
- THEN the call is refused and the manifest is unchanged

#### Scenario: A person approves the agent's plan and it is built
- GIVEN an agent submitted a plan that adds a page and a menu item
- WHEN an editor of the app approves it and the agent calls the two tools for those steps
- THEN the page and the menu item are in the app's development version

#### Scenario: A step that differs from the plan is refused
- GIVEN an approved plan that adds the page `Overview`
- WHEN the agent calls `buildiq.upsertPage` for a page `Admin` instead
- THEN the call is refused

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
