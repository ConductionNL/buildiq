## Context

OpenBuild manifests carry a top-level `schedules[]` array — the
apphost-scheduling capability. Each entry declares a scheduled task (a cadence
plus an action) that a generic OpenRegister AppHost reconciler translates into
an OpenConnector job. The reconciler and the `schedules[]` manifest schema are
already implemented and live-verified. The schema itself ships from
**nextcloud-vue** (PR #132, off `beta`), NOT this repo. What is missing is an
authoring UI: today `schedules[]` is only hand-editable in raw manifest JSON.

This change adds that UI and nothing else — it is a `kind: code` change scoped
to the OpenBuild Vue frontend.

### Ground-truth architecture (verified)

- **Editor host** — `src/views/PageDesignerHost.vue` holds `this.manifest`
  (data field); `onManifestUpdate()` (~line 410) assigns it; `save()`
  (~lines 420–467) PUTs the whole ApplicationVersion manifest to
  `PUT /apps/openregister/api/objects/openbuild/applicationVersion/{versionUuid}`
  (fallback `/application/{uuid}`). The persisted payload is
  `{ ...version, manifest }`, so **any top-level manifest key the editor does
  not touch survives the round-trip**. A schedules editor therefore gets
  persistence for free by mutating `manifest.schedules` and emitting
  `update:manifest` — no new endpoint, no new store, no new save path.
- **Section pattern to mirror** — `src/components/WorkflowAttachmentsSection.vue`
  is a controlled component: `:manifest` prop in, `@update:manifest` out,
  rendering a list with add/edit/detach and hosting a standalone dialog
  (`src/dialogs/WorkflowAttachmentDialog.vue`) per the modal-isolation gate.
  Its siblings mounted in `PageDesignerHost.vue` are `ThemeSection`,
  `WorkflowAttachmentsSection`, `DocumentAttachmentsSection`. The new
  `SchedulesSection.vue` mounts as the 4th such section.
- **Synchronization list** — there is NO OpenConnector index route;
  synchronizations are OR objects. Reuse the pattern in
  `openconnector/src/modals/v2/JobFormFields.vue:341-368`:
  `fetchSynchronizations()` → `GET /apps/openregister/api/objects/openconnector/synchronization?limit=500`,
  mapped to `{ id, label: name || title || id }`. It must degrade gracefully
  (free-text id fallback) when OpenConnector / OR is absent, exactly like
  `src/components/ConnectorSourcePicker.vue`.
- **Form primitives** — editors use plain `@nextcloud/vue` (`NcSelect` with
  `:input-label`, `NcTextField`, `NcCheckboxRadioSwitch`, `NcButton`,
  `NcModal`), NOT the runtime `Cn*` components. House style: see
  `ConnectorSourcePicker.vue`, `AppSettingsModal.vue`, `ThemeSection.vue`.
- **Validation service pattern** — `src/services/manifestValidation/*`
  (`theme.js`, `workflowAttachments.js`, `documentAttachments.js`,
  `connectorDataSource.js`) each export a `validateX(manifest)` used by
  `src/composables/useManifestValidator.js`. Add `schedules.js` there and wire
  it in.
- **Tests** — Vitest (`npm run test`); component specs in `tests/components/`
  (e.g. `WorkflowAttachmentsSection.spec.js`), service specs in
  `tests/services/` (e.g. `workflowAttachmentsValidation.spec.js`).

## Goals / Non-Goals

**Goals**
- A citizen developer can add, edit and remove a scheduled task through the UI
  instead of hand-editing manifest JSON.
- The written manifest entry is valid against the nextcloud-vue `schedules[]`
  schema and stores EITHER `interval` OR `cron` (never both).
- The section degrades gracefully when the synchronization list can't load.

**Non-Goals**
- No changes to the AppHost reconciler or OpenConnector job creation (already
  live).
- No new OpenRegister schema, register fragment, or seed data.
- No new backend service, controller, or route.
- No new save endpoint — persistence rides the existing ApplicationVersion PUT.
- Not authoring the `schedules[]` JSON-schema (that lives in nextcloud-vue #132).

## Decisions

### Decision 1: Cadence UX — friendly presets + Custom cron

A single `NcSelect` "Cadence" offers five presets. Non-custom presets write an
`interval` (seconds); "Custom" reveals a raw 5-field `cron` `NcTextField` with
live validation. The entry stores exactly one of `interval` | `cron`.

| Preset  | Writes                    |
|---------|---------------------------|
| Hourly  | `interval: 3600`          |
| Daily   | `interval: 86400`         |
| Weekly  | `interval: 604800`        |
| Monthly | `interval: 2592000` (30d) |
| Custom  | `cron: "<5-field cron>"`  |

On edit, an existing entry is reverse-mapped: a known interval value selects
its preset; any other `interval` or a `cron` value selects "Custom" (a
non-preset interval is surfaced in an optional number+unit field so it
round-trips). Switching a preset clears any previously-stored `cron`, and
choosing "Custom" clears `interval` — so the one-of invariant holds at write
time, not only at validate time.

### Decision 2: Action = labeled select, one option now

An "Action" `NcSelect` (`:input-label="Action"`) lists action types; today the
only option is **"Run a synchronization"** → `action:
"openconnector:synchronization"`. Below it, a **synchronization picker**
(`NcSelect`) populates `arguments.synchronizationId`. The action is NOT
hardcoded away: future action types add options and (optionally) their own
argument sub-forms. For the sync action the written shape is:

```json
{
  "id": "nightly-brp-sync",
  "enabled": true,
  "interval": 86400,
  "action": "openconnector:synchronization",
  "arguments": { "synchronizationId": "00000000-0000-0000-0000-000000000000" }
}
```

### Decision 3: Synchronization picker degrades gracefully

`fetchSynchronizations()` calls
`GET /apps/openregister/api/objects/openconnector/synchronization?limit=500`
and maps results to `{ id, label }`. On any failure (route 404, network error,
OpenConnector/OR absent) the picker falls back to a plain `NcTextField` where
the developer can type a raw `synchronizationId`. The already-stored id is
preserved and shown either way — mirroring `ConnectorSourcePicker.vue`.

### Decision 4: `enabled` and `id`

- `enabled` — `NcCheckboxRadioSwitch type="switch"`, default `true`.
- `id` — a stable slug, auto-derived from a human label (kebab-case) or typed
  directly in an id `NcTextField`; unique within `manifest.schedules[]`. The
  reconciler uses `id` as the OpenConnector job's stable key, so edits must
  preserve it and adds must not collide.

### Decision 5: Controlled component + free persistence (no new save path)

`SchedulesSection.vue` never calls the API to save. It computes its list from
`manifest.schedules`, and every mutation (add/edit/remove) emits an
`update:manifest` with a shallow-cloned manifest whose `schedules` array is
replaced. `PageDesignerHost` already owns the save; the section is pure
presentation + local edit state. This is the same contract
`WorkflowAttachmentsSection.vue` uses.

### Declarative-vs-imperative decision (hydra ADR-031)

There is **no declarative-backend behaviour in this change**. The
apphost-scheduling reconciler (OR AppHost → OpenConnector jobs) already exists
and is untouched. This change adds only an authoring UI that writes the
already-defined declarative `schedules[]` manifest data. No
`x-openregister-notifications` dialect, no imperative dispatch, no new
service — ADR-031 is satisfied by construction (nothing declarative-backend is
added or changed).

### ADR-004 compliance

The UI is Vue 2.7 + `@nextcloud/vue` primitives only (`NcSelect`,
`NcTextField`, `NcCheckboxRadioSwitch`, `NcButton`, `NcModal`) — no runtime
`Cn*` components in the editor. The dialog lives in its own file under
`src/dialogs/` (modal-isolation gate). Every `NcSelect` carries an
`:input-label` (nc-input-labels gate). No DOM data-attribute reads, no admin
router exposure — none apply to a page-designer section.

## Validation rules (`services/manifestValidation/schedules.js`)

For each entry in `manifest.schedules`:
- Exactly one of `interval` (positive integer seconds) or `cron` is present —
  both-present or neither is an error.
- When present, `cron` is a 5-field expression (minute hour day-of-month month
  day-of-week); malformed field count or tokens is an error.
- `action` is on the allow-list (currently `["openconnector:synchronization"]`).
- For `action: "openconnector:synchronization"`,
  `arguments.synchronizationId` is a non-empty string.
- `id` is a non-empty slug and unique across `schedules[]`.

Errors surface through `useManifestValidator` exactly like the sibling
validators (side-panel list + the section's inline message).

## Mixed-spec rationale / Dependencies (cross-repo)

This change is `kind: code` (OpenBuild Vue). The `schedules[]` JSON-schema
*definition* it authors against is a **nextcloud-vue delta already shipped in
PR #132** (off `beta`) — a separate repo and spec, declared in `depends_on`.

Because the canonical client validator
(`validateManifest` from `@conduction/nextcloud-vue`, consumed by
`useManifestValidator`, and the `check:manifest` gate) resolves against the
installed nextcloud-vue build, an editor-authored manifest containing
`schedules[]` must not be rejected before #132 is merged and released.
**Guard/sequence:** treat `schedules[]` as an **additive** top-level key — the
canonical validator must tolerate it (unknown-but-allowed), and the app-side
`schedules.js` checks are the authoritative gate until #132 lands. Ship this UI
against a nextcloud-vue build that includes #132, OR keep the section behind
the same tolerance so a stale validator returns `valid` for `schedules[]`
rather than failing closed.

## No OR schema / no seed data

This change adds **no** OpenRegister schema, no `register.d/` fragment, and no
seed data. `schedules[]` is manifest JSON persisted inside the existing
ApplicationVersion object via the existing PUT; it is not a separate OR object
and needs no new schema surface here. (The schema that *validates* it is the
nextcloud-vue delta, not an OR register.)

## Risks / Trade-offs

- **Stale validator false-negative** — if the deployed nextcloud-vue predates
  #132 and its `validateManifest` fails closed on unknown keys, saving a
  schedules manifest could be blocked. Mitigation: additive-tolerance guard
  above; app-side `schedules.js` as the authoritative check.
- **Interval reverse-mapping ambiguity** — a hand-authored `interval` that
  isn't one of the four preset constants maps to "Custom"; handled by the
  optional number+unit field so it round-trips without data loss.
- **Sync id drift** — a stored `synchronizationId` whose synchronization was
  deleted still shows (free-text / raw id) rather than silently clearing, so
  edits never destroy a valid-looking reference the developer didn't intend to
  drop.

## Migration Plan

None. Additive frontend only. Apps with no `schedules[]` render an empty
section; apps with an existing `schedules[]` (hand-authored) load into the
list unchanged.

## Open Questions

- Capability name `openbuild-schedules-authoring` vs `schedules-editor` — see
  DEFERRED_QUESTIONS in the change summary.
- Whether to expose the plain number+unit interval field for *every* preset or
  only as the "non-preset interval" escape hatch (currently the latter).
