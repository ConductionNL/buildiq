# Tasks — AI copilot / prompt-to-app

> Verify every referenced symbol against HEAD before editing — handler names,
> `TOOL_DESCRIPTORS`, wizard step files and `ManifestDiff.vue` were confirmed
> on 2026-07-11 but may have moved.

## 1. Tool-catalogue access + plan validation (backend)

- [x] 1.1 Add a public `getToolDescriptors(): array` accessor on
      `lib/Mcp/OpenBuildToolProvider.php` returning `self::TOOL_DESCRIPTORS`
      (the const stays private/single-source); annotate it
      `@spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md` (REQ-OBAIC-002)
- [x] 1.2 Add `lib/Service/Copilot/CopilotPlanValidator.php` — pure class
      validating a decoded plan `{summary, steps[]}` against the descriptors:
      tool id on the allow-list; required keys present; `enum`, `pattern`,
      `type`, `minLength`/`maxLength`, `minimum`/`maximum` constraints from
      each tool's `inputSchema`; returns `[]` or a list of
      `{stepIndex, message}` violations (REQ-OBAIC-002)
- [x] 1.3 Add `lib/Service/Copilot/CopilotPromptBuilder.php` — builds the
      constrained system prompt: serialised tool catalogue, JSON-only output
      contract (`{summary, steps[]}`), target-app context (current manifest
      summary when `appSlug` given), and the repair re-prompt variant that
      embeds the previous parse error (REQ-OBAIC-002)

## 2. CopilotService (backend)

- [x] 2.1 Add `lib/Service/CopilotService.php` with
      `health(): array` — `interface_exists('OCP\\TaskProcessing\\IManager')`
      + `getAvailableTaskTypes()` contains `TextToText::ID`; returns
      `{available, reason?}` with `reason` ∈
      `unsupported_server|no_provider` (REQ-OBAIC-001)
- [x] 2.2 In `CopilotService`, implement `plan(string $brief, ?string
      $appSlug, string $userId): array` — schedule a `TextToText` task via
      `OCP\TaskProcessing\IManager::scheduleTask` (appId `openbuild`), poll
      `getTask()` to `STATUS_SUCCESSFUL|STATUS_FAILED` with a 120 s timeout,
      strip code fences, `json_decode` strict; on parse failure do exactly
      one repair round-trip then throw a 422-mapped
      `CopilotPlanException` (REQ-OBAIC-002)
- [x] 2.3 In `CopilotService::plan()`, reject a hybrid or unknown `appSlug`
      with `unsupported_target`/`not_found` before any LLM call, and run
      `CopilotPlanValidator` on the parsed plan (REQ-OBAIC-002,
      REQ-OBAIC-005)
- [x] 2.4 In `CopilotService`, implement `predictManifests(array $plan,
      ?string $appSlug): array` — apply manifest-mutating steps
      (`upsertPage`, `addWidget`, `upsertMenuItem`) to an in-memory copy of
      each touched version's manifest, enforce the caps (256 KB / 100 pages /
      30 menu items / 50 widgets per page), and return
      `{versionSlug: {current, predicted}}` for the plan response
      (REQ-OBAIC-003)
- [x] 2.5 In `CopilotService`, implement `execute(array $plan, string
      $userId): array` — re-run validator + prediction caps, snapshot every
      touched version's manifest, dispatch steps in order (createApp first,
      promoteVersion last) through
      `OpenBuildToolProvider::invokeTool($tool, $arguments)`, collect
      per-step results (REQ-OBAIC-004)
- [x] 2.6 In `CopilotService::execute()`, implement rollback: on any
      `isError` step result or throw, restore all snapshotted manifests,
      delete a plan-created application via
      `lib/Service/ApplicationDeletionService.php`, and rethrow as a
      422-mapped exception carrying the failed step index + handler envelope
      (REQ-OBAIC-004)
- [x] 2.7 Add owners/editors RBAC in `CopilotService` for plans targeting an
      existing app, reusing `lib/Service/PermissionResolver.php` with the
      same grammar and logged admin bypass as
      `AbstractToolHandler::requireWriteRole` (REQ-OBAIC-005)
- [x] 2.8 SPDX + `@license`/`@copyright` docblocks and
      `@spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md`
      tags on every new/changed public method in `lib/Service/CopilotService.php`,
      `lib/Service/Copilot/*.php` (hydra gates 1/16)

## 3. Controller + routes (backend)

- [x] 3.1 Add `lib/Controller/CopilotController.php` with `health()`
      (`#[NoAdminRequired]`, `#[NoCSRFRequired]` not needed — same-origin
      POSTs keep CSRF), `plan()` and `execute()` (both
      `#[NoAdminRequired]`); map service exceptions to
      `JSONResponse` envelopes: 503 unavailable, 422
      `plan_invalid|unsupported_target`, 403 forbidden, 404 not_found
      (REQ-OBAIC-001/002/004/005)
