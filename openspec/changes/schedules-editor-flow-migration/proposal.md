---
kind: code
---

## Why

`schedules-editor` (shipped, `feat(editor): visual editor for manifest schedules[] (apphost-scheduling)`, commit `0b6eddc3`) added the Schedules section of the page designer. Its "run a synchronization" action reads and writes the **classic OpenConnector ingestion dialect** directly:

- `ScheduleEditDialog.vue` calls `GET /apps/openregister/api/objects/openconnector/synchronization?limit=500` to populate the synchronization picker (`fetchSynchronizations()`), and writes `action: "openconnector:synchronization"` + `arguments.synchronizationId` into `manifest.schedules[]`.
- `src/services/manifestValidation/schedules.js` hard-codes `SCHEDULE_ACTIONS = ['openconnector:synchronization']` and requires `arguments.synchronizationId` for that action.
- `src/components/SchedulesSection.vue` branches display logic on the same literal action string.

This is exactly the dialect the fleet is retiring: classic Source/Mapping/Synchronization/Job objects stored in OpenRegister's `openconnector` register, in favour of OpenRegister's native Flow engine (ADR-065). The fleet-wide retirement decision is being recorded in parallel as hydra change `adr-092-openconnector-dialect-retirement` — **note the number**: an earlier attempt at this ADR collided with ADR-091, which was independently claimed by "Externally-Authenticated API Surface Belongs to OpenConnector" (merged into hydra `development` 2026-08-16, originally drafted as ADR-085). The dialect-retirement ADR was renumbered to **ADR-092** as a result; the stale `docs/adr-091-openconnector-dialect-retirement` branch should be treated as superseded. Cite the change by name (`adr-092-openconnector-dialect-retirement`) rather than a bare number until it merges — ADR numbers in this fleet have already moved once during the scoping of this very change.

The retirement's policy target is 2026-08-31, but the actual cutover for any consumer — including this one — is gated on OpenRegister's `flow-sync-decomposition` openspec change (openregister repo, `openspec/changes/flow-sync-decomposition`) landing a real implementation. That change is itself still proposal-stage (a `proposal.md` + `design.md`, no `tasks.md` yet) as of 2026-08-16. It decomposes the monolithic `synchronization-run` flow node into addressable, idempotent contributed nodes (`openconnector.source-paginate`, `openconnector.change-detect`, `openconnector.contract-resolve`, `openconnector.contract-write`) plus a first-class iteration construct. Until that lands, a Flow cannot express "the thing a classic Synchronization object does" as a single, listable, addressable unit — so there is nothing yet for a Flow-native schedule picker to point at.

**A second, adjacent surface exists.** `src/dialogs/AutomationEditDialog.vue` (unrelated to `schedules-editor` — it belongs to the older Automations page, `AutomationsPage.vue`) independently duplicates the identical call: `GET /apps/openregister/api/objects/openconnector/synchronization`, its own `fetchSynchronizations()`. It was not part of the `schedules-editor` change and is not touched here, but it carries the same migration debt and should not be forgotten when the unblocked work starts.

This change does **not** attempt the migration. Classic-dialect Synchronization objects and the ADR-092/flow-sync-decomposition primitives both still need to exist side-by-side in production until the cutover, and flow-sync-decomposition has not shipped a single node yet. This change scopes and stages the migration so it is ready to pick up the moment the blocking dependency lands, and puts a visible marker in this app's openspec that `schedules-editor`'s "run a synchronization" action is dialect debt, not a finished design.

## What Changes

- **Nothing in code.** No `.vue`, `.js`, or schema file changes ship with this proposal.
- **New openspec change** (`schedules-editor-flow-migration`) recording: the exact classic-dialect call sites in scope, the Flow-native target shape once it exists, the open design questions that block writing tasks with real substance, and the explicit BLOCKED gate.
- **Capabilities:** none — this change modifies no shipped capability. It exists to hold a plan; `tasks.md` capture the audit + design work that can happen now, and the go/no-go gate for the work that cannot.

## Capabilities

### New Capabilities
<!-- None. This change ships no code and defines no new capability. -->

### Modified Capabilities
<!-- None. schedules-editor's shipped capability (the Schedules section) is unchanged; this change only records the plan to migrate its "run a synchronization" action later. -->

## Impact

- **Blocked on:** openregister `flow-sync-decomposition` (no `tasks.md` yet — implementation has not started) landing the decomposed nodes and a stable, listable "flow that replaces a synchronization" primitive.
- **Blocked on:** hydra `adr-092-openconnector-dialect-retirement` merging, so the fleet-wide policy this change cites has a stable ADR number (superseding the abandoned ADR-091 attempt).
- **Files that will eventually change** (not touched by this proposal): `src/dialogs/ScheduleEditDialog.vue`, `src/components/SchedulesSection.vue`, `src/services/manifestValidation/schedules.js`, and — as a related but separately-scoped surface — `src/dialogs/AutomationEditDialog.vue`.
- **No RBAC, schema, or i18n impact** in this change — it is planning-only.
