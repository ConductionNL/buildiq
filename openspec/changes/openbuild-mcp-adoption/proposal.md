---
kind: code
---

# Proposal: openbuild-mcp-adoption

## Why

OpenBuild builds apps. Its MCP provider therefore exposes the most dangerous write surface in the
fleet: `lib/Mcp/OpenBuildToolProvider.php` registers **eight** tools under the
`IMcpToolProvider::openbuild` alias, six of which mutate a virtual app —
`openbuild.upsertPage`, `openbuild.addWidget`, `openbuild.upsertMenuItem`,
`openbuild.upsertSchema`, `openbuild.createApp`, `openbuild.promoteVersion`. Any agent holding a
grant for those tools **can rewrite the UI of a running app**. That is remote code-authoring
delivered through a chat prompt, and today it is reachable by any MCP client OpenRegister serves,
with no plan, no preview and no human in the loop.

Two facts make this worse, and both were verified at HEAD:

1. **The blast radius is not the MCP surface's to own.** Those same eight tools are *also*
   OpenBuild's AI Copilot vocabulary. `Service/CopilotService` executes plan steps through
   `OpenBuildToolProvider::invokeTool()`, and `Copilot/CopilotPlanValidator` +
   `Copilot/CopilotPromptBuilder` restrict LLM-proposed plans to
   `OpenBuildToolProvider::getToolDescriptors()`. In the **Copilot** context these tools are safe:
   `POST /api/copilot/plan` performs zero writes and returns a predicted manifest, a human reviews
   it, and `POST /api/copilot/execute` re-validates server-side and rolls back atomically on failure.
   In the **MCP** context none of that exists. The same handlers are safe behind one door and unsafe
   behind the other — and they are currently behind both.
2. **OpenBuild has no declarative surface at all.** Zero of its schemas carry `x-openregister-mcp`,
   so `SchemaDerivedToolProvider` emits nothing for `openbuild`. The read questions a human actually
   asks ("which apps exist? did my export to GitHub finish?") are unanswerable, while the write
   questions nobody should be able to ask are wide open. Exactly backwards.

ADR-063 also forbids hand-writing derivable CRUD. `openbuild.listApps` is `Application.search` and
`openbuild.getAppManifest` is a version read — both belong in the dialect, not in PHP.

## What Changes

**The authoring tools stay; the door they are behind changes.** OpenBuild's agent-facing MCP surface
becomes **100% declarative and 100% read-only**, and the write surface is retained for the Copilot's
human-in-the-loop flow only:

- **Sever the provider from MCP.** `OpenBuildToolProvider` stops implementing `IMcpToolProvider`, the
  `IMcpToolProvider::openbuild` alias is removed from `lib/AppInfo/Application.php`, and the class is
  renamed to `Service\Copilot\CopilotToolExecutor` to say what it actually is. The handler classes
  under `lib/Mcp/Handler/` move with it. **No handler logic is deleted** — the Copilot keeps working,
  unchanged, and `CopilotService` / `CopilotPlanValidator` / `CopilotPromptBuilder` keep their
  vocabulary.
- **Declare `x-openregister-mcp` on 5 of 12 schemas, `search` + `get` only**: `Application`,
  `ApplicationVersion`, `ApplicationTemplate`, `exportJob`, `Automation`. No write verb anywhere.
- OpenBuild ends up with **zero hand-written MCP tools**, so no `IMcpScannableServices` opt-in is
  needed: nothing in OpenBuild is genuine non-CRUD *agent* behaviour.

The result: an agent can tell you what apps you have and whether your export succeeded. It cannot
touch a manifest.

## Capabilities

### New Capabilities
- `openbuild-mcp-surface`: OpenBuild's agent-facing MCP tool surface — the curated read-only dialect, and the normative refusal of every manifest- and app-mutating tool on the MCP surface.

### Modified Capabilities
- `ai-copilot`: the Copilot's tool catalogue is now sourced from a Copilot-owned executor rather than from an `IMcpToolProvider`; the catalogue contents, the plan/preview/approve/execute flow and the rollback semantics are unchanged.

## Impact

- `lib/Mcp/OpenBuildToolProvider.php` → `lib/Service/Copilot/CopilotToolExecutor.php`; `implements IMcpToolProvider` dropped.
- `lib/Mcp/Handler/*` (9 files) → `lib/Service/Copilot/Tools/*`; bodies unchanged.
- `lib/AppInfo/Application.php` — `IMcpToolProvider::openbuild` alias **removed**.
- `lib/Service/CopilotService.php`, `lib/Service/Copilot/CopilotPlanValidator.php`, `lib/Service/Copilot/CopilotPromptBuilder.php` — type-hint updates only.
- `lib/Settings/openbuild_register.json` — dialect on `Application`, `ApplicationVersion`, `ApplicationTemplate`, `exportJob`.
- `lib/Settings/register.d/40-automations.json` — dialect on `Automation`.
- `tests/Unit/Mcp/*`, `tests/Unit/Service/CopilotServiceTest.php`, `tests/Unit/Service/PrincipalMatcherTest.php` — retargeted at the renamed class.
- **Cross-project:** depends on OpenRegister `origin/development` (`SchemaDerivedToolProvider`, `McpAnnotationValidator`). No OpenRegister change required.

### Risks

**Risk 1 — a surviving write tool lets an agent rewrite a running app's UI. Severity: High.**
Mitigation: remove the alias, so the tools are never emitted into any MCP catalog. Refusing at
registration is strictly stronger than gating at invoke time. Note the authoring tools default
`versionSlug` to `development`, which bounds but does not eliminate the damage —
`openbuild.promoteVersion` exists, and a two-step agent can promote what it just wrote.

**Risk 2 — the rename breaks the Copilot. Severity: Medium.** Mitigation: it is a pure move —
class renamed, namespace changed, `implements` clause dropped. `getToolDescriptors()`,
`invokeTool()` and every handler keep their signatures and bodies, so `CopilotService` changes only
its type hint. Three existing test files already construct the provider directly and will catch a
mistake.

**Risk 3 — the `register.d` union merge drops the dialect. Severity: Medium.** Mitigation:
`register.d/20-data-registers.json` and `31-export-job-broker-credential.json` re-declare
`Application` and `exportJob` to add properties. Union-merge has silently dropped keys before, so a
task verifies post-import that `x-openregister-mcp` survived on both, rather than assuming it.

## Rollback

Revert the JSON hunks to drop the dialect (an app with no opted-in schema derives no tools — no code
change needed). Revert the PHP commit to restore the class name and the alias.

## Open Questions

- `HelloMessage` is app-template scaffold and `BuiltAppRoute` is internal routing plumbing. Both look
  like cruft. Out of scope here; worth a cleanup change.
- Should the Copilot's own tool catalogue eventually be expressed as `#[McpTool]` on Copilot services
  with `scope`/`destructiveHint` declared, so its *internal* steps carry the same honest metadata as
  the fleet's MCP tools? Deferred — it changes nothing about agent reachability.
