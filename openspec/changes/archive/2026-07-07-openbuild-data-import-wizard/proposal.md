---
kind: code
depends_on: []
chain:
  - openbuild-data-import-wizard
---

## Why

Every citizen-developer / low-code builder in the market (Baserow, NocoBase,
Directus, Budibase, Airtable) treats **"upload a spreadsheet and get a working
data model + rows"** as day-one, table-stakes functionality — it is the single
most common on-ramp for the non-technical maker persona OpenBuild targets. It is
how a municipality's process owner turns "the Excel we already track permits in"
into a running app. OpenBuild's `openbuild-schema-designer` today only lets an
author hand-build schemas field-by-field, and there is **no path anywhere in
OpenBuild to seed a virtual app's data from an existing file** — a grep of the
source tree finds `csv`/`xlsx` only as allowed *attachment* MIME types on
`FilesPageEditor`, never as an import surface. A maker who already has their data
in a spreadsheet must retype every column as a schema field and then hand-enter
every row, which for the "I already have the data" case is the whole reason they
would abandon the tool.

The capability the wizard needs **already exists in OpenRegister** and is exactly
the abstraction OpenBuild is supposed to consume (ADR-022). OpenRegister ships:

- `POST /api/registers/{id}/import` — accepts an uploaded `xlsx`/`xls`/`csv`/`json`
  file, auto-detects the type from the extension, gates the write on
  **manage-permission** for the target register (default-secure: admin-only when
  no manage rule exists), and creates/updates objects — optionally creating the
  schema from the file's header row (`ImportService` + `SchemaImport`, verified
  in `RegistersController::import()` at HEAD).
- `GET /api/registers/{id}/schemas/{schema}/import-template` — emits a
  schema-shaped `xlsx`/`csv` template so a maker can download the exact columns,
  fill them offline, and re-upload.
- `POST /api/registers/import/rollback` — undoes the most recent import.

OpenBuild binds none of these today. This change surfaces them as a guided
**Import data wizard** scoped to a virtual app's own per-version register
(`openbuild-{slug}-{versionSlug}`, per ADR-002), so the maker imports into the
right isolated register with zero new OpenBuild import code — every schema/object
write goes through OpenRegister, honouring the "one write path" rule.

## What Changes

- **NEW** `src/dialogs/ImportDataWizard.vue` (standalone dialog per the
  modal-isolation rule) — a stepped wizard opened from the app-detail cockpit's
  structural-widget grid (the existing Register/Schemas card gains an **Import
  data** affordance) and from the Schema Designer. Steps: (1) choose target —
  an **existing schema** in the active version's register, or **create a new
  schema from the file**; (2) upload an `xlsx`/`xls`/`csv`/`json` file; (3) a
  **column-mapping / inferred-field preview** (header row → schema fields with a
  guessed type per column, editable before commit) plus a first-N-rows sample;
  (4) confirm + import; (5) a **result summary** (created / updated / skipped
  counts, per-row errors) with an **Undo import** action.
- **NEW** `src/composables/useDataImport.js` — a thin client that POSTs the file
  (multipart) to `POST /apps/openregister/api/registers/{registerId}/import`
  with `type` auto-detected by OR, `includeObjects: true`, and the OpenBuild
  target scoped to the **active `ApplicationVersion.register`**; reads back the
  import summary; and offers rollback via `POST
  /apps/openregister/api/registers/import/rollback`. It contains **no import
  parsing of its own** — OpenRegister owns file parsing, schema inference, and
  object writes.
- **NEW** "Download a template" leg — for the *existing-schema* path, the wizard
  offers a download that calls `GET
  /apps/openregister/api/registers/{registerId}/schemas/{schema}/import-template`
  so the maker gets exactly the right columns.
- **RBAC** — the wizard is only offered when the caller holds a build/manage
  role on the Application (reusing OpenBuild's existing `PermissionResolver` /
  per-Application `permissions` gate); the import itself is independently
  re-checked server-side by OpenRegister's own manage-permission gate on the
  target register, so OpenBuild never becomes a permission-bypass path.
- **Version-scoped, promotion-safe** — imports always target the **active
  version's own register**, never a shared data register (`dataRegisters`
  bindings are read-only shared data and are excluded from the import target
  list). Imported rows are ordinary objects in that per-version register, so
  they are captured by version snapshots and carried/isolated by promotion
  exactly like hand-entered data — no new versioning surface.
- **NO** new OpenBuild PHP; **NO** OpenBuild-side file parsing, schema
  inference, or object writing (all delegated to OpenRegister); **NO** change to
  the manifest shape, the exporter, or the promotion chain.

### Capabilities

#### New Capabilities

- `openbuild-data-import-wizard`: the stepped Import-data wizard dialog, the
  `useDataImport` composable that consumes OpenRegister's
  `registers/{id}/import` + `import-template` + `import/rollback` endpoints
  scoped to the active version's per-version register, the create-new-schema and
  map-to-existing-schema target paths with an inferred-field/column-mapping
  preview, the result summary with undo, the build/manage RBAC gate, and the
  entry points on the app-detail cockpit and the Schema Designer.

#### Modified Capabilities

_None._ This change is additive — a new dialog, a new composable, and new entry
affordances. It does not modify the manifest endpoint, the schema-designer
authoring contract, or the versioning/promotion/export surfaces; imported rows
are ordinary objects in the version register the schema designer already targets.

## Impact

- **New frontend code**: `src/dialogs/ImportDataWizard.vue` (~350 LOC),
  `src/composables/useDataImport.js` (~150 LOC), an "Import data" affordance
  wired into the app-detail Register/Schemas card and a Schema Designer toolbar
  button (~40 LOC), plus Vitest suites. **Zero new PHP.**
- **Integration contract (pinned to OpenRegister's existing surface)** —
  OpenBuild calls exactly:
  1. `POST /apps/openregister/api/registers/{registerId}/import` (multipart file
     + `includeObjects=true`; `type` auto-detected by OR) — the object/schema
     write.
  2. `GET /apps/openregister/api/registers/{registerId}/schemas/{schema}/import-template`
     (`format=xlsx|csv`) — the offline template download.
  3. `POST /apps/openregister/api/registers/import/rollback` — undo.
  All reads/writes ride the caller's NC session; `registerId` is always the
  active `ApplicationVersion.register`. A Newman assertion pins the import
  endpoint's response envelope (created/updated/skipped counts) so OR-side drift
  fails CI rather than production.
- **RBAC / security**: the wizard visibility is gated by OpenBuild's per-app
  build/manage permission; the write is independently re-gated by OpenRegister's
  register manage-permission (default-secure, admin-only when no manage rule
  exists), so the two gates compose and neither is bypassed. OpenBuild uploads
  only the file bytes the maker chose; it never parses, transforms, or persists
  them itself.
- **No breaking changes** — purely additive; an app that never imports behaves
  byte-identically. No manifest, schema, version, or export shape changes.

## Open Questions

- **OQ-1**: Column-mapping *persistence* — should a saved mapping profile be
  reusable across repeat imports of the same file shape? Deferred; v1 maps
  per-import.
- **OQ-2**: Async import for very large files — OR's import is synchronous
  today; a background-job variant (mirroring the exporter's `RunExportJob`) is a
  follow-up if large-file timeouts appear in practice. v1 imports synchronously
  and surfaces a size hint.
- **OQ-3**: Should the create-new-schema path pre-open the Schema Designer on the
  inferred schema for refinement before the row import runs, or import rows
  immediately after confirming the inferred fields? v1 imports immediately after
  the inline preview; "open in designer first" is a deferred enhancement.
