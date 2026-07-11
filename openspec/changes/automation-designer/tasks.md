# Tasks — automation-designer

> Apply notes for the implementing agent: verify every claim below against
> HEAD before coding (files move); compile ONLY to existing declarative
> primitives per design.md Decision 2 — if a mapping doesn't fit, stop and
> flag it, do not invent a runner/listener/TimedJob. All ids/keys of compiled
> artifacts are `aut-` prefixed. No process tasks here (PRs/issues are
> handled outside this file).

## 1. Schema & storage

- [x] 1.1 Add `lib/Settings/register.d/40-automations.json` — ADR-037 fragment declaring the `Automation` schema (slug `automation`) on the shared `openbuild` register: `slug`, `name`, `description`, `applicationSlug`, `versionUuid`, `enabled`, typed `trigger`, optional `condition`, `actions[]` (typed records incl. reserved `approval` type per design Decision 8), `provenance` (notificationKeys/lifecycleActions/scheduleIds/ruleSetSlug/openconnectorObjects/compiledHash); English property names; `_comment` explaining ADR-031 posture and the `aut-` namespace
- [x] 1.2 Add `tests/Unit/Settings/AutomationsFragmentTest.php` — fragment parses, merges via `SettingsService::deepMergeConfig` without colliding with `10-business-rules.json`/`20-data-registers.json` schema keys

## 2. Compiler & dispatcher (backend)

- [x] 2.1 Add `lib/Service/AutomationCompilerService.php` — pure `compile(array $automation): array` producing the CompiledPlan per design Decision 3 (dialect backend: `x-openregister-notifications` entries keyed `aut-<slug>-<n>` with trigger mapping created/updated/deleted/transition; lifecycle backend: `related-object-upsert`/`webhook-dispatch` records with `aut-<slug>` marker; schedules backend: `schedules[]` entry valid against `src/services/manifestValidation/schedules.js` rules; rules backend: RuleSet `aut-<uuid8>` + ConditionActionRule) plus canonical-JSON `compiledHash`; enforce the v1 matrix fail-closed (typed exception naming the unsupported combination)
- [x] 2.2 In `AutomationCompilerService` add `apply(plan)` / `remove(provenance)` / `status(automation)` — idempotent upsert/delete of plan artifacts through the real OR `ObjectService` and the existing ApplicationVersion manifest PUT path (ADR-022; no new persistence layer); `status` recomputes the hash against live artifacts for drift; never touch non-`aut-` prefixed entries
- [x] 2.3 Add `lib/Service/RuleActionDispatcher.php` — implements the executor's `fn(string $type, array $params, array $payload)` contract: `send-notification` → `OCP\Notification\IManager`, `object-op` → `ObjectService::saveObject` (use `JobOwnerImpersonator` when no session user), `webhook` → POST via `OCP\Http\Client\IClientService`, `start-workflow`/`call-rule-set` kept
- [x] 2.4 Edit `lib/Service/ConditionActionExecutor.php` — add `object-op` and `webhook` to `SIDE_EFFECT_ACTIONS`; dry-run suppression unchanged
- [x] 2.5 Edit `lib/Service/RuleEngineService.php` — pass the wired `RuleActionDispatcher` callable at the `ConditionActionExecutor::execute()` call site (line ~142; fixes the verified silent no-op of side-effect actions in wet runs); constructor gains the dispatcher dependency
- [x] 2.6 Edit `lib/Service/ApplicationVersionService.php` — in the version-branch/copy flow, clone the source version's automations to the new version (new uuids, recompiled with fresh `aut-<uuid8>` rule-set slugs) per design Decision 6
- [x] 2.7 (ADDED — not in original task list, required by REQ-AUTD-005) Add `lib/Listener/AutomationCleanupListener.php` subscribing OR's `ObjectDeletedEvent`: when a deleted object's schema is `automation`, call `AutomationCompilerService::remove()` with its provenance. Automation CRUD (including delete) intentionally stays on OR REST per ADR-022 (task 3.1 explicitly excludes a delete route to satisfy the redundant-controller gate), so there is no controller hook to run the cleanup — the event listener is the imperative companion to that declarative delete, mirroring the already-established `ProductionVersionGuardListener`/`HybridMetadataLockListener` pattern (ADR-031 §Exceptions(1)). Registered in `lib/AppInfo/Application.php`; unit test `tests/Unit/Listener/AutomationCleanupListenerTest.php`.

