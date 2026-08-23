---
kind: code
depends_on:
  - nextcloud-vue#132   # manifest `schedules[]` JSON-schema definition (off `beta`)
---

## Why

An Buildiq manifest already carries a top-level `schedules[]` array — the
**apphost-scheduling capability**. Each entry is a declarative scheduled task
that a generic OpenRegister AppHost reconciler turns into a concrete
OpenConnector job (run a synchronization on a cadence). The backend
reconciler and the manifest schema for `schedules[]` are already implemented
and live-verified; the schema itself ships from **nextcloud-vue** (added in
PR #132, off `beta` — NOT this repo).

Today the only way to add a scheduled task to an app is to hand-edit the
manifest JSON. A citizen developer working in Buildiq's app editor has no
UI surface for it, so the apphost-scheduling loop is only half-closed: the
runtime can execute schedules, but nobody can author them without dropping to
raw JSON. This change closes the loop with an **authoring UI only** — no new
backend behaviour, no new OR schema, no reconciler work.

Per hydra ADR-004 the editor is plain Vue 2.7 + `@nextcloud/vue`, and per
hydra ADR-031 there is deliberately **no declarative-backend behaviour** in
this change: the reconciler already exists and is untouched; this is a pure
authoring surface that mutates `manifest.schedules` in the page designer.

## What Changes

- **NEW** `SchedulesSection.vue` (`src/components/`) — a controlled component
  (`:manifest` prop in, `@update:manifest` out) that renders the app's
  `manifest.schedules[]` as a list with add / edit / remove, mirroring
  `WorkflowAttachmentsSection.vue`. It mounts as a 4th section in
  `PageDesignerHost.vue` alongside ThemeSection, WorkflowAttachmentsSection
  and DocumentAttachmentsSection.
- **NEW** `ScheduleEditDialog.vue` (`src/dialogs/`) — a standalone
  `NcModal`-based dialog (per the hydra modal-isolation gate) that edits one
  schedule entry: a friendly **cadence preset** dropdown (Hourly / Daily /
  Weekly / Monthly / Custom → writes an `interval` in seconds, or a validated
  5-field `cron` for Custom), an **Action** select (one option today: "Run a
  synchronization" → `action: "openconnector:synchronization"`), a
  **synchronization picker** (`NcSelect`) that populates
  `arguments.synchronizationId` and degrades to a free-text id field when the
  list can't be loaded, an **Enabled** switch (default true), and a stable
  **id** slug.
- **NEW** `services/manifestValidation/schedules.js` — app-side strict checks
  (exactly one of `interval` | `cron`; 5-field cron shape; allow-listed
  action; unique entry ids; `arguments.synchronizationId` required for the
  sync action), wired into `useManifestValidator` alongside the existing
  workflow/theme/document/connector validators.
- **NO new backend, route, OR schema, or seed data.** Persistence is free:
  `PageDesignerHost.save()` already PUTs the whole ApplicationVersion manifest
  (`{...version, manifest}`), so any top-level key the editor doesn't touch —
  including `schedules[]` — survives. The section simply mutates
  `manifest.schedules` and emits `update:manifest`.
- **Cross-repo dependency / additive-validation guard.** The `schedules[]`
  JSON-schema definition lives in nextcloud-vue (#132). The client-side
  canonical validator (`validateManifest` from `@conduction/nextcloud-vue`,
  and `check:manifest`) must **tolerate** a `schedules[]` array additively so
  an editor-authored schedules manifest is not rejected before #132 merges —
  see design.md "Mixed-spec rationale / Dependencies".

### Capabilities

- **ADDED** `buildiq-schedules-authoring` — the visual authoring surface for
  the manifest `schedules[]` array in Buildiq's app editor.

No existing capability is modified. `buildiq-page-designer` gains a new
section by composition (a mounted sibling component), not by changing its
existing requirements.
