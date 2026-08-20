---
kind: code
---

## Why

Market analysis (2026-07-11) found **prompt-to-app is table-stakes** across
every app-builder OpenBuild competes with: a citizen developer describes the
app they want in natural language and the builder scaffolds schemas, pages,
menus and widgets from that brief. OpenBuild has all of the plumbing and none
of the surface:

- `lib/Mcp/OpenBuildToolProvider.php` already exposes the full authoring
  surface as MCP tools — `openbuild.listApps`, `openbuild.getAppManifest`,
  `openbuild.createApp`, `openbuild.promoteVersion`, `openbuild.upsertSchema`,
  `openbuild.upsertPage`, `openbuild.addWidget`, `openbuild.upsertMenuItem` —
  each dispatched to a dedicated handler under `lib/Mcp/Handler/` that owns
  argument validation, per-Application RBAC (`requireWriteRole`, owners /
  editors via `PermissionResolver`), OR object locking, and manifest caps
  (`checkManifestCaps`: 256 KB / 100 pages / 30 menu items / 50 widgets).
- The AI Chat Companion (OR PR #1466 orchestrator) can *reach* these tools,
  but a free chat is not a prompt-to-app flow: there is no "describe an app,
  review the proposal, create it" surface in the wizard, and no in-builder
  copilot that proposes concrete, reviewable operations.

Today the only user-facing paths are the manual wizard and the page designer.
This change adds the missing user-facing surface: a **plan → review →
approve → execute** copilot that turns a natural-language brief into a
validated list of builder operations and applies them through the exact same
handler code the MCP tools use.

## What Changes

- **NEW** `lib/Service/CopilotService.php` (plus `lib/Service/Copilot/`
  collaborators `CopilotPromptBuilder.php` and `CopilotPlanValidator.php`) —
  accepts a natural-language brief + optional target app slug, calls an LLM
  via Nextcloud's **Task Processing API** (`OCP\TaskProcessing\IManager`,
  `TextToText` task type — provider-agnostic, works with local / EU-hosted
  models, available on NC 30+) with a constrained system prompt, and parses a
  **JSON plan** whose steps are restricted to the eight MCP tool contracts
  (single source of truth: `OpenBuildToolProvider::TOOL_DESCRIPTORS`).
  Execution dispatches every approved step through
  `OpenBuildToolProvider::invokeTool()` — the same handler classes, RBAC,
  locking and caps as MCP; **no duplicated builder logic**. Execution is
  atomic: manifests are snapshotted before the first step and restored (and a
  plan-created app deleted via `ApplicationDeletionService`) when any step
  fails.
- **NEW** `lib/Controller/CopilotController.php` + three routes in
  `appinfo/routes.php` (specific-first, before the SPA catch-all per
  ADR-016/029): `GET /api/copilot/health`, `POST /api/copilot/plan`,
  `POST /api/copilot/execute`, all `#[NoAdminRequired]`. RBAC: plans that
  target an existing app require **owners/editors** on that Application
  (admin bypass logged, matching `AbstractToolHandler::requireWriteRole`);
  plans that create an app require the same permission as the creation
  wizard (`applicationCreation#wizard` — any authenticated user, caller
  becomes owner).
- **NEW** wizard entry — `src/dialogs/CreateApplicationWizard/Step1Basics.vue`
  gains a health-gated **"Generate with AI"** affordance opening
  `src/dialogs/CopilotGenerateDialog.vue` (standalone `NcModal` per the
  modal-isolation gate): describe the app → preview the proposed schemas /
  pages / menu items as a reviewable step list → confirm → the app is
  created and the wizard routes to it.
- **NEW** builder copilot panel — `src/components/copilot/CopilotPanel.vue`
  (+ `CopilotProposal.vue`), a chat-style side panel toggled from
  `src/views/PageDesignerHost.vue`. Every assistant turn proposes concrete
  operations rendered as a reviewable list plus a manifest diff (reusing
  `src/components/ManifestDiff.vue`); the user approves before anything is
  applied — **no silent mutations**.
- **Validation before execution.** Server-side: every plan step is validated
  by `CopilotPlanValidator` against the tool's `inputSchema` from
  `TOOL_DESCRIPTORS` and the predicted manifest is dry-run against the
  manifest caps. Client-side: the predicted manifest is additionally gated by
  the canonical **manifest v2 validator** (`validateManifest` from
  `@conduction/nextcloud-vue`, ADR-024) before the Approve button enables.
  Failed validation → nothing is applied.
- **Graceful degradation.** When no TaskProcessing `TextToText` provider is
  configured (or the server is NC < 30), `GET /api/copilot/health` returns
  503 and all copilot entry points are hidden; admins see a hint pointing at
  the Nextcloud AI provider settings — mirroring the Chat Companion's
  `/api/chat/health` gating pattern.

### Non-goals

- **No autonomous multi-turn agent loops** — every mutation requires an
  explicit user approval of a concrete plan; the copilot never self-iterates.
- **No arbitrary code generation** — plan steps are restricted to the
  allow-listed builder operations; there is no path to emit or execute code.
- **No editing of exported/hybrid real apps** — the copilot targets virtual
  apps only; hybrid apps (metadata-locked, `HybridMetadataLockListener`) are
  rejected by the plan endpoint and get no panel.

## Capabilities

### Added Capabilities

- **ai-copilot** — the prompt-to-app surface: natural-language brief →
  validated builder-operation plan → human review → atomic execution through
  the existing MCP handler layer.

### Referenced (no change here)

- `openbuild-application-register` / MCP handler family — the operation layer
  the copilot executes through; unchanged.
- AI Chat Companion (openregister #1466) — remains the free-form chat
  surface; this change adds the deterministic plan/approve surface beside it
  and shares the same provider configuration.

## Impact

- Apps, manifests and the MCP surface behave identically when the copilot is
  unused or unavailable; the feature is purely additive.
- New backend: 1 controller, 1 service + 2 collaborators, 3 routes. New
  frontend: 1 dialog, 2 components, 1 composable, 1 API service module.
- Unblocks the "prompt-to-app" competitive gap identified in the 2026-07-11
  market analysis.
