## Context

Foundation spec `openbuilt-versioning-model` (spec C) lands the two-object versioning
model (`Application` + `ApplicationVersion`), the `promotesTo` linear chain, the
per-version register, the cycle / back-reference guards, and the deletion endpoint with
its strategy switch. ADR-002 calls out that promotion is **manual for v1**, that the
target is exactly `sourceVersion.promotesTo` (single downstream), and that admins choose
between three data strategies at promotion time:

- `start-with-source-data` — copy rows from source's register into target's; apply
  source's schema set; target's prior rows are replaced.
- `migrate-existing-data` — keep target's rows; apply source's schema set; OR's
  schema-import handles breaking column-level changes.
- `empty-start` — drop target's rows; install source's schema set into target's register.

This spec ships the dialog + the backend endpoint that together realise that flow.
Per ADR-002 §Decision and the locked prompt decisions, schema-diff handling is **deferred
to OR's schema-import / register-merge API** — openbuilt forwards the source's schema set
and trusts OR's breaking-change handling. Concurrency relies on **OR object locking** on
the target ApplicationVersion row — no openbuilt-side lock table. Permission relies on
the existing `permissions.{owners,editors}` resolution on the parent Application —
Nextcloud admins are NOT auto-granted (deliberate constraint).

The call sites for the dialog are deferred: this spec ships
`src/dialogs/PromoteVersionDialog.vue` as a reusable component; the actual "Promote"
button on the version switcher / detail page lives in spec B
(`openbuilt-app-detail-overview`).

## Goals / Non-Goals

**Goals:**

- Ship `POST /api/applications/{appUuid}/versions/{versionUuid}/promote` with a
  `{strategy}` JSON body returning `200 application/json` (the updated target
  ApplicationVersion) on success.
- Encode the three data strategies as a single explicit branch in
  `VersionPromotionService::promote()` with clear pre/post conditions per branch.
- Encode the default-strategy rule (ADR-002 chain-position-based) as a pure function
  callable from both the dialog (UI default) and the service (input validation).
- Acquire OR object lock on the target ApplicationVersion before any data operation;
  return `409 Conflict {code, lockedBy, expiresAt}` on contention; always release on
  success and on failure.
- On any failure inside the strategy step, flip the target's `status` to `archived`
  with a `_self.promotionFailedAt` ISO-8601 timestamp, release the lock, return `500`
  with the captured error payload. **Atomic-ish** — we do not roll back data because the
  source register is untouched and the target is recoverable by re-promotion.
- Forward the source's schema set to OR's schema-import / register-merge API for the
  target register **without** an openbuilt-side dry-run or diff UI.
- Replace the target's `semver` with the source's `semver` at promotion time.
- Ship `PromoteVersionDialog.vue` (`<NcDialog>` per ADR-004 modal-isolation rule)
  with: target name + register name labels, three-strategy radio (default per
  chain-position rule), inline strategy descriptions, destructive type-the-slug
  confirmation gate for `empty-start`.

**Non-Goals:**

- The "Promote" button placement on the detail page / version switcher — spec B.
- The `?version=<slug>` URL routing — spec E.
- The creation wizard — spec F.
- OR's schema-import / register-merge implementation — openregister-side.
- Multi-target promotion (DAG fan-out) — ADR-002 roadmap item, not v1.
- Auto-promotion (cron, event triggers) — ADR-002 roadmap item, not v1.
- Openbuilt-side schema diff / dry-run UI — deferred to OR per Decision 4.
- The post-promotion upstream-source minor-bump — owned by spec C's existing semver
  auto-bump rule.
- Audit-trail aggregation across promotions — OR object time-travel handles it for
  free per ADR-002.

## Decisions

### Decision 1 — Single downstream target via `sourceVersion.promotesTo`

