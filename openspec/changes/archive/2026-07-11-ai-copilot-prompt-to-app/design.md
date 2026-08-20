# Design — AI copilot / prompt-to-app

## Context

- `lib/Mcp/OpenBuildToolProvider.php` is a thin dispatcher over eight handler
  classes (`lib/Mcp/Handler/*Handler.php`, base `AbstractToolHandler.php`).
  The handlers — not a separate service — are the operation layer: they own
  argument validation (`validateArgs`), per-Application RBAC
  (`requireWriteRole` with owners/editors + logged admin bypass via
  `PermissionResolver`), OR object locking (`saveVersionManifest` acquires
  `lockObject` before the read-modify-write, throws 409 on contention), and
  manifest growth caps (`checkManifestCaps`).
- The tool catalogue lives in `OpenBuildToolProvider::TOOL_DESCRIPTORS` —
  each tool carries a JSON-Schema `inputSchema` (slug patterns, enums,
  required keys, size limits).
- The canonical manifest v2 validator is
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`
  (ADR-024), consumed by `validateManifest` from `@conduction/nextcloud-vue`
  and by `scripts/check-manifest.js` (Ajv). **There is no JSON-Schema engine
  in composer.json** — the v2 schema is not runnable in PHP today.
- The AI Chat Companion (openregister #1466) established the degradation
  pattern this repo already e2e-tests: a health endpoint
  (`/api/chat/health` → 200 provider configured / 503 not), UI gated on it,
  Playwright specs `test.skip(health.status() === 503, ...)`
  (`tests/e2e/chat-companion-streaming.spec.ts`).
- The creation wizard is `src/dialogs/CreateApplicationWizard.vue` with step
  components under `src/dialogs/CreateApplicationWizard/` (Step 1 =
  `Step1Basics.vue`); its backend is `applicationCreation#wizard`
  (`POST /api/applications/wizard`, `#[NoAdminRequired]`, caller becomes
  owner).
- `appinfo/info.xml` declares NC **28–34**; `OCP\TaskProcessing` ships in
  NC **30+**.

## Decisions

### 1. Execute through `OpenBuildToolProvider::invokeTool()` — not a parallel service

`CopilotService::execute()` dispatches each approved plan step as
`$toolProvider->invokeTool($step['tool'], $step['arguments'])`. This is the
*same code path* the MCP/chat surface uses, so RBAC, slug validation, route
injection guards, manifest caps, and the OR write lock are enforced once, in
one place. The copilot adds orchestration (ordering, atomicity, result
aggregation) around the handlers — never a second implementation of their
logic. Adding a ninth operation later means adding one handler + one
descriptor; the copilot picks it up automatically because its allow-list and
argument validation read `TOOL_DESCRIPTORS` (exposed via a new public
`getToolDescriptors()` accessor — the private const stays the single source).

### 2. LLM access via `OCP\TaskProcessing` `TextToText` — provider-agnostic, NC 30+

The plan step calls Nextcloud's Task Processing API:

- Availability = `interface_exists('OCP\\TaskProcessing\\IManager')`
  (NC ≥ 30) **and** `IManager::getAvailableTaskTypes()` containing
  `OCP\TaskProcessing\TaskTypes\TextToText::ID`. This is what
  `GET /api/copilot/health` reports (200 / 503 + machine-readable reason
  `unsupported_server` | `no_provider`).
- Invocation = schedule a `TextToText` task (`IManager::scheduleTask`) with
  `appId = 'openbuild'` + the acting user id, then poll `getTask()` until
  `STATUS_SUCCESSFUL` / `STATUS_FAILED` with a hard timeout (120 s) —
  TaskProcessing is asynchronous by design; polling inside the request keeps
  the controller contract synchronous for the frontend.
- No provider coupling: whatever backend the admin configured (local Ollama,
  EU-hosted, Nextcloud AI apps) serves the task. OpenBuild never names a
  model or vendor.

On NC 28/29 the copilot is simply unavailable (same 503 path as
"no provider") — no conditional compilation, one degradation story.

### 3. Plan contract: JSON constrained to the tool catalogue; layered validation

The system prompt (built by `CopilotPromptBuilder`) embeds the serialised
tool catalogue and demands a single JSON object:

```json
{
  "summary": "one-sentence description of what will be built",
  "steps": [
    { "tool": "openbuild.createApp", "arguments": { "slug": "…", "name": "…" } },
    { "tool": "openbuild.upsertSchema", "arguments": { "appSlug": "…", "slug": "…", "title": "…", "properties": { } } }
  ]
}
```

Validation layers, in order — a failure at any layer means **nothing is
applied**:

1. **Parse + repair (server).** The LLM output is parsed as strict JSON
   (code-fence tolerant). On parse failure, exactly **one** repair round-trip
   (re-prompt with the parse error) is attempted; a second failure returns
   422 `plan_invalid`. No unbounded loops (non-goal: autonomous agents).
2. **Structural (server).** `CopilotPlanValidator` checks every step against
   the tool's `inputSchema` from `getToolDescriptors()`: tool id on the
   allow-list, required keys present, enum/pattern/type/length constraints
   met. Implemented directly against the descriptor array in PHP — we do
   **not** add a JSON-Schema composer dependency for the six constraint
   kinds the descriptors actually use.
