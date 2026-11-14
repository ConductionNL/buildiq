---
kind: code
---

# Proposal: buildiq-mcp-adoption

## Why

Buildiq builds apps, and Ruben decided on 12 Nov (decision 392) that outside AI agents may build
too. `lib/Mcp/BuildiqToolProvider.php` already gives MCP clients eight builder tools under the
`IMcpToolProvider::buildiq` alias: `buildiq.listApps`, `buildiq.getAppManifest`,
`buildiq.createApp`, `buildiq.promoteVersion`, `buildiq.upsertSchema`, `buildiq.upsertPage`,
`buildiq.addWidget` and `buildiq.upsertMenuItem`. The same tools are the AI Copilot's vocabulary.

Two gaps remain, both verified at HEAD:

1. **No person between an agent and the write.** In the Copilot, `POST /api/copilot/plan` writes
   nothing, a person reviews the predicted manifest and `POST /api/copilot/execute` re-validates and
   rolls back on failure. Over MCP a single tool call writes the manifest, and `promoteVersion` runs
   directly.
2. **No declarative read surface.** None of Buildiq's schemas carry `x-openregister-mcp`, so the
   read questions people ask ("which apps exist? did my export to GitHub finish?") have no derived
   tool.

### Matrix rows (buildiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `ai-external-agent-builds` | outside agents build through the eight tools, under the app's roles and an approved plan |

## What changes

- **Keep the eight builder tools on MCP.** `BuildiqToolProvider` stays an `IMcpToolProvider`, and
  its handlers stay shared with the Copilot.
- **Roles on every write.** Each write tool requires an owners or editors role on the target app,
  as the Copilot does.
- **An approved plan on every write.** An agent submits its steps as a plan, a person with a write
  role approves it in buildiq, and each write call must name that plan and match one of its steps.
  `promoteVersion` follows the same rule.
- **Declare `x-openregister-mcp` on 5 of 12 schemas, `search` + `get` only**: `Application`,
  `ApplicationVersion`, `ApplicationTemplate`, `exportJob`, `Automation`.

## Capabilities

### New Capabilities
- `buildiq-mcp-surface`: Buildiq's agent-facing MCP surface: the eight builder tools behind roles
  and an approved plan, and the curated read-only dialect.

### Modified Capabilities
- `ai-copilot`: the Copilot and outside agents share the builder operations and one approval rule.

## Impact

- `lib/Mcp/BuildiqToolProvider.php`, `lib/Mcp/Handler/*`: an approved-plan check before every write.
- `lib/Service/CopilotService.php`: plans submitted by an outside agent, approved in buildiq.
- The Copilot panel (board BqCopilot): a person approves or rejects an agent's plan.
- `lib/Settings/openbuild_register.json`, `lib/Settings/register.d/40-automations.json`: the dialect.
- `tests/Unit/Mcp/*`, `tests/Unit/Service/CopilotServiceTest.php`.
- **Cross-project:** depends on OpenRegister `origin/development` (`SchemaDerivedToolProvider`,
  `McpAnnotationValidator`). No OpenRegister change required.

### Risks

**Risk 1: an agent writes without approval. Severity: High.** Mitigation: the approved-plan check
sits in the handler layer every write passes through, so no tool can skip it, and a test calls each
write tool without a plan.

**Risk 2: an agent promotes what it just wrote. Severity: High.** Mitigation: `promoteVersion` is a
plan step like any other and needs its own approval.

**Risk 3: the `register.d` union merge drops the dialect. Severity: Medium.** Mitigation: a task
verifies after import that `x-openregister-mcp` survived on `Application` and `exportJob`.

## Rollback

Revert the JSON hunks to drop the dialect. Revert the PHP commit to remove the approval check; the
tools then write directly again, as they do today.

## Open Questions

- `HelloMessage` is app-template scaffold and `BuiltAppRoute` is internal routing plumbing. Both look
  like cruft. Out of scope here.