The promotion endpoint resolves the target as exactly `sourceVersion.promotesTo`. If
`promotesTo` is null the endpoint returns `422` with `{code: "no_promote_target"}`
and the dialog's "Promote" entry point is disabled / hidden by the calling surface
(spec B's responsibility). There is **no multi-target chooser** — the chain model is
linear in v1 (ADR-002 §Decision).

**Why:** the linear chain is the locked model for v1. Branching DAGs are a roadmap
item; the API contract here matches the data model exactly.

**Alternatives considered:** Letting the admin name an arbitrary target —
rejected (breaks the chain semantics; would require additional cycle prevention; UI
becomes a graph editor).

### Decision 2 — Three data strategies, encoded as an explicit switch in the service

`VersionPromotionService::promote()` accepts `$strategy ∈ {start-with-source-data,
migrate-existing-data, empty-start}` and branches on it:

| Strategy | Target rows | Target schemas | Use case |
| --- | --- | --- | --- |
| `start-with-source-data` | Replaced with copies of source's rows | Source's schema set imported into target's register | Iterating; "the test data IS the new shape of prod data" |
| `migrate-existing-data` | Kept; column-level changes driven by OR's schema-import | Source's schema set imported into target's register | Production upgrades where target's data must survive |
| `empty-start` | Dropped; target register left empty | Source's schema set imported into target's register | Redesigns where target's data is intentionally reset |

All three branches end with `manifest`, `semver`, and the schema-set state taken from
the source. The data-row treatment is the only axis the strategies differ on.

**Why imperative (ADR-031 §Exceptions, cross-system glue + lifecycle guard):** the three
operations are coarse-grained, cross-register, and require sequencing (lock → wipe? →
schema-import → copy? → write back manifest + semver → release lock). OR's
`x-openregister-lifecycle` is intended for single-row state transitions firing
single-row side effects; cross-register cascade with conditional data-copy is outside
that vocabulary.

**Alternatives considered:** Express the three strategies as three separate endpoint
methods — rejected (the strategy IS the input; an admin choosing differently between
strategies is one user choice, not three different ops). Express it as a declarative
lifecycle action with branches — rejected (no precedent in OR's lifecycle vocabulary
for cross-register strategy-branching).

### Decision 3 — Default strategy by chain position (pure function)

Per the locked prompt rule, the default strategy depends on whether the target is the
production version of the application:

- If `targetVersion.uuid === application.productionVersion.uuid` (target is the
  terminal/production version): default to `migrate-existing-data` (preserve production
  rows).
- Else (target is mid-chain): default to `start-with-source-data` (assume admin is
  iterating).
- `empty-start` is **never** the default — always opt-in.

This rule is implemented twice — once in PHP as a static helper
`VersionPromotionService::defaultStrategyFor(Application, ApplicationVersion): string`
(callable from the controller for input-validation purposes) and once in JS inside
`PromoteVersionDialog.vue` (to default the radio when mounted). Both implementations
are pure functions of `(targetIsProduction: bool) → strategy`. The PHP version is
unit-tested in `VersionPromotionServiceTest`.

**Why imperative in PHP but unit-testable:** the rule is pure-function and stateless,
but expressing it declaratively (e.g. a calc field on ApplicationVersion) would
require OR to expose the parent Application's `productionVersion` to a calc context
declared on the version row — possible but heavier than a static helper. The pure
function lives in PHP and is mirrored in JS to keep the dialog's default a client-side
concern.

**Alternatives considered:** Always default to `start-with-source-data` — rejected
(unsafe when the target is production). Always default to `migrate-existing-data` —
rejected (over-conservative for mid-chain iteration). Let the dialog leave the radio
unselected — rejected (an admin who clicks Promote shouldn't have to think about a
default).

### Decision 4 — Schema diff handling: DEFER to OR

The promotion endpoint forwards the source's schema set to OR's schema-import /
register-merge API for the target register. **No openbuilt-side diff dialog, no
dry-run, no breaking-change preflight.** Breaking schema changes are expected to have
been authored upstream during the source version's dev cycle, and the admin is expected
to have used OR's own UI / messaging to validate them at edit time. The promotion
endpoint trusts OR's migration handling to drive the outcome (whether that means
column-level data migration, a "breaking change, abort" response, or anything in
between).

