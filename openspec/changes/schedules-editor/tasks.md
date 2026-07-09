## 1. Authoring UI

- [x] 1.1 Add `src/components/SchedulesSection.vue` — controlled component (`:manifest` in, `@update:manifest` out) listing `manifest.schedules[]` with add/edit/remove, mirroring `WorkflowAttachmentsSection.vue` (no save API of its own)
- [x] 1.2 Add `src/dialogs/ScheduleEditDialog.vue` — standalone `NcModal` (modal-isolation gate) with cadence preset select (Hourly/Daily/Weekly/Monthly→`interval`; Custom→validated 5-field `cron`), reverse-mapping existing entries, enforcing the one-of `interval`|`cron` invariant at write time
- [x] 1.3 In `ScheduleEditDialog.vue` add the Action `NcSelect` (`:input-label`, option "Run a synchronization"→`openconnector:synchronization`, kept extensible) plus the synchronization picker writing `arguments.synchronizationId`
- [x] 1.4 Implement `fetchSynchronizations()` (`GET /apps/openregister/api/objects/openconnector/synchronization?limit=500`, map to `{id,label}`) with graceful free-text fallback when the list can't load — mirror `ConnectorSourcePicker.vue`
- [x] 1.5 Add the Enabled `NcCheckboxRadioSwitch type="switch"` (default true) and the stable unique `id` slug (auto-derive kebab-case from a label, or typed id field)
- [x] 1.6 Mount `SchedulesSection` as the 4th section in `src/views/PageDesignerHost.vue`, wired to the existing `manifest` data field and `onManifestUpdate()`

## 2. Validation

- [x] 2.1 Add `src/services/manifestValidation/schedules.js` — one-of `interval`|`cron`, 5-field cron shape, allow-listed action, unique non-empty ids, `arguments.synchronizationId` required for the sync action
- [x] 2.2 Wire `validateSchedules` into `src/composables/useManifestValidator.js` alongside the existing workflow/theme/document/connector validators; keep `schedules[]` additive-tolerant so a pre-#132 canonical validator does not fail closed

## 3. i18n & quality

- [x] 3.1 Wrap all user-facing strings in `t('openbuild', ...)` with Dutch + English translations per hydra ADR-007 (English source keys)
- [x] 3.2 Pass `eslint` and `stylelint` on the new/changed files

## 4. Tests

- [x] 4.1 Add `tests/components/SchedulesSection.spec.js` (Vitest) — list render, add/edit/remove emit `update:manifest`, empty-state, sync-picker free-text fallback
- [x] 4.2 Add `tests/services/schedulesValidation.spec.js` (Vitest) — both/neither cadence, malformed cron, non-allow-listed action, missing sync id, duplicate id
- [x] 4.3 `npm run test` green

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate schedules-editor --strict` and resolve any structural errors.
- Confirm persistence rides `PageDesignerHost.save()` (ApplicationVersion PUT) — no new endpoint/store/save method is added for schedules.
- Verify `schedules[]` is not stripped by the canonical `validateManifest` / `check:manifest` — the nextcloud-vue #132 schema is the cross-repo dependency; app-side `schedules.js` is authoritative until it lands.

## Acceptance Criteria

- The page designer shows a Schedules section listing `manifest.schedules[]`; empty when absent.
- Add opens the standalone dialog; cadence presets write the correct `interval` (3600/86400/604800/2592000); Custom writes a validated 5-field `cron`; an entry carries exactly one of `interval`|`cron`.
- The Action select writes `action: "openconnector:synchronization"` and the synchronization picker writes `arguments.synchronizationId`; the picker degrades to free text when the list can't load.
- The Enabled switch writes `enabled` (default true); each `id` is a unique kebab-case slug.
- Edit updates the entry in place preserving its `id`; remove deletes it; both emit `@update:manifest`.
- Invalid entries (both/neither cadence, bad cron, non-allow-listed action, missing sync id, duplicate id) are blocked with a message.
- Schedule edits persist via the existing ApplicationVersion PUT with every other top-level manifest key unchanged — no new backend, route, OR schema, or seed data.
- Vitest component + service specs pass; eslint/stylelint clean.
