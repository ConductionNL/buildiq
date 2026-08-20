# Design: openbuild-mcp-adoption

## Context

OpenBuild is the fleet's app builder, so "a tool that mutates a manifest" is not an abstract risk —
it is the product. `OpenBuildToolProvider` today serves two masters through one class:

| Consumer | Path | Human in the loop? |
|---|---|---|
| **AI Copilot** (first-party) | `CopilotService::plan()` → predicted manifest → user reviews → `execute()` → re-validate → atomic, with rollback | **Yes** — plan writes nothing; the user approves a previewed diff |
| **MCP** (any agent) | `IMcpToolProvider::openbuild` alias → `McpToolsService` → `invokeTool()` | **No** — a single tool call writes the manifest |

The same six write handlers sit behind both doors. The Copilot door is well built. The MCP door
should not exist.

## Goals / Non-Goals

**Goals.** Zero hand-written MCP tools for `openbuild`; a read-only declarative surface answering the
questions humans actually ask; the Copilot untouched in behaviour.

**Non-Goals.** Changing the Copilot's UX, plan format, validator or rollback. Deleting handler logic.
Enabling *any* write verb on *any* schema. Touching the business-rules engine.

## Curation table — 5 of 12 schemas ON, all read-only

Every `filters` entry was cross-checked against the schema's real `properties`
(`lib/Settings/openbuild_register.json` + the `register.d/` overlays). OpenRegister's
`McpAnnotationValidator::validateFilters()` rejects a schema at import if a filter names a
non-property, so this is a hard gate, not a style preference.

### ON

| Schema | Verbs | Filters (verified real properties) | One-line justification |
|---|---|---|---|
| `Application` | `search`, `get` | `status`, `appType`, `slug` | The primary noun — "which apps do we have, what's live?" is the question; reading an app's metadata mutates nothing. |
| `ApplicationVersion` | `search`, `get` | `application`, `status`, `semver` | "What's in production, what's still draft?" — the version chain is the answer, and a read of it cannot promote anything. |
| `ApplicationTemplate` | `search`, `get` | `category`, `useCase`, `slug` | "What can I start from?" — a genuinely useful assistant question, and templates are public, inert catalog content. |
| `exportJob` | `search`, `get` | `status`, `target`, `applicationUuid` | "Did my GitHub export finish, and if not why?" — `status` + `errorMessage` are exactly the support question. Read only: **creating** an export job pushes code to a GitHub org using brokered credentials. |
| `Automation` | `search`, `get` | `applicationSlug`, `enabled`, `trigger` | "What runs automatically on my app / why did it do that?" — a plausible support question, and reading a rule is not escalation. Writes would let an agent install a persistent trigger→action rule, i.e. indirect code execution. |

**No `create`, `update` or `delete` on any of the five.** `ApplicationVersion.manifest` *is* the app's
UI; `Application` writes provision registers and version chains; `exportJob` writes push code to
GitHub. Each is a destructive write by any honest reading, and none is worth an agent's convenience.

### OFF — and why

| Schema(s) | Why OFF |
|---|---|
| `RuleSet`, `DecisionTable`, `ConditionActionRule`, `TestCase` | The business-rules **authoring** surface. Reading a decision table is low-value and token-expensive; writing one mutates live policy. This is an authoring UI's job. |
| `RuleExecutionLog` | High-volume machine log with unbounded token cost (`inputPayload`, `outputResultaat` blobs per execution). Nobody asks an assistant for it in prose. |
| `BuiltAppRoute` | Internal routing plumbing (`slug` → `applicationUuid`). Zero human-facing value. |
| `HelloMessage` | App-template scaffold. Should probably be deleted from the register outright — see proposal Open Questions. |

Five ON is the top of the "bias to fewer" band and every one earns it. Twelve would have been the lazy answer.

## Surgery classification — all 8 hand-written tools