- [x] 3.2 Register the three routes in `appinfo/routes.php` inside the
      `Routes::standard()` `$extra` array, before the SPA catch-all,
      each with a spec-reference comment:
      `['name' => 'copilot#health', 'url' => '/api/copilot/health', 'verb' => 'GET']`,
      `['name' => 'copilot#plan', 'url' => '/api/copilot/plan', 'verb' => 'POST']`,
      `['name' => 'copilot#execute', 'url' => '/api/copilot/execute', 'verb' => 'POST']`
      (REQ-OBAIC-001)
- [x] 3.3 Run the mechanical gates on the new backend files (route-auth,
      route-reachability, semantic-auth, spdx, spec-coverage) and fix
      findings

## 4. Frontend service + composable

- [x] 4.1 Add `src/services/copilot.js` — `fetchCopilotHealth()`,
      `requestPlan({brief, appSlug})`, `executePlan(plan)` against the three
      routes, normalising error envelopes (REQ-OBAIC-001/002/004)
- [x] 4.2 Add `src/composables/useCopilot.js` (Vue 2.7 composable) — state
      machine `idle → planning → review → executing → done | error`, cached
      health probe, and the canonical-validator gate: run `validateManifest`
      from `@conduction/nextcloud-vue` on every predicted manifest and expose
      `canApprove` (false while any predicted manifest is invalid)
      (REQ-OBAIC-003)
- [x] 4.3 Add `@spec` JSDoc tags on the exported functions of
      `src/services/copilot.js` and `src/composables/useCopilot.js`

## 5. Wizard "Generate with AI" (frontend)

- [x] 5.1 Add `src/dialogs/CopilotGenerateDialog.vue` — standalone `NcModal`
      (modal-isolation gate): brief `NcTextArea`, generating state, review
      pane (plan summary, step list grouped as schemas / pages / menu items,
      validator verdict), "Confirm & create" primary + "Cancel" secondary;
      emits `created(appSlug)` after a successful execute (REQ-OBAIC-006)
- [x] 5.2 In `src/dialogs/CreateApplicationWizard/Step1Basics.vue`, render
      the "Generate with AI" `NcButton` only when the health probe is 200
      (via `useCopilot`), opening `CopilotGenerateDialog`; on `created`,
      close the wizard and route to the new application (REQ-OBAIC-006)
- [x] 5.3 In `Step1Basics.vue`, when health is 503 and the current user is
      an NC admin, render the muted hint linking to the Nextcloud AI
      provider settings; nothing for non-admins (REQ-OBAIC-001)
- [x] 5.4 Add `data-testid` attributes: `copilot-generate-button`,
      `copilot-brief-input`, `copilot-plan-review`, `copilot-confirm`,
      `copilot-cancel`, `copilot-admin-hint`

## 6. Builder copilot panel (frontend)

- [x] 6.1 Add `src/components/copilot/CopilotProposal.vue` — one assistant
      turn: proposed step list (tool + key arguments per row) + before/after
      diff reusing `src/components/ManifestDiff.vue`, Approve (disabled
      while `canApprove` is false) and Discard actions (REQ-OBAIC-003/007)
- [x] 6.2 Add `src/components/copilot/CopilotPanel.vue` — chat-style side
      panel scoped to the edited app+version: message list (user bubbles +
      `CopilotProposal` cards), input row, executing/done/error states;
      Approve triggers `executePlan`, Discard drops the proposal locally
      (REQ-OBAIC-007)
- [x] 6.3 Mount the panel in `src/views/PageDesignerHost.vue` behind a
      health-gated toolbar toggle button, hidden for hybrid apps; pass the
      current `appSlug`/`versionSlug` and refresh the designer's manifest
      after a successful execute (REQ-OBAIC-007)
- [x] 6.4 Add `data-testid` attributes: `copilot-panel-toggle`,
      `copilot-panel`, `copilot-message-input`, `copilot-proposal`,
      `copilot-approve`, `copilot-discard`

## 7. i18n

- [x] 7.1 Wrap all new user-facing strings in `t('openbuild', …)` with
      English source keys (hydra ADR-007) and add NL translations in
      `l10n/`

## 8. Unit tests — PHPUnit

- [x] 8.1 Add `tests/Unit/Service/CopilotPlanValidatorTest.php` — allow-list
      rejection, missing required args, enum/pattern/length violations,
      valid multi-step plan passes (REQ-OBAIC-002)
- [x] 8.2 Add `tests/Unit/Service/CopilotServiceTest.php` — health
      unavailable paths (`unsupported_server`/`no_provider`); plan happy
      path with mocked TaskProcessing manager; exactly-one repair retry then
      422; zero writes at plan time; hybrid target rejected before the LLM
      call (REQ-OBAIC-001/002/005)
- [x] 8.3 Extend `tests/Unit/Service/CopilotServiceTest.php` — predicted
      manifest computation, cap violation → 422; execute dispatches through
      a mocked `OpenBuildToolProvider::invokeTool` in order (createApp
      first); mid-plan `isError` → all snapshots restored + created app
      deleted + failed step index in the envelope (REQ-OBAIC-003/004)