3. **Dry-run (server).** Manifest-mutating steps are simulated on an
   in-memory copy of the target version's manifest; the predicted manifest
   must pass `checkManifestCaps`. The predicted manifest per affected
   version is returned in the plan response.
4. **Canonical manifest v2 gate (client).** The frontend runs
   `validateManifest` (`@conduction/nextcloud-vue`, ADR-024 v2 schema) on
   each predicted manifest and keeps **Approve disabled** while invalid.
   Rationale for running this layer client-side: the canonical schema and
   its Ajv runner ship in the frontend library and there is no PHP
   JSON-Schema engine in this repo; the server layers (2, 3, plus the
   handlers' own `validateArgs` at execute time) remain authoritative for
   everything security-relevant, so the client gate is a strictly additional
   filter, never the only one.

### 4. Atomic execution: snapshot → apply → rollback

`CopilotService::execute()`:

1. Re-runs validation layers 2–3 on the submitted plan (the server never
   trusts the client's review).
2. Snapshots the `manifest` of every ApplicationVersion the plan touches.
3. Applies steps in order via `invokeTool()`. Each handler write already
   holds the OR object lock.
4. On any step returning `isError` (or throwing): restore every snapshotted
   manifest, delete an application **created by this plan** via
   `ApplicationDeletionService`, and return 422 with the failed step index +
   the handler's error envelope. Steps are ordered so `createApp` (when
   present) is always first and `promoteVersion` (discouraged in prompts,
   but on the allow-list) always last, keeping the rollback window coherent.
5. On success: return the ordered per-step results (each handler's success
   payload) so the UI can render a step-by-step report.

This is compensation-based atomicity, not a DB transaction — OR has no
cross-object transactions — and it is documented as such: the guarantee is
"a failed plan leaves no plan-created state behind", scoped to the objects
the plan itself wrote.

### 5. Human-in-the-loop is the security model

The LLM proposes; only the user disposes. `POST /api/copilot/plan` performs
**zero writes**. `POST /api/copilot/execute` only accepts a fully-explicit
plan (the reviewed steps, echoed back verbatim), and RBAC is enforced twice:
at plan time (fail fast, better UX) and inside every handler at execute time
(authoritative). The builder panel and the wizard dialog both render the
exact operations + manifest diff (`ManifestDiff.vue`) before Approve. There
is deliberately no "auto-apply" preference.

### 6. RBAC mapping

- Plan/execute targeting an **existing** app: owners/editors on that
  Application (`requireWriteRole` semantics; admin bypass allowed and logged
  as `rbac.admin_bypass`, identical to the MCP handlers).
- Plan containing `createApp`: same bar as the creation wizard —
  authenticated user (`#[NoAdminRequired]`), caller becomes owner of the
  created Application.
- Hybrid apps (`appType: hybrid`) are rejected at plan time
  (`unsupported_target`): they mirror installed real apps and are
  metadata-locked; the non-goal "editing exported real apps" is enforced
  here, not just documented.

### 7. Relationship to the AI Chat Companion

The companion (FAB + free chat, orchestrated by openregister) and the
copilot (deterministic plan/approve) coexist. They share provider
configuration (both ride the admin's Nextcloud AI setup) but not code paths:
the companion's LLM loop lives in openregister; the copilot's constrained
plan call lives in OpenBuild because the plan contract, dry-run, and
atomicity are OpenBuild-domain concerns. The health-gating and skip-on-503
e2e conventions are copied from the companion so operators learn one model.

### 8. Frontend shape

- `src/services/copilot.js` — thin fetch wrapper for the three endpoints.
- `src/composables/useCopilot.js` — Vue 2.7 composable owning the state
  machine (`idle → planning → review → executing → done | error`), the
  client-side canonical-validator gate, and the health probe (cached per
  page load).
- Wizard: `Step1Basics.vue` renders the health-gated "Generate with AI"
  button; the flow itself lives in the standalone
  `src/dialogs/CopilotGenerateDialog.vue` (modal-isolation gate).
- Builder: `CopilotPanel.vue` is mounted in `PageDesignerHost.vue` behind a
  toolbar toggle, chat-style, one `CopilotProposal.vue` card per assistant
  turn. All user-facing strings via `t('openbuild', …)` (English keys,
  ADR-007) with NL translations.

## Risks

- **LLM output quality varies by provider.** Mitigated by the constrained
  prompt + strict validation (a bad plan is rejected, never half-applied)
  and the single repair retry. Small local models may fail often; the error
  surface says so honestly ("The AI could not produce a valid plan…").
- **Synchronous polling holds a PHP worker up to the timeout.** Acceptable
  for v1 (plan calls are user-initiated and rare); if it becomes a problem,
  the TaskProcessing task id can be surfaced for frontend polling without
  changing the plan contract.
- **Compensation rollback is not transactional.** A crash between step N and
  the rollback can leave partial state. Scope is documented; every write
  still goes through the locked handler path, so nothing is ever silently
  corrupted — worst case is a visible, deletable draft artefact.
- **Prompt-injection via user brief.** The brief is data inside a template
  whose executable surface is the allow-list; the worst a hostile brief can
  do is propose allow-listed operations the user must still approve and RBAC
  must still permit.

## Seed Data

None. No new OR schemas, registers, or seed objects — the plan operates on
the existing `openbuild` register through existing handlers.