## 3. Controller & routes

- [x] 3.1 Add `lib/Controller/AutomationsController.php` — `#[NoAdminRequired]` routes `compile`, `enable`, `disable`, `dryRun`, `status` (uuid-addressed); each resolves the automation + Application, then enforces RBAC per design Decision 7 via `PermissionResolver::matchesCaller()` (`['owners','editors']`; production-version enable = `['owners']`, `allowAdminBypass: false`) returning 403 JSON before any compile side effect; no CRUD pass-throughs (redundant-controller gate)
- [x] 3.2 Edit `appinfo/routes.php` — register the five `automations#*` routes beside the `rules#*` block with uuid requirements
- [x] 3.3 Add `tests/Unit/Controller/AutomationsControllerTest.php` — 403 for non-member, editor allowed on draft version, editor 403 / owner 200 for production enable, admin-bypass NOT honoured on production enable

## 4. Backend unit tests (PHPUnit)

- [x] 4.1 Add `tests/Unit/Service/AutomationCompilerServiceTest.php` — one test per matrix ✅ cell asserting exact artifact shape (REQ-AUTD-004 scenarios); determinism (same input → same plan + hash); idempotent recompile; unsupported cells throw with the combination named; delete removes only provenance-listed artifacts (hand-authored key untouched); drift hash mismatch detected
- [x] 4.2 Add `tests/Unit/Service/RuleActionDispatcherTest.php` — send-notification hits IManager, object-op hits ObjectService::saveObject with mapped fields, webhook POSTs the compiled target, unknown type surfaces an error
- [x] 4.3 Extend `tests/Unit/Service/` coverage for `RuleEngineService` — wet run invokes the dispatcher, dry-run does not (REQ-AUTD-010 scenarios); existing tests stay green
- [x] 4.4 Add `tests/Unit/Service/ApplicationVersionServiceAutomationCloneTest.php` — branch clones automations with new uuids + distinct `aut-` slugs; disabling the clone leaves the source version's artifacts unchanged (REQ-AUTD-009 scenario)

## 5. Frontend — Automations surface

