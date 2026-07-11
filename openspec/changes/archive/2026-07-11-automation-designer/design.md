# Design — automation-designer

## Context

OpenBuild owns four declarative automation primitives; the designer composes
them behind one surface. Everything below is verified against HEAD in this
worktree.

### Ground-truth architecture (verified)

- **Notifications dialect** — `x-openregister-notifications` is a keyed map of
  entry-id → `{ trigger: {type, action?}, enabled, channels[], recipients[],
  subject: {nl, en} }` (live example:
  `lib/Settings/register.d/10-business-rules.json:153-190`, entries
  `ruleset-activated` / `ruleset-archived`, `trigger.type: "transition"`,
  channel `nc-notification`, recipient kind `object-acl`).
  `src/views/SchemaDesigner.vue:578,620` round-trips the block;
  `src/components/schema-editor/NotificationEditor.vue` is a **read-only v1
  stub** ("notifications slice deferred to v1.1"). The automation compiler is
  therefore the **first write surface** for this dialect and does not depend
  on the v1.1 editor.
- **Lifecycle dialect** — `x-openregister-lifecycle` transitions carry typed
  action records from a fixed enum
  (`src/components/schema-editor/LifecycleEditor.vue:176-182`):
  `audit-event-emit`, `notification-send`, `related-object-upsert`,
  `related-object-archive`, `webhook-dispatch`. This means **object-op and
  webhook actions are already declaratively expressible on a lifecycle
  transition** — no new primitive needed for that trigger.
- **Schedules** — manifest `schedules[]` entries
  (`{id, enabled, interval|cron, action: "openconnector:synchronization",
  arguments: {synchronizationId}}`) are authored by
  `src/components/SchedulesSection.vue`, validated by
  `src/services/manifestValidation/schedules.js` (wired in
  `src/composables/useManifestValidator.js:30`), persisted by the existing
  ApplicationVersion PUT in `PageDesignerHost.vue`, and reconciled into
  OpenConnector jobs by the OR AppHost (no schedules code in openbuild
  `lib/` — confirmed by grep). The allow-listed action today is exactly
  `openconnector:synchronization`.
