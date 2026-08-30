## Why

Buildiq has adopted the walkthrough contract (ADR-043 — `src/manifest.json:8-27` ships a `walkthrough` block with the `buildiq:getting-started` tour) but NOT the first-time-setup contract (ADR-042): `src/manifest.json` has no `setup` block, while peer apps pipelinq, procest, larpingapp and opencatalogi all ship one. The gap is not cosmetic — it reproduces exactly the failure modes ADR-042 was written for:

1. **Seeding rides on a repair step that only runs on install/version-bump.** `lib/Repair/SeedApplicationTemplates.php:110-116` seeds the bundled ApplicationTemplates; if it skipped (fixtures dir missing, OR unavailable in the repair context) there is no user-facing remedy — the Store and the create-from-template flow are silently empty.
2. **The getting-started walkthrough sends new users into that empty room.** Tour steps `go-store` and `create-app` (`src/manifest.json:21-22`) instruct the user to open the Store and clone a template; on an unseeded instance the tour dead-ends with no explanation.
3. **The remote template store is hidden until an admin finds an unrelated settings pane.** `SettingsService::CONFIG_DEFAULTS` (`lib/Service/SettingsService.php:62-64`) deliberately defaults `registry_url` to `''` so the remote store "stays hidden until an admin configures it" — but nothing ever *prompts* the admin to configure it (or to consciously skip it).

The nc-vue building blocks named by ADR-042 (`CnWizardDialog`, `fieldsFromSchema`, the manifest v2 `setup` block, `run-action` step type) are the same machinery the sibling change `buildiq-walkthrough-editor` already plans to let Buildiq users *author* for their virtual apps — but that change edits OTHER apps' setup blocks; Buildiq's OWN manifest still has none. The builder app should be a first-class example of the contract it teaches.

## What Changes

- **Add a `setup` block to `src/manifest.json`** (manifest v2, per ADR-042): `{ enabled: true, version: 1, completionConfigKey: "setup_completed_version", steps: [...] }` with:
  - `info` step — what Buildiq is, what the wizard will do.
  - `run-action` step (required) — "Seed bundled templates": POSTs a privileged server-side action that re-runs the `SeedApplicationTemplates` logic idempotently (create-missing, never overwrite), reporting seeded/skipped counts. This gives unseeded instances a UI remedy that today only exists as an install-time repair step.
  - `config-fields` step (optional) — remote template store: `registry_url`, `registry_register`, `registry_token`, written through the existing `SettingsService` update path (`SECRET_KEYS` semantics preserved — token write-only). Skippable: the local store works without it.
  - `summary` step — recap + the manifest `observability.health.checks` status.
- **Server-side setup action endpoint** — `POST /api/setup/seed-templates`, admin-only (`#[AuthorizedAdminSetting]` or explicit admin guard per ADR-005), idempotent, returns `{ seeded, skipped }`. Reuses `SeedApplicationTemplates`'s fixture-loading code, extracted into a service both the repair step and the action call.
- **Gate the walkthrough on setup completion** — the `buildiq:getting-started` tour keeps `trigger: "first-visit"` but the setup wizard (admin-facing) runs first for admins; non-admin users on an unconfigured instance see the standard not-yet-configured state instead of a dead-end tour (exact interplay per the nc-vue `CnAppRoot` phased boot described in ADR-042).
- **No BREAKING changes.** Instances already seeded and configured see the wizard pre-satisfied (completion key stamped by the summary step); nothing changes for end users.

## Capabilities

### New Capabilities

- `buildiq-first-time-setup`: the manifest `setup` block (steps, completion key), the admin-only idempotent seed-templates action endpoint, and the seeded/configured gating semantics.

### Modified Capabilities

_None in this repo's existing spec set._ (`settings-and-observability` is consumed unchanged — the config-fields step writes through the existing settings surface; `buildiq-template-catalogue` seeding semantics are reused, not modified.)

## Impact

- `src/manifest.json` — new `setup` block.
- `lib/Repair/SeedApplicationTemplates.php` — seeding logic extracted to a `TemplateSeedService`; the repair step becomes a thin wrapper (behaviour unchanged).
- New `lib/Controller/SetupController.php` + one route in `appinfo/routes.php` (specific-first, before the SPA catch-all; declared auth attribute per ADR-016/ADR-029).
- **Dependency:** nc-vue's ADR-042 wizard engine (`CnWizardDialog` + manifest `setup` renderer). If the shared renderer has not shipped by implementation time, the manifest block + server action still land (they are the contract), and the dialog falls back to the `component` escape hatch.
- Sibling coordination: `buildiq-walkthrough-editor` (edits setup blocks of virtual apps) — no file overlap; this change is Buildiq's own manifest.
