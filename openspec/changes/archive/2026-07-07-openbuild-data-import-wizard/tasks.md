# Tasks: openbuild-data-import-wizard

> No OpenBuild PHP: all file parsing, schema inference, and object writes are delegated to OpenRegister's shipped register-import (`POST /api/registers/{id}/import`, `.../schemas/{schema}/import-template`, `.../import/rollback`). Verify those endpoints against OpenRegister HEAD before wiring.

## 1. Client delegation to OpenRegister import

- [x] 1.1 Add `src/composables/useDataImport.js`: multipart-POST the file to `POST /apps/openregister/api/registers/{registerId}/import` (type auto-detected, `includeObjects: true`), read back the created/updated/skipped summary + per-row errors, and offer rollback via `POST /apps/openregister/api/registers/import/rollback`. Contains NO import parsing/inference/writing of its own. Also wrap `GET /apps/openregister/api/registers/{registerId}/schemas/{schema}/import-template`.
  - **spec_ref**: `specs/data-import-wizard/spec.md#requirement-a-maker-can-seed-a-virtual-apps-data-from-an-uploaded-file`
  - **acceptance_criteria**:
    - All parsing/inference/writes go to OpenRegister; OpenBuild adds no import backend
    - Verified against the OR endpoints at HEAD (`RegistersController::import()` / import-template / rollback)

## 2. Wizard dialog (modal-isolation)

- [x] 2.1 Add `src/dialogs/ImportDataWizard.vue` (standalone dialog per the modal-isolation rule): steps (1) choose target — existing schema in the active version's register OR create-from-file; (2) upload `xlsx`/`xls`/`csv`/`json`; (3) column-mapping / inferred-field preview + first-N-rows sample (editable types before commit); (4) confirm + import; (5) result summary with Undo. Only the active `ApplicationVersion.register`'s schemas are selectable targets; shared bound `dataRegisters` excluded. Strings via `t()`; `NcSelect` carries `inputLabel`.
- [x] 2.2 Surface an "Import data" affordance on the app-detail cockpit Register/Schemas card and in the Schema Designer, gated by the caller's build/manage role (existing `PermissionResolver` / per-Application `permissions`).
  - **spec_ref**: `specs/data-import-wizard/spec.md#requirement-imports-are-version-scoped-and-promotion-safe`, `#requirement-the-import-is-authorised-on-both-sides`
  - **acceptance_criteria**:
    - Target list = active version register schemas only; affordance hidden for non-builders
    - Imported rows are ordinary objects in the per-version register (captured by snapshots/promotion; no new versioning surface)

## 3. Verify

- [x] 3.1 `openspec validate openbuild-data-import-wizard --strict` clean; vitest for the composable + wizard (stubbed OR import endpoint: create-schema path, existing-schema path, rollback, non-builder hidden, shared-register excluded); manifest still Ajv-valid; no OpenBuild-side import backend added.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + vitest green; delegation + double-auth + version-scoping verified
