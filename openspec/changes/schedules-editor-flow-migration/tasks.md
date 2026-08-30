## 1. Audit — classic-dialect call sites (do now)

- [x] 1.1 Confirm the exact `schedules-editor` call sites reading/writing the classic OpenConnector dialect (re-grepped 2026-08-16, files may move — re-verify before starting section 3):
  - `src/dialogs/ScheduleEditDialog.vue:449` — `fetchSynchronizations()`, `GET /apps/openregister/api/objects/openconnector/synchronization`
  - `src/dialogs/ScheduleEditDialog.vue:272,297` — writes `action: "openconnector:synchronization"`
  - `src/components/SchedulesSection.vue:174` — branches display on `schedule.action === 'openconnector:synchronization'`
  - `src/services/manifestValidation/schedules.js:24,115` — `SCHEDULE_ACTIONS = ['openconnector:synchronization']`, requires `arguments.synchronizationId`
- [ ] 1.2 Decide (with whoever owns Automations) whether `src/dialogs/AutomationEditDialog.vue` (own, independent `fetchSynchronizations()` at line 929, same endpoint) is folded into this change's scope or tracked as a separate migration — it is NOT part of `schedules-editor` and is not assumed in-scope here.
- [ ] 1.3 Re-run the grep for `openconnector/synchronization` and `openconnector:synchronization` across `src/` immediately before starting section 3 work — this app moves fast and file locations/line numbers above will drift.

## 2. Define the Flow-native target shape (research now, cannot finalize until section 4 unblocks)

- [ ] 2.1 Once OpenRegister's `flow-sync-decomposition` design solidifies, confirm what a "list the Flows a schedule can target" endpoint looks like — likely `GET /apps/openregister/api/objects/<register>/flow` (or a dedicated flow-listing route); today no such stable, addressable "flow that replaces a synchronization" exists to query.
- [ ] 2.2 Decide the manifest-shape equivalent of `action: "openconnector:synchronization"` + `arguments.synchronizationId` — candidate: `action: "openregister:flow"` + `arguments.flowId`, but naming should follow whatever vocabulary hydra `adr-092-openconnector-dialect-retirement` settles on fleet-wide, not be invented locally.
- [ ] 2.3 Answer the paradigm question before writing UI tasks: does scheduling stay "pick one thing to run" (same `NcSelect` + picker shape, only the data source and action literal change — i.e. `AutomationEditDialog.vue` and `ScheduleEditDialog.vue` need NOT change structurally), or does a Flow target expose parameters/inputs that a classic Synchronization never had (in which case the dialog needs a new input-mapping sub-UI, not just a swapped picker)? This determines whether section 4 is a small diff or a redesign.
- [ ] 2.4 If 1.2 concluded `AutomationEditDialog.vue` is in-scope, decide whether its `fetchSynchronizations()` and `ScheduleEditDialog.vue`'s copy should be deduplicated into one shared composable/service at migration time (both are currently independent copies of the same call).
- [ ] 2.5 Confirm with the schedules-editor design (`openspec/changes/schedules-editor/design.md`) whether the `arguments` bag's shape assumptions (single scalar id) hold for a Flow target, or whether validation (`schedules.js`) needs a more general "action-specific argument shape" contract instead of one hard-coded field per action.

## 3. BLOCKED — do not start until both land

- [ ] 3.1 **Gate:** OpenRegister `flow-sync-decomposition` ships the decomposed nodes (`openconnector.source-paginate`, `openconnector.change-detect`, `openconnector.contract-resolve`, `openconnector.contract-write`) AND a stable way to list/address "the flow that used to be a synchronization." Check `openregister/openspec/changes/flow-sync-decomposition/tasks.md` exists and has entries checked before treating this as unblocked — as of 2026-08-16 that change has no `tasks.md` at all (implementation not started).
- [ ] 3.2 **Gate:** hydra `adr-092-openconnector-dialect-retirement` is merged, giving the fleet a stable ADR number and canonical vocabulary to cite in shipped code/docs (not the abandoned ADR-091 attempt, not an invented placeholder).
- [ ] 3.3 Re-confirm the 2026-08-31 policy target is still live once 3.1/3.2 resolve — a decomposition change with no `tasks.md` yet on 2026-08-16 makes that date tight; if it slips, this change's own timeline slips with it and should say so rather than silently miss the date.

## 4. Implementation (placeholder — do not start; write real tasks only once section 3 is unblocked)

- [ ] 4.1 Replace `fetchSynchronizations()` with the Flow-native equivalent decided in 2.1/2.2, in every file identified by section 1 (re-audited per 1.3).
- [ ] 4.2 Update `schedules.js` validation to the new action literal + argument shape from 2.2/2.5.
- [ ] 4.3 Update `SchedulesSection.vue` display branching to the new action literal.
- [ ] 4.4 If 2.4 concluded a shared composable is warranted, extract it before or alongside the `AutomationEditDialog.vue` change.
- [ ] 4.5 Data migration for any already-persisted `manifest.schedules[]` entries carrying `action: "openconnector:synchronization"` — needs its own plan (in-place rewrite vs. dual-read fallback) once the target shape is final; not assumed here.
- [ ] 4.6 Update Vitest specs (`tests/components/SchedulesSection.spec.js`, `tests/services/schedulesValidation.spec.js`) for the new action/argument shape.

## Acceptance Criteria (for this planning change only)

- The classic-dialect call sites listed in section 1 are accurate as of the audit date.
- The blocking dependencies (openregister `flow-sync-decomposition`, hydra `adr-092-openconnector-dialect-retirement`) are named precisely, including the ADR-091 numbering collision, so a future reader does not cite a stale number.
- No `.vue`, `.js`, or schema files change as part of this proposal — section 4 stays unstarted placeholders until section 3's gates clear.