| Tool id | Verdict | Disposition |
|---|---|---|
| `openbuild.listApps` | **Derivable CRUD** — this is `Application.search` with a `status` filter | **Deleted from the MCP surface.** Superseded by `openbuild.application.search`. Retained as a Copilot plan step (it is a legitimate read step inside a plan). |
| `openbuild.getAppManifest` | **Derivable CRUD** — a version/manifest read | **Deleted from the MCP surface.** Superseded by `openbuild.applicationversion.get`. Retained as a Copilot plan step. |
| `openbuild.createApp` | Non-CRUD (provisions an Application + a version chain + a register) | **Removed from MCP. NOT moved to `#[McpTool]`.** Copilot-internal only. |
| `openbuild.promoteVersion` | Non-CRUD lifecycle (dev → prod) | **Removed from MCP. NOT moved to `#[McpTool]`.** Copilot-internal only. |
| `openbuild.upsertSchema` | Non-CRUD (sub-document mutation of a version's register) | **Removed from MCP. NOT moved.** Copilot-internal only. |
| `openbuild.upsertPage` | Non-CRUD (manifest sub-document mutation) | **Removed from MCP. NOT moved.** Copilot-internal only. |
| `openbuild.addWidget` | Non-CRUD (manifest sub-document mutation) | **Removed from MCP. NOT moved.** Copilot-internal only. |
| `openbuild.upsertMenuItem` | Non-CRUD (manifest sub-document mutation) | **Removed from MCP. NOT moved.** Copilot-internal only. |

This is the one place this change departs from the fleet's default surgery recipe, so it is worth
being explicit: **six of these tools are genuine non-CRUD, and the recipe says genuine non-CRUD moves
to a service with `#[McpTool]`. We are not moving them.** "Genuine non-CRUD" is a test of *shape*,
not of *safety* — it earns a tool the right to exist, not the right to be handed to an agent. These
six fail the separate question ADR-063 rule 3 actually asks ("would a human plausibly ask an
assistant to do this, and is it safe?"): rewriting a running app's UI from a chat prompt is not
something to enable and then govern. They keep their home and their human gate; they lose their MCP
reachability.

Net: OpenBuild's `#[McpTool]` count is **zero**, so no `IMcpScannableServices` implementation is
added — an opt-in listing no services would be an empty seam.

## Decisions

### Decision 1: sever the alias rather than delete the class

Alternative considered: delete `OpenBuildToolProvider` outright, per the pipelinq exemplar. **Rejected
— it would break the Copilot.** Unlike pipelinq's provider, this class is load-bearing for a
first-party feature: `CopilotService:228/322` calls `getToolDescriptors()` for plan validation and
`invokeTool()` for execution. The pipelinq recipe assumed the provider's *only* consumer was MCP. Here
it is not, and following the recipe blindly would have deleted a working feature. Dropping
`implements IMcpToolProvider` and the alias achieves the entire security goal — the tools become
unreachable from MCP — while leaving the Copilot bit-for-bit intact.

### Decision 2: rename to `CopilotToolExecutor` and move out of `lib/Mcp/`

A class named `*ToolProvider` living in `lib/Mcp/` that is not an MCP provider is a trap for the next
reader — someone will "fix" it by re-adding the interface. The rename encodes the decision in the
type system's most durable comment: the file path.

### Decision 3: read-only, and argue every enablement

The brief's instruction was to argue anything enabled. What is enabled is five `search`/`get` pairs
over app metadata, template catalog entries, export-job status and automation definitions. None
returns credential material (`exportJob.githubCredentialId` is a broker **reference**, not a secret —
worth re-confirming at implementation time, see Open Questions). None mutates. The worst case of the
entire enabled surface is that an agent tells you about an app you already own.

## Nextcloud Integration

- Services: `Service\Copilot\CopilotToolExecutor` (renamed), `CopilotService`, `CopilotPlanValidator`, `CopilotPromptBuilder` — unchanged behaviour.
- DI: the `OCA\OpenRegister\Mcp\IMcpToolProvider::openbuild` alias registration is **removed** from `Application.php`. No `IMcpScannableServices` alias is added.

## Security Considerations

This change is entirely a reduction in attack surface: eight tools (six of them writes) leave the MCP
catalog; ten read-only derived tools enter it. `PermissionResolver` and the per-handler authorisation
checks continue to guard the Copilot path unchanged. The derived reads are gated by OpenRegister RBAC
via `ObjectService`, and every derived invocation writes an immutable audit record — which the
hand-written handlers did **not** do. So the surviving surface is both smaller and better audited.

## File Structure

```
lib/
  Mcp/
    OpenBuildToolProvider.php   ← MOVED → lib/Service/Copilot/CopilotToolExecutor.php
    Handler/*.php  (9 files)    ← MOVED → lib/Service/Copilot/Tools/*.php
  AppInfo/Application.php       ← IMcpToolProvider::openbuild alias REMOVED
  Settings/
    openbuild_register.json     ← dialect on Application, ApplicationVersion, ApplicationTemplate, exportJob
    register.d/40-automations.json ← dialect on Automation
```

## Trade-offs

An agent can no longer build an app for you by chat. That is the point. The prompt-to-app experience
already exists, is better, and keeps a human in the loop — it is the Copilot, reachable in OpenBuild's
own UI at `POST /api/copilot/plan`. What this change removes is the *undefended duplicate* of that
capability, which offered the same power with none of the preview, approval or rollback.

## Open Questions

- Confirm `exportJob.githubCredentialId` is a broker reference and never inlines a token before
  enabling `exportJob.get`. If it can carry a secret, `exportJob` drops to OFF — the dialect cannot
  project fields away.
- Does the `register.d` union merge preserve `x-openregister-mcp` on a schema that an overlay
  re-declares (`Application`, `exportJob`)? Verified by task, not assumed.