**Why:** spec D's surface area is bounded — we ship the strategy switch and the dialog,
not a parallel schema-management UI. Duplicating OR's schema-change tooling in openbuilt
would invite drift between the two; placing schema validation at the **edit** time
(when the admin is in OR's UI) is the right point in the lifecycle.

**Spec language for the requirement:** "The promotion endpoint SHALL invoke OR's
schema-import / register-merge API for the target register with the source's schema
set; OR's own breaking-change handling drives the outcome." The endpoint surfaces OR's
response to the caller — success → continue the strategy step; failure → bubble up
as a `500` with OR's payload preserved.

**Alternatives considered:** Pre-flight diff in openbuilt — rejected on scope and on
DRY (OR already owns this). Block promotion on detected breaking changes — rejected
(the admin's intent expressed by clicking Promote is precisely "I accept the migration
outcome"; blocking adds friction without adding safety).

### Decision 5 — Concurrency via OR object locking on the target version row

`VersionPromotionService::promote()` acquires OR's object lock on the target
ApplicationVersion row before doing any work. The lock is released in a `finally`
block. If the lock is already held by another caller, the endpoint returns
`409 Conflict` with body `{code: "version_locked", lockedBy: "<uid>", expiresAt:
"<ISO-8601>"}`. The lock holder identification (`lockedBy`) and TTL (`expiresAt`) come
from OR's lock metadata — openbuilt does not maintain its own lock model.

**Why imperative + leaning on OR:** the lock is a cross-system concurrency primitive,
not business logic. ADR-022 mandates consuming OR abstractions; the lock is one of them.

**Alternatives considered:** An openbuilt-side lock table — rejected (duplicates OR's
existing primitive and adds a second source of truth). A pessimistic transaction on
the underlying DB — rejected (operations span multiple registers, so DB-level
transactionality is insufficient).

### Decision 6 — Permission: editor or owner on the Application (NC admins NOT auto-granted)

The promotion endpoint resolves the caller's role on the parent Application via the
existing `permissions.{owners,editors}` block (declared in spec C / ADR-005). Viewers,
non-members, and anyone outside the owners + editors sets get `403 Forbidden` with
`{code: "insufficient_permission"}`. **Nextcloud admins are NOT auto-granted** — this
is a deliberate constraint, documented as: admin power applies to Nextcloud-level app
management, not to promoting individual virtual apps. If a Nextcloud admin needs to
promote, they must be added as an owner or editor on the specific Application.

**Why:** the version-promotion action is a per-app operational concern, not a
platform-management concern. Splitting the two authorisation domains keeps NC admin
permissions narrow and forces explicit per-app delegation.

**Alternatives considered:** Auto-grant NC admins — rejected (defeats the
per-application RBAC contract). Require owner-only (not editor) — rejected (editors
already have manifest-edit rights; promotion is a logical extension of editing).

### Decision 7 — Semver: target inherits source's value

On every successful promotion, the target's `semver` is replaced with the source's
`semver` value at the moment of promotion. This applies to **both** production targets
and mid-chain targets — the rule is uniform.

Per ADR-002 §Semver and spec C REQ-OBV-103, the **next** edit on the upstream source
version will patch-bump the source's semver normally (manifest hash diff fires the
auto-bump). The spec C semver auto-bump rule continues to apply post-promotion; this
spec does not introduce a new bump.

The semver replacement is part of the manifest-write step at the end of
`VersionPromotionService::promote()` — the target's `semver` is set explicitly before
the OR save call, so the spec C auto-bump on the target sees an unchanged manifest
hash and does nothing (the source's manifest is byte-identical to the source's manifest
because it IS the source's manifest).

**Why:** clear, uniform, no special case for production-vs-mid-chain. The next-cycle
bump on the upstream source is left to spec C's existing rule, which fires naturally on
the admin's next manifest edit.

**Alternatives considered:** Bump the target's semver on promotion — rejected (the
target now matches the source exactly; replacing the version makes that observable to
consumers). Leave the target's semver alone — rejected (target's manifest is now the
source's manifest; leaving target's semver stale misleads consumers about what they
are running).

### Decision 8 — Atomic-ish on failure: archive the target, log, surface 500

`VersionPromotionService::promote()` runs in a `try { … } catch { … }` block. On any
failure during the schema-import or data-strategy step (e.g. OR's schema-import
returns failure, a register-row copy errors, the manifest save fails), the service:

1. Sets `targetVersion.status = "archived"`.
2. Writes `_self.promotionFailedAt = <ISO-8601 now>` (using OR's metadata-namespaced
   self-keys; exact mechanism depends on what the OR floor exposes, mirroring spec C
   Decision 9's orphan-mark approach).
3. Saves the targetVersion row.
4. Releases the OR object lock (in the `finally` block — always runs).
5. Returns `500 Internal Server Error` with `{code: "promotion_failed", strategy:
   "<chosen>", message: "<captured OR/PHP error>"}`.

The admin is then expected to **manually inspect + recover** — either by re-promoting
once the underlying issue is resolved, or by deleting the archived target via the
deletion endpoint (`?strategy=delete-now` from spec C) and recreating it.

**Why "atomic-ish" not fully atomic:**

- The **source** register is read-only during promotion (the operations copy / import
  from it, never write to it). The source is therefore unchanged on failure regardless
  of where we fail.
- The **target** register may be in a partial state after failure (e.g. some rows
  copied, some not; new schemas imported, but data-copy interrupted). Reverting that
  partial state would require either snapshot-and-restore (heavy) or a full re-import
  of the previous schema set + the previous data (effectively a reverse promotion). Both
  are heavyweight and brittle.
- Instead, we surface the failure clearly (`archived` status + timestamp + log line),
  preserve the source, and trust the admin to re-promote or delete the broken target.
  Re-promotion is idempotent at the user-visible level — running the same strategy
  again replaces the target's state with the source's, regardless of the prior partial
  state.

**Alternatives considered:** Transactional rollback of the target — rejected (cost vs
benefit; the admin can re-promote, which is the same outcome). Leave the target's
status as-is (e.g. `published`) on failure — rejected (misleads consumers into thinking
promotion succeeded). Throw without flipping status — rejected (the target's manifest
is in an inconsistent state and shouldn't show as published).

### Decision 9 — Trigger surface: `src/dialogs/PromoteVersionDialog.vue` (call sites deferred)

The dialog lives at `src/dialogs/PromoteVersionDialog.vue` (NEW, `<NcDialog>`-based per
ADR-004 modal-isolation rule). Props:

- `sourceVersion` — the ApplicationVersion the admin is promoting **from**.
- `targetVersion` — the version pointed at by `sourceVersion.promotesTo`. If null, the
  dialog refuses to mount.

Emits:

- `confirm({strategy: 'start-with-source-data' | 'migrate-existing-data' | 'empty-start'})`
  — fired when the admin clicks Confirm.
- `cancel` — fired on Cancel / dialog close.

The dialog's parent (the version switcher / detail page surface delivered by spec B)
is responsible for opening the dialog, listening to the events, and calling the
backend endpoint. **For spec D the dialog component itself is shipped; the call sites
are deferred to spec B.**

The dialog's body shows:

- A summary line: `Promote <sourceVersion.name> → <targetVersion.name>` with both
  versions' `register` names visible for the admin's reference.
- A three-option radio for the strategy, defaulted per the chain-position rule
  (Decision 3).
- An inline description under each strategy explaining its effect on target data.
- For `empty-start` only: a destructive-confirmation input that asks the admin to
  type the application slug. The Confirm button stays disabled until the typed value
  exactly matches `application.slug`.
- A Cancel button (always enabled).
- A Confirm button (disabled while the destructive-confirmation gate is unmet; enabled
  otherwise).

ADR-004 modal-isolation rule: the dialog is a standalone `.vue` file under
`src/dialogs/`, not inline markup in the parent.

### Seed Data section

Per ADR-001 (org-wide), every register-shipping change documents its seed data. **This
spec ships no register changes and writes no seed data.** Promotion is an admin action
fired at runtime; nothing is seeded at install time.

- No `lib/Repair/*` files are added.
- No entries are added to `lib/Settings/openbuilt_register.json` (the
  `ApplicationVersion` schema is owned by spec C, unchanged here).
- No seed objects are written by this spec.

This is explicit and intentional: promotion is **a verb, not a noun** — there is no
"default promotion" to seed.

### Declarative-vs-imperative decision section

Per ADR-031, every business-logic site is classified.

| Concern | Declarative attempt | Final decision | Rationale |
| --- | --- | --- | --- |
| Data-copy / schema-import / wipe at promotion time | Declare three `on_transition` actions on ApplicationVersion lifecycle | **Imperative** (`VersionPromotionService::promote()`) | ADR-031 §Exceptions: cross-register operations + conditional strategy-branching are outside OR's lifecycle vocabulary (single-row transitions firing deterministic side effects). The three strategies share scaffolding (lock acquisition, schema-import, manifest write, semver replace) but diverge on data treatment — the diverging branches are a natural switch in service code, not a declarative table. |
| Default strategy rule (chain-position-based) | Calc field on ApplicationVersion or on Application | **Imperative** (pure-function helper in PHP, mirrored in JS) | The rule is pure-function and trivially unit-testable. Expressing it declaratively would require OR to expose a cross-row read (target's parent Application's `productionVersion`) inside a calc context declared on the version row — heavier than a static helper and not a current OR capability. The helper IS pure, so the imperative shape carries no hidden state. |
| OR object lock acquisition + release | n/a — locking is a cross-system primitive | **Out of scope** (lean on OR) | Locking is an OR-side concept; openbuilt is just a caller. No declarative-vs-imperative decision applies here per ADR-022 (consume OR abstractions). |
| On-failure target.status flip | `on_save` / `on_transition` action with conditional firing | **Imperative** (try/catch + explicit status write inside the service) | The "flip on failure" decision needs to fire from inside a catch block scoped to the strategy step, not from an OR lifecycle hook that has no visibility into the surrounding try/catch frame. ADR-031 §Exceptions covers this as cross-cutting failure handling. |
| Semver copy from source to target | Calc field on target.semver | **Imperative** (explicit write in service before manifest save) | The copy is a one-shot side effect of a specific action (promotion), not a continuously-derived field. Calc fields are for continuously-derived values. |
| Permission resolution (owner/editor on Application) | Declarative `x-openregister-authorization` on the endpoint | **Declarative** at the contract level (RBAC block on Application owned by spec C / ADR-005) | The auth check is consumed by the controller; the controller calls an already-existing helper that reads `permissions.{owners,editors}` declaratively. No new imperative auth code in this spec. |

## Risks / Trade-offs

- **Risk: OR's schema-import / register-merge API returns failure mid-promotion,
  leaving the target's schema set in a partial state.** → Mitigation: the failure
  triggers the on-failure status flip (Decision 8); the target is marked `archived`
  with a timestamp so the admin can identify and recover. The source register is
  untouched; re-promotion after fixing the upstream issue is the prescribed recovery.
- **Risk: Concurrent promote requests on the same target version race on the OR lock
  acquisition.** → Mitigation: OR's lock primitive is the single source of truth; the
  second caller deterministically gets `409 Conflict` with `lockedBy` + `expiresAt` so
  the UI can communicate the contention. No openbuilt-side locking.
- **Risk: `empty-start` is irreversible — once the target's rows are wiped, the admin
  cannot recover them.** → Mitigation: the destructive-confirmation gate in the dialog
  (type the app slug) raises the friction. The dialog's inline description for
  `empty-start` explicitly labels it as destructive. Backend-side: re-promotion with
  `start-with-source-data` or `migrate-existing-data` is the recovery path if the
  source still has the desired data shape; otherwise OR object time-travel on the
  target's previous state is the last-resort recovery.
- **Risk: Schema breaking changes propagate to production via `migrate-existing-data`
  without admin awareness, because openbuilt does not pre-flight the diff.** →
  Mitigation: ADR-002 records the decision — schema validation belongs at edit time,
  inside OR's UI. OR's schema-import / register-merge surfaces breaking-change warnings
  / failures at promotion time; the admin sees those in the endpoint's response and
  can decide. The deferred-to-OR boundary is explicit; failures bubble up as `500`s
  with OR's payload preserved.
- **Risk: The default-strategy rule's pure function drifts between PHP and JS over
  time.** → Mitigation: both implementations are pure functions of
  `(targetIsProduction: bool) → strategy` with a small, fixed truth table; unit tests
  on the PHP side and an explicit Playwright test asserting the dialog defaults
  correctly per chain-position are part of tasks.md. Future-proofing: keep the rule
  named the same (`defaultStrategyFor`) in both languages and add the unit-test
  cross-check as a Newman+Playwright pairing.
- **Trade-off: Atomic-ish (not fully atomic) on the target register.** → Per Decision
  8, full atomicity would require snapshot-and-restore on the target register, which
  is heavyweight; the source-untouched + re-promotable-idempotent + clear-failure-flag
  combination is a deliberate simpler design.
- **Trade-off: NC admins are NOT auto-granted promotion rights.** → Per Decision 6,
  this is a feature, not a bug; it forces explicit per-app delegation. Documented in
  the README of the version-promotion capability so admins setting up a new app know
  they need to add themselves to `permissions.owners` (or `permissions.editors`) on
  the Application.
- **Trade-off: Single-downstream target only.** → Per Decision 1 / ADR-002, branching
  DAG promotion is a roadmap item. The current API (`POST .../promote` with no target
  parameter) reads naturally for the linear case; a future DAG extension would add a
  `targetVersionUuid` body field and the controller would resolve that against the
  source's `promotesTo[]` set — a clean additive extension without breaking v1
  callers.

## Open Questions

None — the locked decisions in the prompt cover every architectural axis. Implementation
details (exact OR API for object-locking acquisition, exact mechanism for the
`_self.promotionFailedAt` flag, exact schema-import call shape) will surface during
apply and are tracked in tasks.md. Genuine ambiguities surfaced during artifact
generation are listed in the `DEFERRED_QUESTIONS` block at the end of this delivery.