- **Rules engine** — `lib/Service/RuleEngineService.php` loads a RuleSet
  bundle by slug, evaluates DecisionTables / ConditionActionRules, supports
  `dryRun`, persists a `RuleExecutionLog` (AVG art. 22), enforces a 500 ms
  soft timeout and PII masking. `ConditionActionExecutor` evaluates FEEL
  conditions via `ExpressionEvaluator`/`FeelParser` and runs typed actions
  (`set-veld`, `send-notification`, `start-workflow`, `call-rule-set`) with an
  **optional `$dispatcher` callable** for side effects. **Verified defect:**
  `RuleEngineService.php:142` never passes a dispatcher, so side-effecting
  actions no-op even in wet runs — the docblock ("the RuleEngineService wires
  the live dispatcher") is currently false. Routes:
  `appinfo/routes.php:178-180` (`rules#evaluate|schema|testAll`).
  `src/modals/RuleSetTestSandboxModal.vue` is the test-panel pattern to
  mirror.
- **RBAC** — `lib/Service/PermissionResolver.php` is the single grammar
  (`user:`/`group:` principals, role buckets). `VersionPromotionController`
  uses `WRITE_ROLES = ['owners','editors']` and `allowAdminBypass: false` for
  promotion (REQ-OBVP-007). `openbuild-rbac` spec defines the
  owner|editor|viewer buckets on `Application.permissions`.
- **Version scoping** — manifests are per-`ApplicationVersion`
  (`promotesTo` chains, cycle guard in `ApplicationVersionService.php:269`);
  each version owns its register copy, so schema-dialect edits
  (notifications, lifecycle) are naturally version-scoped.
- **Extension points** — OR schemas ship as `lib/Settings/register.d/NN-*.json`
  fragments (ADR-037, deep-merged by `SettingsService`); OpenBuild's own UI
  pages ship as `src/manifest.d/NN-*.json` fragments + `src/registry.js`
  entries (ADR-036) — see `20-business-rules.json` → `RuleSetsPageView`.

## Goals / Non-Goals

**Goals**

- One surface where an editor composes trigger + optional condition + actions
  and gets a working automation without knowing which dialect executes it.
- Each automation is **one declarative object** with provenance: list, edit,
  enable/disable, dry-run and delete as a unit.
- Compilation is **deterministic, idempotent and reversible** — same input
  always produces the same artifacts; delete removes exactly what compile
  created; hand-edits to compiled artifacts are detected as drift.
- **No new imperative engine in openbuild** (ADR-031). Execution stays where
  it already lives: OR dialect handlers, the AppHost schedules reconciler /
  OpenConnector, and the existing `RuleEngineService`.
- Fix the pre-existing unwired-dispatcher defect so rules actions actually
  fire (spec REQ-BRE-006 already requires this).

**Non-Goals**

- **Approval-step / human-task action** — see Decision 8: deferred, needs a
  new OR primitive.
- No changes to the AppHost reconciler, the OR dialect handlers, or
  OpenConnector internals.
- No new manifest schema keys and no nextcloud-vue changes (v1 emits only
  shapes the deployed validators/reconcilers already accept).
- Not replacing the specialist editors (LifecycleEditor, SchedulesSection,
  DecisionTableEditor) — they remain the power-user surfaces for their
  dialects.
- No DecisionTable authoring inside the designer; a condition may *reference*
  an existing rule set, not define tables.

## Decisions

### Decision 1: Automation is a stored declarative object, compiled on demand

A new `automation` schema (shared `openbuild` register, ADR-037 fragment
`lib/Settings/register.d/40-automations.json`) is the **source of truth**:

```json
{
  "slug": "notify-caseworkers-on-permit",
  "name": "Notify case-workers on new permit",
  "applicationSlug": "permit-tracker",
  "versionUuid": "…",
  "enabled": true,
  "trigger": { "type": "object-created", "schema": "permit" },
  "condition": null,
  "actions": [
    { "type": "send-notification", "channels": ["nc-notification"],
      "recipients": [{ "kind": "object-acl", "permission": "manage" }],
      "subject": { "en": "New permit {{title}}", "nl": "Nieuwe vergunning {{title}}" } }
  ],
  "provenance": {
    "notificationKeys": [{ "schema": "permit", "key": "aut-notify-caseworkers-on-permit-1" }],
    "lifecycleActions": [], "scheduleIds": [], "ruleSetSlug": null,
    "openconnectorObjects": [], "compiledHash": "sha256:…"
  }
}
```

Trigger types: `object-created` | `object-updated` | `object-deleted` |
`lifecycle-transition` (schema + transition action name) | `schedule`
(interval | 5-field cron, reusing the schedules-editor cadence UX) |
`manual`. Condition: `{type: "feel", expression}` or
`{type: "rule-set", ruleSetSlug}` or null. Actions: typed records only —
`send-notification`, `run-synchronization` (OpenConnector), `object-op`
(create/update on a schema, field mapping with `{{payload}}` placeholders),
`webhook` (POST url + payload template). Property names are English (new
schema; the Dutch field names of the pre-existing rules schemas are
untouched).

The designer always renders **from the automation object**, never by parsing
compiled artifacts — that is what makes compilation reversible in practice:
the object is the decompiled form.

### Decision 2: v1 compilation matrix — existing primitives only, fail closed

| Trigger ↓ / Action → | send-notification | run-synchronization | object-op | webhook |
|---|---|---|---|---|
| object created/updated/deleted | ✅ notifications dialect entry | ⛔ v1.1 | ⛔ v1.1 | ⛔ v1.1 |
| lifecycle-transition | ✅ notifications dialect entry (`trigger: {type: "transition", action}`) | ⛔ v1.1 | ✅ lifecycle `related-object-upsert` action | ✅ lifecycle `webhook-dispatch` action |
| schedule | ⛔ v1.1 | ✅ `schedules[]` entry (`openconnector:synchronization`) | ⛔ v1.1 | ⛔ v1.1 |
| manual | ✅ rules backend | ✅ rules backend | ✅ rules backend | ✅ rules backend |

**Conditions:** v1 supports a condition only on the **manual** trigger (the
rules backend is the only existing primitive that evaluates FEEL). The
notifications/lifecycle dialects carry no condition slot (verified — no
`condition` key in the dialect example or `LifecycleEditor` payloads) and
`schedules[]` has none either. A ⛔ cell or an unsupported condition is
**blocked in the editor and at compile time with an explicit message**
("This trigger/action combination is not yet expressible declaratively") —
never silently dropped, never stubbed. The ⛔ cells become expressible when
OR grows an event→rules bridge / dialect condition slot and the `schedules[]`
allow-list gains an `openbuild:rule-set` action; those are documented
follow-ups (issues filed at apply time is not a task — the *code* renders the
matrix from one shared constant so lighting up a cell later is data, not
rework).

Rationale for the strictest cells: nothing in the deployed stack invokes the
rules engine on OR object events or on a cron (grep: no schedules handling in
openbuild `lib/`, `RuleExecutionLog.triggerContext` is only ever `'api'`).
Bridging that gap inside openbuild would mean a listener/TimedJob evaluating
automations — exactly the new imperative engine ADR-031 forbids here.

### Decision 3: Three compilation backends, one compiler

`AutomationCompilerService::compile(Automation): CompiledPlan` maps the
matrix's ✅ cells:

1. **Dialect backend** — event/transition triggers with `send-notification`
   compile to `x-openregister-notifications` entries on the target schema of
   the automation's version register, keyed `aut-<slug>-<n>`. Object-event
   triggers map to dialect `trigger.type: created|updated|deleted`;
   transition triggers to `{type: "transition", action}` (the shape verified
   in `10-business-rules.json`). Lifecycle-transition triggers with
   `object-op`/`webhook` actions compile to typed
   `related-object-upsert`/`webhook-dispatch` records appended to the named
   transition's `actions[]` — tagged with an `aut-<slug>` marker field so
   they are distinguishable from hand-authored actions.
2. **Schedules backend** — `schedule` trigger + `run-synchronization`
   compiles to a `manifest.schedules[]` entry with `id: aut-<slug>-<n>`,
   validated by the existing `schedules.js` validator and persisted via the
   existing ApplicationVersion manifest PUT. No allow-list extension in v1.
3. **Rules backend** — `manual` trigger compiles condition + actions to a
   namespaced RuleSet (`slug: aut-<uuid8>`, `ruleType: condition-action`,
   `eigenaarApp: <applicationSlug>`) plus one ConditionActionRule whose
   `conditie` is the FEEL expression (or a `call-rule-set` action referencing
   the chosen rule set) and whose `acties` are the mapped typed actions.
   "Run now" and the test panel invoke the **existing**
   `POST /api/rules/{slug}/evaluate` machinery via the automations
   controller (dry-run flag passthrough). Execution, audit
   (`RuleExecutionLog`), timeout and PII masking are inherited — zero new
   engine code.

The compiler is pure (returns a plan); a thin `apply()` step upserts the plan
through the real OR `ObjectService` / the ApplicationVersion manifest PUT
(ADR-022 — no new persistence path).

### Decision 4: Provenance, idempotency, reversibility, drift

- Every compiled artifact id/key carries the `aut-<automation-slug>` prefix
  (rule sets use `aut-<uuid8>` since rule-set slugs are global and version
  clones must not collide — see Decision 6).
- `provenance` lists every artifact the last compile produced plus
  `compiledHash` (sha256 of the canonical-JSON compiled plan).
- **Recompile** upserts by id/key and deletes provenance-listed artifacts no
  longer in the plan → idempotent; compiling twice is a no-op.
- **Delete** removes exactly the provenance-listed artifacts, then the
  automation object. Hand-authored dialect entries/schedules (non-`aut-`
  prefixed) are never touched.
- **Drift**: `status` endpoint recomputes the plan hash and compares the live
  artifacts; a hand-edited compiled artifact surfaces a warning badge in the
  list with a "Recompile (overwrite)" action — the automation object always
  wins, keeping compilation deterministic.

### Decision 5: Enable/disable compiles state, artifacts stay in place

`enabled: false` recompiles with every artifact's own enabled switch off
(notification entry `enabled: false`, schedules entry `enabled: false`,
ConditionActionRule `actief: false`; lifecycle actions are removed from
`actions[]` since the dialect has no per-action enabled flag — provenance
retains them for re-enable). Artifacts remain in place and inert, so
re-enabling is a cheap recompile and provenance never dangles.

### Decision 6: Version scoping and clone-on-branch

An automation belongs to one `ApplicationVersion` (`applicationSlug` +
`versionUuid`), consistent with manifests. Dialect and schedule artifacts are
naturally version-scoped (they live in the version's register/manifest and
ride existing version-copy flows). The automation **objects** live in the
shared register, so the version-branch flow in `ApplicationVersionService`
gains one hook: clone the source version's automations to the new version
(new uuids) and recompile them there. Rule-set slugs are keyed off the
automation object uuid (`aut-<uuid8>`), so clones get distinct rule sets and
`promotesTo` chains never share a mutable compiled artifact.

### Decision 7: RBAC — editors author, owners enable on production

- **Author / edit / dry-run / enable on non-production versions**: caller must
  match `['owners','editors']` on the Application via
  `PermissionResolver::matchesCaller()` (same posture as
  `VersionPromotionController::WRITE_ROLES`).
- **Enable (or edit-while-enabled) on the version currently set as the
  Application's production version**: `['owners']` only, with
  `allowAdminBypass: false` — mirroring promotion's REQ-OBVP-007 rationale
  (going live is an ownership act).
- Enforcement lives in `AutomationsController` on the effectual routes
  (`compile`/`enable`/`disable`/`dry-run`) — an uncompiled automation object
  is inert data, so the compile/enable boundary is the security boundary.
  Object CRUD itself stays on OR REST (ADR-022 / redundant-controller gate);
  per the runtime-group-scoped-access precedent, UI affordance-hiding is
  presentation, the controller check is the authority.

### Decision 8: Approval-step (human task) action — deferred follow-up

Every v1 action compiles to a fire-and-forget primitive. An approval step is
different in kind: it needs a durable task object, an assignee, a completion
event that resumes the automation, and escalation — i.e. a **new OR
primitive** (human-task schema + resume bridge), which ADR-031 says must be
designed as a dialect on the OR side first, not improvised in openbuild.
**Decision: out of scope for this change; explicitly a non-goal.** The action
picker does not show it; the design reserves `{type: "approval"}` in the
action vocabulary so the schema does not need a breaking change when the OR
primitive lands.

### Decision 9: Dry-run test panel rides the rules engine for every trigger

`POST /api/automations/{uuid}/dry-run {payload}` compiles the automation
**in-memory to its rules-backend representation** (deterministic for every
matrix cell — dialect-backed actions map 1:1 onto executor action records),
then evaluates with the existing `RuleEngineService` `dryRun: true` path.
The response reuses the executor's shape: condition matched?, would-be
actions (`… (dry-run, skipped)`), errors, duration. This gives one uniform
test surface (mirroring `RuleSetTestSandboxModal`) without ever dispatching
side effects and without touching the dialect handlers.

### Decision 10: Complete the ADR-031 §Exceptions path (dispatcher + vocabulary)

- New `lib/Service/RuleActionDispatcher.php` implementing the
  `fn(string $type, array $params, array $payload)` contract:
  `send-notification` → NC `IManager`; `object-op` →
  `ObjectService::saveObject` (owner-attributed; background contexts reuse
  `JobOwnerImpersonator`); `webhook` → HTTP POST through NC `IClientService`
  against the compiler-materialised target config; `start-workflow` /
  `call-rule-set` keep their documented semantics.
- `RuleEngineService.php:142` passes the dispatcher (fixes the verified
  no-op defect); dry-run continues to suppress dispatch inside the executor,
  unchanged.
- `ConditionActionExecutor::SIDE_EFFECT_ACTIONS` gains `object-op` and
  `webhook` — new **typed declarative records** run by the existing engine,
  which is the sanctioned ADR-031 §Exceptions code path (see the fragment
  `_comment` in `10-business-rules.json:2`), not a new engine.

### ADR-004 compliance

Editor UI is Vue 2.7 + `@nextcloud/vue` primitives (`NcSelect` with
`:input-label`, `NcTextField`, `NcCheckboxRadioSwitch`, `NcModal`,
`NcNoteCard`); the dialog and test panel live in their own files under
`src/dialogs/` / `src/modals/` (modal-isolation gate); state arrives via
props/OR REST, no DOM data-attribute reads; no admin components in the
router.

## Risks / Trade-offs

- **Dialect trigger-type coverage** — only `trigger.type: "transition"` is
  observed in-repo; `created|updated|deleted` are the dialect's documented
  event types (ADR-031) but the deployed OR must handle them. Mitigation:
  compile-time capability probe of the installed OR dialect surface; a
  missing trigger type blocks enable fail-closed with a message (same guard
  pattern as schedules-editor's additive-tolerance rule) rather than
  compiling a dead notification.
- **Restrictive v1 matrix** — event-triggered object-ops/webhooks (the
  Zapier-iest cells) are v1.1. Accepted: shipping them now would require the
  forbidden in-app event engine; the matrix constant makes lighting cells up
  later cheap.
- **Shared-register rule-set namespace** — `aut-` prefixed slugs could
  collide with a hand-authored rule set. Mitigation: `aut-<uuid8>` keying +
  the designer's validator rejects hand-authored `aut-*` slugs in
  DecisionTableEditor flows is *not* added (out of scope); collision odds are
  uuid-grade.
- **Drift races** — a hand edit between compile and status check can be
  overwritten by "Recompile". Accepted and surfaced in the confirm dialog
  ("the automation definition wins").
- **Lifecycle disable semantics** — removing compiled actions on disable (no
  per-action enabled flag) means a hand-diff of the schema shows them
  vanish/reappear; provenance + the `aut-` marker make this auditable.

## Migration Plan

Additive only. No existing objects change shape; apps without automations see
an empty list. The dispatcher wiring changes wet-run behaviour of existing
rule sets from "silently skipped" to "dispatched" — this is the already
specified REQ-BRE-006 behaviour; release notes call it out.

## Open Questions

- Should the Automations page also *import* (adopt) a hand-authored
  notification entry / schedule into an automation object (reverse
  compilation)? Deferred — the deterministic mapping makes it feasible, but
  ownership semantics of adopted artifacts need product input.
- Whether the dry-run panel should persist sample payloads as reusable
  TestCase objects (rules engine already has them). Leaning yes in v1.1 to
  converge the two sandboxes.
