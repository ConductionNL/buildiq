---
kind: code
depends_on:
  - schedules-editor   # writes manifest `schedules[]` entries and reuses its validator
---

## Why

OpenBuild's automation story is declarative-only (hydra ADR-031) and today it
is **fragmented across four disconnected surfaces**, all verified against
HEAD:

- **Schema lifecycle state machines** — `x-openregister-lifecycle` authored by
  `src/components/schema-editor/LifecycleEditor.vue`, whose transitions carry
  typed action records from a fixed enum (`audit-event-emit`,
  `notification-send`, `related-object-upsert`, `related-object-archive`,
  `webhook-dispatch`).
- **Schema notifications** — the `x-openregister-notifications` dialect
  (ADR-031), e.g. `lib/Settings/register.d/10-business-rules.json:153`. The
  authoring UI (`NotificationEditor.vue`) is a **read-only v1 stub**; the only
  write path is hand-editing schema JSON.
- **Scheduled tasks** — the manifest `schedules[]` block (apphost reconciler →
  OpenConnector jobs); the `SchedulesSection.vue` editor from the
  `schedules-editor` change is applied on this branch.
- **Business rules** — the DMN/FEEL engine (`RulesController`,
  `RuleEngineService`, `ConditionActionExecutor`, `DecisionTableEditor`,
  `RuleSetTestSandboxModal`) with FEEL conditions, typed actions, dry-run and
  a `RuleExecutionLog` audit trail.

What is missing is the single **"when X happens, do Y"** surface every
competitor (Zapier, Power Automate, Mendix workflows, n8n's own UI) leads
with. A citizen developer who wants "when a permit application is created,
notify the case-worker group" must today know which of four dialects to
hand-author and where each one lives. The primitives all exist; the product
gap is purely one of composition and discoverability.

There is also a verified pre-existing defect this change absorbs:
`lib/Service/RuleEngineService.php:142` calls
`ConditionActionExecutor::execute()` **without the `$dispatcher` callable**
its own docblock promises to wire, so `send-notification` / `start-workflow` /
`call-rule-set` actions silently no-op even in wet (non-dry-run) evaluations.

## What Changes

- **NEW "Automations" surface** — `src/views/AutomationsPage.vue` (registered
  in `src/registry.js`, routed via a new `src/manifest.d/40-automations.json`
  fragment, mirroring the business-rules page), listing automations **per
  ApplicationVersion** with enable/disable, status and drift badges;
  `src/dialogs/AutomationEditDialog.vue` composing TRIGGER (object
  created/updated/deleted, lifecycle transition on a chosen schema, cron
  schedule, or manual) + optional CONDITION (FEEL expression subset or
  rule-set reference) + ACTIONS (send notification, run OpenConnector
  synchronization/job, object-op create/update, webhook POST);
  `src/modals/AutomationTestPanelModal.vue` — a dry-run sandbox mirroring
  `RuleSetTestSandboxModal.vue`.
- **NEW `Automation` OR schema** — `lib/Settings/register.d/40-automations.json`
  (ADR-037 fragment on the shared `openbuild` register). One automation is one
  declarative object: trigger, condition, actions, `enabled`, version scoping
  (`applicationSlug` + `versionUuid`) and a `provenance` block naming every
  compiled artifact, so it can be listed/edited/disabled/deleted **as one
  unit**.
- **NEW `AutomationCompilerService`** — deterministically **compiles** an
  automation to the existing declarative primitives and nothing else:
  `x-openregister-notifications` entries, `x-openregister-lifecycle`
  transition actions, manifest `schedules[]` entries, and namespaced
  RuleSet/ConditionActionRule objects evaluated by the **existing**
  `RuleEngineService`. Compilation is idempotent (upsert by `aut-`-prefixed
  ids/keys), reversible (delete removes exactly the provenance-listed
  artifacts) and drift-detected (content hash). **No new imperative engine is
  introduced in openbuild.**
- **NEW `AutomationsController`** — thin, value-adding routes only (CRUD stays
  on OR REST per ADR-022): `compile`, `enable`, `disable`, `dry-run`, `status`.
  RBAC via the existing `PermissionResolver`: owners∪editors author and
  dry-run; enabling on the **production** version is owners-only (no admin
  bypass, mirroring REQ-OBVP-007).
- **FIX + EXTEND the rules action path** — wire the promised-but-unwired
  `ActionDispatcher` into `RuleEngineService` (new
  `lib/Service/RuleActionDispatcher.php`) and extend the executor's **typed
  declarative action vocabulary** with `object-op` (via OR `ObjectService`)
  and `webhook` (POST via an OpenConnector-materialised call config). This is
  the documented ADR-031 §Exceptions code path being completed, not a new
  engine.
- **Explicit v1 compilation matrix** — trigger/action combinations that no
  existing primitive can express (e.g. object-created + object-op, conditions
  on plain object-event triggers, approval-step/human-task actions) are
  **blocked fail-closed in the designer with a clear message** and documented
  as follow-ups in design.md. Nothing is stubbed or silently dropped.

### Capabilities

- **ADDED** `automation-designer` — the unified trigger→condition→actions
  authoring surface that compiles to OpenBuild's existing declarative
  automation primitives.

No existing capability is modified structurally: `business-rules-engine`,
`openbuild-schedules-authoring` and `openbuild-schema-designer` are consumed
as compilation targets by composition. The dispatcher wiring corrects
`business-rules-engine` REQ-BRE-006 behaviour to what its spec already
requires (actions of triggered rules actually execute).

## Impact

- **Frontend**: new view + dialog + modal + registry/manifest-fragment
  entries; no changes to existing editors.
- **Backend**: new controller (5 routes), 2 new services, 1 register.d
  fragment, a one-line dispatcher wiring fix in `RuleEngineService`, a small
  clone hook in the version-branch flow of `ApplicationVersionService`.
- **Cross-repo**: none required for v1 — every compiled artifact is a shape
  the deployed reconcilers/dialects already accept (`schedules[]` entries use
  the existing `openconnector:synchronization` action only; notification
  entries use the existing dialect trigger types). Extensions (schedules
  allow-list, dialect condition slot) are follow-ups, not dependencies.
- **Data**: additive schema only; no migration of existing objects.