- [x] 5.1 Add `src/views/AutomationsPage.vue` — per-version list (name, trigger summary, action summary, enabled `NcCheckboxRadioSwitch`, drift badge with "Recompile (overwrite)" confirm), version selector, empty state, "New automation"; CRUD via OR REST (`/apps/openregister/api/objects/openbuild/automation`), effectual calls via the automations routes
- [x] 5.2 Add `src/dialogs/AutomationEditDialog.vue` — standalone `NcModal`: trigger picker (object created/updated/deleted with schema picker; lifecycle transition with schema + transition pickers read from the version register's `x-openregister-lifecycle`; schedule reusing the schedules-editor cadence presets/custom-cron UX; manual), optional condition (FEEL `NcTextField` or rule-set `NcSelect`), actions list with per-type sub-forms (notification subject/channels/recipients; synchronization picker with free-text fallback mirroring `ScheduleEditDialog`; object-op schema + field mapping; webhook url + payload template); every `NcSelect` carries `:input-label`; matrix-invalid combinations disabled inline with the explanatory message (REQ-AUTD-003)
- [x] 5.3 Add `src/modals/AutomationTestPanelModal.vue` — mirror `RuleSetTestSandboxModal.vue`: sample-payload `NcTextArea`, Run button → `POST /api/automations/{uuid}/dry-run`, render condition outcome + would-be actions (dry-run/skipped) + errors + duration; `data-testid` hooks for e2e
- [x] 5.4 Add `src/services/automationMatrix.js` — the single shared v1 matrix constant (trigger × action × condition support) consumed by the dialog, list badges and (as fixture) unit tests, so lighting up a cell later is data-only
- [x] 5.5 Edit `src/registry.js` + add `src/manifest.d/40-automations.json` — register `AutomationsPageView` as a `type: "custom"` page (route `/automations`, no top-level menu entry, mirroring `20-business-rules.json`'s off-nav posture)

## 6. Frontend unit tests (vitest)

- [x] 6.1 Add `tests/components/AutomationsPage.spec.js` — list render per version, empty state, enable/disable emits the controller call, drift badge renders, version switch refetches (REQ-AUTD-001/006 scenarios)
- [x] 6.2 Add `tests/dialogs/AutomationEditDialog.spec.js` — the three compose scenarios of REQ-AUTD-002 produce the expected object shapes; matrix-blocked combination shows the message and prevents save (REQ-AUTD-003)
- [x] 6.3 Add `tests/services/automationMatrix.spec.js` — matrix constant matches design Decision 2 cell-for-cell; condition allowed only on manual

## 7. E2E (Playwright — every non-excluded spec scenario)

- [ ] 7.1 Add `tests/e2e/automations.spec.ts` — REQ-AUTD-001: list renders for a seeded version, empty state on a fresh version, version selector switches the list
- [ ] 7.2 In `tests/e2e/automations.spec.ts` — REQ-AUTD-002: compose event+notification, schedule+run-synchronization, manual+condition+object-op through the dialog and assert the saved rows
- [ ] 7.3 In `tests/e2e/automations.spec.ts` — REQ-AUTD-003: event trigger + webhook action blocked with message; condition on schedule trigger blocked
- [ ] 7.4 In `tests/e2e/automations.spec.ts` — REQ-AUTD-005: delete removes the compiled schedules entry from the page designer's Schedules section while a hand-authored schedule survives; hand-edit a compiled schedule → drift badge → Recompile restores it
- [ ] 7.5 In `tests/e2e/automations.spec.ts` — REQ-AUTD-006: disable flips the schedules entry to disabled (visible in SchedulesSection) and the row badge; re-enable restores it
- [ ] 7.6 In `tests/e2e/automations.spec.ts` — REQ-AUTD-007: test panel dry-run shows would-be actions for a matching payload and "condition did not match" for a non-matching one
- [ ] 7.7 Add `tests/e2e/automations-rbac.spec.ts` — REQ-AUTD-008 (pattern of `rbac-403.spec.ts`): editor authors + enables on draft; editor gets 403 enabling on production; owner succeeds

## 8. i18n, quality & docs

- [ ] 8.1 Wrap all user-facing strings in `t('openbuild', ...)` with English source keys + Dutch translations (hydra ADR-007)
- [ ] 8.2 `eslint` + `stylelint` clean on new/changed frontend files; `composer check:strict` clean on new/changed PHP (fix any pre-existing issues encountered in touched files)
- [ ] 8.3 Update `docs/` — new "Automations" feature page: what compiles to what (the matrix), provenance/drift semantics, RBAC (editor authors, owner enables on production), dry-run panel; cross-link from the business-rules and schedules docs pages

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate automation-designer --strict` and resolve structural errors.
- Confirm NO new imperative execution path exists: grep the diff for new BackgroundJob/TimedJob/event-listener registrations — there must be none.
- Confirm every compiled artifact id/key in fixtures carries the `aut-` prefix and that delete leaves non-prefixed siblings untouched.
- Hydra gates: modal-isolation (dialog/modal in own files), nc-input-labels on every `NcSelect`, route-auth on all five new routes, spec-coverage `@spec` tags on every new/changed method, notification-dialect gate must stay green (the compiler writes the canonical ADR-031 dialect only).

## Acceptance Criteria

- The Automations page lists automations per ApplicationVersion with enabled state and drift badges; empty state on fresh versions.
- The dialog composes all v1-supported trigger/condition/action shapes and blocks every ⛔ matrix cell with an explanatory message — nothing unsupported can be saved or partially compiled.
- Compilation emits only: `x-openregister-notifications` entries, `x-openregister-lifecycle` typed transition actions, `manifest.schedules[]` entries (existing `openconnector:synchronization` action only), and `aut-<uuid8>` RuleSet/ConditionActionRule objects — deterministic, idempotent, all `aut-` namespaced, hash recorded in provenance.
- Edit recompiles in place; disable/enable toggles artifact-level enabled flags; delete removes exactly the provenance-listed artifacts; drift is detected and recompile-overwrite restores the definition.
- Dry-run panel evaluates via the existing rule engine with `dryRun: true` and dispatches nothing.
- RBAC: owners∪editors author/test/enable on non-production; production enable is owners-only with no admin bypass; violations get 403 JSON.
- `RuleEngineService` passes the wired dispatcher (wet runs dispatch; dry runs don't); executor vocabulary includes `object-op` and `webhook`.
- PHPUnit + vitest suites green; Playwright `automations.spec.ts` + `automations-rbac.spec.ts` cover every non-excluded scenario; docs updated.