- [x] 8.4 Add `tests/Unit/Controller/CopilotControllerTest.php` — auth
      attributes present, exception→status mapping (503/422/403/404),
      viewer-role 403 on existing-app execute, caller-becomes-owner on
      createApp plans (REQ-OBAIC-005)

## 9. Unit tests — vitest

- [x] 9.1 Add `tests/composables/useCopilot.spec.js` — state machine
      transitions, health caching, `canApprove` false on a v2-invalid
      predicted manifest and true on a valid one (REQ-OBAIC-003)
- [x] 9.2 Add `tests/components/CopilotProposal.spec.js` — step list render,
      diff mount, Approve disabled while invalid, Discard emits without
      network (REQ-OBAIC-003/007)
- [x] 9.3 Add `tests/components/CopilotPanel.spec.js` — user bubble renders
      synchronously, proposal card appears on plan response, Approve calls
      `executePlan` once, Discard never calls it (REQ-OBAIC-007)
- [x] 9.4 Add `tests/dialogs/CopilotGenerateDialog.spec.js` — brief →
      review → confirm emits `created`; cancel sends no execute; button
      hidden + admin hint via mocked 503 health (REQ-OBAIC-001/006)

## 10. E2E tests — Playwright

- [x] 10.1 Add `tests/e2e/copilot-wizard-generate.spec.ts` — skip-on-503
      pattern from `tests/e2e/chat-companion-streaming.spec.ts`; scenarios:
      "Generate with AI creates the described app after confirmation" (use
      `page.route` to stub `/api/copilot/plan` with a fixed deterministic
      plan, then let `/api/copilot/execute` hit the real backend and assert
      the created app renders), "Cancelling the review applies nothing",
      and "The button is absent without a provider" (route-mock health→503)
      (REQ-OBAIC-006, REQ-OBAIC-001 UI scenarios)
- [x] 10.2 Add `tests/e2e/copilot-panel.spec.ts` — same skip/stub approach
      on a seeded app: "Approving a proposal applies it to the open app"
      (real execute, assert manifest gains the page), "Discarding a proposal
      changes nothing", "No write happens before approval" (assert zero
      requests to `/api/copilot/execute` and zero manifest PUTs between
      proposal render and approval via `page.on('request')`)
      (REQ-OBAIC-007)
- [x] 10.3 Reference the spec in test titles
      (`(spec: ai-copilot)`) so gate-19 e2e traceability resolves the
      REQ-OBAIC-001/003/006/007 scenarios; REQ-OBAIC-002/004/005 carry
      `@e2e exclude` in the spec delta

## 11. Documentation

- [x] 11.1 Add `docs/ai-copilot.md` — what the copilot does, the
      plan/review/approve model, provider setup (TaskProcessing, NC 30+),
      degradation behaviour, RBAC, and the atomicity guarantee wording from
      design.md Decision 4
- [x] 11.2 Register the page in `docs/sidebars.js` and link it from
      `docs/intro.md`'s feature overview

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate ai-copilot-prompt-to-app --strict` and resolve any
  structural errors.
- Run the hydra gates (`/hydra-gates`) — pay attention to route-auth,
  semantic-auth, modal-isolation, spec-coverage and e2e-coverage (gate-19).
- Confirm `POST /api/copilot/plan` truly performs zero writes (grep the
  service for `saveObject`/`invokeTool` outside `execute()`).
- Confirm the execute path contains no re-implementation of handler logic —
  every mutation must flow through `OpenBuildToolProvider::invokeTool()`.
- `composer check:strict` and `npm run test` green; `npm run check:manifest`
  untouched (no shell-manifest change in this feature).

## Acceptance Criteria

- `GET /api/copilot/health` returns 200 with a `TextToText` provider on
  NC 30+, 503 with `unsupported_server`/`no_provider` otherwise; all entry
  points hidden on 503; NC admins see the provider hint in wizard Step 1.
- `POST /api/copilot/plan` turns a brief into `{summary, steps[]}` limited
  to the eight allow-listed tools, each step valid against its
  `inputSchema`; unparsable output gets exactly one repair retry then 422;
  planning writes nothing; hybrid targets are rejected.
- The plan response carries current + predicted manifests; caps enforced
  server-side at plan time; the frontend blocks Approve while a predicted
  manifest fails the canonical manifest v2 validator.
- `POST /api/copilot/execute` re-validates, snapshots, dispatches through
  `OpenBuildToolProvider::invokeTool()`, and on any failure restores
  snapshots + deletes a plan-created app — nothing applied; on success it
  returns ordered per-step results.
- RBAC: owners/editors (logged admin bypass) for existing apps;
  wizard-equivalent (authenticated, caller becomes owner) for creation.
- Wizard Step 1 "Generate with AI" flow works: brief → review → confirm →
  app created → routed; cancel applies nothing.
- The PageDesignerHost copilot panel proposes reviewable operations with a
  manifest diff; Approve applies, Discard doesn't, and no write ever happens
  before approval.
- PHPUnit + vitest suites pass; both Playwright specs pass (or skip cleanly
  on 503); gate-19 traceability resolves every scenario; docs page live in
  the sidebar.
